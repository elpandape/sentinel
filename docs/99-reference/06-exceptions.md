# 📚 Exceptions

> Every exception class Sentinel throws: what it means, what raises it, whether you are meant to
> catch it, and what to do instead of catching it.

**On this page:** [The convention](#the-convention) · [The fifteen classes](#the-fifteen-classes) ·
[Configuration and declarations](#configuration-and-declarations) · [Query, comparison and references](#query-comparison-and-references) ·
[Pipeline and dispatch](#pipeline-and-dispatch) · [Integrity and immutability](#integrity-and-immutability) ·
[Security](#security) · [Lifecycle](#lifecycle) · [Import](#import) ·
[What never throws](#what-never-throws) · [When each one surfaces](#when-each-one-surfaces) ·
[⚠️ Pitfalls](#️-pitfalls) · [✅ Best practices](#-best-practices)

---

## The convention

**Every exception message in this package is plain English, and none of them is translated.** No
class in `src/Exceptions/` calls `trans()`. That is deliberate and it matches what Laravel itself
does: an exception here is a message to whoever wrote the configuration or the call, and the person
reading it is a developer with a stack trace, not a user with a browser.

End-user-facing sentences live somewhere else entirely — in `resources/lang/en/sentinel.php` and
`resources/lang/es/sentinel.php`, reached through a `message()` method on an event or an enum:

| Sentence you can show a person | Where it comes from |
|---|---|
| Why an entry was not written | `Events\AuditDiscarded::message()` |
| Why a write did not complete | `Events\AuditWriteFailed::message()` |
| Why a verification failed | `Enums\IntegrityBreak::message()`, via `Events\IntegrityVerificationFailed::message()` |
| Why a restoration skipped a field | `Enums\Omission::message($key)` |
| Why a flush did not empty the buffer | `Events\BufferFlushFailed::message()` |

> 🔒 **Security.** Do not render an exception message into a user-facing response. Several of them
> interpolate configuration keys, key identifiers, disk names, archive paths and stream names —
> `EncryptionException::unknownKey()` names your key id, `RedactionException::archived()` names the
> disk and the path. That is exactly what a developer needs and exactly what a visitor should never
> see.

### There is no base class and no marker interface

Each of the fifteen extends an SPL class directly. There is no `SentinelException`, no shared
interface, and none is planned as a catch-all — so **`catch (SentinelException)` is not something you
can write**, and neither is a single `report()` rule in `bootstrap/app.php` that covers the package.

The parent class is the signal. `InvalidArgumentException` and `LogicException` mean *the call or the
configuration is wrong* — a bug, fix it, never catch it. `RuntimeException` and
`BadMethodCallException` mean *something about the environment or the state of the trail refused* —
a few of those are worth catching at a boundary, and the table below says which.

---

## The fifteen classes

| Class | Extends | Raised by | Catch it? |
|---|---|---|---|
| `ConfigurationException` | `InvalidArgumentException` | `Support\Config`, the provider, model declarations, stream and retention resolution | **Never.** Fix `config/sentinel.php` or the model. |
| `QueryException` | `InvalidArgumentException` | `Query\AuditQuery`, `Support\Reference`, `Capture\PendingEvent`, `Console\Concerns\WalksStreams`, `Integrity\Verifier` | **Never.** Narrow, paginate, or pass a saved model. |
| `ComparisonException` | `InvalidArgumentException` | `Query\AuditQuery::compare()`, `Query\Comparison` | **Never.** Name a subject and a version that exists. |
| `LedgerException` | `BadMethodCallException` | `Query\AuditQuery`, `Ledger\ChangedFieldPredicate`, `Ledger\ContextPredicate` | **Never.** The method is not part of the query that ledger answers. |
| `DiscardException` | `LogicException` | `Pipeline\Discard::because()` | **Never.** Move the discard into the pipeline. |
| `DispatchException` | `LogicException` | `Data\AuditData::fromPayload()` | **Never.** A payload crossed a process boundary malformed. |
| `ImmutableAuditException` | `RuntimeException` | `Models\Audit` on `updating` and `deleting` | **Never.** There is nothing to recover; do not edit entries. |
| `CanonicalizationException` | `RuntimeException` | `Integrity\JsonCanonicalizer` | **Never.** The value cannot be hashed; keep it out of the payload. |
| `SnapshotException` | `RuntimeException` | `Snapshot\SnapshotBuilder` | **Never.** Exclude or cast the attribute. |
| `EncryptionException` | `RuntimeException` | `Security\Keyring`, `Support\Config` | At a read boundary, to render "unavailable" rather than fail a page. |
| `SignatureException` | `RuntimeException` | `Integrity\Signers`, `Integrity\OpenSslSigner` | Rarely. It is a key-material problem, not a data one. |
| `RedactionException` | `RuntimeException` | `Redaction\Redactor` | **Yes.** A refusal an erasure workflow has to report. |
| `ComplianceException` | `RuntimeException` | `Compliance\Requirements`, `Redaction\Redactor`, `Retention\Pruner` | **Yes**, for the two runtime ones. Never the boot one. |
| `ArchiveException` | `RuntimeException` | `Archive\BatchWriter`, `Archive\BatchReader`, `Archive\Rehydrator`, `Archive\Line` | **Yes**, at the operator boundary. Nothing was removed. |
| `ImportException` | `RuntimeException` | `Import\Shape` | **Yes**, though `sentinel:import` already turns it into exit `2`. |

> 📌 **Note.** The `ElPandaPe\Sentinel\Exceptions` namespace is **public surface**, frozen from
> `v1.0.0-rc.1`: the class names, the named constructors and the parent classes do not change inside
> 1.x. Message *wording* is not frozen — never match on message text
> ([API stability](09-api-stability.md)).

---

## Configuration and declarations

### `ConfigurationException`

The one you will meet first. Twenty-one named constructors, all of them saying the same thing in
different words: something in `config/sentinel.php` or in a model's `$audit*` declarations does not
say what it needs to say.

| Constructor | Condition | Fix |
|---|---|---|
| `missing($key)` | A key `Support\Config` requires is absent | Publish or restore the key |
| `expected($key, $type, $given)` | The key holds the wrong PHP type | Give it the declared type |
| `unknown($key, $value, $accepted)` | A string that is not one of the accepted values | Use one the message lists |
| `invalidClass($key, $value, $expected)` | A class-string that is not the contract or a subclass | Implement `Contracts\Masker`, `Contracts\Transformer`, `Contracts\Resolver` … |
| `missingApplicationKey($key)` | A derived secret has no `APP_KEY` to derive from | `php artisan key:generate`, or declare the value |
| `streamTooLong($name)` | A resolved stream name exceeds 64 characters | Shorten the stream strategy's output |
| `streamEmpty()` | A stream strategy resolved to `''` | Every entry belongs to a named chain |
| `eventTooLong($event, $limit)` | `Sentinel::event()` was given a name over 64 characters | Shorten the name |
| `tagTooLong($tag, $limit)` | A label exceeds the column | Shorten the label; it is never truncated |
| `unreadableTransition($column, $property)` | A `$auditTransitions` column is also in `$auditExclude` / `$auditRedact` / `$auditHash` / `$auditEncrypt` | Pick one |
| `omittedTransition($column)` | A transition column is left out of `$auditInclude` | Add it to the include list |
| `ambiguousTransition($model, $columns)` | Two state columns and the call named neither | Add `->on('status')` |
| `notAParent($model, $relation)` | `$auditParents` names a `morphTo` | `$auditParents` names `belongsTo` relations only |
| `notAuditable($model)` | A mass operation on a model with no `Auditable` trait | Add the trait, or drop the `auditing()` call |
| `unreadableRetention($key, $declared)` | A retention period that is not a span | Write `7 years`, not a relative date |
| `instantRetention($key, $declared)` | A period that does not reach into the past | It would release every entry as it is written |
| `ambiguousRetention($first, $second, $target)` | Two retention keys govern the same entries | Declare one |
| `renamedArchiveCodec()` | A published config still has `archive.compress` | Replace with `codec => 'gzip'` or `codec => null` |
| `coldLedgerAsDefault()` | `ledger.default` points at the archive driver | Name it as a fanout destination instead |
| `archivePathTooLong($path)` | An archive path exceeds 512 characters | Shorten `ledger.ledgers.archive.path` |
| `doesNotPartition($driver)` | Partition maintenance on an engine that does not partition | Only `mysql` and `pgsql` partition |

**Where it fires matters more than what it says.** `Support\Config` is a lazy reader: almost every
key is validated the first time something asks for it, not at boot. A typo in a key nothing reads on
a healthy request sits there harmlessly until the path that needs it runs.

```php
// config/sentinel.php
'on_write_failure' => 'shrug',   // accepted values are exactly 'throw' and 'log'
```

Nothing complains at boot. Nothing complains in a green test suite. The first time a ledger write
actually fails, `Config::writeFailurePolicy()` reads the key and raises
`ConfigurationException::unknown('on_write_failure', 'shrug', 'throw, log')` — replacing a
diagnosable write failure with a message about the failure policy. Worse under compliance mode:
`writeFailurePolicy()` returns `FailurePolicy::Throw` and returns *before* the string is read, so a
bogus value is never parsed at all. See [Failure policy](../09-operations/05-failure-policy.md).

Three fire earlier than the rest:

- `coldLedgerAsDefault()` and the ledger-driver `unknown()` checks fire in `SentinelServiceProvider`
  when the ledger is resolved.
- `eventTooLong()` fires in the `Capture\PendingEvent` **constructor** — at the
  `Sentinel::event('…')` call, before any builder method, and long before a write. That is on
  purpose: the name is inside the canonical payload the hash covers, so an engine that truncated it
  would leave an entry that can never reproduce its own hash
  ([Canonicalization](../07-integrity/03-canonicalization.md)).
- `streamTooLong()` and `streamEmpty()` fire in `Integrity\Stream` on the write path, per entry.

> 🐘 **Engine.** SQLite ignores `VARCHAR` widths, so a length guard that lives in the database rather
> than in PHP is invisible there. That is why `eventTooLong()`, `tagTooLong()` and `streamTooLong()`
> are raised by the package rather than left to the column: outside strict mode MySQL would
> **truncate** the value after the hash was already sealed over the full one, and PostgreSQL would
> reject it mid-write on a chain that had already been sealed.

---

## Query, comparison and references

### `QueryException`

Fourteen constructors covering the read side, the reference parser and stream enumeration.

| Constructor | Condition |
|---|---|
| `unknownOperation($operation)` | `whereOperation()` given something other than `attach`, `detach`, `update` |
| `unsavedModel($model)` | A model with no key passed to `for()`, `by()`, `PendingEvent::subject()` or a transition |
| `missingKey($type)` | A recorded type passed as a string with no key as the second argument |
| `backwardsPeriod()` | `between()` given an end earlier than its start |
| `noLabels()` | `whereTag([])` — an empty list asks nothing and would return the whole trail |
| `noField()` | `whereFieldChanged('')` |
| `noContextValue($filter)` | `whereIp('')` or `whereRoute('')` |
| `noType()` | `whereType('')` |
| `unbounded($limit)` | `get()` matched more than `AuditQuery::DEFAULT_LIMIT` (500) entries |
| `noCursor()` | `after('')` |
| `unreachableLimit($limit)` | `take(0)` or a negative limit |
| `unreachablePage($perPage, $page)` | `paginate()` with a non-positive page or size |
| `cannotEnumerateStreams($ledger)` | A ledger that is not `Contracts\EnumeratesStreams` asked for every stream |
| `unreferenceable($type)` | Something that is neither a model nor a type-and-key |

`unbounded()` is the one that surprises people. `AuditQuery::get()` probes for `DEFAULT_LIMIT + 1`
rows and **refuses rather than truncates** — handing back the first 500 of 40 000 looks exactly like
handing back all of them, and a report built on that is quietly wrong:

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

// Throws QueryException::unbounded(500) once the invoice has more than 500 entries.
$entries = Sentinel::audits()->for($invoice)->get();

// Any one of these is the answer:
$entries = Sentinel::audits()->for($invoice)->take(100)->get();
$page    = Sentinel::audits()->for($invoice)->paginate(perPage: 100, page: 1);
$next    = Sentinel::audits()->for($invoice)->after($lastId)->take(100)->get();
```

`cannotEnumerateStreams()` is the one operators meet. `sentinel:verify`, `sentinel:prune` and
`sentinel:checkpoint` with no `--stream` all ask the ledger to name its chains, and the `null` driver
is the one shipped ledger that cannot — so they exit `2` rather than quietly reporting that nothing is
wrong about a list nobody could produce ([Exit codes](07-exit-codes.md)).

### `ComparisonException`

| Constructor | Condition |
|---|---|
| `withoutSubject()` | `compare()` on a query that has not been narrowed with `for()` |
| `acrossSubjects()` | Two entries about different subjects |
| `missingVersion($version)` | No entry of that subject carries the version asked for |

`acrossSubjects()` exists for one reason worth repeating: an empty diff would read as *nothing
changed*, which is not what happened. See
[Field history and comparing versions](../06-reading/04-field-history.md).

### `LedgerException`

A `BadMethodCallException`, because the complaint is about the method and not the argument.

| Constructor | Condition | Fix |
|---|---|---|
| `cannotFilterBy($filter, $ledger)` | The configured ledger does not declare this filter | Use a filter the driver declares, or implement `Contracts\DeclaresFilters` |
| `cannotTranslateOn($filter, $driver)` | The filter exists, but there is no predicate for this database engine | Supported: `mysql`, `pgsql`, `sqlite` |

A driver that declares nothing is taken to answer only the assumed set, and that set never grows — so
a filter published after it was written is answered by nobody. That is a deliberate compatibility
rule, not a bug ([The Ledger contract](../11-extending/01-the-ledger-contract.md)).

---

## Pipeline and dispatch

### `DiscardException`

One constructor, `outsideThePipeline($reason)`, thrown by `Pipeline\Discard::because()` when no
pipeline pass is running — from an `AuditCreating` or `AuditCreated` listener, from a model observer,
or from anywhere else past the ledger boundary.

It is a `LogicException` because it is a programming error with a mechanical cause: the ledger
assigns `sequence` in the same operation as the write, so a discard past that point would leave a gap
`verifyIntegrity()` reports as tampering. Cancelling is free while the entry has no identity and
impossible afterwards ([Discarding entries](../05-pipeline-and-security/06-discarding-entries.md)).

> ⚠️ **Warning.** A `DiscardException` raised inside an `AuditCreating` listener is caught by the
> dispatch strategy and handed to `Capture\WriteFailure`, which dispatches `Events\AuditWriteFailed`
> **before** it consults the policy. So one programming error surfaces as both a write-failure
> announcement and a `LogicException`. No entry is written and the chain stays contiguous.

### `DispatchException`

| Constructor | Condition |
|---|---|
| `proposedItsOwnPlaceInTheChain($column)` | A payload crossing a process boundary names `sequence`, `hash` or `previous_hash` |
| `incompletePayload($key)` | A payload missing `audit_type`, `event` or `occurred_at` |

Both come from `Data\AuditData::fromPayload()`, which is what a queued job hands back to the ledger.
Those three keys are the only required ones: unknown keys are dropped, and every other missing key
takes its constructor default. The chain columns are refused because the ledger reads the chain and
assigns them, in the same operation as the write, in every mode — that is the single reason a
tamper-evident chain and an asynchronous write are compatible at all
([Running audits on a queue](../09-operations/03-queues.md)).

---

## Integrity and immutability

### `ImmutableAuditException`

```php
use ElPandaPe\Sentinel\Models\Audit;

$entry = Audit::query()->find($id);

$entry->update(['metadata' => ['note' => 'fixed']]);
// ImmutableAuditException: Audit [01J…] cannot be updated: an entry is a link in a hash chain,
// and rewriting it breaks every entry that follows.

$entry->delete();
// ImmutableAuditException: Audit [01J…] cannot be deleted: removing an entry leaves a hole its
// chain has no way to describe.
```

The guard is two listeners registered in `Models\Audit::booted()`, on `updating` and `deleting`. It
covers `save()`, `update()`, `delete()` and `destroy()`, and a subclass named in
`sentinel.models.audit` inherits it.

**What the guard does not cover:** it runs on Eloquent model events, so a change that does not go
through the model never reaches it. `Audit::query()->where(...)->update([...])` and the equivalent
query-builder delete fire no model events and are not stopped. Nothing in the package can stop them —
what catches them is `sentinel:verify`, which reports the row as `HashMismatch` or the gap as
`SequenceGap` ([Verification](../07-integrity/06-verification.md)).

To destroy content, redact. To remove a range, archive and prune. Both append.

### `CanonicalizationException`

| Constructor | Condition |
|---|---|
| `unsupportedNumber($value)` | `NAN` or `±INF` — JSON carries neither |
| `unsupportedType($value)` | Anything that is not a scalar, an array or `null` |
| `invalidString()` | A string that is not valid UTF-8 |

Raised by `Integrity\JsonCanonicalizer` while building the RFC 8785 canonical payload. If the value
cannot be canonicalized, the hash cannot be computed, so there is nothing to write. Keep the value
out of the entry with `$auditExclude`, or cast it.

### `SnapshotException`

One constructor, `unsupportedType($attribute, $value)`, raised by `Snapshot\SnapshotBuilder` when an
attribute holds something no snapshot can represent — a resource, a closure, an open file handle. The
message names the attribute and the type it found, and tells you both ways out:

```php
final class Invoice extends Model
{
    use Auditable;

    /** @var list<string> */
    protected array $auditExclude = ['pdf_handle'];
}
```

---

## Security

### `EncryptionException`

| Constructor | Condition |
|---|---|
| `unknownKey($keyId)` | `security.encryption.key_id` names a key that is not on `security.encryption.keys` |
| `unusableKey($keyId, $cipher, $previous)` | OpenSSL or Laravel's encrypter refused the key for that cipher; the original is the `$previous` |

The sentence in `unknownKey()` states the whole risk model in one line: *a key that leaves the keyring
takes the values it wrote with it — entries keep verifying, and stop being readable.* The ciphertext
is inside the canonical payload, so removing a key breaks decryption and never the chain. Keep old
keys on the ring for as long as any entry references them
([Encryption and the keyring](../05-pipeline-and-security/03-encryption-and-the-keyring.md)).

### `SignatureException`

| Constructor | Condition |
|---|---|
| `unknownKey($keyId)` | The signing key is not on `integrity.signature.keys` |
| `verifyOnly($keyId)` | `integrity.signature.private_key` is unset — this node holds only the public half |
| `unusableKey($keyId, $half)` | The PEM string or `file://` path could not be read |
| `unsignable($keyId, $algorithm)` | OpenSSL does not know that digest, or the key signs the message itself and refuses a pre-hashed one, as EdDSA keys do |

`verifyOnly()` is a shape, not a fault: a verifying node that holds no private half is a legitimate
deployment. It becomes an exception only when that node is asked to *write* a signed entry
([Signing the chain](../07-integrity/04-signing.md)).

---

## Lifecycle

### `RedactionException`

Three refusals, and **none of them writes anything** — two of the three say so in as many words,
ending with "Nothing was written."

| Constructor | Condition | Is it retryable? |
|---|---|---|
| `retired($stream, $sequence)` | The entry left the hot table and nothing kept its content | No |
| `archived($stream, $sequence, $disk, $path)` | The content lives in an archive batch this version cannot redact — the message names the disk and the path | No |
| `unverifiable($stream, $sequence)` | The entry no longer reproduces its own hash | No, and this one is an alarm |

`unverifiable()` is the important one. A tombstone over an altered row would hide the alteration
behind a declaration — the entry would then read as *deliberately destroyed* when what actually
happened is *tampered with*. The redaction is refused so the alarm stays
([Redaction and tombstones](../08-lifecycle/04-redaction-and-tombstones.md)).

```php
use ElPandaPe\Sentinel\Exceptions\ComplianceException;
use ElPandaPe\Sentinel\Exceptions\RedactionException;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Redaction\Redactor;

try {
    $tombstone = app(Redactor::class)->redact(
        Audit::query()->findOrFail($auditId),
        'erasure request from the patient portal',
        $actor,
    );
} catch (ComplianceException|RedactionException $refusal) {
    // Permanent for this version. Report it to whoever filed the request;
    // do not queue a retry.
    ErasureRequest::find($requestId)->refuse($refusal->getMessage());
}
```

Note the order the service checks in: an already-redacted entry returns its existing tombstone and
raises nothing; then the content must still be here; then compliance mode requires an actor; then the
entry must reproduce its own hash.

### `ComplianceException`

| Constructor | Where | Condition |
|---|---|---|
| `incomplete($missing)` | `Compliance\Requirements::enforce()`, at provider boot | `sentinel.compliance` is on while `integrity.signature.enabled` or `integrity.checkpoints.enabled` is off |
| `unattributed()` | `Redaction\Redactor::redact()` | A redaction under compliance names no actor |
| `unarchived($stream, $from, $to)` | `Retention\Pruner` | A `delete` prune under compliance meets a range no archive batch covers |

`incomplete()` is thrown from the service provider's `boot()`, so it takes the **whole application**
down at boot — every request, every command, every worker. That is the point: compliance mode is a
claim about what the trail can prove, and it cannot prove it without signatures and anchors. Never
catch this one; turn the named switches on, or turn compliance off
([Compliance mode](../08-lifecycle/05-compliance-mode.md)).

### `ArchiveException`

Nine constructors covering writing a batch, reading it back and putting a range back. Four of them
say **nothing was removed** in as many words — the batch is written, read back and rehashed *before*
a single row is deleted, so a failure costs a batch nobody keeps rather than a range nobody can
restore.

| Constructor | Condition |
|---|---|
| `refused($disk, $path)` | The disk refused the write |
| `unreadable($disk, $path)` | The batch could not be read back after being written |
| `corrupt($path)` | It did not come back as the bytes written to it |
| `unknownFormat($path, $format, $known)` | The batch declares a container format this build does not read |
| `miscounted($path, $claimed, $found)` | It records a different number of entries than it holds |
| `incompleteOperation($missing)` | An operation line does not name the columns an operation has |
| `discontiguous($path, $sequence)` | A sequence of the recorded range is missing from the batch |
| `occupied($stream, $sequence)` | Rehydration found that position already held by an entry with a different hash |
| `unverifiable($path, $sequence)` | An entry does not reproduce its own hash when read back out of the batch |

`occupied()` and `unverifiable()` are the two that a rehydration raises, and both mean the same thing
in practice: the range stays where it is ([Rehydration](../08-lifecycle/03-rehydration.md),
[Cold archiving](../08-lifecycle/02-cold-archiving.md)).

---

## Import

### `ImportException`

| Constructor | Condition |
|---|---|
| `absentTable($table, $connection)` | There is no such table on that connection |
| `unrecognisedShape($table, $origin, $missing)` | A table of the right name and the wrong shape |

`unrecognisedShape()` is the refusal that earns its keep. Importing from a table that looks right and
is not would **not fail** — it would succeed, and put rows in the trail that mean something other
than what they say. Both are raised by `Import\Shape` before a single source row is read, so
`sentinel:import` reports them and exits `2` with nothing written
([The import runbook](../12-migrating/03-the-import-runbook.md)).

---

## What never throws

Four things that could plausibly raise an exception and deliberately do not. Each one is a result
object or an event instead, because the caller has to be able to see a partial outcome:

| Operation | What you get instead | Why |
|---|---|---|
| A restoration that cannot proceed | `Restore\RestoreResult` with `$refused` set to an `Enums\Omission`, or `$skipped` keyed by field | A restoration is not all-or-nothing: eight of the fourteen omissions refuse one key while the rest go through ([Restoring state](../06-reading/08-restoring-state.md)) |
| A verification that finds a break | `Integrity\VerificationResult` carrying an `Enums\IntegrityBreak`, plus `Events\IntegrityVerificationFailed` | A broken chain is a finding to report, not an error to propagate out of a walk that had more to read |
| A stage or listener stopping an entry | `Events\AuditDiscarded`, with a stage and a reason | Refusing an entry is a legitimate outcome; it is what the pipeline is for |
| A ledger write that did not land | `Events\AuditWriteFailed`, then whatever `on_write_failure` says | Whether it propagates is a policy decision, and the announcement is not ([Failure policy](../09-operations/05-failure-policy.md)) |

> 📌 **Note.** `Enums\Omission` and `Enums\IntegrityBreak` both carry translated `message()` methods.
> These are the sentences you may put in front of a person; exception messages are not
> ([Enums](04-enums.md)).

---

## When each one surfaces

Knowing *when* a class can fire tells you where to put the guard rail — or that there is nothing to
guard.

| Moment | Classes that can fire here |
|---|---|
| Application boot | `ComplianceException::incomplete()`, from the provider's `boot()` |
| The first time the ledger or the buffer is resolved | `ConfigurationException::coldLedgerAsDefault()` and the ledger/buffer driver `unknown()` checks. Both bindings are `scoped`, so this is the first capture or read of a scope, not boot |
| At the call, before anything is written | `ConfigurationException::eventTooLong()`, `QueryException::unsavedModel()`, `ComparisonException`, `QueryException` on the read side, `LedgerException` |
| On the write path, per entry | `ConfigurationException` (stream, labels, model declarations, severity), `SnapshotException`, `CanonicalizationException`, `EncryptionException`, `SignatureException` |
| At the ledger boundary | `DispatchException`, `DiscardException`, `ImmutableAuditException` |
| Only when a write has already failed | `ConfigurationException::unknown('on_write_failure', …)` |
| Inside an operator command | `ComplianceException::unattributed()` / `::unarchived()`, `RedactionException`, `ArchiveException`, `ImportException`, `ConfigurationException::unreadableRetention()` / `::doesNotPartition()` |

Everything in the last row is already translated into an exit code by the command that drives it —
`0`, `1` or `2` — so a cron never sees a stack trace ([Exit codes](07-exit-codes.md)). Two commands
are exceptions to that: `sentinel:rekey` performs its read *outside* its `try`, so a `QueryException`
from the read escapes uncaught, and `sentinel:show <id>` finds its entry by primary key rather than
through the Query API.

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| A write failure is reported as `ConfigurationException: [sentinel.on_write_failure] has unknown value` | The key is only read at the moment a write fails, and a typo replaces the real diagnosis with its own | Set it to exactly `throw` or `log`; a bad value is invisible until the day it matters |
| `QueryException::unbounded` on a report that worked yesterday | The subject crossed 500 entries; `get()` refuses rather than silently truncating | `take()`, `paginate()` or walk with `after()` |
| `ConfigurationException::eventTooLong` at `Sentinel::event()`, nowhere near a save | The name is checked in `PendingEvent`'s constructor because it sits inside the hash | Shorten the name; the guard cannot move later |
| A `catch` around a `save()` swallows a configuration error and the model stops being audited | `ConfigurationException` extends `InvalidArgumentException`, which broad catches often include | Never catch package exceptions around business writes |
| `sentinel:verify` reports `HashMismatch` on a row you "fixed" | `Audit::query()->where(...)->update()` fires no model events, so `ImmutableAuditException` never ran | Do not edit entries; the chain is the only thing that can tell you it happened |
| `SnapshotException` on every save of one model | An attribute holds a resource or a closure | `$auditExclude` it, or cast it to something serializable |
| A redaction fails with `RedactionException::unverifiable` and retrying never helps | The entry no longer reproduces its own hash; refusing is how the alarm is kept | Investigate the tampering; do not attempt to tombstone over it |
| The whole application 500s at boot after switching compliance on | `ComplianceException::incomplete()` from the service provider — signatures or checkpoints are off | Turn both on, or turn compliance off |
| `EncryptionException::unknownKey` while entries still verify fine | The chain covers the ciphertext, so removing a key breaks reading and never the hash | Put the old key back on `security.encryption.keys` |
| A `try { … } catch (Throwable)` around a whole request hides an `ImmutableAuditException` | It extends `RuntimeException`, so a blanket handler absorbs it | Let it through; there is no recovery to attempt |

---

## ✅ Best practices

✅ **Do** — let configuration and usage exceptions reach your error handler. They name a key and an
accepted value, and the fix is always in the configuration or the call.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

// If severity.events['invoice.approved'] holds nonsense, a ConfigurationException
// naming that key is exactly what you want to see in the log.
Sentinel::event('invoice.approved')->subject($invoice)->record();
```

❌ **Don't** — wrap an audited business write in a catch that swallows them. The model keeps saving
and quietly stops being audited, and nothing will tell you.

```php
try {
    $invoice->update(['status' => 'approved']);
} catch (\InvalidArgumentException) {
    // Absorbs ConfigurationException, QueryException and ComparisonException alike.
    // The invoice is updated. There is no audit entry. Nobody is told.
}
```

---

✅ **Do** — bound every read that could grow. `AuditQuery::get()` refuses past 500 matches, and the
refusal is the feature: it is the only thing standing between you and a report that is silently
missing rows.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

$page = Sentinel::audits()
    ->for($patient)
    ->byOccurrence()
    ->paginate(perPage: 50, page: $request->integer('page', 1));
```

❌ **Don't** — catch `QueryException::unbounded` and retry with a `take()`. You have then chosen an
arbitrary prefix of an unordered answer and told nobody.

```php
try {
    $entries = Sentinel::audits()->for($patient)->get();
} catch (\ElPandaPe\Sentinel\Exceptions\QueryException) {
    $entries = Sentinel::audits()->for($patient)->take(500)->get();   // silently partial
}
```

---

✅ **Do** — catch `RedactionException` and `ComplianceException` at the boundary of an erasure
workflow, and treat both as permanent. Report the message to whoever filed the request.

```php
use ElPandaPe\Sentinel\Exceptions\ComplianceException;
use ElPandaPe\Sentinel\Exceptions\RedactionException;
use ElPandaPe\Sentinel\Redaction\Redactor;

try {
    $tombstone = app(Redactor::class)->redact($entry, $reason, $actor);
} catch (ComplianceException|RedactionException $refusal) {
    $request->refuse($refusal->getMessage());   // no retry, no queue, no backoff
}
```

❌ **Don't** — put a redaction behind a retrying queued job. All three `RedactionException` cases are
permanent for this version, and `unverifiable()` means an alarm that a retry loop will bury.

```php
// A job with tries=5 turns one tampering alarm into five identical failures
// and then a failed_jobs row nobody reads.
RedactAuditEntry::dispatch($auditId)->onQueue('erasures');
```

---

✅ **Do** — declare an unserializable attribute out of the entry once, in the model, rather than
meeting `SnapshotException` on every save.

```php
use ElPandaPe\Sentinel\Concerns\Auditable;

final class Order extends Model
{
    use Auditable;

    /** @var list<string> */
    protected array $auditExclude = ['stream_handle'];
}
```

❌ **Don't** — catch `SnapshotException` in an observer and carry on. The save succeeds, the entry
does not exist, and the gap looks exactly like an operation that never happened.

```php
try {
    $order->save();
} catch (\ElPandaPe\Sentinel\Exceptions\SnapshotException) {
    // The order is saved. Nothing recorded it. That is the worst outcome available.
}
```

---

✅ **Do** — match on the class, never on the message. Class names and named constructors are frozen
surface; wording is not.

```php
use ElPandaPe\Sentinel\Exceptions\EncryptionException;

report(fn (EncryptionException $e) => Ops::page('audit keyring', $e->getMessage()));
```

❌ **Don't** — branch on message text. It changes for clarity inside a patch release, and a
`str_contains()` that stops matching fails open.

```php
if (str_contains($exception->getMessage(), 'not on its keyring')) {
    // Silently stops being true the day the sentence is reworded.
}
```

---

✅ **Do** — show a person an enum's or an event's `message()`, which is translated into both shipped
languages.

```php
use ElPandaPe\Sentinel\Events\AuditDiscarded;
use Illuminate\Support\Facades\Event;

Event::listen(AuditDiscarded::class, fn (AuditDiscarded $e) => Ops::note($e->message()));
```

❌ **Don't** — render an exception message into a user-facing response. It is English-only by design
and several of them name key identifiers, disks and paths.

```php
return response()->json(['error' => $exception->getMessage()], 422);
// May hand a visitor your encryption key id, your archive disk and your stream names.
```

---

**See also:** [Enums](04-enums.md) · [Events](05-events.md) · [Exit codes](07-exit-codes.md) ·
[Configuration](02-configuration.md) · [API stability](09-api-stability.md) ·
[Failure policy](../09-operations/05-failure-policy.md) ·
[Discarding entries](../05-pipeline-and-security/06-discarding-entries.md) ·
[Monitoring and troubleshooting](../09-operations/08-monitoring-and-troubleshooting.md)
