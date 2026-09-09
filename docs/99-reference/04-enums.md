# 📚 Enums

> Every enum the package declares, case by case: the value it stores, what that value means when you
> read it back, and which code writes it.

**On this page:** [The whole set](#the-whole-set) · [What an entry is](#what-an-entry-is) · [Where and how it settles](#where-and-how-it-settles) · [What verification found](#what-verification-found) · [Reading and restoring](#reading-and-restoring) · [Storage](#storage) · [Internal enums](#internal-enums) · [Pitfalls](#️-pitfalls) · [Best practices](#-best-practices)

---

## The whole set

Eighteen enums live in `src/Enums/`. Every one of them is backed by `string` — nothing in Sentinel is
a pure enum, because every case either lands in a column, is read out of `config/sentinel.php`, or is
rendered through a translation key, and all three want a stable literal rather than an ordinal.

Fourteen are part of the surface the 1.0 freeze covers. Four carry `@internal` and are classified as
internal by `tests/SurfaceTest.php` under the reason *"values only an internal writes and only an
internal reads"* — you will see them in a command's output, never in a signature you call.

| Enum | Backing | Frozen | What it answers |
|---|---|---|---|
| `AuditEvent` | `string` | yes | Which of the engine's own event names an entry carries |
| `Severity` | `string` | yes | How much an entry weighs |
| `Source` | `string` | yes | Which kind of process the entry came out of |
| `Mode` | `string` | yes | Where and when an entry settles |
| `MassMode` | `string` | yes | What an audited mass statement writes down |
| `FailurePolicy` | `string` | yes | What a write that did not complete costs the request |
| `FanoutPolicy` | `string` | yes | Whether a secondary destination refusing an entry fails the write |
| `IntegrityBreak` | `string` | yes | What verification found **wrong** |
| `SignatureState` | `string` | yes | What verification found about one signature |
| `ContentState` | `string` | yes | What an entry's content says about itself |
| `Filter` | `string` | yes | One case per published query filter |
| `RelationOperation` | `string` | yes | What happened to one related record |
| `Omission` | `string` | yes | Why a restoration did not do something it was asked to |
| `ArchiveCodec` | `string` | yes | How the bytes of an archive batch are written |
| `BatchLine` | `string` | **internal** | What one line of an archive batch is |
| `CheckpointState` | `string` | **internal** | What the anchors said about a range |
| `PruneAction` | `string` | **internal** | What `sentinel:prune` does with a released range |
| `RetentionHold` | `string` | **internal** | Why a stream released nothing |

> 📌 **Note.** Cases are only ever **added**. None is renamed or reinterpreted, for the reason the
> serialized entry gives in [Serialization](08-serialization.md): a stored value is a fact somebody
> already wrote down, and renaming it would change what the old rows say.

---

## What an entry is

### `AuditEvent`

The fifteen event names the engine itself writes. It is what lands in the `event` column for an entry
Sentinel produced on its own — but the column is a plain string, so a custom event
(`Sentinel::event('invoice.approved')`) or an authentication entry carries a name that is **not** a
case here.

| Case | Value | Written by | Reads as (en) |
|---|---|---|---|
| `Created` | `created` | `Capture\ModelObserver` | created |
| `Updated` | `updated` | `Capture\ModelCapture`, `Mass\AuditedQuery::update()` | changed |
| `Deleted` | `deleted` | `Capture\ModelObserver`, `Mass\AuditedQuery::delete()` | deleted |
| `Restored` | `restored` | `Capture\ModelObserver` (a soft delete undone) | restored |
| `ForceDeleted` | `force_deleted` | `Capture\ModelObserver` | permanently deleted |
| `Attached` | `attached` | `Capture\RelationCapture`, `Capture\ParentCapture` | attached |
| `Detached` | `detached` | `Capture\RelationCapture`, `Capture\ParentCapture` | detached |
| `Synced` | `synced` | `Capture\Relations\RecordsRelationChanges` | synced |
| `Upserted` | `upserted` | `Mass\AuditedQuery::upsert()` | inserted or updated |
| `Transition` | `transition` | `Capture\ModelCapture`, `Transitions\TransitionBuilder` | moved |
| `Restore` | `restore` | `Restore\Restorer` | restored the state of |
| `Rekeyed` | `rekeyed` | `Security\Rekeyer` | re-keyed |
| `Read` | `read` | `Compliance\AccessLog` | read |
| `Redacted` | `redacted` | `Redaction\Redactor` | redacted an entry about |
| `Custom` | `custom` | **nothing** | recorded |

Two of those rows are worth a second read.

`Restored` and `Restore` are different facts. `Restored` is Eloquent's soft-delete restore on your
model. `Restore` is Sentinel writing a **new** entry that says an earlier state was put back — see
[Restoring state](../06-reading/08-restoring-state.md).

`Custom` has no producer in `src/`. A custom event gets `audit_type = 'custom'` and
`event = 'invoice.approved'` (the name you passed), so the `events.custom` translation line only
renders for an entry whose `event` column is literally the string `custom`, which the package never
writes.

`whereEvent()` takes either form:

```php
use ElPandaPe\Sentinel\Enums\AuditEvent;
use ElPandaPe\Sentinel\Facades\Sentinel;

Sentinel::audits()->whereEvent(AuditEvent::ForceDeleted)->get();
Sentinel::audits()->whereEvent('invoice.approved')->get();
```

> 🧪 **Verify it.** `tests/ConventionsTest.php` (*"names every audit event in both catalogues"*)
> fails if any `AuditEvent` case is missing a line under `events` in `resources/lang/en` **or**
> `resources/lang/es`. Adding a case without the two lines turns `make ci` red.

### `Severity`

| Case | Value | `rank()` |
|---|---|---|
| `Info` | `info` | 0 |
| `Notice` | `notice` | 1 |
| `Warning` | `warning` | 2 |
| `Critical` | `critical` | 3 |

`rank()` exists so severities can be compared without anybody hard-coding an order, and `atLeast()`
is the comparison itself:

```php
use ElPandaPe\Sentinel\Enums\Severity;

Severity::Critical->atLeast(Severity::Warning); // true
Severity::Warning->atLeast(Severity::Warning);  // true
Severity::Info->atLeast(Severity::Notice);      // false
```

The severity of an entry is decided at capture and no pipeline stage touches it. It is resolved in
this order:

1. `AuditPolicy::of($model)->severity` — the model's `$auditSeverity`, a `Severity` case or its
   string value. Anything else raises `ConfigurationException`.
2. `sentinel.severity.events[<event name>]` — keyed by the name as it lands in the `event` column,
   which is why it accepts names that are not `AuditEvent` cases.
3. `sentinel.severity.default`.

Shipped overrides in `config/sentinel.php`: `deleted → notice`, `force_deleted → warning`,
`rekeyed → notice`, `failed → warning`, `lockout → critical`, `password_reset → notice`. `login` and
`logout` are deliberately absent — getting in is routine — so they fall to `severity.default`.

```php
namespace App\Models;

use ElPandaPe\Sentinel\Concerns\Auditable;
use ElPandaPe\Sentinel\Enums\Severity;
use Illuminate\Database\Eloquent\Model;

final class Patient extends Model
{
    use Auditable;

    protected Severity $auditSeverity = Severity::Critical;
}
```

`Models\Audit` casts the column to `Severity`, so `$audit->severity` is the case, never the string.
`whereSeverity()` accepts only the case.

### `Source`

Which kind of process wrote the entry. Eight of the nine are resolved by
`Context\Resolvers\SourceResolver`; the ninth is not resolved at all.

| Case | Value | When the resolver picks it |
|---|---|---|
| `Queue` | `queue` | The process is settling an audit under `mode = queue` |
| `Job` | `job` | A queued job is running (and it is not an audit job) |
| `Scheduler` | `scheduler` | The scheduler is running, or the command is `schedule:run`/`schedule:work`/`schedule:finish` |
| `Api` | `api` | There is a request and it matches `resolvers.request.api` |
| `Http` | `http` | There is a request and it does not |
| `Cli` | `cli` | An Artisan command is running |
| `System` | `system` | Running unit tests, or nothing above matched |
| `Console` | `console` | Running in console with no command name |
| `Import` | `import` | Never — see below |

The order in that list **is** the contract, and it is a `match(true)` read top to bottom. A queued
write is still "the request that queued it" in every other resolver; here it is `Source::Queue`,
because that is the process actually writing the entry.

`Import` is the odd one. Nothing resolves it: it is written by `Import\Origins\OwenIt` and
`Import\Origins\Altek` and by nothing else. It marks a fact this trail was *told about* rather than
one it witnessed, and `Restore\Planner` reads it — a restoration of an imported entry that names no
fields is refused with `Omission::EntryImported`, because the origin package may not have recorded
the whole record.

`Data\AuditData` defaults `source` to `Source::System`, and `Models\Audit` casts the column.

---

## Where and how it settles

### `Mode`

| Case | Value | Effect |
|---|---|---|
| `Sync` | `sync` | The entry is written inside the request that caused it (the default) |
| `Queue` | `queue` | A job settles it; the ledger runs in a worker |
| `Buffered` | `buffered` | Entries accumulate and are flushed in batches |

Read through `Support\Config::mode()` from `sentinel.mode`. `Dispatch\Dispatcher` resolves the
strategy from it per entry. Two places check it directly: `sentinel:flush` refuses to run unless the
mode is `Buffered`, and the buffer's two shutdown hooks — the application's `terminating` callback
and `WorkerStopping` — are always registered but return without touching the buffer unless it is.
`Import\Importer` sets `sentinel.mode` to `sync` for the duration of an import, so a bulk import
never queues.

Full behaviour in [Performance modes](../09-operations/01-performance-modes.md).

### `MassMode`

What an audited `Builder::update()`, `delete()` or `upsert()` writes down.

| Case | Value | Writes | Cost |
|---|---|---|---|
| `Summary` | `summary` | One entry: what the statement was aimed at, which columns it wrote, how many rows it reached | Does not grow with the size of the set |
| `Individual` | `individual` | One entry per row with the state it was actually in, plus the summary over them | Every row is read before the statement runs and held while described |
| `Hybrid` | `hybrid` | `Individual` while the set is under `mass_operations.threshold`, `Summary` the moment it is not | Bounded by the threshold |

`Summary` is the default and stays the default. Read through `Config::massMode()` from
`sentinel.mass_operations.mode`; a null key falls back to `Summary`. A per-statement override goes
through the builder macro:

```php
use App\Models\Invoice;
use ElPandaPe\Sentinel\Enums\MassMode;

Invoice::query()->where('status', 'draft')->auditing()->delete();
Invoice::query()->where('status', 'draft')->auditing(MassMode::Individual)->delete();
Invoice::query()->where('status', 'draft')->auditing('hybrid')->delete();
```

An unrecognised string there raises `ConfigurationException::unknown('auditing', …)` naming
`summary, individual, hybrid`. See [Mass operations](../03-capture/05-mass-operations.md).

### `FailurePolicy`

| Case | Value | What a failed write does |
|---|---|---|
| `Throw` | `throw` | The original exception is rethrown into whoever caused the entry (the default) |
| `Log` | `log` | An error line goes through `sentinel.log_channel` and the request continues |

Read through `Config::writeFailurePolicy()` from `sentinel.on_write_failure`, and consulted from
exactly one place: `Capture\WriteFailure::inRequest()`. Two consequences fall straight out of that.

When `sentinel.compliance` is `true`, `writeFailurePolicy()` returns `Throw` and **returns before the
string is read** — so under compliance a typo in `on_write_failure` is never even parsed. When
compliance is off, an unrecognised value raises `ConfigurationException::unknown('on_write_failure',
…, 'throw, log')` — but only at the moment a write actually fails, never at boot.

It is a string enum and not a boolean because the question has more answers waiting for it. None of
those answers is implemented today; the shape is what allows one to be added without replacing the
key. See [Failure policy](../09-operations/05-failure-policy.md).

### `FanoutPolicy`

| Case | Value | A destination refusing the entry |
|---|---|---|
| `Strict` | `strict` | Fails the write (the default, and the fallback when the key is null) |
| `Primary` | `primary` | Raises `Events\LedgerDestinationFailed`; the write settles anyway |

Read through `Config::fanoutPolicy()` from `sentinel.ledger.ledgers.fanout.on_failure`. Under
`Strict`, what the other destinations already took stays with them: the entry is sealed before it is
handed out, and nothing downstream can unseal it. That is why a `Strict` failure does **not** mean
"nothing was written". See [Fanout](../11-extending/05-fanout.md).

---

## What verification found

Three enums split one question three ways, and the split is load-bearing. `IntegrityBreak` holds
**only** conditions that make a verification fail. Every condition a sound installation can
legitimately be in lives in one of the others. Folding one into `IntegrityBreak` would make
`isIntact()` return `false` for a healthy chain — silently, because nothing would change shape.

### `IntegrityBreak`

| Case | Value | What it means |
|---|---|---|
| `HashMismatch` | `hash_mismatch` | The entry no longer reproduces its own hash |
| `LinkMismatch` | `link_mismatch` | The entry's `previous_hash` does not match the entry before it |
| `SequenceGap` | `sequence_gap` | A sequence number is missing, so the chain cannot be followed past it |
| `SignatureMismatch` | `signature_mismatch` | The entry carries a signature its own key does not verify |
| `ProjectionMismatch` | `projection_mismatch` | The relation lines in the projection no longer match the ones the entry sealed |
| `CheckpointMismatch` | `checkpoint_mismatch` | An anchor no longer folds to the root it recorded |

`message(string $stream, int $sequence, string $auditId)` renders
`sentinel::sentinel.integrity.<value>`, and it is the **one** place the sentence is built —
`Integrity\VerificationResult::message()` and `Events\IntegrityVerificationFailed::message()` both
delegate here, so a reason cannot say one thing when it is announced and another when it is read.

`ProjectionMismatch` is the case that will wake somebody up for nothing if you let it. The relation
index is a projection of the lines the entry sealed; the chain does not cover it. Its own translated
sentence ends *"The chain is intact: the projection is not part of it."*

```php
use ElPandaPe\Sentinel\Enums\IntegrityBreak;
use ElPandaPe\Sentinel\Events\IntegrityVerificationFailed;
use Illuminate\Support\Facades\Event;

Event::listen(IntegrityVerificationFailed::class, function (IntegrityVerificationFailed $event): void {
    match ($event->reason) {
        IntegrityBreak::ProjectionMismatch => logger()->warning($event->message()),
        default => logger()->critical($event->message()),
    };
});
```

> ⚠️ **Warning.** On the two checkpoint paths `IntegrityVerificationFailed::$auditId` carries the
> anchor's root hash rather than an audit id, and `$sequence` carries the anchor's `from`. A broken
> anchor is a fact about a range, and there is no single entry to name.

### `SignatureState`

| Case | Value | Coexists with an intact chain |
|---|---|---|
| `Signed` | `signed` | yes |
| `Unsigned` | `unsigned` | yes — the entry was written before signing was switched on |
| `Invalid` | `invalid` | **no** — becomes `IntegrityBreak::SignatureMismatch` |
| `UnknownKey` | `unknown_key` | yes — the key id is not on `integrity.signature.keys` |

The four-way split is the one RFC 4033 §5 draws for DNSSEC (secure / insecure / bogus /
indeterminate), for the same reason: collapsing "not signed" into "signature failed" turns a report
into noise. Only `Invalid` produces a break.

The tallies come back as `array<string, int>` keyed by the case value, and anchors are counted
separately from entries:

```php
use ElPandaPe\Sentinel\Enums\SignatureState;
use ElPandaPe\Sentinel\Facades\Sentinel;

$report = Sentinel::verifyEverything();

$unsigned = $report->signatures()[SignatureState::Unsigned->value] ?? 0;
$onAnchors = $report->anchorSignatures()[SignatureState::Unsigned->value] ?? 0;
```

### `ContentState`

| Case | Value | Meaning |
|---|---|---|
| `Sealed` | `sealed` | The content is the content the entry was written with |
| `Redacted` | `redacted` | The content was destroyed on purpose and a tombstone says so |
| `Altered` | `altered` | **The break** — becomes `IntegrityBreak::HashMismatch` |

The discriminant is `redacted_at`; the hash only corroborates. `Redacted` is a healthy, intentional
state: it does not break the chain, does not stop the walk and does not invert `isIntact()`. A
tampering does all three, and wins over a tombstone standing next to it — otherwise a redaction
would be a place to hide one. `StreamVerification::redacted()` reads the `redacted` tally.

See [Verification](../07-integrity/06-verification.md) and
[Redaction and tombstones](../08-lifecycle/04-redaction-and-tombstones.md).

---

## Reading and restoring

### `Filter`

One case per published query filter, and the vocabulary a ledger driver uses to declare what its
backend can translate. `method()` gives the query-builder method name that the refusal message
cites.

| Case | Value | `method()` | In `assumed()` |
|---|---|---|---|
| `Subject` | `subject` | `for` | ✅ |
| `Actor` | `actor` | `by` | ✅ |
| `Event` | `event` | `whereEvent` | ✅ |
| `Severity` | `severity` | `whereSeverity` | ✅ |
| `Source` | `source` | `whereSource` | ✅ |
| `Tenant` | `tenant` | `forTenant` | ✅ |
| `Transaction` | `transaction` | `inTransaction` | ✅ |
| `Trace` | `trace` | `withTrace` | ✅ |
| `Period` | `period` | `between` | ✅ |
| `Tag` | `tag` | `whereTag` | ❌ |
| `FieldChanged` | `field` | `whereFieldChanged` | ❌ |
| `Version` | `version` | `whereVersion` | ❌ |
| `Relation` | `relation` | `whereRelation` | ❌ |
| `Related` | `related` | `whereRelated` | ❌ |
| `Operation` | `operation` | `whereOperation` | ❌ |
| `Type` | `type` | `whereType` | ❌ |
| `Ip` | `ip` | `whereIp` | ❌ |
| `Route` | `route` | `whereRoute` | ❌ |
| `After` | `after` | `after` | ❌ |

`Filter::answeredBy(Ledger $ledger)` returns the driver's `supportedFilters()` when it implements
`Contracts\DeclaresFilters`, and `Filter::assumed()` otherwise. **`assumed()` never grows.** It is
the set as it stood in v0.9.0 — the nine ticked above — because a driver written against that
surface never named the filters published after it, and assuming it could translate them would have
it quietly dropping a criterion instead of refusing one. A filter published after that is answered
only by a driver that names it.

Every driver shipped in this package returns `Filter::cases()`; `Ledger\FanoutLedger` delegates to
its primary. The refusal lands as the filter is **added**, not when the query runs:

```
App\Ledger\SearchLedger cannot filter by field, so whereFieldChanged() is not part of the query it answers.
```

`Ip` and `Route` are the two that live inside the `context` JSON column rather than in a column of
their own, and their values are deliberately the context key each one reads — `ip` and `route` — so
`Ledger\ContextPredicate` builds the JSON path straight from the case with no second mapping.

`After` is not a criterion at all but a place in the walk. It is declared like the rest because a
driver that cannot order by the identifier cannot honour it. See
[Filters reference](../06-reading/02-filters-reference.md) and
[The Ledger contract](../11-extending/01-the-ledger-contract.md).

> 🐘 **Engine.** `LedgerException::cannotTranslateOn()` is raised when the filter exists but Sentinel
> has no predicate for the connection's driver. `whereFieldChanged()`, `whereIp()` and `whereRoute()`
> are implemented for `mysql`, `pgsql` and `sqlite` only.

### `RelationOperation`

| Case | Value | The pivot row |
|---|---|---|
| `Attach` | `attach` | Did not exist before, exists now |
| `Detach` | `detach` | Existed before, does not now |
| `Update` | `update` | Existed both times, carrying different pivot data |

It names **what happened to one related record**, not which API was called. A `sync()` that adds one
record and drops another writes **one** entry carrying an `Attach` line and a `Detach` line — so
`whereOperation('attach')` finds the ones `sync()` did, which is most of them. The API that produced
the entry is not lost: it travels in the entry's `metadata`, which the chain covers.

```php
use ElPandaPe\Sentinel\Enums\RelationOperation;
use ElPandaPe\Sentinel\Facades\Sentinel;

Sentinel::audits()
    ->whereRelation('items')
    ->whereOperation(RelationOperation::Attach, RelationOperation::Update)
    ->get();
```

`whereOperation()` is variadic and accepts strings too; an unrecognised one raises
`QueryException::unknownOperation()`. See [Relationship auditing](../03-capture/04-relationships.md).

### `Omission`

Why something a restoration was asked for did not happen. The first six refuse the **whole**
restoration and land in `RestoreResult::$refused`; the remaining eight refuse **one key** and land in
`RestoreResult::$skipped`, keyed by field name.

| Case | Value | Scope | Cause |
|---|---|---|---|
| `SubjectMissing` | `subject_missing` | whole | The record the entry is about no longer exists |
| `EntryRedacted` | `entry_redacted` | whole | The entry's contents were destroyed on purpose |
| `EntryTampered` | `entry_tampered` | whole | The entry no longer reproduces its own hash |
| `EntryStateless` | `entry_stateless` | whole | The entry holds no earlier state to put back |
| `Cancelled` | `cancelled` | whole | An `AuditRestoring` listener returned `false` |
| `EntryImported` | `entry_imported` | whole | `source = import` **and** no fields were named |
| `UnknownField` | `unknown_field` | one key | The record no longer has that column |
| `UnrecordedField` | `unrecorded_field` | one key | The entry does not record it |
| `IdentityField` | `identity_field` | one key | It identifies the record rather than describing its state |
| `RedactedField` | `redacted_field` | one key | It was stored masked and the original is gone |
| `HashedField` | `hashed_field` | one key | It was stored as a digest, which cannot be reversed |
| `KeyUnavailable` | `key_unavailable` | one key | The key that encrypted it is not on the keyring |
| `RelatedMissing` | `related_missing` | one key | The related record no longer exists |
| `Unchanged` | `unchanged` | one key | The record already holds the value |

`message(string $key = '')` renders `sentinel::sentinel.restore.<value>` with `:key`.

`EntryImported` is conditional, and its sentence tells the caller so: name the fields explicitly and
the restoration proceeds. Read the reason through `RestoreResult::reason($key)` rather than the two
properties separately — it falls back to `$refused`, because a refused restoration answers for every
key at once.

```php
use ElPandaPe\Sentinel\Enums\Omission;

$result = $audit->restore(['email', 'role']);

if ($result->refused instanceof Omission) {
    return $result->refused->message();
}

foreach ($result->skipped as $key => $omission) {
    logger()->info($omission->message($key));
}
```

See [Restoring state](../06-reading/08-restoring-state.md).

---

## Storage

### `ArchiveCodec`

| Case | Value | `extension()` | Needs |
|---|---|---|---|
| `Gzip` | `gzip` | `.gz` | `ext-zlib` |

One case, on purpose. Gzip is the only codec core PHP offers without a package — bz2 and zip are no
more enabled by default, and zstd and brotli are extensions. `ext-zlib` is a Composer **`suggest`**
and not a `require`, because archiving is a driver most installations never resolve. `compress()` and
`decompress()` fall back to the input bytes when `gzencode()`/`gzdecode()` return `false`, so a
broken zlib degrades to plain text rather than raising.

It is an enum and not a boolean because `Archive\Manifest` records the **name**: a flag could never
say what to inflate a batch written two years ago with. `sentinel.ledger.ledgers.archive.codec`
accepts `'gzip'` or `null` (plain text). The old boolean key `compress` is refused outright with
`ConfigurationException::renamedArchiveCodec()` — the config merge is one level deep, so a stale
published file would otherwise quietly start writing batches in the clear.

See [Cold archiving](../08-lifecycle/02-cold-archiving.md).

---

## Internal enums

These four are `@internal`. They are not covered by the 1.0 freeze and no public signature takes or
returns one — you meet their **values** in command output and in a batch file on disk.

### `BatchLine`

`Header = 'batch'`, `Entry = 'entry'`, `Operation = 'operation'`. Every line of an NDJSON archive
batch names its own kind in a `kind` key rather than having it inferred from position, so a new kind
can be added later without every older reader misreading the file. Kinds may be added; none is ever
renamed or reinterpreted.

### `CheckpointState`

`Anchored = 'anchored'`, `Archived = 'archived'`, `Absent = 'absent'`. What the anchors said about a
**range** — which is why it is not a case of `SignatureState`, an enum that answers for one entry.
None of the three is a defect, so none is in `IntegrityBreak`: an unanchored range is walked entry by
entry and comes back with the same answer, only slower. `Archived` is never granted on the word of
the manifest alone — the manifest is unsigned and unhashed, so on its own it would turn *"delete the
rows, insert one row"* into a supported way of making evidence disappear. `sentinel:verify` prints
the tally, translating `archived` as **retired** and `absent` as **none**.

### `PruneAction`

`Archive = 'archive'`, `Delete = 'delete'`. What `sentinel:prune --action=` does with a range
retention has released. `Archive` is the default, because the action that loses nothing is the one an
operator should get for forgetting a flag. An unrecognised value is not an exception: the command
warns with the accepted list and returns `Command::INVALID`.

### `RetentionHold`

`Undeclared = 'undeclared'`, `Unanchored = 'unanchored'`, `Tail = 'tail'`, `Retained = 'retained'`.
Why a stream released nothing, with `message(string $stream, int $sequence, string $held)` rendering
`sentinel::sentinel.retention.<value>`. The unit of retention is the anchored window and not the
entry, so an operator who declared a ninety-day policy and sees nothing pruned is owed the reason —
and there are four of them. Without this, an honest report and a broken configuration look
identical.

See [Retention and pruning](../08-lifecycle/01-retention-and-pruning.md).

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `whereEvent('custom')` returns nothing, though you recorded custom events | `AuditEvent::Custom` has no producer in `src/`. A custom event's `event` column holds the name you passed; `custom` is its `audit_type` | `Sentinel::audits()->whereType('custom')`, or `whereEvent('invoice.approved')` |
| `whereSeverity('critical')` is a type error | The signature is `whereSeverity(Severity $severity)` — no string form, unlike `whereEvent()` | Pass `Severity::Critical` |
| `$audit->severity === 'critical'` is always false | `Models\Audit` casts `severity` to `Severity` and `source` to `Source` | Compare the case, or `$audit->severity->value` |
| After a rolling deploy, entries land with `severity = info` and `source = system` for no reason | `AuditData::fromPayload()` uses `Severity::tryFrom(…) ?? Severity::Info` and `Source::tryFrom(…) ?? Source::System` — a worker on older code loses the shade of meaning rather than the entry, with no diagnostic | Deploy workers before or with the code that writes the new case |
| `ConfigurationException: … [sentinel.auditSeverity] has unknown value [high]` at capture time | `$auditSeverity` accepts a `Severity` or one of its four values only | Use `Severity::Warning`, or the literal `'warning'` |
| `BadMethodCallException: … cannot filter by field` on a custom ledger | The driver does not implement `Contracts\DeclaresFilters`, so it is taken to answer only `Filter::assumed()` — the nine filters of v0.9.0 | Implement `DeclaresFilters` and name every filter the backend can translate |
| An on-call page fires for `IntegrityVerificationFailed` and the chain turns out to be intact | `IntegrityBreak::ProjectionMismatch` is a break of the relation **projection**, which the chain does not cover | Match on the case, not on the event class; the sentence itself says the chain is intact |
| `sentinel:verify` reports unsigned entries and you expect a failure | `SignatureState::Unsigned` and `UnknownKey` coexist with an intact chain by design; only `Invalid` becomes a break | Read `isIntact()`, not the signature tally |
| `whereOperation('sync')` throws `QueryException` | `RelationOperation` names what happened to a related record, not the API called | Ask for `attach`, `detach` or `update` |
| A restoration of an imported entry comes back refused with `entry_imported` | `Restore\Planner` refuses only when `$fields === null` and `source = import` | Name the fields: `$audit->restore(['email'])` |
| `ConfigurationException` about `compress` after upgrading | `ledger.ledgers.archive.compress` was renamed to `codec`; the boolean is refused rather than ignored, because the config merge is one level deep | Set `'codec' => 'gzip'` or `null` in the published config |
| A typo in `on_write_failure` surfaces as a `ConfigurationException` *during* an unrelated ledger failure | `Config::writeFailurePolicy()` is called only from `WriteFailure::inRequest()`; nothing validates the key at boot, and under `compliance` the string is never even read | Keep it to `throw` or `log`; assert it in a config test |
| `sentinel:prune --action=purge` exits non-zero with no exception | `PruneAction::tryFrom()` returns null and the command warns and returns `INVALID` | Pass `archive` or `delete` |

---

## ✅ Best practices

✅ **Do** — pass the enum case to a filter, and compare the case when you read one back. The column
is cast, so the string comparison silently never matches.

```php
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Facades\Sentinel;

$critical = Sentinel::audits()->whereSeverity(Severity::Critical)->get();

foreach ($critical as $audit) {
    if ($audit->severity === Severity::Critical) {
        // …
    }
}
```

❌ **Don't** — compare `severity` or `source` against a string. `Models\Audit::casts()` maps both to
their enum, so this branch is dead code that no test will notice.

```php
if ($audit->severity === 'critical') { /* never true */ }
```

---

✅ **Do** — use `Severity::atLeast()` for a threshold. It compares `rank()`, so a case added later
sits in the right place without you touching the comparison.

```php
use ElPandaPe\Sentinel\Enums\Severity;

$alarming = $audits->filter(fn ($audit) => $audit->severity->atLeast(Severity::Warning));
```

❌ **Don't** — hard-code an order over the values. The string ordering has nothing to do with the
weight, and `'critical' < 'info'` is alphabetically true.

```php
$order = ['info', 'notice', 'warning', 'critical'];
$alarming = $audits->filter(fn ($audit) => array_search($audit->severity->value, $order, true) >= 2);
```

---

✅ **Do** — match on the `IntegrityBreak` case when you react to a verification failure. A stale
relation projection and a broken hash are different incidents and want different responses.

```php
use ElPandaPe\Sentinel\Enums\IntegrityBreak;
use ElPandaPe\Sentinel\Events\IntegrityVerificationFailed;
use Illuminate\Support\Facades\Event;

Event::listen(IntegrityVerificationFailed::class, function (IntegrityVerificationFailed $event): void {
    match ($event->reason) {
        IntegrityBreak::ProjectionMismatch => logger()->warning($event->message()),
        default => logger()->critical($event->message()),
    };
});
```

❌ **Don't** — page on the event class alone. `ProjectionMismatch` fires for a chain that is
perfectly intact, and the third false alarm is the one that gets the alert muted.

```php
Event::listen(IntegrityVerificationFailed::class, fn () => OnCall::page('audit tampering'));
```

---

✅ **Do** — declare `supportedFilters()` on a ledger driver you write, naming every `Filter` the
backend can actually translate. The refusal then lands where the filter is added, with the method
name in the message.

```php
use ElPandaPe\Sentinel\Contracts\DeclaresFilters;
use ElPandaPe\Sentinel\Enums\Filter;

final class SearchLedger implements DeclaresFilters /* …, Ledger */
{
    /** @return list<Filter> */
    public function supportedFilters(): array
    {
        return [...Filter::assumed(), Filter::Tag, Filter::Type];
    }
}
```

❌ **Don't** — leave `DeclaresFilters` off and assume the driver answers everything. It is taken to
answer `Filter::assumed()` only — nine filters, frozen at v0.9.0 — so `whereTag()` on your driver
raises `LedgerException` even if the backend could have served it.

---

✅ **Do** — key `sentinel.severity.events` by the event name exactly as it lands in the `event`
column. `Config::defaultSeverity()` takes `AuditEvent|string`, so a custom event needs no enum case.

```php
// config/sentinel.php
'severity' => [
    'default' => 'info',
    'events' => [
        'deleted' => 'notice',
        'invoice.approved' => 'notice',
    ],
],
```

❌ **Don't** — try to add a case to `AuditEvent` for a custom event of your own. The enum is the set
the engine writes, `tests/ConventionsTest.php` requires a translation line in both language files for
every case, and the `event` column is a free string that needs neither.

---

✅ **Do** — check `Source::Import` before you treat an entry as evidence of something this
application did. An imported entry is a fact the trail was told about, and the restore planner
already refuses to act on one blindly.

```php
use ElPandaPe\Sentinel\Enums\Source;

if ($audit->source === Source::Import) {
    // Recorded by another package and copied in — name the fields to restore.
}
```

❌ **Don't** — read `source` as a location. It names the kind of process, and the order the resolver
tries is fixed: a model saved inside a queued job is `Source::Job`, and one saved while a worker is
settling an audit is `Source::Queue`, whatever request originally queued it.

---

**See also:** [Schema](03-schema.md) · [Configuration](02-configuration.md) · [Events](05-events.md) · [Exceptions](06-exceptions.md) · [API stability](09-api-stability.md) · [Filters reference](../06-reading/02-filters-reference.md) · [Verification](../07-integrity/06-verification.md) · [Failure policy](../09-operations/05-failure-policy.md)
