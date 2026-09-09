# ♻️ Compliance mode

> What one boolean changes, why the application refuses to boot without signatures and anchors, and
> exactly which reads leave a record — and which do not.

**On this page:** [What it is](#what-it-is-and-what-it-is-not) · [Turning it on](#turning-it-on) · [It refuses to boot](#it-refuses-to-boot) · [The five things it enforces](#the-five-things-it-enforces) · [What it does not make immutable](#what-it-does-not-make-immutable) · [The access log](#the-access-log) · [Which reads are recorded](#which-reads-are-recorded) · [The self-reading walk](#the-self-reading-walk) · [What it costs](#what-it-costs) · [What a regime still asks of you](#what-a-regime-still-asks-of-you) · [Pre-audit checklist](#pre-audit-checklist) · [⚠️ Pitfalls](#️-pitfalls) · [✅ Best practices](#-best-practices)

---

## What it is, and what it is not

Compliance mode is one configuration key. It adds no mechanism that is not already in the package:
the hash chain is unconditional, [signing](../07-integrity/04-signing.md) and
[anchors](../07-integrity/05-checkpoints-and-anchors.md) are switches, archiving is the default
prune action, and [redaction](04-redaction-and-tombstones.md) already takes an actor. What
compliance mode does is **remove the choices** — it makes the configuration that supports the
claim the only configuration that boots, and it adds one thing on the read path that has no
equivalent when it is off: a record of who looked.

> 🔒 **Security.** Sentinel certifies nothing. It ships technical primitives — a chained hash, a
> signature, an anchor, a tombstone, an access record. Whether a given regime is satisfied by them
> is a question for somebody who knows that regime. No string anywhere in `src/`, `config/` or
> `resources/` names a norm, and none will be added: a package that printed the name of a standard
> would be making a claim only an assessor can make.

The name is a description of what the configuration *supports*, not a badge. An installation with
`'compliance' => true` is one where signatures and anchors are on, a lost write raises, a range
leaves only after a copy of it exists, an erasure names who ordered it, and reads through the Query
API are on the trail. That is all it means.

## Turning it on

```php
// config/sentinel.php
return [
    'compliance' => true,

    'integrity' => [
        'checkpoints' => [
            'enabled' => true,
            'every'   => 1000,
        ],
        'signature' => [
            'enabled' => true,
            'signer'  => 'hmac',
        ],
    ],
];
```

No migration is involved. `sentinel_access_log` is created by the same install as every other table
(`ElPandaPe\Sentinel\Console\InstallCommand::TABLES` lists all seven), and it simply stays empty
until compliance mode is on — an installation that never asks for a row per query does not pay for
one, and turning the mode on is a config change and a deploy, never a schema change.

Check what is live with the framework's own command, which the package extends:

```
php artisan about
```

`ElPandaPe\Sentinel\Console\About` prints six rows under `Sentinel`, one of them `Compliance mode`,
shown as `ENABLED` or `OFF`. It deliberately prints no key, no key identifier and no signer
configuration — `about` output is pasted into issues.

## It refuses to boot

This is the part that surprises people, and it is the feature rather than a defect.
`ElPandaPe\Sentinel\Compliance\Requirements::enforce()` runs from the service provider's `boot()`
and throws when compliance is on and either switch is off:

```
ElPandaPe\Sentinel\Exceptions\ComplianceException

Sentinel is in compliance mode with [integrity.signature.enabled, integrity.checkpoints.enabled]
switched off. Compliance mode is a claim about what the trail can prove, and it cannot prove it
without them. Turn them on, or turn compliance off.
```

You will see this as a **500 on every route and a non-zero exit from every artisan command**,
including `php artisan config:cache`, because it happens while the container is booting the
provider. Nothing partially works.

It names **every** missing switch, not the first one, so one deploy fixes both. The fix is one of
two things and there is no third:

| Message names | What to do |
|---|---|
| `integrity.signature.enabled` | Set it to `true` and configure a key — see [Signing the chain](../07-integrity/04-signing.md) |
| `integrity.checkpoints.enabled` | Set it to `true` — see [Checkpoints and anchors](../07-integrity/05-checkpoints-and-anchors.md) |
| both | Both, in the same change |
| — (you did not mean to be in this mode) | Set `'compliance' => false` |

> ⚠️ **Warning.** The failure is at boot and not at the first write, on purpose. The first write
> after a bad deploy may be a year away, and by the time it arrives the entries that were supposed
> to be signed are not. There is no command that signs history retroactively, and there
> deliberately is not one, so a boot-time refusal is the only moment at which the mistake is still
> free to fix.

> 💡 **Tip.** Turn signatures and anchors on, deploy, let them run, and flip `compliance` in a
> **second** deploy. Signing is not retroactive: entries written before the switch stay `unsigned`,
> which is a state and not a failure — but you would rather discover the ordering in staging than
> in a rollback.

There is one narrow exception to the boot check: `Requirements::enforce()` only runs when
`config('sentinel.compliance')` exists at all, so an application that has not published the config
file boots normally instead of dying on a package it has not configured.

## The five things it enforces

| # | What changes | Where it is decided | What you see when it bites |
|---|---|---|---|
| 1 | Signatures and anchors must be on | `Compliance\Requirements::enforce()` | The application does not boot |
| 2 | `on_write_failure` is forced to `throw` | `Support\Config::writeFailurePolicy()` | A failed audit write raises out of the request that caused it |
| 3 | `sentinel:prune --action=delete` refuses an unarchived range | `Retention\Pruner::refuseUnarchivedDelete()` | `ComplianceException`, exit code 2, nothing removed from that window |
| 4 | A redaction must name an actor | `Redaction\Redactor::redact()` | `ComplianceException`, the row is untouched |
| 5 | Every Query API read is recorded twice | `Query\AuditQuery::read()` → `Compliance\AccessLog` | An `access` entry in `sentinel_audits` and a row in `sentinel_access_log` |

And one more, off to the side, on a divided table: `sentinel:partitions --retire --force` will not
drop a partition that still holds entries. Outside compliance mode `--force` lifts that refusal;
under it the guard is unconditional and the report says `unarchived` rather than `occupied`
(`Partitions\Maintainer::refusal()`). The command exits 1. See
[Partitioning](../10-database-engines/06-partitioning.md) for the correct order — prune with
`--action=archive`, *then* retire.

### Forced throw, and the one path where it does not apply

`Config::writeFailurePolicy()` returns `FailurePolicy::Throw` unconditionally under compliance,
overruling whatever `on_write_failure` says. The two settings say different things: `on_write_failure`
is an operator deciding how much a lost entry is allowed to cost, and compliance is a statement that
no entry may be lost at all.

> ⚠️ **Warning.** The forced throw reaches the **in-request** path only.
> `Capture\WriteFailure::afterCommit()` never propagates, in any mode — the framework runs commit
> callbacks in a bare `foreach`, so throwing there would stop every later entry of the same
> transaction from being attempted and would surface out of a `DB::transaction()` that has already
> committed. With `transactions.after_commit` at its default of `true`, an audit that fails after a
> commit is logged and announced through `AuditWriteFailed`, not thrown, even under compliance. If
> you need to know about those, listen for the event — see
> [Failure policy](../09-operations/05-failure-policy.md).

### Archive before delete

`sentinel:prune` defaults to `--action=archive`, which writes the window out, reads it back,
re-digests it and rehashes every entry before removing a row. `--action=delete` removes the window
without writing it anywhere. Under compliance mode the second one is refused unless the range
already has a real archive batch — evidenced by a manifest row with cold columns, not by a flag:

```
Sentinel is in compliance mode, where a range is deleted only after it has been archived.
Sequences 1-4 of stream global have no archive batch, so nothing was removed.
Run the prune with --action=archive, or turn compliance off.
```

This is what makes `--action=delete` usable at all under compliance: the supported shape is
*archive now, delete the hot rows later*, which is exactly what a range that was
[rehydrated](03-rehydration.md) from its batch and is being retired again looks like.

## What it does not make immutable

Compliance mode adds nothing to immutability, and the phrase in the shipped config comment
overstates it. `ElPandaPe\Sentinel\Models\Audit::booted()` registers `updating` and `deleting`
guards **unconditionally**, in every configuration:

```php
use ElPandaPe\Sentinel\Models\Audit;

Audit::query()->first()->update(['event' => 'created']);
// ElPandaPe\Sentinel\Exceptions\ImmutableAuditException
// Audit [01JB…] cannot be updated: an entry is a link in a hash chain, and rewriting it
// breaks every entry that follows.
```

Two things follow, and both matter to an assessor:

- **Turning compliance off does not make entries writable.** The guard is a property of the model,
  not of the mode.
- **A refused attempt is not recorded.** The guard throws before anything could be written, so
  nothing lands in the trail saying somebody tried. If you need that, catch
  `ImmutableAuditException` in your own handler and record it as a
  [custom event](../03-capture/07-custom-and-authentication-events.md).

And the guard is on the *model*. A raw `DB::table('sentinel_audits')->update(...)` goes straight
past it — that is the door `Redaction\Redactor` itself uses, and nothing in the row distinguishes
the two. What separates a declared redaction from an attack is the chained, signed trail entry the
redaction writes and an attacker does not. See
[Redaction and tombstones](04-redaction-and-tombstones.md).

## The access log

Under compliance mode, one read of the trail writes **two** records, and the split is the point.

| | Where | Chained? | Signed? | What it is for |
|---|---|---|---|---|
| The proof | `sentinel_audits`, `audit_type = 'access'`, `event = 'read'` | Yes | Yes | Makes the read provable |
| The index | `sentinel_access_log` (model `Models\AuditAccess`) | No | No | Makes it answerable by actor and by date |

An access log that can be edited proves nothing about who looked, so the editable copy is
deliberately the second one and never the only one. The row carries `audit_id`, which is a lookup
and **not** a foreign key: retention can prune the proving entry out from under the row, and the row
is still a truthful record of a read that happened. `AuditAccess::audit()` then returns `null`.

The proof carries the question inside it. `Compliance\AccessLog::write()` puts the described query
into the entry's `metadata` under an `access` key, and `metadata` is one of the twenty-seven columns
of the canonical payload — so what was asked for is hashed and signed along with everything else.

```php
use ElPandaPe\Sentinel\Models\AuditAccess;

$reads = AuditAccess::query()
    ->where('actor_type', 'user')
    ->where('actor_id', (string) $officer->getKey())
    ->latest('created_at')
    ->get();

foreach ($reads as $read) {
    $read->query;    // ['tenant_id' => 'acme', 'event' => 'updated', 'limit' => 50, 'offset' => 100]
    $read->results;  // how many entries were handed back
    $read->context;  // ip, user agent, url, route, method, copied from the entry
    $read->audit()?->verifyIntegrity();  // null once the proving entry has been pruned
}
```

The table is indexed on `(actor_type, actor_id, created_at)` and on `audit_id` — the two questions
it exists for: *what has this person been reading, and when*, and *where is the entry that proves
this row*.

### What `query` records, and what it leaves out

`AccessLog::describe()` reads fourteen published properties off the query object and drops the ones
that are null. It records the **shape of the question**, not a rendered SQL string — the ledger that
answered it may not have been a database.

| Recorded | Not recorded |
|---|---|
| `subject`, `actor` (as `type:id`) | the date range from `between()` |
| `event`, `severity`, `source`, `audit_type` | `whereTag()` / `whereAnyTag()` |
| `tenant_id`, `transaction_id`, `trace_id` | `whereRelation()` / `whereRelated()` / `whereOperation()` |
| `changed_field`, `versions` | `whereIp()` / `whereRoute()` |
| `after`, `limit`, `offset` | the ordering (`latest()`, `byOccurrence()`) |

> 📌 **Note.** `limit` and `offset` describe the page the caller was **handed**, not the probe the
> driver ran. Both `paginate()` and an uncapped `get()` ask the ledger for one row more than they
> return; the recorded bound and the recorded `results` describe the answer. An uncapped `get()` is
> recorded with `limit = AuditQuery::DEFAULT_LIMIT` (500). A read that was *refused* for being
> unbounded is not recorded at all — nothing was handed over.

## Which reads are recorded

The record hangs off `Query\AuditQuery::read()`, which is reached from exactly two terminals:
`get()` and `paginate()`. Everything below follows from that, and the gaps are by design rather than
by omission: the package's own suite asserts both halves of the table, the recorded reads and the
unrecorded ones, in `tests/Compliance/ReadPathsTest.php`.

**Recorded** — the read goes through the Query API:

| Call | Note |
|---|---|
| `Sentinel::audits()->…->get()` | The base case |
| `Sentinel::audits()->…->paginate($per, $page)` | One entry and one row per page |
| `Sentinel::audits()->for(…)->compare(1, 3)` | One record: it is one read underneath |
| `Sentinel::timeline()->…->get()` | `timeline()` is `audits()->byOccurrence()` |
| `Sentinel::transitions()->…->get()` | The transition query wraps an `AuditQuery` |
| `$invoice->relationHistory('lines')->get()` | Goes through `Sentinel::audits()` |
| `php artisan sentinel:show --subject=App\Models\Invoice:7` | Reads a life through the query |
| `php artisan sentinel:export …` | An export is a read, and the largest one a trail ever serves |
| `php artisan sentinel:rekey …` | Reads through the query before rotating |

**Not recorded** — and this is the more useful list:

| Call | Why not |
|---|---|
| `$invoice->audits()->get()` | A `MorphMany` on your own model. Recording it would mean an access entry per row hydrated, including every row the verifier, the presenter and the exporter hydrate on their way to doing something else |
| `$invoice->latestAudit()` | Same relation, same reason |
| `$invoice->audits()->field('email')->get()` | An Eloquent scope on the model, not a Query API filter |
| `Audit::query()->…` | Eloquent straight at the table |
| `php artisan sentinel:show 01JB…` | Finds one entry by primary key; the Query API has no filter on an entry's own id |
| `php artisan sentinel:redact 01JB…` | Same route, same reason |
| `Sentinel::verifyIntegrity()` / `verifyAnchors()` / `verifyRoots()` / `verifyEverything()` | They read to prove, not to disclose |
| `php artisan sentinel:verify` | Same |
| `php artisan sentinel:prune`, `Archive\Rehydrator::restore()` | Lifecycle machinery, not disclosure |

> 💡 **Tip.** The workaround is one line: reach the trail through `Sentinel::audits()` wherever the
> read has to be on the record. It is the **route** that decides whether an access record is
> written, never the caller and never the command. A panel that serves an auditor should use
> `Sentinel::audits()->for($model)`, not `$model->audits()`.

### Modes that lose the projection

`AccessLog::project()` hangs off the `settled` callback of `Capture\Recorder::record()`, which
`Dispatch\Dispatcher::handed()` invokes only when it is holding a settled `Audit`. Under
`mode = queue` and `mode = buffered` the strategies return `Handover::accepted()` with no entry —
the mode took it and will settle it elsewhere.

> ⚠️ **Warning.** Under an asynchronous [performance mode](../09-operations/01-performance-modes.md),
> the chained `access` entry is still written (on the worker, or at flush) but **no
> `sentinel_access_log` row is**. The proof survives; the index does not. If you need both, keep
> `mode = sync` under compliance, and check `config('sentinel.mode')` as part of the pre-audit
> checklist below.

The pipeline runs before any of that, so a discard policy applies too: a
`Sentinel::filter()` that returns `false` for `$audit->audit_type === 'access'` drops the entry
before the ledger assigns a sequence, and the row goes with it, because `record()` returns `null`
and the callback never fires. Both records disappear together and nothing reports it.

## The self-reading walk

An access entry is an **ordinary entry**. It consumes a sequence of the very stream it audits — the
same stream resolver, the same chain, the same signature. That is what makes a read provable, and it
has a consequence:

```php
// Under compliance mode, on a trail with 3 entries:
Sentinel::audits()->get();      // reads 3, writes entry #4
Sentinel::audits()->get();      // reads 4, writes entry #5
```

An unfiltered walk of the whole table therefore sees its own footprints. The default order is
oldest-first, which pushes them to the tail rather than into the page in front of you, but a walk
that keeps paginating will eventually reach them — and each page it reads adds one more entry behind
it. It converges, since each page of *n* adds one entry, but a naive "read every page until empty"
loop over an unfiltered query is not a walk you want to start.

Two ways out, both ordinary:

```php
use ElPandaPe\Sentinel\Compliance\AccessLog;

// Walk by cursor rather than by offset, so pages do not shift underneath you:
$page = Sentinel::audits()->take(500)->get();
$next = Sentinel::audits()->after($page->last()->id)->take(500)->get();
```

…or exclude the type outright when the read is about business history rather than about access:
narrow with `whereType()` to the type you actually want. See
[Order, paging and walking the trail](../06-reading/03-order-paging-and-walking.md).

## What it costs

In writes, per read through the Query API: **one chained entry plus one projection row**. The entry
pays everything an entry pays — the pipeline, the tail read that assigns its sequence, the hash, the
signature — so a read stops being free and becomes a read plus a write.

The figure published with the package, measured over a hundred reads of fifty entries each on one
machine: **0.309 s without it against 0.557 s with it — about +2.5 ms per read, or 1.8×**. That is
one machine on one shape of data and it is a report, not a gate.

> 🧪 **Verify it.** `benchmarks/volume.php` carries a harness for exactly this. Run
> `make bench-volume` and read the section `-- what a read of the trail costs under compliance
> mode --`, which prints the read with the mode off, the read with it on, and the difference,
> on your data and your hardware.

In operations, the standing costs are two:

- **`sentinel_access_log` grows one row per query, forever.** Nothing in `src/` removes rows from
  it. Pruning (`Retention\Cascade`) only ever touches audits, labels, relation lines and transactions, so
  a retention policy on `'access'` prunes the chained entries and leaves the projection rows behind
  — after which `AuditAccess::audit()` answers `null` for every one of them. The only thing that
  shrinks that table is partition retirement: `sentinel:partitions --table=access_log`. Give it a
  plan on day one.
- **Access entries interleave with business history** in `sentinel_audits`, which grows the table a
  paginating panel is paging through and adds rows to every anchor window.

## What a regime still asks of you

The package ships primitives. Everything below is outside it, and an assessor will ask about all of
it:

| The package gives you | The application still owes |
|---|---|
| A record of who read the trail through the Query API | Authorization: *who is allowed to*. Sentinel records the read, it does not gate it |
| A chained, signed entry per read | Custody of the signing key. A key the application server can read is a key an attacker with that server can sign with |
| A tombstone that destroys one entry's content | Proof the erasure reached replicas, backups, exports and the copies you made yourself. Sentinel cannot see any of those |
| An anchor and a manifest row explaining an absence | A retention *decision* — how long, for what, and who signed off |
| `sentinel:export` with a digest and a signature beside the body | Handing the verifying half of the key to the recipient, and their check on receipt |
| `redaction_reason` on the entry and on the trail | A process that produces a real reason, and a person answerable for it |
| Immutability at the model | Database privileges. A role that can `UPDATE sentinel_audits` defeats the model guard |

> 🔒 **Security.** `redacted_at`, `redaction_reason` and `redacted_hash` sit **outside** the
> canonical payload, outside the signature and outside the anchor fold. Whoever can empty `before`
> can equally write those three columns. The second hash proves only that the remains of a tombstone
> are the ones the redaction left; what separates a declared redaction from an attack is the chained,
> signed trail entry — which an attacker does not write. Say this out loud to an assessor before they
> find it.

## Pre-audit checklist

Ten things to confirm before someone asks. Each one is checkable from a terminal or a config file.

| # | Check | How |
|---|---|---|
| 1 | Compliance mode is actually on in the environment being audited | `php artisan about` → `Compliance mode: ENABLED` |
| 2 | Signatures are on *and* were on before the entries in scope were written | Sample an old entry: `$entry->verifySignature()` is not `Unsigned` |
| 3 | The signing key's verifying half is available to the auditor, and the private half is not on the app server under `openssl` | `integrity.signature.keys` vs `private_key` |
| 4 | Anchors exist over the whole range in scope, especially anything already pruned | `php artisan sentinel:verify --depth=anchors` |
| 5 | The chain verifies end to end | `php artisan sentinel:verify` |
| 6 | `mode` is `sync`, or you can explain why `sentinel_access_log` has fewer rows than there are `access` entries | `config('sentinel.mode')` |
| 7 | Nothing was deleted without a batch: every gap has both a manifest row and an anchor | `php artisan sentinel:verify` reports no `sequence_gap` |
| 8 | Every redaction in scope names an actor and has a trail entry | `Audit::query()->whereNotNull('redacted_at')` against `audit_type = 'security'`, `event = 'redacted'` |
| 9 | `sentinel_access_log` has a growth plan (partitions), and you can say what it is | `php artisan sentinel:partitions --table=access_log --dry-run` |
| 10 | No `Sentinel::filter()` policy silently discards `access` or `security` entries | Read the policies your application registers |

Two more that are about the story rather than the data: know which reads are *not* recorded (the
table above) and be able to say why, and know that `sentinel_archives` is indexed by stream and
range and never by subject — so an erasure request covering one person's whole history is answered
range by range, not with one query.

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| Every route 500s and every artisan command fails right after enabling compliance | `Requirements::enforce()` runs in the provider's `boot()` and both switches were off | Turn on `integrity.signature.enabled` and `integrity.checkpoints.enabled`, or turn compliance off |
| Old entries verify but report `Unsigned` after enabling compliance | Signing is not retroactive and there is no command that signs history | Expected. Say so; a bulk `UPDATE` would produce signatures proving only that somebody with write access passed through |
| `sentinel:prune --dry-run --action=delete` reports a range, the real run refuses it | The dry-run early return in `Retention\Pruner::retire()` sits *before* the archived-first guard | Plan with `--action=archive`, or check `sentinel_archives` for the range yourself before planning a delete |
| `sentinel:prune --action=delete` exits 2, prints no table, and some rows are already gone | The refusal throws out of the per-window loop; `PruneCommand` maps every `Throwable` to exit 2, so streams already walked keep what they removed | Archive first. Re-run and read the report |
| A read leaves no row in `sentinel_access_log` | It went through `$model->audits()`, an Eloquent query, `sentinel:show <id>` or a verification walk — none of which reach `AuditQuery::read()` | Reach the trail through `Sentinel::audits()` |
| `access` entries appear but `sentinel_access_log` stays empty | `mode` is `queue` or `buffered`; the projection hangs off the settled callback, which async modes never reach | Use `mode = sync` under compliance, or query the `access` entries directly |
| `AuditAccess::audit()` returns `null` | Retention pruned the proving entry; the row is deliberately not a foreign key | Give `'access'` a retention no shorter than the log's own lifetime |
| A paginating walk keeps finding new entries at the tail | Each page writes an `access` entry into the stream being walked | Walk by `after()` cursor, or narrow with `whereType()` |
| `sentinel_access_log` is the biggest table in the schema | It grows one row per query and the prune never touches it | `sentinel:partitions --table=access_log`; retention on `'access'` will not shrink it |
| `sentinel:partitions --retire --force` refuses and exits 1 | Under compliance the occupied-partition guard is unconditional; the report says `unarchived` | `sentinel:prune --action=archive` first, then retire |
| A failed audit write inside a transaction was logged, not thrown | `WriteFailure::afterCommit()` never propagates, in any mode | Listen for `AuditWriteFailed` |
| The recorded `query` is missing the date range or the labels you filtered on | `AccessLog::describe()` records fourteen named properties; `between()`, labels, relation criteria, ip and route are not among them | Do not treat the recorded query as a full reproduction of the filter |
| A second redaction of an already-redacted entry succeeds without an actor | Idempotency is the first branch of `Redactor::redact()` and returns before the compliance guard | Expected; the first redaction is the one that had to name somebody |

## ✅ Best practices

✅ **Do** — turn signatures and anchors on in one deploy, and compliance in the next. The mode
refuses to boot without them, so shipping all three at once turns an ordering mistake into an outage.

```php
// Deploy 1
'integrity' => ['signature' => ['enabled' => true], 'checkpoints' => ['enabled' => true]],
'compliance' => false,

// Deploy 2, once entries are signing and anchors exist
'compliance' => true,
```

❌ **Don't** — flip all three keys in the same change and cache the config. `config:cache` runs the
provider, so the refusal lands in the deploy step and the previous release is already gone.

```php
'compliance' => true,
'integrity'  => ['signature' => ['enabled' => false]],  // boot fails, everywhere
```

---

✅ **Do** — reach the trail through `Sentinel::audits()` in any code path that serves a human
looking at somebody else's history. It is the route, not the intention, that decides whether the
read is recorded.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

$history = Sentinel::audits()->for($patient)->take(50)->get();
```

❌ **Don't** — use the model relation there and assume compliance mode covers it. It does not, and
nothing warns you: `$patient->audits()` is a `MorphMany` on your own model and leaves no trace.

```php
$history = $patient->audits()->get();   // no access entry, no access-log row
```

---

✅ **Do** — give `sentinel_access_log` a growth plan the day you enable the mode. It gains a row per
query and nothing in the package removes one.

```
php artisan sentinel:partitions --table=access_log --ahead=3 --retire='12 months'
```

❌ **Don't** — declare a retention policy on `'access'` and believe the table is handled. That
policy prunes the chained entries and orphans every projection row, which then answers `null` from
`audit()` — the worst of both.

```php
'retention' => ['access' => '1 year'],   // shrinks sentinel_audits, not sentinel_access_log
```

---

✅ **Do** — pass an actor to every redaction, in application code and on the command line, whether
or not compliance is on. The trail entry is the only thing that separates a declared redaction from
an attack.

```php
use ElPandaPe\Sentinel\Redaction\Redactor;
use ElPandaPe\Sentinel\Support\Reference;

app(Redactor::class)->redact($entry, 'Erasure request 4711', Reference::to($officer));
```

❌ **Don't** — leave the actor to the mode to enforce. Outside compliance the same call is accepted
and writes a trail entry naming nobody, which is the shape of an unattributable deletion.

```php
app(Redactor::class)->redact($entry, 'Erasure request 4711');   // accepted, and unattributable
```

---

✅ **Do** — keep `mode = sync` under compliance, or write down why not. The chained proof survives an
asynchronous mode; the searchable row does not.

```php
'mode' => 'sync',
```

❌ **Don't** — move to `queue` for read latency and keep quoting `sentinel_access_log` as the record
of who looked. It will be missing exactly the reads that mattered under load.

```php
'mode' => 'queue',   // access entries still written; access-log rows silently stop
```

---

✅ **Do** — say plainly, in your own documentation, that the package certifies nothing and list what
your application adds on top: authorization, key custody, database privileges, backup handling.

❌ **Don't** — present `'compliance' => true` to an assessor as evidence of a standard. It is
evidence of a configuration. The standard is satisfied by a system, and most of that system is
yours.

---

**See also:** [Retention and pruning](01-retention-and-pruning.md) · [Cold archiving](02-cold-archiving.md) · [Redaction and tombstones](04-redaction-and-tombstones.md) · [Export and rekey](06-export-and-rekey.md) · [Signing the chain](../07-integrity/04-signing.md) · [Checkpoints and anchors](../07-integrity/05-checkpoints-and-anchors.md) · [Verification](../07-integrity/06-verification.md) · [The Query API](../06-reading/01-the-query-api.md) · [Failure policy](../09-operations/05-failure-policy.md) · [Performance modes](../09-operations/01-performance-modes.md) · [Partitioning](../10-database-engines/06-partitioning.md) · [Security checklist](../13-best-practices/03-security-checklist.md) · [Configuration](../99-reference/02-configuration.md) · [Schema](../99-reference/03-schema.md) · [Exceptions](../99-reference/06-exceptions.md)
