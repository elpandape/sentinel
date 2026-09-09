# 🧠 What Sentinel is (and is not)

> The honest positioning page: what problem this package solves, what it refuses to solve, and the
> cases where the right decision is to install something else.

**On this page:** [The unit](#the-unit-is-the-audit-record) · [What one chain carries](#what-one-chain-carries) · [The walls](#the-walls-in-this-problem-space) · [When not to adopt it](#when-not-to-adopt-sentinel) · [What adoption costs](#what-adoption-costs) · [Compared with the alternatives](#compared-with-the-alternatives) · [Pitfalls](#️-pitfalls) · [Best practices](#-best-practices)

---

## The unit is the audit record

An activity logger writes a line: *user 42 updated invoice 77*. Sentinel writes a record, and the
record is the product. One row of `sentinel_audits` answers, in forty columns, what changed, who
changed it, on whose behalf, from where the change came, which business operation and which HTTP
request produced it, what the full state was before and after — and whether that row itself can be
proven untouched since it was written.

The last clause is the one that makes this a different kind of package. Every entry carries a
`hash` computed over a canonical form of twenty-seven of its columns, prefixed with the entry's
`payload_version`, its `stream`, its `sequence` and the `previous_hash` of the entry before it in
that stream. Editing an entry breaks its own hash. Deleting one breaks the next one's link.
Reordering breaks both. Chaining is unconditional — there is no configuration key that turns it
off — which is why the word *ledger* appears in the description and *log* does not.

> 📌 **Note.** `payload_version`, `stream`, `sequence`, `hash`, `previous_hash` and the frozen
> column list in `Integrity\CanonicalPayload` are load-bearing. Changing any of them changes what
> every already-written entry hashes to, so a change there costs a `payload_version` bump plus a
> backwards-compatibility test against a golden dataset. Nothing in this documentation invites you
> to touch them. See [the integrity model](04-the-integrity-model.md).

The practical consequence of the design decision: a third party can be handed the rows and the
public half of the signing key, and prove that nothing was altered — without holding a decryption
key, without database access, and without the ability to write a single entry. `CanonicalPayload`
decrypts nothing on its way to the hash, so verification reproduces byte for byte with
`sentinel.security.encryption.keys` set to an empty array.

## What one chain carries

Model changes are one kind of entry among several. Everything below goes through the same pipeline,
the same ledger, the same `sequence` and the same hash — there is no side channel for facts that did
not come from Eloquent, and no way to tell one kind apart in the chain except by reading its
`audit_type`.

| `audit_type` | Written by | What it records |
|---|---|---|
| `model` | `Capture\ModelCapture` | A create, update, delete or force-delete on an auditable model |
| `transition` | `Transitions\TransitionBuilder`, or `ModelCapture` when an update moves a column named in `$auditTransitions` | A record moving between declared states |
| `relation` | `Capture\RelationCapture`, `Capture\ParentCapture` | `attach`, `detach`, `sync`, `toggle`, `updateExistingPivot`, and a `belongsTo` child changing hands |
| `custom` | `Capture\PendingEvent` (`Sentinel::event()`) | A fact the application states outright — an approval, a dispatch |
| `auth` | `Capture\AuthenticationSubscriber` | Laravel's authentication events, when the subscriber is registered |
| `mass` | `Mass\MassCapture` | A `Builder::update()`, `delete()` or `upsert()` that asked to be audited |
| `restore` | `Restore\Restorer` | The Restore Engine putting a record back out of the trail |
| `security` | `Redaction\Redactor`, `Security\Rekeyer` | A redaction, or a key rotation |
| `access` | `Compliance\AccessLog` | A read through the Query API, under compliance mode only |

One value is deliberately **not** in the chain: a business transaction's header. `Sentinel::transaction()`
writes a row into `sentinel_transactions` describing the operation — its name, its actor, how many
entries it wrote, whether it was abandoned — and that row is updated when the operation ends. It
takes no `sequence` and carries no hash, because what happened is in the entries, where the chain
covers it. Note also that `Sentinel::transaction()` opens **no database transaction**: correlating and
atomising are separate decisions, and combining them is yours.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;
use Illuminate\Support\Facades\DB;

DB::transaction(fn (): bool => Sentinel::transaction('invoice.approve', function () use ($invoice): bool {
    $invoice->update(['status' => 'approved']);
    $invoice->payments()->create(['amount' => $invoice->total]);

    Sentinel::event('invoice.approved')->subject($invoice)->record();

    return true;
}));
```

## The walls in this problem space

Every Laravel auditing package meets the same obstacles. What differentiates them is which ones they
closed and which ones they documented as limitations. Sentinel's answers, each verifiable in the
source:

| Wall | Sentinel's answer |
|---|---|
| Eloquent fires no model event for `Builder::update()` / `delete()` / `upsert()` | Closed, but **opt-in per query**: `->auditing()` is a macro on the Eloquent builder, so a query that does not ask costs exactly what it cost before. Three modes; `summary` is the default. See [Mass operations](../03-capture/05-mass-operations.md) |
| A pivot change (`attach`, `detach`, `sync`, `toggle`) surfaces no event at all | Closed with **no change to call sites**: the trait overrides `newBelongsToMany()` and `newMorphToMany()`, and the subclasses wrap all six operations behind a reentrancy guard. See [Relationship auditing](../03-capture/04-relationships.md) |
| Telling a restore apart from an update that merely cleared `deleted_at` | The observer listens to four writing Eloquent events and derives six (`event`, `audit_type`) pairs from them; `restored` is reconstructed from the `updated` that cleared the mark, because by the time `restored` fires the original has already been synced |
| Restoring into a schema that moved | A per-field plan, not an all-or-nothing apply. `Restore\RestoreResult` reports `applied`, `skipped` (each field name mapped to the `Enums\Omission` that explains it) and `refused`, and carries no boolean — four fields back out of six is neither success nor failure. See [Restoring state](../06-reading/08-restoring-state.md) |
| No authenticated user in a worker, a command or the scheduler | Context is resolved **at capture**, never at settlement, and travels inside the entry. Ten resolvers, each replaceable. See [Queues, commands and schedulers](../04-context/05-queues-commands-and-schedulers.md) |
| Audits written inside a transaction that later rolls back | `transactions.after_commit` is on by default, using the framework's own commit dispatch. The pipeline still runs at capture — the context is only true then — so a rollback costs you that work, which is cost and not correctness |
| Serialising enums, dates, casts and value objects the same way twice | `Snapshot\SnapshotBuilder` applies the model's own casts and normalises key order all the way down; the chain hashes the canonical structure, never the text the engine stored. See [Snapshots](../03-capture/02-snapshots.md) |
| Hashing a JSON payload so two runs agree byte for byte | RFC 8785 canonicalisation, implemented in-package and tested against the RFC vectors. See [Canonicalization](../07-integrity/03-canonicalization.md) |
| Right to erasure versus an immutable chain | Stated as a trade, not solved: a tombstone empties all six content columns and keeps existence, `sequence`, `hash` and the next entry's link. Verification grows a third per-entry answer. See [Redaction and tombstones](../08-lifecycle/04-redaction-and-tombstones.md) |
| A trail that only grows, so verifying it means reading everything | Signed anchors over fixed windows, and three verification depths that each report what they proved. Both off by default. See [Checkpoints and anchors](../07-integrity/05-checkpoints-and-anchors.md) |

---

## When not to adopt Sentinel

This is the section to read before the feature list. Most of the walls above are real and most
applications do not hit them; the cost of this package is real and every application pays it.

### You want a change log, not a ledger

If the question is *who edited this post* and nobody in your organisation will ever run
`sentinel:verify`, you are paying roughly **11.5× the cost of an unaudited write**, a forty-column
table with thirteen indexes, six side tables and eleven artisan commands for a property you will
never exercise. `owen-it/laravel-auditing` is smaller, older, has years of downloads behind it, and
does that job. Take the ledger-versus-log distinction at its word and choose the log.

### Your stack is not PHP 8.4 and Laravel 13

`composer.json` requires `"php": "^8.4"` and `illuminate/*: "^13.0"`, and nothing else. Laravel 12 is
excluded. There is no back-compatibility branch and there is no plan for one. Both alternatives
support considerably older stacks. This constraint alone disqualifies most applications already in
production.

### You run MariaDB

MariaDB is refused by name rather than guessed at. `Ledger\ChangedFieldPredicate` has a JSON dialect
for `sqlite`, `pgsql` and `mysql`, and its `default` arm throws:

```
Sentinel has no field predicate for the [mariadb] engine, so whereFieldChanged()
cannot be answered there. Supported: mysql, pgsql, sqlite.
```

The engines run on every push are PostgreSQL 16, MySQL 9 and SQLite 3.45. See
[Choosing an engine](../10-database-engines/01-choosing-an-engine.md).

### You need a screen this quarter

The core is UI-agnostic by architectural invariant, and it **mounts no routes**. What ships is a
Query API, a presenter that renders entries as sentences, and `Http\Resources\AuditResource` — a
`JsonResource` that adds no key, renames none and hides none, purely so you get Laravel's collection
and pagination envelope around `Audit::toArray()`. The package will not mount a route for it,
because which entries a given request may see is an authorisation question it has no standing to
answer for your application. If you need an administration screen and you need it now, this is a
build, not an install.

That is an invariant, not an omission waiting to be corrected. Any administration UI for this
package belongs in a **package of its own**, sitting on top of the Query API the same way your own
screens would: `composer.json` names no UI package, and nothing in `src/` knows a panel exists.
Installing the core gets you no screen, and no future version of the core will change that.

### Nobody will own the operational surface

Retention, anchoring, partition maintenance, key rotation and buffer flushing are commands the
**application** must register on its own scheduler. The package deliberately schedules nothing.

Without an owner the failure mode is silent and specific. Pruning's unit is the anchored window, not
the entry: `Retention\Frontiers` will only offer a window that an anchor covers, that holds no
still-retained entry, and that is not the window holding the stream's highest sequence. So an
installation that sets `'auth' => '90 days'` and never runs `sentinel:checkpoint` frees **nothing,
forever**, and is perfectly correct in doing so. And because a window is folded whole, the effective
retention of a range is that of its longest-lived entry inside it.

### Your hot write path cannot afford the cost, and cannot accept the buffered mode's loss window

The two asynchronous modes roughly halve what the *request* pays. `queue` costs about 8% more end to
end, because deferring moves work rather than removing it. `buffered` is the only mode cheaper
overall (~11% less) and it is the only one that can lose an entry: **what a dying process was holding
is gone**, bounded only by `buffer.size` and `buffer.flush_interval`.

> ⚠️ **Warning.** A lost buffered entry took no `sequence`, so it leaves no gap. Verification walks a
> shorter chain and correctly reports it intact. The chain proves that what settled was not tampered
> with; it never proves that everything that happened settled. If a specific path cannot tolerate
> that, do not audit that path in `buffered` mode.

### You need proof against a compromised application

No signer here makes that claim, and the package says so rather than implying otherwise. Three tiers,
each with what it does **not** stop: `HmacSigner` stops database-only access — a stolen backup, a
replica, a console, a SQL-injection sink — and does not stop anyone who can read `APP_KEY`.
`OpenSslSigner` additionally stops the machine's own administrator when the private key lives off the
box, and does not stop whoever holds that private key. `NullSigner` stops nothing. A fourth tier — a
forward-secure MAC that evolves and erases its key — is named and declared out of scope.

The ceiling above all three: **no signature proves the content is true.** Someone with application
access at capture time produces a perfectly intact, perfectly signed chain of false statements. This
is append-time integrity, and that is all it is. See [Signing the chain](../07-integrity/04-signing.md).

### You need inclusion proofs for a verifier who does not trust the log

Anchors fold; they do not tree. `root₀` covers the window's identity and the previous root, then each
entry hash folds into the running root. Nothing in Sentinel consumes an inclusion proof, so none is
produced. If your design calls for a client asking the log to prove one record's membership, this is
not that system — the `algorithm` column records `fold-sha256` precisely so a `merkle-sha256` could
arrive later, but it has not.

### You expect a compliance certification

Sentinel certifies nothing. It ships technical primitives — a chain, signatures, anchors, tombstones,
an access record — and whether a given regime is satisfied by them is a question for someone who
knows that regime. `compliance => true` hardens the configuration and refuses to boot without
signatures and anchors; it issues no certificate and changes nothing about a procurement
conversation.

### The remaining refusals, briefly

| If this is you | Why Sentinel is the wrong answer |
|---|---|
| You want a stable, battle-tested release | This is `v1.0.0-rc.1`: the API is frozen but it is a release candidate, single-author, and needs `composer require elpandape/sentinel:^1.0@RC` because Composer will not otherwise install it |
| You are buying it for record-and-restore over an existing `altek/accountant` trail | That package never wrote a `before` — `Import\Origins\Altek` says so in its own docblock — so a whole-record `restore()` on an imported entry is refused by name rather than fabricated |
| You need entry-level erasure at scale | The prune unit is the anchored window; per-entry removal is the tombstone, which empties one entry in place. `sentinel_archives` is indexed by stream and range, never by subject, so an erasure request spanning someone's whole history is answered range by range |
| Your team will not internalise the two clocks | `occurred_at` (when it happened) and `created_at` / `sequence` (when it settled) agree only while writing is synchronous. A lifeline built on `created_at` keeps working and quietly starts answering a different question |

## What adoption costs

Every figure below has a provenance. The write-path numbers come from the package's own benchmark
(`make bench`): one create per iteration, 1,000 iterations after 200 warm-up writes, median of three
passes, SQLite, same machine and same run. They are a report, never a gate, and they are not a
promise about your schema.

| Cost | Measured | What it means for you |
|---|---|---|
| Ordinary write | 179 µs unaudited → 2,068 µs under `sync` (~11.5×) | The dominant cost is the pipeline, canonicalisation and hash. `$auditSnapshots = false` buys storage, not microseconds |
| Asynchronous modes | `queue` 1,077 µs in the request (−48%, ~+8% total); `buffered` 1,194 µs (−42%, ~−11% total) | Both halve the request. Only `buffered` is cheaper overall, and only `buffered` can lose entries |
| Mass operations, per row over 500 rows | unaudited 0.7 µs · `summary` 4.6 µs · `hybrid` over its threshold 19.6 µs · `individual` 889.7 µs | `summary` is 2.3 ms for the whole operation at any set size. `individual` writes 501 entries for 500 rows and is never the default |
| Signing | unsigned 2,400 µs · HMAC 2,437 µs (+1.5%) · OpenSSL RSA-2048 3,252 µs (+35.5%) | HMAC is inside the noise floor (the `NullSigner` control itself swings −10% to +12%). RSA is ~850 µs of private-key work per entry, on every write |
| Compliance mode reads | 0.309 s → 0.557 s over 100 reads of 50 entries (~+2.5 ms per read) | The durable cost is structural, not temporal: every Query API read writes a chained entry that **consumes a sequence in `sentinel_audits`**, so the audit table now grows with reads |
| Storage | Not published as a per-row figure | Each entry stores the complete `before`, the complete `after` **and** the diff in `changes`, in a 40-column table with 13 indexes plus six side tables. Size it against your own widest audited table before adopting |
| Key material | Four distinct secrets, with permanent obligations | A retired signing key must stay on the ring or its history becomes unverifiable; an encryption key must outlive the entries it wrote; the hashing salt is stable by definition — rotating it breaks no chain and destroys the comparability of every digest ever written |
| Scheduled jobs the application must own | `sentinel:checkpoint`, `sentinel:prune`, `sentinel:flush` (under `buffered`), `sentinel:partitions` (if partitioned), a watchdog on `sentinel:verify` | The package registers eleven commands and schedules none of them. See [Scheduling](../09-operations/07-scheduling.md) |

> ⚠️ **Warning.** The first `sentinel:checkpoint` run over an existing trail is the one trail-walking
> command that takes no bound at all — `--stream` is the only option it has, and there is no
> `--limit`, no `--batch` and no range. It anchors every complete window each stream still owes, so
> at the default window a ten-million-entry stream emits ten thousand anchors and reads the whole
> trail. Run the first pass by hand, before putting the command on `->hourly()`.

## Compared with the alternatives

Both alternatives are good software that has been in production for far longer than this package.
The comparison worth trusting is one that concedes that.

| | Sentinel | `owen-it/laravel-auditing` | `altek/accountant` |
|---|---|---|---|
| Maturity | `v1.0.0-rc.1`, single author, new on Packagist | Years of production use, large community | Long-standing, record-and-restore focus |
| Stack floor | PHP 8.4, Laravel 13 only | Far wider | Far wider |
| Community UI packages | None | Yes | — |
| Update snapshot | Complete `before` and `after` | `getDirty()` only — the fields that moved, not the record (a create and a delete do carry everything) | A complete `after`; **no `before` anywhere**, only the names of the dirty attributes |
| Array-valued attributes | Captured | Dropped before writing unless `audit.allowed_array_values` is turned on | Captured |
| Labels | One row per label in `sentinel_audit_tags` | Comma-joined in one column with no escaping, so a label containing a comma was already two labels when it was written | — |
| Impersonation | `impersonator_type` / `impersonator_id`, from a configurable session key | No concept — the import guide maps it to *left empty* | — |
| Pivot changes | Audited with no change to call sites | Requires calling `auditAttach()` and friends yourself | Needs an extra package plus an explicit event declaration |
| Mass `Builder::update()` | Audited when the query asks with `->auditing()` | Not covered | Not covered |
| Integrity | Chained: each entry links to the previous one in its stream, and the whole chain can be folded and signed | No hashing at all | `Notary::sign()` hashes each row over its own attributes — altering row N leaves row N+1's signature intact, so it proves one row was not edited, never that none were removed, reordered or inserted |

The honest reading of that table: the two rows that have no equivalent anywhere else are **pivot
auditing** and **mass-operation coverage**, and the integrity chain is a different category of thing
rather than a better version of theirs. Everything else is a trade against maturity and stack reach.

If you are migrating, read the guide for your source package before you plan the work — they are
genuinely different migrations. Two expectations to set with stakeholders up front: the **chain
starts at the import** (nobody hashed the old rows as they were written, and Sentinel will not
fabricate a proof that no one touched data it never saw), and the **source already lost things** that
no importer can recover. See [From owen-it](../12-migrating/01-from-owen-it.md) and
[From altek](../12-migrating/02-from-altek.md).

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| A retention policy is set and nothing is ever deleted, with no error | Pruning retires anchored windows, and nothing has been anchored | Schedule `sentinel:checkpoint`, after one manual first pass. Retention without anchors is inert by design |
| An `'auth' => '90 days'` policy frees nothing even with anchoring on | A window is folded whole, so the effective retention of a range is that of its longest-lived entry, and under the default `integrity.stream => 'tenant'` a stream mixes logical types | Expect retirement at window granularity, or narrow the stream strategy before there is data |
| The application stops booting the moment `compliance => true` is set | `Compliance\Requirements::enforce()` throws `ComplianceException::incomplete` unless `integrity.signature.enabled` and `integrity.checkpoints.enabled` are both on | Turn both on, or turn compliance off. The refusal at boot is the feature — the first write that should have been signed may be a year away |
| Auditing silently stops for the rest of a request or job | `Sentinel::pause()` was called and something threw before `resume()`; `resume()` clears the flag unconditionally and nothing else takes it down | Use `Sentinel::withoutAuditing()`, which restores the previous flag in a `finally` and therefore nests |
| `whereFieldChanged()` throws on a connection that works for everything else | The connection is MariaDB, and `Ledger\ChangedFieldPredicate` has no dialect for it | Use PostgreSQL, MySQL or SQLite. The refusal is deliberate: answering with a predicate that might not mean the same thing is worse |
| A model uses `Contracts\Auditable` and records nothing | Implementing the interface registers no Eloquent listeners; `bootAuditable()` in the trait is what calls `registerModelEvent()` for `created`, `updated`, `deleted`, `forceDeleted` and `updating` | Use the trait. Implement the contract only when the declarations must be computed |
| A mass `update()` runs and writes no entry | Mass auditing is opt-in per query — `auditing()` is a macro on the Eloquent builder, so a query that never asks is never intercepted | Add `->auditing()` to the queries that need it. There is deliberately no global switch |
| Entries are missing after a worker crash, and `sentinel:verify` reports the chain intact | Under `buffered`, what a dying process held never reached the ledger, so it took no sequence and left no gap | Use `sync` or `queue` on paths that cannot lose an entry, and listen for `BufferFlushFailed` |
| A history reads in the wrong order under `queue` or `buffered` | `$model->audits()` orders by `id`, a ULID minted at settlement, and `created_at` is the settlement clock | Read human-facing history with `Sentinel::timeline()` or `->byOccurrence()`, which order by `occurred_at` |

## ✅ Best practices

✅ **Do** — suspend auditing with `withoutAuditing()`. It saves the previous flag and restores it in a
`finally`, so it nests correctly and survives an exception.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

Sentinel::withoutAuditing(fn () => $importer->run());
```

❌ **Don't** — reach for `pause()` and `resume()`. A throw between them leaves the engine off for the
rest of the container scope, and the failure mode is silent absence — the worst one an audit engine
has.

```php
Sentinel::pause();
$importer->run();   // throws — and nothing after this ever gets audited
Sentinel::resume();
```

✅ **Do** — turn on retention and anchoring together, and run the first anchoring pass by hand. The
prune unit is the anchored window, so one without the other does nothing.

```php
// config/sentinel.php
'integrity' => ['checkpoints' => ['enabled' => true, 'every' => 1000]],
'retention' => ['auth' => '90 days'],
```

```php
// routes/console.php — only after `php artisan sentinel:checkpoint` has run once, by hand
Schedule::command('sentinel:checkpoint')->hourly();
Schedule::command('sentinel:prune')->daily();
```

❌ **Don't** — declare a retention policy and leave the scheduler empty. Nothing will ever be
retired, no error will be raised, and the trail will grow exactly as if you had declared nothing.

```php
'retention' => ['auth' => '90 days'],   // and nothing on the scheduler: inert, forever
```

✅ **Do** — ask for the verification depth whose answer you actually need. Only `verifyIntegrity()`
rehashes entries and can say *intact*.

```php
Sentinel::verifyIntegrity('tenant:acme')->isIntact();
```

❌ **Don't** — read an anchor walk's `anchored` as `intact`. `verifyAnchors()` reads no anchored
entry at all; it proves the anchors are a contiguous signed chain and nothing about the current
content underneath them.

```php
Sentinel::verifyAnchors('tenant:acme');   // "anchored" — not the same claim
```

✅ **Do** — give an external verifier the signature keyring and nothing else. The hash covers the
ciphertext and `CanonicalPayload` decrypts nothing, so verification reproduces with an empty
encryption ring — the holder can prove every entry untouched and write none.

```php
config(['sentinel.security.encryption.keys' => []]);

Sentinel::verifyIntegrity('tenant:acme')->isIntact();   // still true
```

❌ **Don't** — hand over decryption keys so that someone can "check the data". Verifying does not
need plaintext, and a key handed out for verification is a key you cannot take back.

✅ **Do** — read a human-facing history by the clock of the fact.

```php
Sentinel::timeline()->for($invoice)->get();
```

❌ **Don't** — build a lifeline on `created_at` or on the `id` order of `$model->audits()`. Under
`queue` and `buffered` those are settlement order, and the display keeps working while quietly
answering a different question.

```php
$invoice->audits()->get();   // ordered by ULID id: when it settled, not when it happened
```

✅ **Do** — start thin when nobody owns the operational surface: take the chain, which is
unconditional and needs no configuration, and leave every optional mechanism off.

```php
'mode' => 'sync',
'compliance' => false,
'retention' => [],
'integrity' => ['signature' => ['enabled' => false], 'checkpoints' => ['enabled' => false]],
```

❌ **Don't** — switch on compliance mode as a default hardening step. It forces `on_write_failure`
to `throw`, so an audit write failure becomes a failed user request, and it makes the audit table
grow with reads rather than with business writes.

---

**See also:** [The audit record](02-the-audit-record.md) · [The integrity model](04-the-integrity-model.md) · [Architecture](05-architecture.md) · [Choosing your setup](../02-getting-started/05-choosing-your-setup.md) · [Installation](../02-getting-started/01-installation.md) · [Performance modes](../09-operations/01-performance-modes.md) · [Compliance mode](../08-lifecycle/05-compliance-mode.md) · [API stability](../99-reference/09-api-stability.md)
