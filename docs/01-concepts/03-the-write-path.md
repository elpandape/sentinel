# 🧠 The write path

> One entry, followed from the Eloquent save that caused it to the sealed row in the ledger — every
> component it passes through, everything that can drop it, and what changes when the write is
> deferred to a queue or a buffer.

**On this page:** [The map](#the-map) · [The walk, step by step](#the-walk-step-by-step) · [The seven stages](#the-seven-stages) · [What is decided where](#what-is-decided-where) · [Where an entry can die](#where-an-entry-can-die) · [What a failure costs](#what-a-failure-costs) · [The same walk under `queue`](#the-same-walk-under-queue) · [The same walk under `buffered`](#the-same-walk-under-buffered) · [⚠️ Pitfalls](#️-pitfalls) · [✅ Best practices](#-best-practices)

---

Three components own three questions, and no other component answers them:

| Question | Owner | Source |
|---|---|---|
| What happened? | the capture | `src/Capture/` |
| What may the entry say? | the pipeline | `src/Pipeline/Pipeline.php` |
| Where and when does it land? | the dispatcher | `src/Dispatch/Dispatcher.php` |

Everything else on this page follows from that split. `Capture\Recorder` is the single door between
the first question and the other two — every capture in the package goes through it, so an
identifier that must be on every entry is stamped in one place rather than seven. The **ledger**,
and only the ledger, assigns `sequence`, `previous_hash` and `hash`, inside the same operation as
the write. That last rule is what makes a tamper-evident chain and an asynchronous write compatible
at all: the order entries reach the ledger is not the order the facts happened in.

## The map

```text
                        ┌────────────────────────────────────────────────┐
   Eloquent  ──────────▶│  created · updated · deleted · forceDeleted    │
                        └──────────────────────┬─────────────────────────┘
                                               ▼
                                  Capture\ModelObserver
                                     restore? force delete?
                                               ▼
                                   Capture\ModelCapture
                        Snapshot\SnapshotBuilder ─▶ before / after
                        Diff\Diff                ─▶ changes
                                               ▼
                                     Data\AuditData
                          (mutable; carries no place in the chain)
                                               ▼
                                    Capture\Recorder
                                 capture_id ??= ULID
                                 transaction_id
                                               ▼
      ┌──────────────────────── Pipeline\Pipeline ───────────────────────────┐
      │  1  FilterUnchanged          ← may return null                       │
      │  2  ResolveContext                                                   │
      │  3  ResolveTags              ← may throw                             │
      │  4  NormalizeData                                                    │
      │  5  MaskSensitiveData                                                │
      │  6  EncryptSensitiveData     ← may throw                             │
      │  7  EnforcePolicies          ← may return null                       │
      │  ─────────────────────────────────────────────────────────────────── │
      │     event Auditing           ← a listener returning false stops it   │
      └───────────────────────────────┬──────────────────────────────────────┘
                                      │  stopped ⇒ event AuditDiscarded, nothing written
                                      ▼
                              Dispatch\Dispatcher
                 transaction open on the subject's connection
                 and transactions.after_commit = true ?
                        ├─ yes ─▶ Connection::afterCommit(…)   (rollback discards it)
                        └─ no  ─▶ now
                                      ▼
        ┌──────────────────┬──────────────────────────┬────────────────────────┐
        │  SyncStrategy    │  QueueStrategy           │  BufferStrategy        │
        │  settles here    │  Jobs\SettleAudit ─▶ …   │  Buffer ─▶ Flusher ─▶ …│
        └────────┬─────────┴────────────┬─────────────┴───────────┬────────────┘
                 └──────────────────────┴─────────────────────────┘
                                      ▼
                             Dispatch\Settlement
                               event AuditCreating
                                      ▼
                        Contracts\Ledger::writeMany()
              ┌──────────────────────────────────────────────────┐
              │  StreamGate::tail(stream)   ← lock, then read    │
              │  EntryBuilder::build()                           │
              │    id (ULID) · stream · sequence · previous_hash │
              │    payload_version · algorithm · version         │
              │    hash = H(prefix ‖ canonical payload)          │
              │    signature · created_at · labels               │
              │  INSERT rows + label rows + relation projection  │
              └───────────────────────┬──────────────────────────┘
                                      ▼
                             event AuditCreated
                                      ▼
                    event Audited   (in the process that captured)
```

## The walk, step by step

The example throughout is `$invoice->update(['status' => 'sent'])` on a model using
`ElPandaPe\Sentinel\Concerns\Auditable`, with the shipped defaults: `mode = sync`,
`transactions.after_commit = true`, `on_write_failure = throw`.

**1 — Eloquent fires `updated`.** The trait registers listeners for exactly four events —
`created`, `updated`, `deleted`, `forceDeleted` — plus `updating`, which writes nothing and only
vets state-machine moves. `Capture\ModelObserver` receives the model. Four writing events become six
(`event`, `audit_type`) pairs — not six audit types — because two of them are derived here rather
than listened for: a `restored` is the
`updated` that clears the deletion mark, and a `transition` is the `updated` that moved a column named
in `$auditTransitions`. One event is filtered out instead — the `deleted` that `forceDelete()` fires
on its way to `forceDeleted` is suppressed, so only the second becomes an entry.

> 📌 **Note.** `Model::query()->update([...])` and `->delete()` fire no model event, so they arrive
> at none of this. See [Mass operations](../03-capture/05-mass-operations.md) for the per-query
> opt-in that covers them.

**2 — `Capture\ModelCapture` builds the entry.** First gate: `Sentinel::isRecording()`, which is
`config('sentinel.enabled') && ! paused`. If it is false the method returns and nothing at all
happens — no event, no log line. Then `Snapshot\SnapshotBuilder::pair()` builds the complete
before/after state through the model's own casts, `Diff\Diff::between()` turns the pair into the
change list, and a diff path matching a column in `$auditTransitions` swaps `audit_type` and
`event` to `transition`. The result is a `Data\AuditData` — a mutable object whose properties are
named after the audit columns, and which deliberately has no `sequence`, `hash` or `previous_hash`.

**3 — `Capture\Recorder` stamps what belongs to every entry.** `capture_id` is set with `??=`, so a
caller that brought its own keeps it; it is a ULID with a unique index behind it and is the
idempotency key for the whole path. `Transactions\TransactionScope::stamp()` writes
`transaction_id` if a business transaction is open. Both happen before the pipeline, because the
correlation has to be sealed while the scope that owns it is still open.

**4 — `Pipeline\Pipeline::process()` runs the stages.** The list comes from
`config('sentinel.pipeline')`, defaulting to `Pipeline::DEFAULT_STAGES`. Each stage is resolved
from the container on every entry, so constructor injection works. A stage returning `null` ends
the pass; the first stage to return null owns the discard. The pass is opened with
`Discard::begin()` and closed in a `finally`, so a stage that throws still leaves the discard state
consistent.

**5 — the `Auditing` event is announced.** Last, at the end of the pipeline and not before it: a
listener holds an entry that is already masked, digested and encrypted, so nothing reaches a
listener in the clear that the ledger will not also hold. Returning `false` stops the entry with
the reason `cancelled`. `subject_type` and `subject_id` are restored in a `finally` afterwards — a
listener may change what the entry says about itself, never what it is about.

**6 — What the capture named goes on the entry a second time.** `ResolveContext` already applied
it inside the pipeline: an actor named at the call site, with the impersonator columns cleared —
whoever the session resolved was standing in for the actor resolved alongside them, not for the one
just named — and, for a redaction trail, the tenant of the entry it redacts. The recorder applies it
once more here, for a published stage list that left `ResolveContext` out: the entry is attributed
as named all the same, and only what the pipeline got to see is different.

**7 — `Dispatch\Dispatcher::dispatch()` decides where and when.** It asks the *subject's*
connection — not the audit connection — whether a transaction is open. With
`transactions.after_commit` on and a level above zero, the hand-over is registered in
`Connection::afterCommit()`; a rollback then throws the entry away in every mode, silently and by
design. Otherwise the hand-over happens on the spot. It then resolves one strategy per entry, so
the mode may change between two writes.

**8 — `Dispatch\SyncStrategy` settles it here.** It calls `Dispatch\Settlement::settle()`, which
announces `AuditCreating` (announced, never consulted — the sequence is about to be assigned and
refusing here would leave a gap), calls `Ledger::write()`, then announces `AuditCreated`.

**9 — the ledger seals it.** `Ledger\DatabaseLedger::write()` is `writeMany([$audit])`, so the
single write and the batch take exactly the same path. Inside one transaction:
`Ledger\StreamGate::tail()` locks the stream and reads its last `sequence` and `hash`;
the ledger takes the next `version` for the subject; `Ledger\EntryBuilder::build()` mints the ULID,
stamps `payload_version = 1` and the configured algorithm, hashes over the frozen canonical
payload, signs the hash, and attaches labels as a **loaded relation** so they stay out of
`getAttributes()` and out of the hash. Then the rows go in, the label rows go in, and the relation projection is written — all
in the same transaction.

**10 — the events close.** `AuditCreated` carries the sealed `Models\Audit`. Back in the
dispatcher, `TransactionScope::settled()` increments the operation's counter, `Audited` is
announced with the entry, and a `$settled` callback (used by the restore engine) is invoked.

> 🧪 **Verify it.** `$invoice->latestAudit()->sequence` and `->previous_hash` exist the moment
> `update()` returns under `sync`. Under `queue` or `buffered` there is no entry to read yet — see
> the two walks below.

## The seven stages

| # | Stage | What it does | Can it stop the entry? |
|---|---|---|---|
| 1 | `FilterUnchanged` | Drops an entry whose comparison ran and came back empty: `changes === []` on an `updated` or on any `relation` entry, or `affected_rows === 0` on a mass entry. | Yes — reason `unchanged` |
| 2 | `ResolveContext` | Runs the context engine: actor, impersonator, tenant, source, `request_id`, `trace_id`, `span_id` and the `context` JSON. | No |
| 3 | `ResolveTags` | Merges the model's `$auditTags`, whatever the caller put on the entry, and `tags.default`. A label over 64 characters throws `ConfigurationException`. | No (throws) |
| 4 | `NormalizeData` | Recursively `ksort`s `before`, `after`, `metadata` and `context`. Leaves `changes` alone — there, position is meaning. | No |
| 5 | `MaskSensitiveData` | Applies the redaction list, then the hashing list, matching key names at any depth. | No |
| 6 | `EncryptSensitiveData` | Encrypts declared fields under the current key and writes `encryption = {fields, key_id}`. An unknown or unusable key throws `EncryptionException`. | No (throws) |
| 7 | `EnforcePolicies` | Applies every predicate registered with `Sentinel::filter()`, on the entry as it will be written. | Yes — reason `policy` |

The order is first-runs-first and it is load-bearing in two places. `FilterUnchanged` is first for
cost: an entry discarded there pays no context resolution, no mask and no encryption.
`EnforcePolicies` is last so a policy decides on the finished entry rather than on plaintext the
ledger never sees. Full detail, including how to insert your own stage, is in
[The write pipeline](../05-pipeline-and-security/01-the-write-pipeline.md).

> ⚠️ **Warning.** `'pipeline' => []` means *the shipped list*, not an empty pipeline — Laravel's
> config merge is one level deep, and an installation that published the file before the key
> existed would otherwise transform nothing and say nothing about it. Declaring a list also pins
> you: a stage a later version adds will not run until you name it.

## What is decided where

| Field | Set by | When |
|---|---|---|
| `occurred_at` | the capture | at capture — never moves afterwards |
| `capture_id` | `Recorder::identify()` (`??=`) | at capture |
| `transaction_id` | `TransactionScope::stamp()` | at capture |
| `source`, actor, impersonator, `tenant_id`, `request_id`, `trace_id`, `span_id`, `context` | `ResolveContext` | in the pipeline, in the capturing process |
| `tags` | `ResolveTags` | in the pipeline |
| `encryption` | `EncryptSensitiveData` | in the pipeline |
| `id` (ULID) | `EntryBuilder` | at settlement |
| `stream` | `Integrity\Stream`, called by the ledger | at settlement |
| `sequence`, `previous_hash` | `StreamGate::tail()` + `EntryBuilder` | at settlement, inside the write |
| `version` | the ledger, per `(subject_type, subject_id)` | at settlement |
| `payload_version`, `algorithm` | `EntryBuilder` | at settlement |
| `hash` | `Integrity\Hasher` | at settlement |
| `signature`, `signature_key_id` | `Integrity\Signers` | at settlement |
| `created_at` | `EntryBuilder` | at settlement |

Two consequences fall straight out of the table. First: `occurred_at` and `created_at` agree under
`sync` and stop agreeing the moment settlement leaves the request — see
[Order, paging and walking](../06-reading/03-order-paging-and-walking.md). Second: `sequence`,
`hash`, `previous_hash` and `payload_version` are the ledger's alone, and a payload arriving from a
queue or a buffer that names any of the first three is refused with `DispatchException` rather than
taken at its word.

## Where an entry can die

| Point | Mechanism | What you observe | Leaves a gap? |
|---|---|---|---|
| Recording is off | `config('sentinel.enabled')` false, or `Sentinel::pause()` | nothing — no entry, no event | no |
| `FilterUnchanged` | stage returns `null` | `AuditDiscarded`, reason `unchanged` | no |
| `EnforcePolicies` | stage returns `null` | `AuditDiscarded`, reason `policy` | no |
| Your own stage | returns `null` after `Discard::because('…')` | `AuditDiscarded` with your reason, or `unspecified` | no |
| An `Auditing` listener | returns `false` | `AuditDiscarded`, reason `cancelled`, stage `Events\Auditing` | no |
| Rollback | the commit callback never runs | nothing — no event | no |
| A duplicate `capture_id` | `Deduplicates`, then the unique index | nothing written for that capture; the rest of a batch still settles | no |
| A write that failed | see the next section | `AuditWriteFailed` | no |
| A buffer that died holding entries | process death, or the buffer refusing `putBack()` | `BufferFlushFailed` at best; nothing at worst | no |

Every row says **no**, and that is the single most important sentence about verification on this
page: a discarded, refused or lost entry consumed no `sequence`, so it leaves nothing for
`verifyIntegrity()` to find. The chain proves that what settled was not tampered with. It cannot
prove that everything that happened settled. Loss detection is out of band — see
[Monitoring and troubleshooting](../09-operations/08-monitoring-and-troubleshooting.md).

Discarding is legal only while the pipeline pass is open. `Discard::because()` called after the
ledger has assigned a sequence throws `DiscardException::outsideThePipeline()`, because a hole in
the chain is exactly what verification reports as tampering.
[Discarding entries](../05-pipeline-and-security/06-discarding-entries.md) covers the mechanism.

## What a failure costs

`on_write_failure` governs one thing: an exception raised while **settling**, on a path where the
caller is still on the stack. It does not reach anything else on the walk.

| Where it throws | Caught by | Under `on_write_failure = throw` | Under `log` |
|---|---|---|---|
| Snapshot or diff (step 2) | nobody | propagates out of `save()` | propagates out of `save()` |
| A pipeline stage (step 4) | nobody | propagates out of `save()` | propagates out of `save()` |
| An `Auditing` listener (step 5) | nobody | propagates out of `save()` | propagates out of `save()` |
| Settlement in the request | `Capture\WriteFailure::inRequest()` | `AuditWriteFailed`, then the exception is rethrown | `AuditWriteFailed` + one line on `log_channel` |
| Settlement after a commit | `Capture\WriteFailure::afterCommit()` | `AuditWriteFailed` + one log line — never thrown | identical |
| Inside a queue worker | nobody in this package | the queue retries, then `failed_jobs` | identical |

> ⚠️ **Warning.** Setting `on_write_failure = log` does **not** make auditing incapable of breaking
> a request. A stage that throws — an over-long label, an encryption key that is not on the keyring,
> an attribute the canonicaliser cannot represent — takes the `save()` down with it whatever the
> policy says, because the policy is applied around the ledger write and nothing else.

A deferred write never propagates, and that is deliberate rather than lenient: Laravel runs commit
callbacks in a bare `foreach`, so throwing there would stop every later entry of the same
transaction from being attempted and would surface out of a `DB::transaction()` that has already
committed. Details in [Failure policy](../09-operations/05-failure-policy.md).

## The same walk under `queue`

Steps 1 through 7 are **identical**. The pipeline always runs in the process that captured, in
every mode: a sensitive value must not exist untransformed even for the moment it waits to be
picked up, and the context describes the request, not the worker that will write the row.

```php
use ElPandaPe\Sentinel\Events\AuditCreated;
use ElPandaPe\Sentinel\Events\Audited;
use Illuminate\Support\Facades\Event;

// config/sentinel.php — 'mode' => 'queue'

$invoice->update(['status' => 'sent']);

// In the request:
Event::listen(function (Audited $event): void {
    $event->audit->event;   // 'updated' — always there
    $event->entry;          // null: settled elsewhere, not "not settled"
});

// In the worker:
Event::listen(function (AuditCreated $event): void {
    $event->entry->sequence;   // assigned here, in the worker
});
```

The divergence:

| Step | `sync` | `queue` |
|---|---|---|
| 8 | `SyncStrategy` calls `Settlement::settle()` | `QueueStrategy` builds `Jobs\SettleAudit` from `AuditData::toPayload()`, applies `queue.connection` and `queue.queue`, marks it `->afterCommit()` whenever `transactions.after_commit` is on, and dispatches it |
| — | `Handover::settled($entry)` | `Handover::accepted()` — accepted, no entry, and there will not be one here |
| 9 | ledger, in the request | ledger, in the worker, through `Settlement::settleOnce()` |
| 10 | `AuditCreated` then `Audited($entry)` | `Audited(null)` in the request; `AuditCreated` in the worker |

What resolves earlier or later:

- **Earlier than you might expect:** everything about the *content* of the entry. Context, labels,
  masks, digests, ciphertext and the `Auditing` veto are all finished before the job is dispatched.
  The job payload is a plain array, not a serialised model — a worker on the previous release can
  read a payload the current one wrote, because unknown keys are dropped and missing ones take
  their constructor defaults.
- **Later:** `id`, `stream`, `sequence`, `previous_hash`, `hash`, `version`, `created_at` and the
  signature. `Dispatcher::dispatch()` returns `null`, so nothing in the request can name the entry.
- **The failure boundary moves.** A queue that refuses the job is a write that did not complete and
  `on_write_failure` decides what it costs the request. A failure *inside* the worker is the
  queue's business: `SettleAudit` catches nothing, the retry runs under the same `capture_id`
  (which settles at most once), and what never lands ends in `failed_jobs`.
- **Batches do not batch.** `QueueStrategy::inRequestBatch()` maps to one job per entry. A mass
  operation over five thousand rows enqueues five thousand jobs.

> 📌 **Note.** While the worker is settling an entry, `Context\Runtime` is marked as writing an
> audit, so any capture that happens *inside* settlement is sourced as `queue` rather than `job`.

## The same walk under `buffered`

Again, steps 1 through 7 are identical, and for the same reason.

```php
// config/sentinel.php
'mode' => 'buffered',
'buffer' => [
    'store' => 'redis',
    'key' => 'sentinel:buffer',
    'size' => 500,            // flush when this many are waiting
    'flush_interval' => 60,   // or when the oldest fact is this old, in seconds
],
```

`Dispatch\BufferStrategy::hand()` pushes the finished `AuditData` into the buffer **first**, then
evaluates the two thresholds through `Buffer\Flusher::due()`. Order matters: the entry that
triggers a flush is already safe, so a flush that fails is always about the entries that were there
before it. The hand-over is `Handover::accepted()` either way.

`Buffer\Flusher::flush()` is the one method every trigger passes through. It takes batches of
`buffer.size`, settles each through `Settlement::settleBatch()`, and on a failure puts the batch
back at the head, dispatches `BufferFlushFailed` with `taken` / `settled` / `returned`, and
rethrows.

The divergence:

| Step | `sync` | `buffered` |
|---|---|---|
| 8 | settles now | `push()`, then flush **only if** a threshold is met |
| 9 | one entry, one tail read | one tail read per stream for the whole batch — that is the whole point of the mode |
| 10 | `AuditCreated` then `Audited($entry)` | `Audited(null)` at push; `AuditCreated` per entry, wherever the flush ran |

What is different in kind, not just in timing:

- **The buffer is not a ledger.** What is in it has no `sequence`, no `hash` and no place in any
  chain. It is the only mode that can lose a fact outright: what a process dies holding never
  reached the chain and leaves no gap.
- **The thresholds are evaluated only when an entry arrives.** Nothing in PHP watches a clock
  between requests, so `flush_interval` bounds the wait only while there is traffic. What bounds a
  quiet buffer is the `terminating` hook, the `WorkerStopping` hook, and `php artisan sentinel:flush`
  on a schedule.
- **Both floors are 1.** `buffer.size => 0` becomes 1 — a flush on every entry — not "never flush".
- **Switching away strands what is waiting.** Both shutdown hooks return early and `sentinel:flush`
  exits `2` when the mode is not `buffered`. Flush first, confirm zero, then switch.

Everything else about this mode, including what `BufferFlushFailed::skipped()` actually counts, is
in [The buffered mode](../09-operations/02-the-buffered-mode.md).

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `$invoice->latestAudit()` is `null` immediately after a save, but the entry appears later | Under `queue` or `buffered`, `Dispatcher::dispatch()` returns `null` and the entry does not exist yet. Under `sync` inside a `DB::transaction()`, the write is deferred to the commit. | Read after the commit, or listen for `AuditCreated`, which fires wherever the ledger assigned identity. |
| A save throws even though `on_write_failure` is `log` | The policy is applied only around the ledger write. A stage that throws — an over-long label, a missing encryption key, an unrepresentable attribute — is not caught anywhere. | Fix the declaration. Do not expect the policy to shield the request from a configuration error. |
| An `updated` produced no entry at all and no exception | `FilterUnchanged` discarded it: the diff was empty, because only excluded columns or only timestamps moved. | Listen for `AuditDiscarded` (reason `unchanged`), or declare the pipeline without `FilterUnchanged` if you want those entries. |
| `AuditDiscarded` names your stage with reason `unspecified` | The stage returned `null` without calling `Discard::because()`. | Call `because('…')` before returning `null`; the string is what an operator reads. |
| `DiscardException` thrown from an `AuditCreated` listener | Discarding is legal only while the pipeline pass is open; by `AuditCreated` the sequence exists. | Refuse in a stage, in a `Sentinel::filter()` policy, or by returning `false` from `Auditing`. |
| An entry's actor is the resolved session user, not the one passed at the call site | The actor was named by mutating `AuditData` in a stage placed before `ResolveContext`, which reassigns the actor columns on every pass | Name the actor through the capture API (`Sentinel::event(…)->actor($user)`): `ResolveContext` applies it itself, and the recorder once more after the pipeline |
| Nothing was written and the transaction "succeeded" | The deferred hand-over never ran because the transaction rolled back — by design, and silently. | Nothing to fix. If you need the entry regardless, `transactions.after_commit = false` asks the ledger to keep claiming facts a rollback undid. |
| `verifyIntegrity()` reports the chain intact although entries are missing | A discarded, refused or buffer-lost entry consumed no sequence, so there is no gap. | Detect loss out of band: `BufferFlushFailed`, `AuditWriteFailed`, the `sentinel:flush` count and exit code. |
| Two entries for the same fact after a retry | The retrying code generated a fresh `capture_id` instead of carrying the one it already had. | Retry under the identifier the capture already had; the unique index only refuses a *repeated* id. |
| An `Auditing` listener changed `subject_id` and it had no effect | The pipeline restores `subject_type` and `subject_id` in a `finally` — the subject decides which chain signs the entry. | Change `metadata`, `context` or `tags` instead. |

## ✅ Best practices

✅ **Do** — put a stage that may discard *before* `MaskSensitiveData`, and one that only annotates
*after* `EncryptSensitiveData`. A discard placed early pays for no mask and no encryption.

```php
// config/sentinel.php
'pipeline' => [
    ElPandaPe\Sentinel\Pipeline\Stages\FilterUnchanged::class,
    App\Sentinel\DropRoutineReads::class,          // may discard: cheap here
    ElPandaPe\Sentinel\Pipeline\Stages\ResolveContext::class,
    ElPandaPe\Sentinel\Pipeline\Stages\ResolveTags::class,
    ElPandaPe\Sentinel\Pipeline\Stages\NormalizeData::class,
    ElPandaPe\Sentinel\Pipeline\Stages\MaskSensitiveData::class,
    ElPandaPe\Sentinel\Pipeline\Stages\EncryptSensitiveData::class,
    ElPandaPe\Sentinel\Pipeline\Stages\EnforcePolicies::class,
],
```

❌ **Don't** — add a stage after `EncryptSensitiveData` that recomputes `changes` from `before` and
`after`. Two ciphertexts of the same value never match, so every field would report as changed and
`FilterUnchanged` would stop filtering anything.

```php
// in a stage placed at the end of the list
$audit->changes = Diff::between($audit->before ?? [], $audit->after ?? [])->toArray();
```

---

✅ **Do** — read the settled entry from `AuditCreated`, which is announced wherever the ledger
assigned identity, and treat `Audited::$entry` as "this process is done with it".

```php
use ElPandaPe\Sentinel\Events\AuditCreated;
use Illuminate\Support\Facades\Event;

Event::listen(function (AuditCreated $event): void {
    $event->entry->sequence;
    $event->entry->hash;
});
```

❌ **Don't** — treat a null `Audited::$entry` as a failure. Null means "settled elsewhere"; a write
that did not complete announces `AuditWriteFailed`, and the two never both go out for one capture.

```php
Event::listen(function (Audited $event): void {
    if ($event->entry === null) {
        report(new RuntimeException('audit lost'));   // wrong under queue and buffered
    }
});
```

---

✅ **Do** — decide keep-or-drop with `Sentinel::filter()` when you do not need to change the entry.
The registry is a plain singleton, so a policy survives a queue worker's scope resets.

```php
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Facades\Sentinel;

// in a service provider's boot(): keep read entries only when they matter
Sentinel::filter(static fn (AuditData $audit): bool => $audit->audit_type !== 'access'
    || $audit->severity !== Severity::Info);
```

❌ **Don't** — try to stop an entry once it has identity. `Discard::because()` throws there, and the
chain admits no holes.

```php
use ElPandaPe\Sentinel\Events\AuditCreated;
use ElPandaPe\Sentinel\Pipeline\Discard;

Event::listen(function (AuditCreated $event): void {
    app(Discard::class)->because('changed my mind');   // DiscardException::outsideThePipeline
});
```

---

✅ **Do** — audit every query that rebuilds a lifeline from `created_at` *before* moving off `sync`,
and move it to `byOccurrence()` or `Sentinel::timeline()`.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

Sentinel::audits()->for($invoice)->byOccurrence()->get();   // the order things happened
Sentinel::timeline()->for($invoice)->get();
```

❌ **Don't** — keep ordering a history by the settlement clock once settlement has left the request.
`created_at` is stamped in the ledger; `occurred_at` at capture.

```php
Sentinel::audits()->for($invoice)->get();   // the order entries settled, not the order of the facts
```

---

✅ **Do** — carry the `capture_id` a retry already had, so one fact stays one entry.

```php
use ElPandaPe\Sentinel\Models\Audit;

$entry = Audit::query()->latest('sequence')->sole();

$entry->capture_id;   // names the capture — unique index, stable across retries
$entry->id;           // names the entry
```

❌ **Don't** — generate a fresh identifier when re-running a unit of work. The database will accept
both rows, and history is append-only: there is no way to take the second one back except a
redaction.

```php
use Illuminate\Support\Str;

$data->capture_id = (string) Str::ulid();   // turns one fact into two entries
```

---

✅ **Do** — flush and confirm the buffer is empty before changing `mode` away from `buffered`.

```bash
php artisan sentinel:flush   # must report 0 settled before you switch
```

❌ **Don't** — flip the mode with entries still waiting. Both shutdown hooks return early and
`sentinel:flush` exits `2` under any other mode, so those entries sit there with nothing reporting
them.

```bash
# SENTINEL_MODE=sync   ← deployed while the Redis list was non-empty
```

---

**See also:** [The audit record](02-the-audit-record.md) · [The integrity model](04-the-integrity-model.md) · [Architecture](05-architecture.md) · [The write pipeline](../05-pipeline-and-security/01-the-write-pipeline.md) · [Discarding entries](../05-pipeline-and-security/06-discarding-entries.md) · [Execution context](../04-context/01-execution-context.md) · [Performance modes](../09-operations/01-performance-modes.md) · [The buffered mode](../09-operations/02-the-buffered-mode.md) · [Running audits on a queue](../09-operations/03-queues.md) · [Failure policy](../09-operations/05-failure-policy.md) · [Events and listeners](../09-operations/04-events-and-listeners.md) · [The hash chain](../07-integrity/01-the-hash-chain.md) · [The Ledger contract](../11-extending/01-the-ledger-contract.md) · [Events reference](../99-reference/05-events.md)
