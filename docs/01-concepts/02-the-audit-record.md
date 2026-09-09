# 🧠 The audit record

> What one entry in `sentinel_audits` actually is: its forty columns, who fills each of them, the
> nine kinds of entry, the two clocks it carries, and the things it deliberately leaves out.

**On this page:** [One entry](#one-entry) · [The forty columns](#the-forty-columns) · [Kinds of entry](#kinds-of-entry) · [The two clocks](#the-two-clocks) · [Identity](#identity) · [What it does not carry](#what-an-entry-deliberately-does-not-carry) · [Pitfalls](#️-pitfalls) · [Best practices](#-best-practices)

---

## One entry

An audit record is one row of `sentinel_audits`. It is not a log line: a log line is a sentence, and
this is a structure that answers a fixed set of questions about one thing that happened —
**what happened, who did it, on whose behalf, what changed, what the state was and is, which
transaction and which request caused it, where it originated, and whether the row itself is intact.**

The table is created whole by `database/migrations/2026_08_26_000000_create_sentinel_audits_table.php`
through `Support\AuditSchema`, and it is never `ALTER`ed by a later minor. Columns a future version
will write exist from day one and stay null until then — an empty column in an empty table costs
nothing, an `ALTER` on ten million rows costs a maintenance window. Seven columns
(`capture_id`, `source_audit_id`, `criteria`, `affected_rows`, `redacted_at`, `redaction_reason`,
`redacted_hash`) were born empty for exactly that reason, and a test asserts they stay null when
nothing writes them.

```php
use ElPandaPe\Sentinel\Models\Audit;

$audit = Audit::query()->with(['tags', 'relations', 'transaction'])->findOrFail($id);

$audit->audit_type;   // 'model'
$audit->event;        // 'updated'
$audit->severity;     // Severity::Info   (cast to the enum)
$audit->source;       // Source::Http     (cast to the enum)
$audit->subject_type; // 'App\Models\Invoice'
$audit->subject_id;   // '01J...'         (always a string, whatever the subject's key type is)
$audit->stream;       // 'tenant:acme'
$audit->sequence;     // 4218             (its position in that chain — the only order there is)
$audit->occurred_at;  // CarbonImmutable, microseconds — when the fact happened
$audit->created_at;   // CarbonImmutable, microseconds — when the ledger settled it
$audit->hash;         // 64 hex characters
```

> 📌 **Note.** `severity` and `source` are cast to `Enums\Severity` and `Enums\Source`; all seven JSON
> columns — `context`, `before`, `after`, `changes`, `metadata`, `encryption` and `criteria` — are cast
> to `array`; `occurred_at`, `created_at` and `redacted_at` are cast
> to `immutable_datetime`. `Models\Audit::getDateFormat()` returns `'Y-m-d H:i:s.u'`, because the
> columns are declared with microsecond precision and Eloquent's default format would truncate them.

---

## The forty columns

Grouped by what they are for. "Filled by" names the class that writes the value, not the column's
storage. The physical types come from the engine grammar — see
[Choosing an engine](../10-database-engines/01-choosing-an-engine.md) — and the exhaustive
column-by-column table lives in [Schema](../99-reference/03-schema.md).

### Identity — 3 columns

| Column | Declared as | Filled by | Notes |
|---|---|---|---|
| `id` | `char(26)` | `Ledger\EntryBuilder` at settlement | A ULID. Primary key, never auto-increment. |
| `stream` | `varchar(64)` | `Integrity\Stream::resolve()` at settlement | The chain the entry belongs to. Capped at 64 characters and refused past it. |
| `sequence` | `unsigned bigint` | `Ledger\DatabaseLedger`, inside the write transaction | Assigned from `Ledger\StreamGate::tail()`. `unique(stream, sequence)` is the chain's backstop. |

`(stream, sequence)` is the only total order the chain has. Neither clock orders it. See
[Streams](../07-integrity/02-streams.md).

### What happened — 3 columns

| Column | Declared as | Filled by | Notes |
|---|---|---|---|
| `audit_type` | `varchar(32)` | the capture class, from its own `AUDIT_TYPE` constant | Nine values are written; see below. |
| `event` | `varchar(64)` | the capture | Usually an `Enums\AuditEvent` value, but the column is a free string: `custom` and `auth` entries carry a caller- or framework-supplied name. |
| `severity` | `varchar(8)` | decided at capture | Model `$auditSeverity` beats `severity.events`, which beats `severity.default`. |

> ⚠️ **Warning.** `severity` is `varchar(8)` for the life of this schema, and `critical` is exactly
> eight characters. `Enums\Severity` has four cases — `info`, `notice`, `warning`, `critical` — and a
> longer name would not fit without the `ALTER` the schema rule forbids.

### Who — 7 columns

| Column | Declared as | Filled by | Null when |
|---|---|---|---|
| `subject_type` / `subject_id` | `varchar(255)` / `varchar(64)` | the capture, from `getMorphClass()` and the key | Nothing has a subject (a `lockout`), or the key is neither string nor int. |
| `actor_type` / `actor_id` | `varchar(255)` / `varchar(64)` | `Context\Resolvers\ActorResolver`, in the `ResolveContext` stage | Nobody was authenticated. A caller-named actor is put back by `Capture\Recorder` after the stage. |
| `impersonator_type` / `impersonator_id` | `varchar(255)` / `varchar(64)` | `Context\Resolvers\ImpersonatorResolver` | Nobody was standing in for anyone — which is most entries. |
| `tenant_id` | `varchar(64)` | `Context\Resolvers\TenantResolver` | No tenant resolved. |

> ⚠️ **Warning.** `tenant_id` must stay nullable and must never be given a placeholder. It is inside
> the canonical payload, so an empty string where the hash was sealed over `null` makes the entry
> fail its own verification. See [Multi-tenancy](../04-context/04-multi-tenancy.md).

### Correlation — 6 columns

| Column | Declared as | Filled by | Notes |
|---|---|---|---|
| `transaction_id` | `char(26)` | `Transactions\TransactionScope`, via `Capture\Recorder` | The id of the header row in `sentinel_transactions`. No join table, no foreign key. |
| `request_id` | `varchar(64)` | `Context\Resolvers\RequestResolver` | Everything one request produced shares it. |
| `trace_id` | `varchar(32)` | `Context\Resolvers\TraceResolver` | W3C Trace Context: 32 hex characters. |
| `span_id` | `varchar(16)` | `Context\Resolvers\TraceResolver` | 16 hex characters. |
| `source` | `varchar(16)` | `Context\Resolvers\SourceResolver` | An `Enums\Source` value. `import` is the one case nothing resolves — the importer writes it. |
| `version` | `unsigned int` | `Ledger\DatabaseLedger::version()` | `max(version) + 1` for that `(subject_type, subject_id)`. Null when the entry has no subject. |

> ⚠️ **Warning.** `version` is assigned without a lock — the ledger reads `max('version')` for the
> subject and adds one. Two concurrent writes to the same subject can therefore reach the same
> number, and a [rehydrated](../08-lifecycle/03-rehydration.md) range brings its original numbers
> back. Treat `whereVersion()` as a filter that may legitimately return more than one entry.

### Payload — 7 columns

| Column | Declared as | Filled by | Null means |
|---|---|---|---|
| `context` | JSON, **not null** | `Context\ContextEngine` | Never null. Every resolver key that is not promoted to a column, merged with `ExecutionContext::all()`. `[]` when nothing resolved. |
| `before` | JSON, nullable | `Snapshot\SnapshotBuilder` | The event has no "before" (a creation), or snapshots are switched off. |
| `after` | JSON, nullable | `Snapshot\SnapshotBuilder` | The event has no "after" (a deletion), or snapshots are switched off. |
| `changes` | JSON, nullable | `Diff\Diff` (model, mass) or `Data\RelationLine::canonical()` (relation) | There was no pair to compare at all. |
| `metadata` | JSON, nullable | the capture or the caller | Nothing was attached — e.g. `['api' => 'sync']`, `['transition' => ['attribute' => 'status']]`, `['guard' => 'web']`. |
| `criteria` | JSON, nullable | `Mass\MassCapture` only | The entry is not a mass operation. Holds the `where` as structure with values as bindings, never interpolated SQL. |
| `affected_rows` | `unsigned bigint`, nullable | `Mass\MassCapture` only | The entry is not a mass operation. See [Mass operations](../03-capture/05-mass-operations.md). |

> 📌 **Note.** `null` and `[]` are different answers in `before`, `after` and `changes`, and nothing
> in the package coalesces them. `null` means "this does not apply to this event"; `[]` means "it
> applied and was empty". A test pins the distinction through a round trip on every engine.

### Integrity — 7 columns

| Column | Declared as | Filled by | Notes |
|---|---|---|---|
| `payload_version` | `unsigned smallint`, default 1 | `Ledger\EntryBuilder::PAYLOAD_VERSION` | Currently `1`. It is inside the hash prefix; changing the canonical payload bumps it. |
| `encryption` | JSON, nullable | `Pipeline\Stages\EncryptSensitiveData` | `{fields, key_id}`. Inside the canonical payload, so forging a rotation breaks the hash. |
| `algorithm` | `varchar(16)`, default `sha256` | `Support\Config::integrityAlgorithm()` at write | Read **back off the row** on verification, never off current config, so old entries keep verifying after a change. |
| `previous_hash` | `char(64)`, nullable | the stream tail | Null for the first entry of a stream. |
| `hash` | `char(64)` | `Integrity\Hasher::hash()` | The link. |
| `signature` | `text`, nullable | `Integrity\Signers::current()` | Left null when the signer attests to nothing — a signer that signs nothing must not leave something that reads as a claim. |
| `signature_key_id` | `varchar(64)`, nullable | the same signer | Which key signed. |

The hash is taken over a prefix plus a canonical body:

`hash = algorithm( payload_version ␟ stream ␟ sequence ␟ (previous_hash ?? '') ␟ canonical )`

where `␟` is the ASCII unit separator and `canonical` is the RFC 8785 canonical JSON of the
**twenty-seven** columns frozen in `Integrity\CanonicalPayload::COLUMNS`. That accounts for the whole
table: 27 canonical + 4 in the prefix + **9 columns the hash does not cover at all** —
`algorithm`, `hash`, `signature`, `signature_key_id`, `capture_id`, `redacted_at`,
`redaction_reason`, `redacted_hash` and `created_at`. See
[Canonicalization](../07-integrity/03-canonicalization.md) and
[The hash chain](../07-integrity/01-the-hash-chain.md).

> 🔒 **Security.** `signature` and `signature_key_id` sit outside the canonical payload deliberately:
> the signature is over the 64-character `hash`, never over the payload, so filling those two columns
> costs no `payload_version` bump and verifying costs no recomposition and no decryption key. See
> [Signing the chain](../07-integrity/04-signing.md).

### Lifecycle — 7 columns

| Column | Declared as | Filled by | Notes |
|---|---|---|---|
| `capture_id` | `char(26)`, nullable | `Capture\Recorder::identify()` | A ULID stamped at capture, or the caller's own if it brought one. `unique(capture_id)` is what makes a queued retry idempotent. Outside the canonical payload — how the entry travelled is not what happened. |
| `source_audit_id` | `char(26)`, nullable | `Restore\Restorer`, `Redaction\Redactor`, `Security\Rekeyer` | The entry this one derives from. Inside the canonical payload. |
| `redacted_at` | `datetime(6)`, nullable | `Redaction\Redactor` | The discriminant for `verifyContent()`. |
| `redaction_reason` | `varchar(255)`, nullable | `Redaction\Redactor` | Why the content was destroyed. |
| `redacted_hash` | `char(64)`, nullable | `Redaction\Redactor` | A second hash over what the tombstone left. Nobody signs it. |
| `occurred_at` | `datetime(6)` | the capture | When the fact happened. |
| `created_at` | `datetime(6)` | `Ledger\EntryBuilder`, `CarbonImmutable::now()` at settlement | When the ledger wrote it. |

> 🧪 **Verify it.** `make test ARGS=tests/Database/AuditsTableTest.php` — the suite asserts exactly
> forty columns by name and exactly thirteen non-primary indexes over the exact column lists.

---

## Kinds of entry

**"Kind of entry" on this page means one of the nine `audit_type` values, and nothing else.** Two
other counts travel under similar words elsewhere and are not this one: the six *(event, audit_type)*
pairs an Eloquent write produces, in
[What gets audited](../03-capture/01-what-gets-audited.md), and the shapes a snapshot pair takes per
event, in [Snapshots](../03-capture/02-snapshots.md). Crossing chapters, count `audit_type` values
here and events there.

`audit_type` says what kind of fact the row records. Nine values are written into
`sentinel_audits`:

| `audit_type` | Written by | Typical `event` values | What it means |
|---|---|---|---|
| `model` | `Capture\ModelCapture`, and the importers | `created`, `updated`, `deleted`, `restored`, `force_deleted` | One Eloquent model change. The default kind. |
| `transition` | `Transitions\TransitionBuilder`, or `ModelCapture` when an update moves a column named in `$auditTransitions` | `transition` | A record moved between states. Carries `metadata.transition.attribute`. |
| `relation` | `Capture\RelationCapture`, `Capture\ParentCapture` | `attached`, `detached`, `synced` | A pivot change, or a `belongsTo` hand-over. `changes` holds relation **lines**, not diff entries. |
| `mass` | `Mass\MassCapture` | `updated`, `deleted`, `upserted` | A `Builder::update()`/`delete()`/`upsert()` opted in with `->auditing()`. Carries `criteria` and `affected_rows`. |
| `custom` | `Capture\PendingEvent` (`Sentinel::event()`) | whatever you name, ≤ 64 characters | A fact the application states outright, with no model change behind it. |
| `auth` | `Capture\AuthenticationSubscriber` | `login`, `logout`, `failed`, `lockout`, `password_reset` | An authentication event. The credentials of a failed attempt are never read, let alone stored. |
| `restore` | `Restore\Restorer` | `restore` | A restoration. A **new** entry pointing back through `source_audit_id`; nothing earlier is rewritten. |
| `security` | `Redaction\Redactor`, `Security\Rekeyer` | `redacted`, `rekeyed` | The trail of an operation performed on the trail itself. |
| `access` | `Compliance\AccessLog` | `read` | Somebody read the trail. Written only under [compliance mode](../08-lifecycle/05-compliance-mode.md), and chained and signed like any other entry. |

Two of those are easy to confuse and worth pinning: `event = 'restored'` on an `audit_type = 'model'`
entry is Eloquent's soft-delete revival, while `audit_type = 'restore'` is Sentinel putting a record
back the way an entry found it. They are different facts. See
[Restoring state](../06-reading/08-restoring-state.md).

> 📌 **Note.** A **business transaction is not an entry kind.** `Sentinel::transaction()` writes a
> header row in `sentinel_transactions` and stamps `transaction_id` onto the entries captured inside
> it; no row with `audit_type = 'transaction'` ever reaches `sentinel_audits`. See
> [Business transactions](../03-capture/06-business-transactions.md).

---

## The two clocks

Every entry carries two instants, and understanding the difference is the single most load-bearing
thing on this page.

- **`occurred_at`** is when the fact happened. It is stamped at capture — `CarbonImmutable::now()`
  inside `ModelCapture`, `RelationCapture`, `PendingEvent`, `TransitionBuilder`, and every other
  capture class — and it never moves afterwards. It is inside `CanonicalPayload::COLUMNS`, so the
  hash covers it.
- **`created_at`** is when the ledger settled the entry. It is stamped by `Ledger\EntryBuilder`, in
  the same operation that assigns `sequence` and seals `hash`. It is **not** in the canonical
  payload; the hash does not cover it.

### When they agree, and when they do not

| Situation | Gap | Why |
|---|---|---|
| `mode = sync`, no open database transaction | microseconds | The pipeline and the write run in the same call. |
| `mode = sync`, inside `DB::transaction()` with `transactions.after_commit = true` | the length of the transaction | The write waits for the commit, on purpose. |
| `mode = queue` | until a worker picks the job up | The entry is dispatched transformed and settles in the worker. |
| `mode = buffered` | until a flush trigger fires | `buffer.size`, `buffer.flush_interval`, end of request, worker shutdown, or `sentinel:flush`. |
| An imported entry | months or years | `Import\Origins\OwenIt` and `Import\Origins\Altek` copy the source row's own instant into `occurred_at`, and refuse the row outright when it does not say when it happened. `source` is `import`. |

### What silently changes when they stop agreeing

Nothing throws. Five things quietly start answering a different question:

1. **Default read order.** `Sentinel::audits()->get()` orders by `created_at` then `id` — settlement
   order. `Sentinel::timeline()` and `->byOccurrence()` order by `occurred_at` then `id`. Under an
   asynchronous mode, a screen built on the default order shows the order things *were written*.
2. **`between()` bounds `created_at`, always.** `Query\Period` says so in as many words, and
   `Ledger\DatabaseLedger` compiles it as `whereBetween('created_at', …)` even when the query is
   ordered `byOccurrence()`. Narrowing and ordering deliberately follow different clocks: `created_at`
   is the partition key of both published range plans and the clock retention counts from, so a
   window on the fact's clock would not line up with the one a prune works in.
3. **Retention counts from `created_at`.** `Retention\RetainedPredicate` compares
   `created_at >= cutoff`. How long a record is *kept* is measured from when it was stored, not from
   a clock a caller can set.
4. **Partitioning routes on `created_at`.** The published range stubs partition on it
   (`partition by range (to_days(created_at))` on MySQL). An entry with a two-year-old `occurred_at`
   still lands in this month's partition.
5. **A lifeline measures with `occurred_at`.** `Transitions\Transition::of()` computes "time spent in
   this state" as the interval between two entries' `occurred_at` values. Rebuild that on `created_at`
   and it keeps working while measuring queue latency instead of business time.

What does **not** change: the chain. `(stream, sequence)` is dense and monotonic in every mode, it is
what `verifyIntegrity()` walks, and it is unaffected by either clock.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

Sentinel::audits()->for($invoice)->get();                 // settlement order
Sentinel::audits()->for($invoice)->byOccurrence()->get(); // the order things happened
Sentinel::timeline()->for($invoice)->get();               // the same, unnarrowed

// Ordering by the fact, narrowing by the ledger — deliberate, not a bug.
Sentinel::timeline()
    ->between(now()->subMonth(), now())   // bounds created_at
    ->get();                              // orders by occurred_at
```

> ⚠️ **Warning.** Two entries can share both clocks to the microsecond and the chain still has one
> unambiguous order. Never reconstruct sequence from a timestamp — read `sequence`.

> 🔒 **Security.** `occurred_at` is hash-covered and `created_at` is not. An entry whose
> `created_at` was edited still verifies; an entry whose `occurred_at` was edited does not. If the
> question you are answering is evidential, ask it of `occurred_at` and `(stream, sequence)`.

---

## Identity

**Every identifier the package mints is a ULID in `char(26)`.** `id`, `transaction_id`,
`capture_id` and `source_audit_id` are all 26 characters; nothing is auto-increment and nothing is a
database sequence. `Models\Audit` uses `HasUlids`, so `getIncrementing()` is `false` and
`getKeyType()` is `'string'`. A ULID sorts by the instant it was minted, which is why
`$model->audits()` can order by `id` alone and still be chronological by settlement.

**Subject, actor and impersonator keys are `varchar(64)` strings.** Not integers, not UUIDs — 64
characters of text, so an auto-increment `int`, a `uuid` and a `ulid` subject all fit the same column
with no later migration. The morph resolves for all three; tests cover each one.

```php
$audit->subject_id;   // '17'    an int-keyed model, cast to string
$audit->subject_id;   // '9f0c…' a uuid-keyed model
$audit->subject;      // Model|null — null when nothing was recorded, and also
                      // when the recorded class no longer exists in the codebase
```

**There is no `updated_at` column, and the model will never write one.**
`Models\Audit::UPDATED_AT` is `null`. The table is append-only in the strongest sense the ORM allows:
`Models\Audit::booted()` registers `updating` and `deleting` hooks that throw
`Exceptions\ImmutableAuditException`, and a subclass named in `models.audit` inherits the guard.

```php
$audit->update(['severity' => 'critical']);
// ImmutableAuditException: Audit [01J…] cannot be updated: an entry is a link in a
// hash chain, and rewriting it breaks every entry that follows.

$audit->delete();
// ImmutableAuditException: Audit [01J…] cannot be deleted: removing an entry leaves a
// hole its chain has no way to describe.
```

> ⚠️ **Warning.** The guard runs on Eloquent model events, so it protects the model and not the
> table. A `DB::table(…)->update()` bypasses it — which is exactly the door the purger and the
> redactor use, deliberately, and exactly the door an unsanctioned write would use. Nothing in the
> row tells them apart; what tells them apart is that the hash stops matching. That is what
> [verification](../07-integrity/06-verification.md) is for.

---

## What an entry deliberately does not carry

| Not on the entry | Where it is instead | Why |
|---|---|---|
| `updated_at`, `deleted_at` | — | The table is append-only. A correction is a new entry, not an edit. |
| Labels | `sentinel_audit_tags`, joined on `audit_id` | `Ledger\EntryBuilder` attaches them as a loaded relation and never as attributes, which keeps them out of `getAttributes()` and therefore out of the hash. Relabelling is not tamper-evident, on purpose. |
| The relation lines as a queryable column | `sentinel_audit_relations` | That table is an **index** over evidence. The evidence itself is in the entry's hashed `changes`. |
| The transaction's name | `sentinel_transactions.name` | The entry carries only `transaction_id`. A header can be updated when the scope closes; an entry cannot. |
| Any foreign key | — | Not to the subject, not to the transaction, and no side table has one back to `sentinel_audits`. A cascade lives badly with date partitioning and batched pruning, and an entry is meant to outlive its subject. |
| Raw SQL of a mass operation | `criteria`, as structure with bindings | A recorded statement you can only read as text is not something a filter or a redactor can act on. |
| The plaintext of an encrypted field | `encryption` names the fields and the key id | The hash covers the ciphertext, so `verifyIntegrity()` runs in an environment holding no key at all. Losing the key loses the value and keeps the proof. |
| Who read the entry | `sentinel_access_log` plus an `access` entry | Only under compliance mode, and only through `Sentinel::audits()` — the `$model->audits()` relation is out of scope by design. |
| A "why" field | `metadata` | A transition's reason travels under a `transition` key so your own metadata stays yours. |

> ⚠️ **Warning.** A tombstone empties **all six** content columns of the canonical payload —
> `context`, `before`, `after`, `changes`, `metadata`, `criteria` — plus the entry's labels and
> relation lines. `changes` carries the literal old and new values, so an entry whose `before` was
> emptied and whose `changes` was not is not redacted at all. See
> [Redaction and tombstones](../08-lifecycle/04-redaction-and-tombstones.md).

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| A timeline looks reordered after switching to `queue` or `buffered` | The read orders by `created_at` (settlement), which stopped being the order things happened | Use `Sentinel::timeline()` or `->byOccurrence()`. `(stream, sequence)` is unaffected. |
| `between(9am, 10am)` returns entries whose `occurred_at` is outside the window | `between()` bounds `created_at` unconditionally, even on a `byOccurrence()` query | Widen the window, or filter the returned collection on `occurred_at` yourself. It is deliberate: `created_at` is the partition and retention clock. |
| `ImmutableAuditException` while "fixing" a row | `Models\Audit` throws on `updating` and `deleting`, subclasses included | Do not fix an entry. Write a new one; that is what `Restore\Restorer` and `Redaction\Redactor` do, each pointing back through `source_audit_id`. |
| `$audit->changes` is empty inside a subclass of `Models\Audit` | `Illuminate\Database\Eloquent\Model` declares a protected `$changes` holding the dirty set of the last `save()`, and it wins over the cast | Read it as `$this->getAttribute('changes')`, the way `Audit::diff()` does. From outside the model, `$audit->changes` is fine. |
| `$audit->subject` is `null` although `subject_type` and `subject_id` are set | The recorded class no longer exists, or the row it names was deleted | Expected. An entry outliving its subject is the normal case; render from `subject_type`/`subject_id` and treat `subject` as a bonus. |
| An entry fails `verifyIntegrity()` right after a "harmless" data fix | `tenant_id` was given an empty string where the hash was sealed over `null`, or a JSON column was touched | Nothing inside `CanonicalPayload::COLUMNS` may be edited. There is no repair; the break is the answer. |
| Two entries for one subject share a `version` | `version` is `max(version) + 1` read without a lock | Do not treat `version` as unique. `whereVersion()` may return several entries; `(stream, sequence)` is the identity that never repeats. |
| Key order inside a JSON column comes back different | Neither MySQL `json` nor PostgreSQL `jsonb` preserves the order you wrote | Read by key. Values and the order *of* diff entries do survive, and `Audit::toArray()` re-imposes the package's order. |

> 🐘 **Engine.** The JSON and date types come from the grammar: `jsonb` and
> `timestamp(6) without time zone` on PostgreSQL 16, `json` and `datetime(6)` on MySQL 9, `text` and
> `datetime` on SQLite. Microseconds survive on all three because the model declares the format;
> SQLite is the only one that also preserves JSON key order, which is why anything touching the
> payload is verified with `make test-dbs`.

---

## ✅ Best practices

✅ **Do** — order by `occurred_at` whenever the question is "what happened, in what order". The
default order is `created_at`, and it means something different the moment writing stops being
synchronous.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

Sentinel::timeline()->for($invoice)->get();
Sentinel::audits()->for($invoice)->byOccurrence()->latest()->get();
```

❌ **Don't** — build a lifeline on `created_at` because it happens to agree today. It keeps working
and silently starts measuring queue latency instead of business time.

```php
Sentinel::audits()->for($invoice)->get()   // settlement order
    ->map(fn ($a) => $a->created_at);      // not when anything happened
```

---

✅ **Do** — read `null` and `[]` as different answers in `before`, `after` and `changes`. `null`
means the state does not apply to this event; `[]` means it applied and was empty.

```php
$audit->before === null;   // a creation: there was no previous state
$audit->after === [];      // there was a state, and it held nothing
```

❌ **Don't** — coalesce them. `$audit->before ?? []` throws away audited information and turns "this
event has no before" into "the record was empty", which is a different claim.

```php
$before = $audit->before ?? [];   // two distinct facts collapsed into one
```

---

✅ **Do** — treat the entry as immutable and derive from it. Every sanctioned operation over a sealed
entry writes a **new** entry pointing back through `source_audit_id`.

```php
$restoration = $audit->restore(['status']);   // a new `restore` entry
$restoration->entry?->source_audit_id === $audit->id;
```

❌ **Don't** — reach past the model guard with the query builder to "correct" a row. It is the same
door the redactor uses, nothing in the row distinguishes the two, and the entry will simply stop
verifying.

```php
DB::table('sentinel_audits')->where('id', $audit->id)->update(['severity' => 'critical']);
// no exception, and verifyIntegrity() is false from here on
```

---

✅ **Do** — identify a subject by `subject_type` + `subject_id` and render defensively. The columns
are `varchar(64)` strings precisely so an int, a UUID and a ULID key all fit.

```php
$label = $audit->subject?->name ?? "{$audit->subject_type}#{$audit->subject_id}";
```

❌ **Don't** — assume `$audit->subject` resolves. It is `null` when nothing was recorded, when the
row was deleted, and when the recorded class no longer exists in the codebase.

```php
$label = $audit->subject->name;   // fatal on any entry that outlived its subject
```

---

✅ **Do** — put anything that has to be provable in `metadata`, which the hash covers.

```php
Sentinel::event('invoice.approved')
    ->subject($invoice)
    ->metadata(['approval_ref' => $reference])
    ->record();
```

❌ **Don't** — encode a provable fact as a label. Labels live in `sentinel_audit_tags`, outside the
canonical payload; anyone with write access can relabel an entry and `verifyIntegrity()` will not
notice. They are operational classification, not evidence. See
[Labels](../06-reading/06-labels.md).

```php
Sentinel::event('invoice.approved')->tags(["approval:{$reference}"])->record();
```

---

✅ **Do** — read labels and relation lines through the relations, and hand a page of entries to
`AuditCollection::loadReferences()` before rendering — one query per morph type instead of one per
row.

```php
$entries = Sentinel::audits()->forTenant('acme')->take(50)->get();
$entries->loadReferences();
```

❌ **Don't** — `find()`, `save()` or `delete()` an `AuditTag` or `AuditRelation` as if it had a key of
its own. Both declare `audit_id` as their primary key and it is not unique, so a delete takes every
row of that entry with it.

```php
AuditTag::query()->find($audit->id)->delete();   // removes every label of that entry
```

---

**See also:** [What Sentinel is](01-what-sentinel-is.md) · [The write path](03-the-write-path.md) · [The integrity model](04-the-integrity-model.md) · [Glossary](06-glossary.md) · [What gets audited](../03-capture/01-what-gets-audited.md) · [Snapshots](../03-capture/02-snapshots.md) · [Diffs](../03-capture/03-diffs.md) · [Execution context](../04-context/01-execution-context.md) · [The ten resolvers](../04-context/02-resolvers-reference.md) · [The hash chain](../07-integrity/01-the-hash-chain.md) · [Canonicalization](../07-integrity/03-canonicalization.md) · [Performance modes](../09-operations/01-performance-modes.md) · [Partitioning](../10-database-engines/06-partitioning.md) · [Schema](../99-reference/03-schema.md) · [Enums](../99-reference/04-enums.md) · [Serialization](../99-reference/08-serialization.md)
