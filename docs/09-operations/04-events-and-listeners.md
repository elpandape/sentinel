# ⚙️ Events and listeners

> The eleven events an entry's life announces, which of them you may refuse and until when, and how
> to write a listener that does not turn the audit engine into the reason a request fails.

**On this page:** [The eleven events](#the-eleven-events) ·
[Where each one fires](#where-each-one-fires) ·
[Cancelling is only legal before the entry has identity](#cancelling-is-only-legal-before-the-entry-has-identity) ·
[AuditDiscarded carries no payload](#auditdiscarded-carries-no-payload) ·
[AuditCreated is not Audited](#auditcreated-is-not-audited) ·
[Writing a listener that does not become the problem](#writing-a-listener-that-does-not-become-the-problem) ·
[Where a throwing listener lands](#where-a-throwing-listener-lands) ·
[Queueing a listener](#queueing-a-listener) ·
[Worked listeners](#worked-listeners)

---

## The eleven events

All eleven live in `ElPandaPe\Sentinel\Events\`. They are ordinary Laravel events: register with
`Event::listen()`, a class-based listener, or a subscriber. Nine of them are dispatched with
`Illuminate\Contracts\Events\Dispatcher::dispatch()` and are announcements. Two are dispatched with
`until()` and are questions.

| Event | Fires | Carries | Cancellable |
|---|---|---|---|
| `Auditing` | At the end of the pipeline, after all seven stages, before the entry has identity | `AuditData $audit` — the full entry, masked, hashed and encrypted | **Yes** — return `false` |
| `AuditDiscarded` | Once an entry has been stopped, whoever stopped it | `auditType`, `event`, `subjectType`, `subjectId`, `stage`, `reason` | No |
| `AuditCreating` | Immediately before `Ledger::write()`, per entry | `AuditData $audit` — still no sequence, no hash | No — announced, not consulted |
| `AuditCreated` | Immediately after the write, per entry | `Audit $entry` — with `stream`, `sequence`, `previous_hash`, `hash` | No |
| `Audited` | Once per accepted hand-over, in the process that captured | `AuditData $audit`, `?Audit $entry` | No |
| `AuditWriteFailed` | On a write that did not complete, before the failure policy decides | `auditType`, `event`, `subjectType`, `subjectId`, `transactionId`, `Throwable $failure` | No |
| `LedgerDestinationFailed` | When a secondary fanout destination refuses an entry the primary already sealed | `destination`, `stream`, `sequence`, `auditId`, `Throwable $reason` | No |
| `BufferFlushFailed` | When a buffered flush does not empty the buffer | `taken`, `settled`, `returned`, `Throwable $reason`, plus `skipped()` | No |
| `IntegrityVerificationFailed` | When a verification finds a break | `stream`, `IntegrityBreak $reason`, `sequence`, `auditId` | No |
| `AuditRestoring` | Before a restoration touches the record | `Audit $entry`, `Model $subject`, `list<string> $applying`, `?string $relation` | **Yes** — return `false` |
| `AuditRestored` | After the commit that made the restoration true | `Audit $entry`, `Model $subject`, `RestoreResult $result` | No |

Five of them can build their own sentence: `AuditDiscarded`, `AuditWriteFailed`,
`LedgerDestinationFailed`, `BufferFlushFailed` and `IntegrityVerificationFailed` each expose
`message()`, which renders a line from `resources/lang/{en,es}` in the application's current locale.
The other six carry objects and no sentence.

> 📌 **Note.** The eleven events are part of the frozen public surface. Their class names,
> constructor arguments and property names do not change inside a major version. See
> [API stability](../99-reference/09-api-stability.md).

---

## Where each one fires

An event fires in whichever process reached the code that dispatches it, and the three performance
modes move that code to three different places. `Pipeline` and `Dispatch\Dispatcher` always run where
the capture happened; `Dispatch\Settlement` runs wherever the ledger write happens.

| Event | `sync` | `queue` | `buffered` |
|---|---|---|---|
| `Auditing` | Capturing process | Capturing process | Capturing process |
| `AuditDiscarded` | Capturing process | Capturing process | Capturing process |
| `AuditCreating` / `AuditCreated` | Capturing process | The worker running `SettleAudit` | Whichever process flushes the batch |
| `Audited` | Capturing process, `$entry` set | Capturing process, `$entry` **null** | Capturing process, `$entry` **null** |
| `AuditWriteFailed` | The ledger refused the write | Only the **enqueue** was refused | The flush that the arriving entry triggered failed |
| `BufferFlushFailed` | Never | Never | Every flush trigger |
| `LedgerDestinationFailed` | Wherever the fanout write happened | | |
| `IntegrityVerificationFailed` | Not on the write path at all — a `Sentinel::verifyIntegrity()` call or `php artisan sentinel:verify` | | |
| `AuditRestoring` / `AuditRestored` | The process calling `restore()`; `AuditRestored` from a commit callback | | |

Three consequences worth internalising:

- **Inside a transaction, settlement waits for the commit.** With the default
  `transactions.after_commit = true`, `AuditCreating`, `AuditCreated` and `Audited` are dispatched
  from a commit callback. A rollback means none of them ever fire — and nothing was written either.
  `Auditing` and `AuditDiscarded` do not wait: the pipeline runs at capture, because that is when the
  context exists.
- **Under `queue`, a ledger failure announces nothing.** `Jobs\SettleAudit::handle()` catches nothing
  on purpose — the queue's retry and `failed_jobs` machinery is the failure policy in a worker.
  `AuditWriteFailed` under `queue` only ever means the bus refused the job.
- **A retry that finds the capture already settled dispatches nothing.**
  `Settlement::settleOnce()` returns `null` without reaching `settle()` when the `capture_id` is
  already in the ledger, so neither `AuditCreating` nor `AuditCreated` goes out a second time.

→ [Performance modes](01-performance-modes.md) · [The buffered mode](02-the-buffered-mode.md) ·
[Running audits on a queue](03-queues.md)

---

## Cancelling is only legal before the entry has identity

`Ledger::write()` reads the tail of the stream, assigns `sequence`, copies the tail's `hash` into
`previous_hash` and seals the new entry's own `hash` — all in one operation. Before that moment the
entry is a mutable `Data\AuditData` that nothing has counted, and dropping it costs nothing. After
it, dropping it would leave a hole in a contiguous sequence, which `Integrity\Verifier` reports as
`IntegrityBreak::SequenceGap` — the same finding it reports for a row somebody deleted to hide it.

So the boundary is not a convention. It is enforced:

```
   capture ─▶ pipeline stages ─▶ Auditing ─▶ AuditCreating ─▶ write ─▶ AuditCreated ─▶ Audited
                    │                │            │
                    └── refuse ──────┘            └── DiscardException from here on
                         (free)
```

### The two cancellable events

`Auditing` and `AuditRestoring` are the only two dispatched with `until()`. `Auditing` is dispatched
from `Pipeline::announce()` — last, after `FilterUnchanged`, `ResolveContext`, `ResolveTags`,
`NormalizeData`, `MaskSensitiveData`, `EncryptSensitiveData` and `EnforcePolicies`. A listener
therefore sees the entry exactly as the ledger will hold it: nothing in the clear that the row will
not also carry.

```php
use ElPandaPe\Sentinel\Events\Auditing;
use ElPandaPe\Sentinel\Pipeline\Discard;
use Illuminate\Support\Facades\Event;

Event::listen(Auditing::class, static function (Auditing $event): ?bool {
    if ($event->audit->subject_type !== \App\Models\DeviceReading::class) {
        return null;                          // no opinion — see the warning below
    }

    app(Discard::class)->because('device telemetry is not kept');

    return false;
});
```

Refusing here spends no sequence: the entry never reaches the ledger, and the next entry written to
that stream takes the number this one would have had.

> ⚠️ **Warning.** `until()` returns the **first non-null response**, and Sentinel checks it with
> `=== false`. A listener that returns `true`, a string, or an object stops every listener behind it
> from running *and lets the entry through* — which looks exactly like those listeners never having
> been registered. Return `null` (or nothing at all) from every path that means "no opinion".

There is a second, separate Laravel rule worth knowing: in `Illuminate\Events\Dispatcher::invokeListeners()`,
a listener returning `false` breaks the loop on **any** event, halting or not. So returning `false`
from an `AuditCreated` or `Audited` listener does not cancel anything — it silences the listeners
registered after yours.

### Past the boundary

`AuditCreating` is dispatched from `Settlement::settle()` with `dispatch()`, not `until()`. It is the
last thing anyone hears about an entry with no identity, and its return value is discarded. Calling
`Discard::because()` from a listener on it throws `DiscardException::outsideThePipeline()`:

```
Sentinel was asked to discard an entry outside the pipeline, giving [too late] as the reason.
The ledger assigns sequence once the pipeline has already run, so a discard past that point
would leave a gap that verifyIntegrity() reports as tampering. Discard from a stage instead.
```

What you will actually observe is stranger than a bare exception, and it is worth knowing before you
are debugging it at three in the morning. The `DiscardException` is raised **inside** the dispatch
strategy's `try`, so `Capture\WriteFailure::inRequest()` gets it first — and that method announces
`AuditWriteFailed` *before* it consults the policy. So one programming error produces:

1. an `AuditWriteFailed` event naming an entry that was never written, then
2. a `LogicException` out of the caller's `save()` (under the default `on_write_failure = throw`).

The chain stays contiguous — no sequence was spent — but your alerting will have paged someone about
a failed write that never happened.

### The subject is not a listener's to rewrite

`Pipeline::announce()` snapshots `subject_type` and `subject_id` before the dispatch and restores
them in a `finally`. Assigning either from an `Auditing` listener is silently undone. The subject
names what the entry is about *and* decides which stream signs it, and both were settled before a
listener saw the entry.

```php
Event::listen(Auditing::class, static function (Auditing $event): void {
    $event->audit->metadata = [
        ...$event->audit->metadata ?? [],
        'release' => config('app.version'),
    ];

    $event->audit->tags[] = 'reviewed';

    // $event->audit->subject_id = '999';   // assigned, then put back. Do not try.
});
```

`metadata`, `tags` and `context` belong to the listener. Everything the pipeline resolved —
`before`, `after`, `changes`, `encryption` — is already sealed in the shape the ledger will hash.

→ [Discarding entries](../05-pipeline-and-security/06-discarding-entries.md) ·
[The write pipeline](../05-pipeline-and-security/01-the-write-pipeline.md)

---

## AuditDiscarded carries no payload

Every stopped entry leaves by exactly one door. A stage returning `null`, `FilterUnchanged` finding
nothing audited changed, `EnforcePolicies` refusing, and an `Auditing` listener returning `false` all
converge on the same dispatch in `Pipeline::process()`.

What the event carries is identity and nothing else: `auditType`, `event`, `subjectType`,
`subjectId`, `stage` and `reason`. No `before`, no `after`, no `changes`, no `metadata`.

That is deliberate and it is a security property, not an oversight. `FilterUnchanged` is the **first**
stage — it runs before `MaskSensitiveData` and before `EncryptSensitiveData`. An `AuditDiscarded`
carrying the payload would hand every listener the untransformed plaintext of exactly the entries the
pipeline never got round to transforming. A listener that logged the event, or put it on a queue,
would be the route the pipeline exists to close.

| `reason` | Set by | Rendered sentence |
|---|---|---|
| `unchanged` | `FilterUnchanged::REASON` | "The :event to :type :id changed nothing that is audited, so no entry was written." |
| `policy` | `EnforcePolicies::REASON` | "A policy discarded the :event entry for :type :id before it reached the ledger." |
| `cancelled` | `Auditing::REASON` | "A listener cancelled the :event entry for :type :id before it reached the ledger." |
| `unspecified` | A stage returned `null` without calling `Discard::because()` | "Stage :stage discarded the :event entry for :type :id before it reached the ledger." |
| anything else | Your own `Discard::because('…')` | Returned **verbatim** — there is no translation key for it |

`stage` is the class-string of whoever stopped it, and `Auditing::class` when a listener did. The
first stage to return `null` owns the discard; the first reason given wins.

```php
use ElPandaPe\Sentinel\Events\AuditDiscarded;

Event::listen(AuditDiscarded::class, static function (AuditDiscarded $event): void {
    logger()->info($event->message(), [
        'stage' => $event->stage,
        'reason' => $event->reason,
        'subject' => $event->subjectType.'#'.$event->subjectId,
    ]);
});
```

> 💡 **Tip.** Always call `app(Discard::class)->because('a sentence a human will read')` before
> returning `false`. Without it the entry still leaves through `AuditDiscarded`, but with reason
> `unspecified`, which renders a generic line naming the stage instead of your reason.

---

## AuditCreated is not Audited

They are different facts and both exist because the difference matters.

| | `AuditCreated` | `Audited` |
|---|---|---|
| Means | An entry has identity in the chain | This process is finished with this entry |
| Dispatched from | `Dispatch\Settlement` — wherever the ledger wrote | `Dispatch\Dispatcher::handed()` — where the capture happened |
| Under `queue` | In the worker | In the request |
| Carries | `Audit $entry`, always | `AuditData $audit`, and `?Audit $entry` |

Under `sync` both fire in the same process, back to back, `AuditCreated` first — pinned by the test
*"arrives after the entry has identity, never before"* in `tests/Events/AuditedTest.php`.

`Audited::$entry` is `null` under `queue` and `buffered`. **Null means "settled elsewhere", never
"not settled".** A write that did not complete announces `AuditWriteFailed` and yields
`Handover::refused()`, and the dispatcher then never reaches the `Audited` dispatch at all —
`tests/Events/AuditedTest.php` pins that too.

```php
use ElPandaPe\Sentinel\Events\AuditCreated;
use ElPandaPe\Sentinel\Events\Audited;

// "An entry exists in the chain." Under `queue`, this runs in a worker.
Event::listen(AuditCreated::class, static function (AuditCreated $event): void {
    Search::index($event->entry->id, $event->entry->stream, $event->entry->sequence);
});

// "This request is done with this entry." Always where the capture happened.
Event::listen(Audited::class, static function (Audited $event): void {
    if ($event->entry === null) {
        return;                               // settled elsewhere, not lost
    }

    Metrics::increment('audits.settled_inline', ['event' => $event->audit->event]);
});
```

> ⚠️ **Warning.** Under `buffered` with `on_write_failure = log`, one capture can produce *both*
> `AuditWriteFailed` and `Audited`. `Dispatch\BufferStrategy::hand()` pushes the entry, tries a
> flush, and returns `Handover::accepted()` unconditionally — correctly, because the arriving entry
> *is* in the buffer whatever the flush did — while the failed flush of the **earlier** batch has
> already been routed through `WriteFailure`. A listener treating the two as mutually exclusive will
> double-count. Under `buffered`, read `BufferFlushFailed` instead.

---

## Writing a listener that does not become the problem

Every one of these events is dispatched **inline, synchronously, on the write path**. `Auditing` and
`AuditDiscarded` are dispatched inside `Pipeline::process()`, which runs inside the caller's
`save()`. `AuditCreating`, `AuditCreated` and `Audited` are dispatched inside the dispatch strategy
or the commit callback. Whatever your listener does, the user's request pays for it.

### Never write to the ledger from a listener

Calling `Sentinel::event(...)->record()`, or saving an audited model, from a listener starts a
**nested capture** inside the one that is running.

Sentinel survives that structurally — `Pipeline\Discard::begin()` pushes the current pass onto a
stack and `end()` pops it, so an inner pass suspends the outer one rather than replacing it, which is
what the test *"keeps the window closed for whatever the listener did to a model of its own"*
verifies. What Sentinel cannot do is stop the recursion: the nested capture dispatches `Auditing`
again, which calls your listener again, which records again.

It is worse from an `AuditCreated` listener under `sync`. That dispatch happens inside
`Settlement::settle()`, which is inside `SyncStrategy::inRequest()`'s `try`. A nested write that
throws is caught there and handed to `WriteFailure::inRequest()`, which announces `AuditWriteFailed`
**for the outer entry** and, under `throw`, rethrows into the caller's `save()` — while that outer
row is sitting in the database with its sequence and hash, perfectly intact.

### Never do I/O on the write path

An HTTP call to a pager, a Slack webhook, a search index, an S3 put — each of them adds its full
latency to the `save()` that caused the entry, and its failure mode to the request's. Dispatch a
job and return.

### Never throw

See the table below. There is no event whose listener can throw safely, and three of them turn a
listener exception into a report about something else entirely.

---

## Where a throwing listener lands

| Listener on | Where the exception goes | Covered by `on_write_failure`? |
|---|---|---|
| `Auditing` | Propagates out of the caller's `save()`. **No `AuditDiscarded` is dispatched** — the exception escapes `Pipeline::process()` before that line | **No.** The pipeline runs in `Capture\Recorder::prepared()`, before every strategy `try` |
| `AuditDiscarded` | Propagates out of `save()` — dispatched after the pipeline's `finally`, outside every strategy `try` | No |
| `AuditCreating` | Caught by the strategy → `AuditWriteFailed` announced → policy decides. The entry is **not** written | Yes |
| `AuditCreated` | Same catch — but the row **is** already written with its sequence and hash. Under `log`, `Audited` is additionally suppressed | Yes, and misleadingly |
| `Audited` | Propagates out of `save()`; `Dispatcher::handed()` sits outside the strategy's `try` | No |
| `AuditWriteFailed` | **Replaces the original failure.** `WriteFailure::announce()` runs before the policy check, so your exception propagates instead of the ledger's | No |
| `BufferFlushFailed` | Replaces the flush failure, for the same reason — `Flusher` dispatches before it rethrows. Swallowed entirely on the `terminating` and `WorkerStopping` triggers, which wrap the flush in `rescue()` | No |
| `IntegrityVerificationFailed` | Aborts the verification run mid-walk. `php artisan sentinel:verify` catches it, prints its own failure line and exits `2` instead of reporting the break it found | No |
| `AuditRestoring` | Propagates to whoever called `restore()`, before the transaction opens | No |
| `AuditRestored` | Inside the commit callback of a transaction that already committed | No |

> 📌 **Note.** `on_write_failure` governs a **ledger** write that did not complete. It is not a
> general try/catch around your listeners, and three of the eleven events are dispatched outside
> every code path that consults it. See [Failure policy](05-failure-policy.md).

---

## Queueing a listener

A listener implementing `Illuminate\Contracts\Queue\ShouldQueue` is wrapped by Laravel's
`Dispatcher::createQueuedHandlerCallable()`. That wrapper enqueues a `CallQueuedListener` job and
**returns nothing**. Three consequences follow, all of them from the framework and none of them
negotiable:

1. **A queued listener can never cancel.** The wrapper returns `null`, `until()` reads `null` as "no
   opinion", and the entry goes through. This applies to `Auditing` and to `AuditRestoring` — a
   queued authorization listener on `AuditRestoring` authorizes everything.
2. **Ordering stops meaning what you think.** Listeners are invoked in registration order, but a
   queued one only *enqueues* in that order. Its body runs when the job is picked up: inline on the
   `sync` queue connection, later in a worker otherwise. The `AuditCreated` → `Audited` ordering is a
   guarantee about the dispatch, not about the bodies of queued listeners.
3. **The event has to survive serialization.** The event object goes into the job payload.
   `AuditWriteFailed`, `LedgerDestinationFailed` and `BufferFlushFailed` each hold a `Throwable`;
   `Auditing`, `AuditCreating` and `Audited` hold a mutable `Data\AuditData`.

The reliable pattern is a thin synchronous listener that extracts the scalars it needs and dispatches
your own job:

```php
use ElPandaPe\Sentinel\Events\AuditCreated;

Event::listen(AuditCreated::class, static function (AuditCreated $event): void {
    ReindexAuditEntry::dispatch(
        id: $event->entry->id,
        stream: $event->entry->stream,
        sequence: $event->entry->sequence,
    );
});
```

---

## Worked listeners

### Alerting on IntegrityVerificationFailed

This one does not fire on the write path. It is dispatched from `Integrity\Verifier::announce()` and
`Integrity\Projections::announce()`, so it reaches you when something calls
`Sentinel::verifyIntegrity()` or when `php artisan sentinel:verify` runs — usually a scheduled task.

Match on the enum case, never on the string. One of the six means something different from the other
five:

```php
use ElPandaPe\Sentinel\Enums\IntegrityBreak;
use ElPandaPe\Sentinel\Events\IntegrityVerificationFailed;
use Illuminate\Support\Facades\Event;

Event::listen(IntegrityVerificationFailed::class, static function (IntegrityVerificationFailed $event): void {
    $context = [
        'stream' => $event->stream,
        'sequence' => $event->sequence,
        'id' => $event->auditId,
        'reason' => $event->reason->value,
    ];

    match ($event->reason) {
        // The chain is intact; the relation index over it is stale. Reproject, do not page.
        IntegrityBreak::ProjectionMismatch => logger()->warning($event->message(), $context),

        IntegrityBreak::HashMismatch,
        IntegrityBreak::LinkMismatch,
        IntegrityBreak::SequenceGap,
        IntegrityBreak::SignatureMismatch,
        IntegrityBreak::CheckpointMismatch => OnCall::page($event->message(), $context),
    };
});
```

Two things the shape of this event hides:

- **`ProjectionMismatch` is not a tamper alarm.** The indexed relation lines are a *projection* of
  the lines the entry sealed; the chain does not cover them. Its own rendered sentence says so:
  "The chain is intact: the projection is not part of it." A listener that pages for every
  `IntegrityVerificationFailed` will page an engineer for a stale index.
- **`$auditId` is not always an audit id.** On the checkpoint paths (`CheckpointMismatch`, and a
  `SignatureMismatch` on an anchor) `Verifier` passes the anchor's root hash into that argument and
  the anchor's `from` into `$sequence`, because a broken anchor is a fact about a *range* and there
  is no single entry to name. Do not build a `sentinel_audits` lookup out of it unconditionally.

→ [Verification](../07-integrity/06-verification.md) ·
[The verification playbook](../07-integrity/07-the-verification-playbook.md)

### Alerting on AuditWriteFailed

Dispatched from `Capture\WriteFailure` on both branches and under both policies, **before** the
policy decides. The policy chooses what happens to the request; it never chooses whether you are
told.

```php
use ElPandaPe\Sentinel\Events\AuditWriteFailed;

Event::listen(AuditWriteFailed::class, static function (AuditWriteFailed $event): void {
    OnCall::page('Sentinel could not write an audit entry', [
        'audit_type' => $event->auditType,
        'event' => $event->event,
        'subject' => $event->subjectType.'#'.$event->subjectId,
        'transaction_id' => $event->transactionId,
        'exception' => $event->failure->getMessage(),
    ]);
});
```

Three warnings on this one:

- **It does not mean "nothing was written."** Under a strict fanout the exception is rethrown after
  the primary ledger has already sealed and stored the entry. `AuditWriteFailed` is at its most
  misleading exactly when the chain is intact.
- **Its `message()` says "deferred" on every branch.** There is one translation key,
  `sentinel::sentinel.ledger.write_failed`, and its sentence reads *"The deferred write of the
  :event entry for :type :id did not complete: :reason"*. Under `sync`, in-request, nothing was
  deferred. Build your own sentence, or at least do not let an operator read the word as a fact.
- **It carries identity and the exception only.** No `before`, no `after`, no `changes`, no
  `metadata` — the same rule as `AuditDiscarded`, for the same reason. The log line
  `WriteFailure::record()` writes on the swallowed branch carries the same six fields and no payload.

→ [Failure policy](05-failure-policy.md) ·
[Monitoring and troubleshooting](08-monitoring-and-troubleshooting.md)

### Alerting on LedgerDestinationFailed

Dispatched from `Ledger\FanoutLedger` when a **secondary** destination refuses an entry the primary
already sealed. Under `FanoutPolicy::Primary` the write settles anyway; under `FanoutPolicy::Strict`
the exception is rethrown — but the event is dispatched *first*, deliberately, because strict is the
policy that most needs it. It is the only event that names the entry that **did** land.

```php
use ElPandaPe\Sentinel\Events\LedgerDestinationFailed;

Event::listen(LedgerDestinationFailed::class, static function (LedgerDestinationFailed $event): void {
    Reconciliation::queue([
        'destination' => $event->destination,        // the driver class that refused it
        'stream' => $event->stream,
        'sequence' => $event->sequence,              // the entry is at this coordinate in the primary
        'audit_id' => $event->auditId,
        'reason' => $event->reason->getMessage(),
    ]);
});
```

`stream` + `sequence` + `auditId` is everything a reconciliation job needs to go and read the entry
back out of the primary and re-offer it to the destination that refused it. That is why the event
carries a coordinate and none of the entry's content.

→ [Fanout: writing to more than one place](../11-extending/05-fanout.md)

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| An `Auditing` listener you registered second never runs, and entries are written anyway | An earlier listener returned a non-`false`, non-`null` value. `until()` returns the first non-null response and halts | Return `null` or nothing from every "no opinion" path in every `Auditing` listener |
| `AuditWriteFailed` fires and then a `LogicException` mentioning `verifyIntegrity()` comes out of `save()` | `Discard::because()` was called from an `AuditCreating` (or later) listener. `WriteFailure` announces before the policy check | Move the discard into a pipeline stage or an `Auditing` listener |
| `AuditWriteFailed` fires for an entry you can find in `sentinel_audits`, with its sequence and hash | Either a strict fanout rethrew after the primary sealed, or an `AuditCreated` listener threw | Check `LedgerDestinationFailed` first; if absent, look at your `AuditCreated` listeners |
| Under `queue`, entries vanish and no event is dispatched at all | `Jobs\SettleAudit` catches nothing. A ledger failure in the worker retries and then lands in `failed_jobs` | Monitor `failed_jobs`, not `AuditWriteFailed`, when `mode = queue` |
| A queued `AuditRestoring` listener authorizes every restoration | Laravel's queued-handler wrapper returns `null`; `until()` reads that as "no opinion" | Keep `AuditRestoring` listeners synchronous |
| Your on-call rota is paged about "tampering" and the chain verifies clean | `IntegrityBreak::ProjectionMismatch` was treated like the other five. It reports a stale relation index over an intact chain | `match` on the enum case and route `ProjectionMismatch` to a warning |
| An operator reads "The deferred write … did not complete" for a failure that happened synchronously | `AuditWriteFailed::message()` has one translation key, whose sentence hardcodes "deferred" | Log your own sentence built from the event's properties |
| Under `buffered`, your metrics count more settlements than there are rows | `BufferStrategy` returns `Handover::accepted()` unconditionally, so `Audited` fires even when the flush it triggered failed | Count from `AuditCreated`, and watch `BufferFlushFailed` for loss |
| A listener saving a model recurses until the request dies | The nested capture dispatches `Auditing`/`AuditCreated` again, calling the same listener | Guard on the subject and the event — `$event->audit->subject_type` on `Auditing`, `$event->entry->subject_type` on `AuditCreated` — or dispatch a job |
| A restoration is refused with `Omission::Cancelled` and nobody knows why | An `AuditRestoring` listener returned `false`. `AuditRestoring` returning `false` is the package's **entire** authorization model for restore | Read `RestoreResult::$refused`; audit your own `AuditRestoring` listeners |

---

## ✅ Best practices

✅ **Do** — return `null` from every `Auditing` path that means "no opinion", and `false` only to
refuse. `until()` halts on the first non-null response, so anything else silently disables the
listeners behind yours.

```php
Event::listen(Auditing::class, static function (Auditing $event): ?bool {
    if ($event->audit->subject_type !== \App\Models\DeviceReading::class) {
        return null;
    }

    app(Discard::class)->because('device telemetry is not kept');

    return false;
});
```

❌ **Don't** — return `true` to mean "keep this one". It halts every later listener *and* lets the
entry through, which is indistinguishable from those listeners never being registered.

```php
Event::listen(Auditing::class, static fn (Auditing $event): bool => true);
```

---

✅ **Do** — treat `Audited::$entry === null` as "settled elsewhere" and branch on it explicitly. A
write that did not complete announces `AuditWriteFailed` and never reaches the `Audited` dispatch.

```php
Event::listen(Audited::class, static function (Audited $event): void {
    if ($event->entry === null) {
        Metrics::increment('audits.deferred');

        return;
    }

    Metrics::increment('audits.settled_inline');
});
```

❌ **Don't** — read a null entry as a lost entry and re-record the fact. Under `queue` and
`buffered`, null is the *normal* outcome, and your compensating write becomes a second entry for one
event — with its own sequence, in the chain, forever.

```php
use ElPandaPe\Sentinel\Events\Audited;
use ElPandaPe\Sentinel\Facades\Sentinel;

Event::listen(Audited::class, static function (Audited $event): void {
    if ($event->entry === null) {
        Sentinel::event('audit.recovered')->record();   // writes a duplicate fact
    }
});
```

---

✅ **Do** — dispatch a job from an `AuditCreated` listener, carrying only the scalars the job needs.
The dispatch is inline on the write path; the work is not.

```php
Event::listen(AuditCreated::class, static function (AuditCreated $event): void {
    ReindexAuditEntry::dispatch($event->entry->id, $event->entry->stream, $event->entry->sequence);
});
```

❌ **Don't** — call an external service from the listener body. Its latency is added to the `save()`
that caused the entry, and under `sync` its exception is caught by the strategy and reported as a
failed write for a row that is already in the database.

```php
Event::listen(AuditCreated::class, static function (AuditCreated $event): void {
    Http::post('https://siem.internal/ingest', $event->entry->toArray());   // charged to the request
});
```

---

✅ **Do** — `match` on `IntegrityBreak` when reacting to `IntegrityVerificationFailed`, and treat
`ProjectionMismatch` differently from the other five.

```php
match ($event->reason) {
    IntegrityBreak::ProjectionMismatch => logger()->warning($event->message()),
    default => OnCall::page($event->message()),
};
```

❌ **Don't** — page on the event class alone. A stale relation index and a rewritten row arrive as
the same class, and the first one is not an incident.

```php
Event::listen(IntegrityVerificationFailed::class, OnCall::page(...));   // cries wolf
```

---

✅ **Do** — keep `Auditing` and `AuditRestoring` listeners synchronous, and put the slow half behind
a job that the listener dispatches after it has decided.

```php
use ElPandaPe\Sentinel\Events\AuditRestoring;

Event::listen(AuditRestoring::class, static function (AuditRestoring $event): ?bool {
    return auth()->user()?->can('restore', $event->subject) === true ? null : false;
});
```

❌ **Don't** — mark a cancellable listener `ShouldQueue`. Laravel's wrapper enqueues and returns
`null`, so the veto never happens and the restoration proceeds.

```php
use ElPandaPe\Sentinel\Events\AuditRestoring;
use Illuminate\Contracts\Queue\ShouldQueue;

final class AuthorizeRestore implements ShouldQueue      // authorizes everything
{
    public function handle(AuditRestoring $event): bool { return false; }
}
```

---

✅ **Do** — name a reason before refusing, so `AuditDiscarded::message()` reads as a sentence rather
than a stage name.

```php
app(Discard::class)->because('draft invoices are not part of the trail');

return false;
```

❌ **Don't** — refuse silently. The entry still leaves through `AuditDiscarded`, with reason
`unspecified`, and six months later nobody can tell your veto from a stage that returned `null`.

```php
Event::listen(Auditing::class, static fn (): bool => false);   // reason: 'unspecified'
```

---

**See also:** [Failure policy](05-failure-policy.md) ·
[Performance modes](01-performance-modes.md) ·
[The buffered mode](02-the-buffered-mode.md) ·
[Running audits on a queue](03-queues.md) ·
[Monitoring and troubleshooting](08-monitoring-and-troubleshooting.md) ·
[The write pipeline](../05-pipeline-and-security/01-the-write-pipeline.md) ·
[Discarding entries](../05-pipeline-and-security/06-discarding-entries.md) ·
[Restoring state](../06-reading/08-restoring-state.md) ·
[Verification](../07-integrity/06-verification.md) ·
[Fanout: writing to more than one place](../11-extending/05-fanout.md) ·
[Events reference](../99-reference/05-events.md) ·
[Enums reference](../99-reference/04-enums.md) ·
[Exceptions reference](../99-reference/06-exceptions.md)
