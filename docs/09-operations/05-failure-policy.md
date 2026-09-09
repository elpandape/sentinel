# ⚙️ Failure policy

> What happens to the request when an audit entry cannot be written — the one setting that decides
> it, the three places that setting cannot reach, and which of the resulting alarms deserve a pager.

**On this page:** [The one question](#the-one-question) · [The two values](#the-two-values) · [One default, not one per environment](#one-default-not-one-per-environment) · [The log channel](#the-log-channel) · [Compliance forces `throw`](#compliance-forces-throw) · [The branch a policy cannot reach](#the-branch-a-policy-cannot-reach) · [What the policy does not cover](#what-the-policy-does-not-cover) · [Fanout: strict and primary](#fanout-strict-and-primary) · [Which alarm deserves a pager](#which-alarm-deserves-a-pager) · [⚠️ Pitfalls](#️-pitfalls) · [✅ Best practices](#-best-practices)

---

## The one question

An audit entry passed the pipeline, reached the dispatcher, and the ledger refused it — the database
was unreachable, a constraint fired, a fanout destination threw. The business write that caused the
entry has already happened. What now?

Sentinel answers that in exactly one class. `Capture\WriteFailure` is the only thing in the package
that decides what a failed write costs, and it has two public methods, one per branch:

| Method | Called from | Announces | Records | Propagates |
|---|---|---|---|---|
| `WriteFailure::inRequest()` | the write that happened while the caller is still on the stack | always | only under `log` | under `throw` |
| `WriteFailure::afterCommit()` | the write deferred to a database commit | always | always | never |

Two verbs run through the whole of this page and they are not interchangeable:

- **Announce** — dispatch `Events\AuditWriteFailed`. It goes out on both branches, under both
  policies, in every mode. The policy decides what happens to the request, never whether you are
  told.
- **Record** — write an `error` line through `Support\Config::logChannel()`. It is written only where
  the failure is swallowed, because propagating *and* logging would report the same fact twice.

Everything else here is which branch you are on and what reaches it.

> 📌 **Note.** `AuditWriteFailed` says the write did not complete. It does not say no entry exists —
> under a strict fanout it is raised after the primary has already sealed and stored the row. See
> [Fanout: strict and primary](#fanout-strict-and-primary).

## The two values

`sentinel.on_write_failure` is read by `Support\Config::writeFailurePolicy()` and parsed into
`Enums\FailurePolicy`. Two cases, and nothing else is accepted.

| Value | Enum case | What the caller sees | What is written to the log | Use it when |
|---|---|---|---|---|
| `throw` (default) | `FailurePolicy::Throw` | the original `Throwable`, rethrown into the `save()` / `record()` that caused the entry | nothing — the exception is the report | a missing entry must stop the operation |
| `log` | `FailurePolicy::Log` | nothing; the request completes normally | one `error` line on `log_channel`, carrying the entry's identity and the exception | you have decided a lost entry costs less than a failed user request |

The `throw` branch rethrows the *original* exception, not a wrapper. Whatever the ledger threw is
what lands in your handler, so `QueryException`, a driver timeout or a fanout destination's own
exception all arrive intact.

```php
// config/sentinel.php
return [
    'on_write_failure' => env('SENTINEL_ON_WRITE_FAILURE', 'throw'), // 'throw' | 'log'
    'log_channel' => env('SENTINEL_LOG_CHANNEL'),                    // null = application default
];
```

> ⚠️ **Warning.** The value is validated **lazily**. `Config::writeFailurePolicy()` is reached from
> exactly one place — `WriteFailure::inRequest()` — so a typo such as `'shrug'` sits harmless
> through every healthy request and then, on the first real ledger failure, replaces a diagnosable
> write failure with a `ConfigurationException` about the failure policy itself. Nothing validates
> it at boot.

The message names both accepted values:
`Sentinel configuration key [sentinel.on_write_failure] has unknown value [shrug]. Accepted: throw, log.`

## One default, not one per environment

There is one shipped default and no environment-specific guidance, and that is a decision rather
than an omission. A policy that is `throw` in CI and `log` in production is a policy that has never
been exercised on the branch that matters — the failure path only ever runs under the value nobody
tested. The default is `throw` because that is the behaviour the package had before the key existed,
so adding the key changed nothing for anyone; `log` is opt-in and stays opt-in.

Two consequences worth stating plainly:

- If you set `log` in production, set it in your test environment too, and write a test that proves
  a failing ledger lets the request through. `tests/Events/WriteFailureTest.php` does exactly that
  against a `FailingLedger` fixture — the same shape works in an application.
- If you set `log` only in production, the first time you find out what it does is during the
  incident it was supposed to soften.

> 🧪 **Verify it.** The `Sentinel` section of `php artisan about` prints the mode, the ledger, the payload
> version, whether compliance is on and whether telemetry is on. It does **not** print
> `on_write_failure`, so there is no command that will tell you which policy an environment is
> running under. Read `config('sentinel.on_write_failure')` — or, under compliance, remember that the
> string is not consulted at all.

## The log channel

`sentinel.log_channel` names the channel a swallowed failure is written through. `null` — the
default — means the application's own default channel. It is read by
`Support\Config::logChannel()` and used as `$log->channel($channel)->error(...)`, so any channel
name from `config/logging.php` works.

The line is `AuditWriteFailed::message()`, and the context is fixed:

| Context key | Value |
|---|---|
| `audit_type` | the entry's `audit_type` — `model`, `relation`, `custom`, `auth`, `mass`, … |
| `event` | the entry's `event` column |
| `subject_type` | the subject's class, or `null` |
| `subject_id` | the subject's key as a string, or `null` |
| `transaction_id` | the business transaction the entry belonged to, or `null` |
| `exception` | the original `Throwable` |

There is deliberately no `before`, no `after`, no `changes` and no `metadata` — the same rule the
event carries. A log line reporting a failed write is not the place a masked value escapes the
pipeline that exists to mask it. `tests/Events/WriteFailureTest.php` asserts the absence of `before`
explicitly.

```php
// config/logging.php
'channels' => [
    'audit-failures' => [
        'driver' => 'slack',
        'url' => env('LOG_SLACK_WEBHOOK_URL'),
        'level' => 'error',
    ],
],

// config/sentinel.php
'on_write_failure' => 'log',
'log_channel' => 'audit-failures',
```

> ⚠️ **Warning.** With `on_write_failure = log` and `log_channel = null`, a lost audit entry becomes
> one `error` line in whatever your default channel is. If that is a file nobody tails, the entry is
> gone and nobody is told. The channel is the whole of the compensation for choosing `log`.

## Compliance forces `throw`

When `sentinel.compliance` is `true`, `Config::writeFailurePolicy()` returns `FailurePolicy::Throw`
and returns *before* the `on_write_failure` string is read at all:

```php
public function writeFailurePolicy(): FailurePolicy
{
    if ($this->complianceEnabled()) {
        return FailurePolicy::Throw;
    }
    // …the string is only read here
}
```

Compliance overrules the setting rather than validating it, because the two say different things.
`on_write_failure` is an operator deciding how much a lost entry is allowed to cost. Compliance is a
statement that no entry may be lost at all — a ledger that can drop entries in silence proves
nothing, so there is no configuration under compliance that lets one be dropped in the request.

Two side effects of the short-circuit:

- An invalid `on_write_failure` value is **never parsed** while compliance is on. Turn compliance
  off and the typo surfaces on the next failed write.
- Compliance has other boot-time requirements that are unrelated to this key —
  `Compliance\Requirements::enforce()` refuses to boot when `integrity.signature.enabled` or
  `integrity.checkpoints.enabled` is off. See [Compliance mode](../08-lifecycle/05-compliance-mode.md).

> ⚠️ **Warning.** Compliance forces `throw`, and `throw` only exists on one of the two branches.
> Read the next section before treating compliance as a guarantee that a failed write is loud.

## The branch a policy cannot reach

With `sentinel.transactions.after_commit` on — the default — an entry captured inside an open
database transaction does not settle at capture. `Dispatch\Dispatcher::dispatch()` registers the
hand-over in `Connection::afterCommit()`, so the write happens after the commit that made the fact
true. That is the branch `WriteFailure::afterCommit()` serves, and it has **no throw branch at all**:

```php
public function afterCommit(AuditData $audit, Throwable $failure): void
{
    $this->record($this->announce($audit, $failure), $failure);
}
```

This is not an oversight and it is not configurable. By the time a commit callback runs, the
transaction has committed and the business write succeeded. An exception thrown there would:

- **report the failure of something that succeeded** — it would surface out of a `DB::transaction()`
  whose commit already happened, so the caller's `catch` would roll back nothing and would be told a
  successful operation failed; and
- **take the rest of the operation down with it** — Laravel runs commit callbacks in a bare
  `foreach` with no guard, so throwing on the first entry means every later entry of the same
  transaction is never attempted. An append-only engine that lost fifty facts because the first one
  hit a constraint is worse than one that lost one.

So a deferred failure is **announced and recorded**, always, whatever `on_write_failure` says and
whatever compliance says. `tests/Events/WriteFailureTest.php` pins it: with the default `throw` and a
ledger that always fails, a `DB::transaction()` completes without raising and `AuditWriteFailed` is
what comes out.

### What this means for your alerting

With the default configuration, **every entry captured inside a `DB::transaction()` behaves as if
the policy were `log`.** That is most entries in most applications. Two things follow:

1. `AuditWriteFailed` is not an optional nicety. It is the only signal on the branch that carries
   most of your traffic, and it is dispatched before the policy is consulted, so it is available on
   both branches and under both values.
2. The log line on the deferred branch goes to `log_channel` even when `on_write_failure` is `throw`,
   because `afterCommit()` always records. Point `log_channel` at something watched regardless of
   which policy you chose.

```php
use ElPandaPe\Sentinel\Events\AuditWriteFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

Event::listen(AuditWriteFailed::class, function (AuditWriteFailed $event): void {
    Log::channel('audit-failures')->critical($event->message(), [
        'audit_type' => $event->auditType,
        'event' => $event->event,
        'subject' => $event->subjectType.'#'.$event->subjectId,
        'transaction_id' => $event->transactionId,
        'exception' => $event->failure,
    ]);
});
```

`AuditWriteFailed` carries identity and the exception, and nothing of what the entry said — the same
rule as `AuditDiscarded`. If you need the payload you are looking at the wrong event; see
[Events and listeners](04-events-and-listeners.md).

> ⚠️ **Warning.** `AuditWriteFailed::message()` renders one translation key,
> `sentinel::sentinel.ledger.write_failed`, and its English and Spanish sentences both begin
> "The deferred write…". The event now fires on the in-request branch too, so an operator reading a
> log line under `sync` + `log` will be told the failure was deferred when it was not. The sentence
> does not tell you which branch you are on; whether an exception also reached the caller does.

### Turning the deferral off

Setting `transactions.after_commit` to `false` moves every write onto the in-request branch, where
the policy applies. That does close the hole — and it costs you discard-on-rollback: the ledger then
keeps entries describing facts a rollback undid. That is a worse trade for almost every application
than an alarm you consume. See [Business transactions](../03-capture/06-business-transactions.md).

## What the policy does not cover

`on_write_failure` governs failures the dispatcher catches. Three whole classes of failure are
outside it, and each is outside it for a reason.

| Failure | Governed by | What you actually see |
|---|---|---|
| A pipeline stage or an `Auditing` listener throws | nothing — it propagates | The exception lands in the caller's `save()` under both policies. The pipeline runs in `Capture\Recorder::prepared()`, before the dispatcher is ever reached, so it is outside every try/catch that consults the policy. No `AuditDiscarded` is dispatched either. |
| The ledger refuses inside a queue worker (`mode = queue`) | the queue | `Jobs\SettleAudit` catches nothing on purpose. The queue retries under the same `capture_id`, which the ledger's unique index refuses to settle twice, and what still does not land ends in `failed_jobs`. **No `AuditWriteFailed` is announced.** Under `queue`, that event only ever means the *enqueue* was refused. |
| A buffered flush fails (`mode = buffered`) | `on_write_failure`, but against the wrong entry | `Dispatch\BufferStrategy` pushes the arriving entry first and then evaluates the thresholds, so a failing flush is reported through `WriteFailure::inRequest()` naming the entry that just arrived — which is by design the one entry that is safe. `Events\BufferFlushFailed` is what names the batch that was at stake. |

Two consequences that surprise people:

- **Under `buffered` + `throw`, a flush failure throws into a request whose entry is safely in the
  buffer.** The exception escapes before `Handover::accepted()` is returned, so `Audited` is not
  announced for it either. Under the deferred branch the same failure is only recorded.
- **A batch reports once.** `SyncStrategy::batch()` reports one failure against `$audits[0]` and
  refuses every hand-over, so three thousand rows hitting an unreachable ledger produce one
  `AuditWriteFailed` and one log line — not three thousand. `BufferStrategy::handAll()` reports once
  in the same way, naming the same entry, but its hand-overs stay accepted: the batch is in the
  buffer whatever the flush did.

> 📌 **Note.** An exception thrown from an `AuditCreated` or `AuditCreating` listener is *inside*
> `Settlement::settle()`, which is inside the strategy's try block. Your listener's bug is therefore
> reported as a failed write — `AuditWriteFailed` announced, `Audited` suppressed — for an entry that
> is already in the database with its sequence and hash. Keep listener bodies non-throwing, or queue
> them.

## Fanout: strict and primary

A fanout ledger writes one entry to several destinations. The first is the primary: it assigns the
`sequence` and seals the `hash`, and the rest are handed the sealed entry through `append()`. Two
ledgers each numbering their own chain would produce two different truths about one fact, so only
one numbers. `Ledger\FanoutLedger::fanOut()` is where a secondary's refusal is handled, and
`sentinel.ledger.ledgers.fanout.on_failure` is what it consults.

| `on_failure` | Enum case | A secondary refuses | The write |
|---|---|---|---|
| `strict` (default, and the fallback when the key is `null`) | `FanoutPolicy::Strict` | `LedgerDestinationFailed` is dispatched, then the exception is rethrown | fails — and `on_write_failure` then decides what that costs the request |
| `primary` | `FanoutPolicy::Primary` | `LedgerDestinationFailed` is dispatched, the loop continues to the remaining destinations | settles |

The primary refusing fails the write under **both** policies. `on_failure` is a question about
secondaries only.

```php
// config/sentinel.php
'ledger' => [
    'default' => 'fanout',
    'ledgers' => [
        'fanout' => [
            'destinations' => ['database', 'archive'],
            'on_failure' => 'primary',
        ],
    ],
],
```

### What `LedgerDestinationFailed` tells you

It is the only event in the package that names an entry that **did** land:

```php
use ElPandaPe\Sentinel\Events\LedgerDestinationFailed;
use Illuminate\Support\Facades\Event;

Event::listen(LedgerDestinationFailed::class, function (LedgerDestinationFailed $event): void {
    Backfill::queue(
        destination: $event->destination,   // class-string of the ledger that refused
        stream: $event->stream,             // the chain the entry belongs to
        sequence: $event->sequence,         // its position in that chain
        auditId: $event->auditId,           // the entry id — it exists, go and fetch it
    );

    Log::channel('audit-failures')->warning($event->message(), ['exception' => $event->reason]);
});
```

`destination`, `stream`, `sequence`, `auditId`, `reason` — and nothing of what the entry said. Those
four coordinates are exactly what a backfill needs: the entry is readable from the primary, so a
listener can fetch it and hand it to the destination that refused.

It is dispatched **before** the strict-policy rethrow, and deliberately so. Strict is the policy that
most needs the announcement, because it rethrows out of a primary that has already sealed and stored
the entry — announced after the throw, an operator would be told a write did not complete and never
told which one did.

> 📌 **Note.** Under `strict`, whatever earlier destinations already took stays with them. An entry
> is sealed before it is handed out and nothing in the fanout can unseal it, so a failed strict write
> leaves the entry present in the primary and in every destination before the one that refused. That
> is a reconciliation job, not a corruption: the chain is intact everywhere it landed.

## Which alarm deserves a pager

Every row here is a distinct signal with a distinct meaning. Treating them as one stream is how a
stale relation index ends up waking someone at 3am.

| Signal | What actually happened | Response |
|---|---|---|
| `AuditWriteFailed`, policy `throw`, in-request branch | The write failed and the user's request already failed with it. The ledger is refusing or unreachable. | **Page** if sustained. The user-facing error rate is already telling you. |
| `AuditWriteFailed`, policy `log`, or any deferred failure | An entry is gone and nothing else will ever say so. The chain will verify as intact because a missing entry consumed no sequence. | **Page.** This is the only notification of a permanent gap. |
| `AuditWriteFailed` where `$failure` came from your own `AuditCreated` listener | The row is in the database with its sequence and hash. Your listener threw inside the strategy's try block. | **Ticket.** Nothing was lost; a listener needs a `try`. |
| `AuditWriteFailed` where `$failure` is a `DiscardException` | A programming error: something called `Discard::because()` from an `AuditCreating` listener or another post-pipeline hook. The entry was never written and the chain is contiguous. | **Ticket.** Move the discard into a pipeline stage or an `Auditing` listener. |
| `AuditWriteFailed` under `mode = queue` | The **enqueue** was refused — the queue backend, not the ledger. | **Page.** Nothing is going to retry an enqueue that never happened. |
| Nothing at all, under `mode = queue`, entries missing | A worker-side ledger failure. `SettleAudit` announces nothing; the queue is the policy. | **Expected.** Alert on `failed_jobs` and the queue's own events, not on a Sentinel event. |
| `ConfigurationException` naming `on_write_failure` | A typo in the key, discovered at the moment of the first real failure — two failures now, one hiding the other. | **Page.** Fix the value; the underlying failure is still unreported. |
| `LedgerDestinationFailed` under `primary` | The entry is in the primary chain and missing from one secondary. Nothing is lost. | **Ticket / backfill** using the four coordinates the event carries. |
| `LedgerDestinationFailed` followed by `AuditWriteFailed` (strict) | Same as above, plus the write was failed on purpose and the request paid for it. | **Page.** Then reconcile the named entry. |
| `BufferFlushFailed` with `returned > 0` | The batch went back into the buffer whole. It settles on the next trigger. | **Expected once; ticket if it repeats.** |
| `BufferFlushFailed` with `skipped() > 0` and `returned == 0` on a failing batch | The buffer refused to take the batch back. Those entries are gone outright, and the shipped sentence still calls them "already settled elsewhere". | **Page.** This is the one path on which the package loses a fact it did not have to. |
| `IntegrityVerificationFailed` with `IntegrityBreak::ProjectionMismatch` | A stale relation index over a chain that is intact — its own translated sentence says so. | **Ticket.** Not a tamper alarm. See [Verification](../07-integrity/06-verification.md). |

> 🔒 **Security.** `verifyIntegrity()` cannot tell you what a failed write cost. An entry that never
> reached the ledger consumed no sequence, so it leaves no gap and no broken link, and a shorter
> chain verifies as intact — correctly. The chain proves that what settled was not tampered with, not
> that everything that happened settled. Loss detection is out of band, and the events on this page
> are it.

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| A `DB::transaction()` committed, the operation succeeded, an entry is missing, and nothing threw — with `on_write_failure = throw`. | The write was deferred to the commit. `WriteFailure::afterCommit()` has no throw branch, so neither `throw` nor compliance can reach it. | Consume `AuditWriteFailed`, and read the `error` line on `log_channel` — the deferred branch always records. |
| A log line says "The deferred write … did not complete" for a plain `save()` outside any transaction. | `AuditWriteFailed::message()` renders one key, `ledger.write_failed`, whose en/es sentences both hardcode "deferred". | Do not infer the branch from the sentence. Under `throw`, the in-request branch also rethrows; the deferred branch never does. |
| A `ConfigurationException` about `on_write_failure` fires during a production incident, and the real failure is nowhere. | The value is parsed lazily in `Config::writeFailurePolicy()`, reached only from `WriteFailure::inRequest()`. Nothing checks it at boot. | Only `throw` and `log` are accepted. Verify the value at deploy time; under compliance the string is never read, so a typo hides until compliance is switched off. |
| `AuditWriteFailed` was announced but the row is in `sentinel_audits` with its sequence and hash. | Either a strict fanout rethrew after the primary sealed the entry, or an `AuditCreated`/`AuditCreating` listener threw inside `Settlement::settle()`. | Read `LedgerDestinationFailed` for the first; wrap the listener body for the second. Never read `AuditWriteFailed` as "nothing was written". |
| Under `mode = queue`, workers cannot reach the database and Sentinel announces nothing. | `Jobs\SettleAudit` catches nothing on purpose — in a worker the queue is the failure policy. | Alert on `failed_jobs` and the queue's own events. Under `queue`, `AuditWriteFailed` only means the enqueue was refused. |
| Under `mode = buffered` + `throw`, a user's `save()` throws for an entry that is safely in the buffer. | `BufferStrategy::hand()` pushes first, then evaluates the thresholds; the *previous* batch's flush failure is reported against the arriving entry through `WriteFailure::inRequest()`. | Read `BufferFlushFailed` for what was actually at stake, and choose `log` if a flush failure must not take a request down. |
| `on_write_failure = log` and an exception from an `Auditing` listener still takes the request down. | The pipeline runs in `Capture\Recorder::prepared()`, before the dispatcher and therefore outside every try/catch that consults the policy. No `AuditDiscarded` goes out either. | Catch inside the listener. To refuse an entry, call `Discard::because()` and return `false`. |
| A fanout secondary is quietly missing entries and nothing ever complained. | `on_failure` is `primary` and nothing listens to `LedgerDestinationFailed`. | Register a listener. Under `primary`, that event is the only report a destination ever fell behind. |
| A batch of thousands hit an unreachable ledger and produced exactly one alarm. | `SyncStrategy::batch()` and `BufferStrategy::handAll()` report once, against the first entry — the first also refuses every hand-over, the second leaves them accepted. | Expected. The count of events is not the count of lost entries. Size the incident from the operation that ran, not from how many alarms it produced. |

## ✅ Best practices

✅ **Do** — keep `on_write_failure` at `throw` unless you have decided, explicitly and in writing,
that a lost entry costs less than a failed request. It is the default because a caller who is still
on the stack can still be told.

```php
// config/sentinel.php
'on_write_failure' => env('SENTINEL_ON_WRITE_FAILURE', 'throw'),
```

❌ **Don't** — set a different value per environment. The failure path then only ever runs under the
value nobody tested, and the first exercise of `log` is the incident it was meant to soften.

```php
// .env.production   SENTINEL_ON_WRITE_FAILURE=log
// .env.testing      SENTINEL_ON_WRITE_FAILURE=throw   ← the branch CI proves is not the one that runs
```

---

✅ **Do** — point `log_channel` at somewhere a human or an alerting rule actually reads, whichever
policy you chose. The deferred branch records unconditionally, so the channel matters even under
`throw`.

```php
'log_channel' => 'audit-failures', // a channel with a real destination behind it
```

❌ **Don't** — choose `log` and leave `log_channel` null. A permanently lost audit entry becomes one
`error` line in the application's default channel, and the compensation for choosing `log` is exactly
that line.

```php
'on_write_failure' => 'log',
'log_channel' => null, // the entry is gone and it went to daily.log
```

---

✅ **Do** — read `AuditWriteFailed` as "this write did not complete", and inspect `$event->failure`
to find out what actually happened.

```php
use ElPandaPe\Sentinel\Events\AuditWriteFailed;

Event::listen(AuditWriteFailed::class, function (AuditWriteFailed $event): void {
    OnCall::page($event->message(), [
        'subject' => $event->subjectType.'#'.$event->subjectId,
        'exception' => $event->failure::class,
    ]);
});
```

❌ **Don't** — treat it as "no entry exists". Under a strict fanout it is raised after the primary
sealed and stored the row, so that reading is wrong exactly when the chain is intact.

```php
Event::listen(AuditWriteFailed::class, function (AuditWriteFailed $event): void {
    Trail::markMissing($event->subjectId); // may be marking an entry that is in the chain
});
```

---

✅ **Do** — listen for `LedgerDestinationFailed` whenever `on_failure` is `primary`, and use the four
coordinates it carries to backfill the destination that refused.

```php
use ElPandaPe\Sentinel\Events\LedgerDestinationFailed;

Event::listen(LedgerDestinationFailed::class, function (LedgerDestinationFailed $event): void {
    Backfill::dispatch($event->destination, $event->stream, $event->sequence, $event->auditId);
});
```

❌ **Don't** — set `on_failure: primary` and register nothing. The whole meaning of `primary` is
"a secondary refusal is not fatal, so somebody else deals with it", and there is no somebody else.

```php
'fanout' => ['destinations' => ['database', 'elasticsearch'], 'on_failure' => 'primary'],
// …and no listener anywhere. The search index silently drifts.
```

---

✅ **Do** — keep `AuditCreated` and `AuditCreating` listener bodies non-throwing, or move the work to
a queued job.

```php
use ElPandaPe\Sentinel\Events\AuditCreated;

Event::listen(AuditCreated::class, function (AuditCreated $event): void {
    IndexAudit::dispatch($event->entry->id); // fails in a worker, not on the write path
});
```

❌ **Don't** — let one throw. It is dispatched inside `Settlement::settle()`, which is inside the
strategy's try block, so your bug is reported as a failed write — and under `throw` it is rethrown
into the caller's `save()` — for a row that is already sealed in the chain.

```php
Event::listen(AuditCreated::class, function (AuditCreated $event): void {
    Search::index($event->entry); // a network blip here reads as a failed audit write
});
```

---

✅ **Do** — treat `AuditWriteFailed` as your compliance alarm on the deferred branch, and alert on it
independently of the policy.

```php
'compliance' => true,          // forces FailurePolicy::Throw
// …and a listener, because the branch that carries most traffic cannot throw
```

❌ **Don't** — assume compliance makes every failed write loud. It forces `throw`, and `throw` only
exists on the in-request branch; with `transactions.after_commit` on, an entry captured inside a
`DB::transaction()` is announced and recorded exactly as under `log`.

```php
'compliance' => true,
'transactions' => ['after_commit' => true],
// A failed deferred write completes the request in silence, apart from the event and the log line.
```

---

**See also:** [Performance modes](01-performance-modes.md) · [The buffered mode](02-the-buffered-mode.md) · [Running audits on a queue](03-queues.md) · [Events and listeners](04-events-and-listeners.md) · [Monitoring and troubleshooting](08-monitoring-and-troubleshooting.md) · [Artisan commands](06-artisan-commands.md) · [Business transactions](../03-capture/06-business-transactions.md) · [The write pipeline](../05-pipeline-and-security/01-the-write-pipeline.md) · [Discarding entries](../05-pipeline-and-security/06-discarding-entries.md) · [Compliance mode](../08-lifecycle/05-compliance-mode.md) · [Fanout: writing to more than one place](../11-extending/05-fanout.md) · [Verification](../07-integrity/06-verification.md) · [Configuration](../99-reference/02-configuration.md) · [Enums](../99-reference/04-enums.md) · [Events](../99-reference/05-events.md) · [Exceptions](../99-reference/06-exceptions.md)
