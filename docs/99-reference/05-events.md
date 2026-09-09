# 📚 Events

> Every event Sentinel dispatches: what it carries, where it fires, whether you can stop it, and in
> what order.

**On this page:** [The eleven events](#the-eleven-events) · [Event reference](#event-reference) · [Firing order](#firing-order) · [What fires under each mode](#what-fires-under-each-mode) · [Registering listeners](#registering-listeners) · [Worked listeners](#worked-listeners) · [⚠️ Pitfalls](#️-pitfalls) · [✅ Best practices](#-best-practices)

---

## The eleven events

All eleven live in `ElPandaPe\Sentinel\Events`. All eleven are `final readonly` classes with public
promoted properties — there are no getters and no setters, and the only mutable thing any of them
holds is the `AuditData` object three of them carry.

| Event | Fires when | Cancellable | Carries a payload |
|---|---|---|---|
| `Auditing` | The pipeline has finished transforming an entry and is offering it to the application | ✅ return `false` | ✅ the whole `AuditData` |
| `AuditDiscarded` | An entry was stopped, by any means | ❌ | ❌ identity only |
| `AuditCreating` | Immediately before `Ledger::write()` | ❌ announced, not consulted | ✅ the whole `AuditData` |
| `AuditCreated` | Immediately after the ledger assigned `sequence`, `previous_hash` and `hash` | ❌ | ✅ the written `Audit` |
| `Audited` | The capturing process is finished with the entry | ❌ | ✅ the `AuditData`, and the `Audit` when it settled here |
| `AuditWriteFailed` | A write did not complete | ❌ | ❌ identity and the `Throwable` |
| `LedgerDestinationFailed` | A secondary fanout destination refused an entry the primary already sealed | ❌ | ❌ coordinates and the `Throwable` |
| `BufferFlushFailed` | A flush under `mode = buffered` did not empty the buffer | ❌ | ❌ four counts and the `Throwable` |
| `AuditRestoring` | A restoration is about to write into the business model | ✅ return `false` | ❌ keys only |
| `AuditRestored` | A restoration committed | ❌ | ✅ the source `Audit`, the subject and the `RestoreResult` |
| `IntegrityVerificationFailed` | A verification found a break | ❌ | ❌ stream, reason, sequence, id |

> 📌 **Note.** Only `Auditing` and `AuditRestoring` are dispatched with Laravel's `until()`. Every
> other event goes out through `dispatch()`, so a listener's return value is discarded entirely.

> 🔒 **Security.** `Auditing` is the only event that hands a listener the before/after payload, and
> it is dispatched at the *end* of the pipeline — after `MaskSensitiveData` and
> `EncryptSensitiveData` have run. A listener therefore sees nothing in the clear that the ledger
> will not also hold. `AuditDiscarded`, `AuditWriteFailed` and `AuditRestoring` carry identity and
> keys precisely so that they cannot become the route by which plaintext leaves the pipeline.

---

## Event reference

### `Auditing`

Dispatched from `Pipeline::announce()`, in the capturing process, after every stage.

| Property | Type | Notes |
|---|---|---|
| `$audit` | `Data\AuditData` | Mutable. Every column of the entry as it will be written. |

Constant: `Auditing::REASON` — the string `'cancelled'`, the reason `AuditDiscarded` carries when a
listener refuses.

A listener returns `false` to refuse the entry, or `null`/nothing to let it through. What a listener
may usefully change is `$audit->metadata`, `$audit->tags` and `$audit->context`. What it may not
change is the subject: `Pipeline::announce()` snapshots `subject_type` and `subject_id` before the
dispatch and puts them back in a `finally`, because the subject names what the entry is about and
which chain signs it.

**Does not** cover exceptions. A listener that throws propagates straight out of the caller's
`save()` — the pipeline runs before every `try`/`catch` that consults `on_write_failure`, and no
`AuditDiscarded` goes out either.

### `AuditDiscarded`

Dispatched from `Pipeline::process()`, in the capturing process. The single exit door for every
stopped entry.

| Property | Type | Notes |
|---|---|---|
| `$auditType` | `string` | `model`, `custom`, `auth`, `transition`, … |
| `$event` | `string` | `created`, `updated`, `invoice.approved`, … |
| `$subjectType` | `?string` | Null for an entry with no subject |
| `$subjectId` | `?string` | |
| `$stage` | `class-string` | Whoever stopped it. `Auditing::class` when a listener refused |
| `$reason` | `string` | See below |

Method: `message(): string` renders `sentinel::sentinel.discarded.{reason}`. A reason the package
does not ship has no translation key, and `message()` then returns the raw reason string unchanged.

The four reasons the package produces:

| `$reason` | Set by | Means |
|---|---|---|
| `unchanged` | `Pipeline\Stages\FilterUnchanged` | An `updated` whose audited diff was empty |
| `policy` | `Pipeline\Stages\EnforcePolicies` | A `Sentinel::filter()` policy refused it |
| `cancelled` | `Auditing` listener returning `false` | The application refused it |
| `unspecified` | Any stage that returned `null` without calling `Discard::because()` | |

### `AuditCreating`

Dispatched from `Dispatch\Settlement::settle()` (and once per fresh entry in `settleBatch()`),
immediately before the ledger writes.

| Property | Type | Notes |
|---|---|---|
| `$audit` | `Data\AuditData` | Still without `sequence`, `previous_hash` or `hash` |

**Does not** accept a veto. Calling `app(Discard::class)->because(...)` from here throws
`Exceptions\DiscardException` — the sequence is assigned in the same operation as the write, so a
discard past this point would leave a gap `verifyIntegrity()` reports as tampering. Mutating the
entry here works mechanically but bypasses masking, encryption and the policy stages that already
ran, so the ledger seals whatever you put there.

### `AuditCreated`

Dispatched from `Settlement::settle()` (and per written entry in `settleBatch()`), immediately after
the write, in whichever process the ledger assigned identity.

| Property | Type | Notes |
|---|---|---|
| `$entry` | `Models\Audit` | Has `stream`, `sequence`, `previous_hash`, `hash`; `exists` is `true` |

From here the row is immutable: `Audit::save()`, `update()` and `delete()` throw
`Exceptions\ImmutableAuditException`.

### `Audited`

Dispatched from `Dispatch\Dispatcher::handed()`, once per accepted hand-over, in the process that
captured the fact.

| Property | Type | Notes |
|---|---|---|
| `$audit` | `Data\AuditData` | The entry as it was handed over |
| `$entry` | `?Models\Audit` | The row, only when capture and settlement happened in the same place |

A `null` `$entry` means "settled elsewhere", not "not settled". Under `sync`, `AuditCreated` fires
first and `Audited` second — `tests/Events/AuditedTest.php` pins that order.

**Does not** fire for an entry the pipeline discarded, and does not fire when the hand-over was
refused (`Handover::refused()`).

### `AuditWriteFailed`

Dispatched from `Capture\WriteFailure::announce()`, which runs on both branches — in-request and
after-commit — and *before* the failure policy is consulted. You are told regardless of whether the
request is taken down.

| Property | Type | Notes |
|---|---|---|
| `$auditType` | `string` | |
| `$event` | `string` | |
| `$subjectType` | `?string` | |
| `$subjectId` | `?string` | |
| `$transactionId` | `?string` | The business-transaction correlation, when there was one |
| `$failure` | `Throwable` | The original exception |

Method: `message(): string` renders `sentinel::sentinel.ledger.write_failed`.

**Does not** mean "nothing was written". Under a strict fanout it is raised *after* the primary
ledger sealed and stored the entry — `LedgerDestinationFailed` is the event that names the entry
which did land.

### `LedgerDestinationFailed`

Dispatched from `Ledger\FanoutLedger::fanOut()` when a secondary destination throws — deliberately
before the `FanoutPolicy::Strict` rethrow, so that strict, the policy that most needs the
announcement, still gets it.

| Property | Type | Notes |
|---|---|---|
| `$destination` | `class-string` | The secondary ledger that refused |
| `$stream` | `string` | |
| `$sequence` | `int` | |
| `$auditId` | `string` | The entry the primary already holds |
| `$reason` | `Throwable` | |

Method: `message(): string` renders `sentinel::sentinel.ledger.destination_failed`.

### `BufferFlushFailed`

Dispatched from `Buffer\Flusher` on two paths: a batch that failed to settle and was put back, and a
buffer that could not even be read.

| Property | Type | Notes |
|---|---|---|
| `$taken` | `int` | Entries removed from the buffer during this flush |
| `$settled` | `int` | Entries the ledger wrote |
| `$returned` | `int` | The failed batch, put back whole |
| `$reason` | `Throwable` | |

Methods: `skipped(): int` is derived as `$taken - $settled - $returned`, so the four counts cannot
contradict each other. `message(): string` renders `sentinel::sentinel.buffer.flush_failed`.

It names a batch, not an entry, and so carries no audit identity — nothing in a failed flush was
ever written. On the threshold trigger an `AuditWriteFailed` goes out alongside it, but that one
names the entry that *arrived*: `BufferStrategy::hand()` pushes the entry into the buffer before it
reads the thresholds, so the entry named there is by construction the one a failed flush cannot
cost. `BufferFlushFailed` names what was actually at stake.

### `AuditRestoring`

Dispatched with `until()` from `Restore\Restorer::restore()` and `restoreRelationship()`.

| Property | Type | Notes |
|---|---|---|
| `$entry` | `Models\Audit` | The entry being restored *from* |
| `$subject` | `Illuminate\Database\Eloquent\Model` | The record about to be written |
| `$applying` | `list<string>` | Keys only, never values |
| `$relation` | `?string` | The relation name on the relationship path; `null` on the field path |

Returning `false` yields `RestoreResult::refused(Omission::Cancelled)` and nothing is written. This
is the whole of Sentinel's answer to *who may restore*: the package defines no gate of its own.

### `AuditRestored`

Dispatched from a commit callback registered in `Restorer::settle()` *after* the ledger's own, which
is why `$result->entry` is always populated here.

| Property | Type | Notes |
|---|---|---|
| `$entry` | `Models\Audit` | The entry that was restored from |
| `$subject` | `Illuminate\Database\Eloquent\Model` | |
| `$result` | `Restore\RestoreResult` | `$applied`, `$skipped`, `$refused`, `$entry` — the new `restore` entry |

**Does not** fire on a rollback.

### `IntegrityVerificationFailed`

Dispatched from `Integrity\Verifier::announce()` (hash, link, sequence gap, signature, checkpoint)
and `Integrity\Projections::announce()` (projection mismatch).

| Property | Type | Notes |
|---|---|---|
| `$stream` | `string` | |
| `$reason` | `Enums\IntegrityBreak` | One of six cases |
| `$sequence` | `int` | On the checkpoint paths this carries the anchor's `from` |
| `$auditId` | `string` | On the checkpoint paths this carries the anchor's root hash, not an audit id |

Method: `message(): string` delegates to `IntegrityBreak::message()`.

> ⚠️ **Warning.** `IntegrityBreak::ProjectionMismatch` dispatches this event for a chain that is
> perfectly intact — the relation index is a projection the chain does not cover, and the shipped
> sentence says so. A listener that pages an on-call engineer for any
> `IntegrityVerificationFailed` will treat a stale index as a tamper alarm. Match on the enum case.

---

## Firing order

### A normal write

Under `mode = sync`, outside a transaction:

```text
Model::save()
  └─ Recorder::record()
       ├─ Pipeline: FilterUnchanged → ResolveContext → ResolveTags → NormalizeData
       │            → MaskSensitiveData → EncryptSensitiveData → EnforcePolicies
       ├─ ① Auditing                (until — the last place a refusal is free)
       ├─ (a declared actor is reapplied here, after the pipeline)
       ├─ ② AuditCreating           (Settlement, immediately before Ledger::write())
       │       Ledger assigns stream, sequence, previous_hash, hash
       ├─ ③ AuditCreated            (Settlement, immediately after)
       └─ ④ Audited                 ($entry is the row)
```

### A discarded write

Whether the stop came from `FilterUnchanged`, from `EnforcePolicies`, or from an `Auditing` listener
returning `false`, the entry leaves by one door and spends no sequence:

```text
Model::save()
  └─ Pipeline
       ├─ a stage returns null, or ① Auditing returned false
       └─ ② AuditDiscarded          (stage + reason; no payload)

  ✗ no AuditCreating, no AuditCreated, no Audited
  ✗ no sequence consumed — the chain has no gap
```

### A failed write

Under `mode = sync`, in-request:

```text
Model::save()
  └─ ① Auditing → ② AuditCreating
       Ledger::write() throws
  └─ SyncStrategy catches → WriteFailure::inRequest()
       ├─ ③ AuditWriteFailed        (always, before the policy is read)
       ├─ on_write_failure = throw → rethrow the original exception into save()
       └─ on_write_failure = log   → log through log_channel, request continues

  ✗ no Audited — the hand-over was refused
```

On the deferred branch (`transactions.after_commit = true` and a transaction was open),
`WriteFailure::afterCommit()` runs instead: it announces `AuditWriteFailed` and logs, and **has no
throw branch at all**. See [Failure policy](../09-operations/05-failure-policy.md).

### A restore

```text
$entry->restore() / $entry->restoreRelationship('items')
  ├─ ① AuditRestoring              (until — return false to refuse)
  └─ one database transaction
       ├─ the subject is written with auditing suspended
       ├─ a new `restore` entry goes through the full pipeline:
       │     ② Auditing → ③ AuditCreating → ④ AuditCreated → ⑤ Audited
       └─ on commit: ⑥ AuditRestored   ($result->entry is populated)
```

---

## What fires under each mode

`mode` decides *where* the ledger half of the cycle happens. The pipeline half always happens in the
capturing process, because the context only exists there.

| Event | `sync` | `queue` | `buffered` |
|---|---|---|---|
| `Auditing` | capturing process | capturing process | capturing process |
| `AuditDiscarded` | capturing process | capturing process | capturing process |
| `AuditCreating` | capturing process | the worker | whichever process flushes |
| `AuditCreated` | capturing process | the worker | whichever process flushes |
| `Audited` | capturing process, `$entry` set | capturing process, `$entry` **null** | capturing process, `$entry` **null** |
| `AuditWriteFailed` | on any settle failure | **only** when the enqueue is refused | on a flush failure the capture triggered |
| `BufferFlushFailed` | never | never | on every failed flush |
| `LedgerDestinationFailed` | capturing process | the worker | the flushing process |

> ⚠️ **Warning.** Under `queue`, a write that fails *inside the worker* announces nothing at all —
> `Jobs\SettleAudit::handle()` catches nothing on purpose. The queue is the failure policy there:
> it retries under the same `capture_id`, which the ledger's unique index refuses to settle twice,
> and what still does not land ends in `failed_jobs`.

Under `buffered`, the flush is triggered from five places and all five reach
`Buffer\Flusher::flush()`: an arriving entry that crosses the size or interval threshold, the
application's `terminating` callback, Laravel's `WorkerStopping` event, and `php artisan
sentinel:flush`. The last three are announced by `BufferFlushFailed` and by nothing else.

### Transactions

With `transactions.after_commit` at its default `true`, and an open transaction on the subject's
connection, `Dispatch\Dispatcher::dispatch()` registers the whole hand-over as an `afterCommit`
callback. That splits the cycle:

| Event | Inside the transaction | At the commit |
|---|---|---|
| `Auditing` | ✅ dispatched at capture | |
| `AuditDiscarded` | ✅ dispatched at capture | |
| `AuditCreating` | | ✅ |
| `AuditCreated` | | ✅ |
| `Audited` | | ✅ |
| `AuditRestored` | | ✅ |

A rollback discards the callback, so none of the commit-side events fire and no entry exists.
`Auditing` and `AuditDiscarded` will already have fired — they describe an intention, not a fact.

Set `transactions.after_commit` to `false` and every event returns to the capture point, failure
policy included. See [Configuration](02-configuration.md).

---

## Registering listeners

Sentinel registers no listeners of its own for these events, and publishes no listener stubs.
Register yours the way you register any other Laravel listener — a closure in a service provider's
`boot()`, or an invokable class.

```php
use ElPandaPe\Sentinel\Events\AuditCreated;
use ElPandaPe\Sentinel\Events\Auditing;
use ElPandaPe\Sentinel\Events\AuditWriteFailed;
use App\Listeners\IndexAuditEntry;
use App\Listeners\RefuseSessionAudits;
use Illuminate\Support\Facades\Event;

public function boot(): void
{
    Event::listen(Auditing::class, RefuseSessionAudits::class);
    Event::listen(AuditCreated::class, IndexAuditEntry::class);

    Event::listen(AuditWriteFailed::class, function (AuditWriteFailed $event): void {
        report($event->failure);
    });
}
```

> ⚠️ **Warning.** Every one of these is dispatched inline, on the write path, inside the request
> that saved the model. A listener that calls an HTTP API is charged to your user. Queue the work
> and keep the listener itself to a few microseconds.

---

## Worked listeners

### Refusing an entry, with a reason a human will read

```php
use ElPandaPe\Sentinel\Events\AuditDiscarded;
use ElPandaPe\Sentinel\Events\Auditing;
use ElPandaPe\Sentinel\Pipeline\Discard;
use Illuminate\Support\Facades\Event;

Event::listen(Auditing::class, function (Auditing $event): ?bool {
    if ($event->audit->subject_type !== \App\Models\SessionToken::class) {
        return null;
    }

    app(Discard::class)->because('session tokens are not kept');

    return false;
});

Event::listen(AuditDiscarded::class, function (AuditDiscarded $event): void {
    logger()->info($event->message(), [
        'stage' => $event->stage,   // ElPandaPe\Sentinel\Events\Auditing
        'reason' => $event->reason, // 'session tokens are not kept'
    ]);
});
```

Returning `null` is load-bearing. The event is dispatched with `until()`, so *any* non-null return
halts every listener behind you — and only `false` actually cancels. Because the reason is not one
the package ships, `message()` hands it back verbatim instead of a translated sentence.

### Enriching an entry without changing what it is about

```php
use ElPandaPe\Sentinel\Events\Auditing;
use Illuminate\Support\Facades\Event;

Event::listen(Auditing::class, function (Auditing $event): void {
    $event->audit->metadata = [
        ...$event->audit->metadata ?? [],
        'release' => config('app.version'),
    ];

    $event->audit->tags[] = 'reviewed';

    // $event->audit->subject_id = '…';  ← silently undone in a finally block
});
```

`metadata`, `tags` and `context` belong to the listener. `subject_type` and `subject_id` are
snapshotted before the dispatch and restored afterwards, whatever the listener assigned. Note also
that the actor you see here is the **resolved** one: an actor named through
`Sentinel::event(...)->actor($user)` is reapplied by `Capture\Recorder::attribute()` *after* the
pipeline, so a listener filtering on `actor_id` will see whoever was authenticated instead.

### Telling "an entry exists" apart from "this process is done"

```php
use ElPandaPe\Sentinel\Events\AuditCreated;
use ElPandaPe\Sentinel\Events\Audited;
use Illuminate\Support\Facades\Event;

// Fires wherever the ledger assigned identity. Under `queue`, that is a worker.
Event::listen(AuditCreated::class, function (AuditCreated $event): void {
    IndexAuditEntry::dispatch($event->entry->id, $event->entry->stream, $event->entry->sequence);
});

// Fires where the capture happened, once per capture.
Event::listen(Audited::class, function (Audited $event): void {
    if (! $event->entry instanceof \ElPandaPe\Sentinel\Models\Audit) {
        return; // settled elsewhere — never "not settled"
    }

    Metrics::increment('sentinel.settled_inline', ['event' => $event->audit->event]);
});
```

> 📌 **Note.** Do not throw from an `AuditCreated` listener under `sync`. It is dispatched inside
> `SyncStrategy::inRequest()`'s `try` block, so your exception is caught and handed to
> `WriteFailure` — announcing `AuditWriteFailed` and suppressing `Audited` for an entry that is
> already in the database with its sequence and hash.

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| A later `Auditing` listener never runs, and the entry was written anyway | The event is dispatched with `until()`. Any non-null return — `true`, a string, an object — halts the rest without cancelling; only `=== false` cancels | Return `null` or nothing from every path that means "no opinion" |
| `AuditDiscarded::message()` reads "Stage X discarded the … entry" instead of your reason | The stage or listener returned `null`/`false` without calling `Discard::because()`, so the reason is `unspecified` | Call `app(Discard::class)->because('…')` immediately before returning |
| `LogicException` mentioning `verifyIntegrity()` out of `save()` | `Discard::because()` was called from an `AuditCreating`/`AuditCreated` listener or a model observer — outside a pipeline pass. It throws `DiscardException` | Move the refusal into a pipeline stage or an `Auditing` listener |
| A `DiscardException` from `AuditCreating` also produced an `AuditWriteFailed` | The exception is raised inside `Settlement::settle()`, caught by the strategy, and routed through `WriteFailure`, which announces before it consults the policy | Nothing to catch — fix the listener. The entry is not written and the chain stays contiguous |
| An exception from an `Auditing` listener took the request down even with `on_write_failure = log` | The pipeline runs in `Recorder::prepared()`, before every `try`/`catch` that reads the policy | Catch inside your own listener. The policy covers ledger writes, not pipeline code |
| A log line says "The deferred write … did not complete" for a failure that happened in the request | `AuditWriteFailed` has one translation key, `ledger.write_failed`, and its shipped sentence hardcodes "deferred" while the event now fires on both branches | Read `$event->failure` and `$event->transactionId` rather than the rendered sentence |
| `Audited` and `AuditWriteFailed` both fired for one capture under `buffered` | `BufferStrategy::hand()` always returns `Handover::accepted()` — the entry *is* in the buffer — while the failed threshold flush went through `WriteFailure::inRequest()` | Under `buffered`, count on `BufferFlushFailed` and treat `AuditWriteFailed` as advisory |
| `BufferFlushFailed::skipped()` is non-zero and the sentence says "had already settled elsewhere" | `skipped()` counts both entries the ledger deduplicated (harmless) and a batch the buffer refused to take back (lost outright). They are indistinguishable from the event | Alert on any non-zero `skipped()` under `buffered`; the two cases cannot be told apart after the fact |
| A ledger failure in a queue worker produced no event | `Jobs\SettleAudit` catches nothing by design | Watch `failed_jobs` and the worker's own log; `AuditWriteFailed` under `queue` means only that the enqueue was refused |
| `AuditWriteFailed` fired but the row is in `sentinel_audits` | A `FanoutPolicy::Strict` secondary destination threw after the primary sealed and stored the entry | Read `LedgerDestinationFailed` — it is the only event naming the entry that did land |
| An on-call page for `IntegrityVerificationFailed` on a chain that verifies clean | `IntegrityBreak::ProjectionMismatch` — a stale relation projection, which the chain does not cover | `match` on `$event->reason` and route `ProjectionMismatch` to a warning |
| `AuditRestored::$result->entry` is set, but the `RestoreResult` returned by `restore()` had `null` | Inside an application-owned transaction the call returns before the commit; the event is dispatched from a callback registered after the ledger's own | Read the entry from the event, or from the `$settled` callback |
| A listener assigned `subject_id` on `Auditing` and the entry has the old one | `Pipeline::announce()` restores both subject columns in a `finally` | The subject is settled at capture; change what the capture passes, not what the listener sees |

---

## ✅ Best practices

✅ **Do** — return `null` from any `Auditing` listener path that means "no opinion". `until()` stops
at the first non-null response, so anything else silently disables the listeners behind you.

```php
Event::listen(Auditing::class, function (Auditing $event): ?bool {
    if ($event->audit->subject_type !== \App\Models\Invoice::class) {
        return null; // not mine — let the next listener decide
    }

    return $event->audit->event === 'read' ? false : null;
});
```

❌ **Don't** — return `true` to mean "keep it". It halts the remaining listeners and lets the entry
through, which looks exactly like a listener that was never registered.

```php
Event::listen(Auditing::class, function (Auditing $event): bool {
    return $event->audit->event !== 'read'; // every later listener is now dead
});
```

---

✅ **Do** — name your reason before refusing, so the discard reads as a sentence.

```php
use ElPandaPe\Sentinel\Pipeline\Discard;

app(Discard::class)->because('heartbeat rows are not kept');

return false;
```

❌ **Don't** — try to refuse from `AuditCreating`. It is announced, not consulted, and
`Discard::because()` throws `DiscardException` there because the sequence is assigned in the same
operation as the write.

```php
use ElPandaPe\Sentinel\Events\AuditCreating;
use ElPandaPe\Sentinel\Pipeline\Discard;

Event::listen(AuditCreating::class, function (AuditCreating $event): void {
    app(Discard::class)->because('too late'); // LogicException out of save()
});
```

---

✅ **Do** — treat a `null` `Audited::$entry` as "settled elsewhere" and look for the real coordinate
on `AuditCreated`.

```php
Event::listen(AuditCreated::class, function (AuditCreated $event): void {
    ArchiveIndex::dispatch($event->entry->stream, $event->entry->sequence);
});
```

❌ **Don't** — treat it as a failed write. Under `queue` and `buffered` it is the normal outcome; a
write that did not complete announces `AuditWriteFailed` instead.

```php
Event::listen(Audited::class, function (Audited $event): void {
    if ($event->entry === null) {
        Alerts::critical('audit lost'); // fires on every entry under queue
    }
});
```

---

✅ **Do** — queue anything slow a listener does, and keep the listener itself to a field read.

```php
Event::listen(AuditCreated::class, function (AuditCreated $event): void {
    PushToSiem::dispatch($event->entry->id)->afterCommit();
});
```

❌ **Don't** — do the work inline. Every one of these events is dispatched on the write path, so the
latency lands on the user who saved the model.

```php
Event::listen(AuditCreated::class, function (AuditCreated $event): void {
    Http::post('https://siem.internal/events', $event->entry->toArray()); // charged to the request
});
```

---

✅ **Do** — match on the `IntegrityBreak` case, not on the event class, when reacting to a
verification failure.

```php
use ElPandaPe\Sentinel\Enums\IntegrityBreak;
use ElPandaPe\Sentinel\Events\IntegrityVerificationFailed;

Event::listen(IntegrityVerificationFailed::class, function (IntegrityVerificationFailed $event): void {
    match ($event->reason) {
        IntegrityBreak::ProjectionMismatch => Ops::warn($event->message()),
        default => Ops::page($event->message()),
    };
});
```

❌ **Don't** — page on every `IntegrityVerificationFailed`. A stale relation projection is not a
tampered chain, and treating it as one is how an alarm stops being read.

```php
Event::listen(IntegrityVerificationFailed::class, fn () => Ops::page('chain broken'));
```

---

✅ **Do** — gate restores on `AuditRestoring`. It is the package's only authorization hook; without a
listener there is no restriction whatsoever.

```php
use ElPandaPe\Sentinel\Events\AuditRestoring;

Event::listen(AuditRestoring::class, function (AuditRestoring $event): ?bool {
    return auth()->user()?->can('restore', $event->subject) === true ? null : false;
});
```

❌ **Don't** — assume a configuration switch guards it. Sentinel defines no gate of its own, on
purpose: restoring writes into your business model and the policy is yours.

```php
// config/sentinel.php — there is no such key
'restore' => ['require_permission' => true],
```

---

✅ **Do** — put only identity into whatever you forward from a failure event.

```php
Event::listen(AuditWriteFailed::class, function (AuditWriteFailed $event): void {
    report($event->failure);
    Ops::note($event->auditType, $event->event, $event->subjectType, $event->subjectId);
});
```

❌ **Don't** — reach back for the payload to enrich the alert. `Auditing` is the only event holding
one, and it holds it only after masking and encryption ran; the failure events omit it deliberately.

```php
Event::listen(Auditing::class, function (Auditing $event): void {
    Cache::put('last_audit_payload', $event->audit->before); // now outside the pipeline's reach
});
```

---

**See also:** [Events and listeners](../09-operations/04-events-and-listeners.md) · [Failure policy](../09-operations/05-failure-policy.md) · [The write pipeline](../05-pipeline-and-security/01-the-write-pipeline.md) · [Discarding entries](../05-pipeline-and-security/06-discarding-entries.md) · [Enums](04-enums.md) · [Exceptions](06-exceptions.md) · [The buffered mode](../09-operations/02-the-buffered-mode.md) · [Running audits on a queue](../09-operations/03-queues.md) · [Restoring state](../06-reading/08-restoring-state.md) · [Verification](../07-integrity/06-verification.md) · [Fanout](../11-extending/05-fanout.md)
