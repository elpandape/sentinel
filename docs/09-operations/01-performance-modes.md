# ⚙️ Performance modes

> One configuration key decides where and when an already-captured, already-transformed audit entry
> settles in the ledger — and what you give up in exchange for the latency you get back.

**On this page:** [The straight answer](#the-straight-answer) ·
[One setting, three routes](#one-setting-three-routes) ·
[The decision table](#the-decision-table) · [What each mode costs](#what-each-mode-costs) ·
[What else changes under an asynchronous mode](#what-else-changes-under-an-asynchronous-mode) ·
[The two clocks](#the-two-clocks) · [A retry is not a second entry](#a-retry-is-not-a-second-entry) ·
[Switching modes on a live system](#switching-modes-on-a-live-system) ·
[⚠️ Pitfalls](#-pitfalls) · [✅ Best practices](#-best-practices)

---

## The straight answer

**Stay on `sync`.** It is the shipped default, and it is the only mode in which the caller can still
be told that the write did not work — under `queue` and `buffered` the request has already returned
by the time the ledger is touched, so `on_write_failure` has nobody left to throw at. Do not change
this key because a benchmark looked good. Change it when request latency is a **measured** problem
you have traced to the audit write, and not before.

When it is, the two asynchronous modes are not interchangeable, and the difference is not speed:

| | Reach for it when | What it costs you |
|---|---|---|
| **`queue`** | The request must get faster and you cannot accept losing an entry. Durability is the queue's, so a durable connection means a captured fact still settles exactly once | About **8 % more end-to-end** than `sync` — one job per entry, and somebody dequeues it. `created_at` and `sequence` stop being capture order. A worker pool that backs up delays the whole trail |
| **`buffered`** | Ingestion volume, not page latency, is the constraint: bulk imports, event streams, a request budget in single-digit milliseconds | It is **the only mode that can lose a fact outright**, and the chain provably cannot tell you that it did. It needs Redis, a scheduled flush, and somebody who has written the loss window down before shipping |

> ⚠️ **Warning.** `queue` is the mode people pick by default and it is rarely the right one. It makes
> the *request* about twice as fast and the *system* slightly slower, and it hands you a second
> moving part — a worker pool — whose backlog is now part of your audit trail's correctness. If page
> latency is the problem, measure whether the audit write is really what is causing it first; if
> throughput is the problem, `queue` is not the mode that solves it.

Whichever you pick, **nothing in your application code changes**, and neither does what an entry
contains. All three run the whole pipeline in the capturing process; the mode decides only where the
finished entry settles. The figures behind the percentages above, with their method and their noise,
are in [What each mode costs](#what-each-mode-costs).

> 💡 **Tip.** Per reader profile — small app, tenant SaaS, regulated, high-write, back-office — the
> mode each one should run and what it must schedule is in
> [Choosing your setup](../02-getting-started/05-choosing-your-setup.md).

---

## One setting, three routes

```php
// config/sentinel.php
'mode' => env('SENTINEL_MODE', 'sync'),   // 'sync' | 'queue' | 'buffered'
```

`ElPandaPe\Sentinel\Dispatch\Dispatcher` reads that key on **every entry** and resolves one
`ElPandaPe\Sentinel\Contracts\DispatchStrategy` from it — `SyncStrategy`, `QueueStrategy` or
`BufferStrategy`. That is the whole of this area. Nothing in your application code changes between
modes: you do not branch at the point of capture, and no model, listener or query is written
differently for one mode than for another.

Because the strategy is resolved per call rather than held, the mode is allowed to change between
two writes — which is what makes it a live setting rather than a boot-time one.

| Mode | Enum case | Where the entry settles |
|---|---|---|
| `sync` | `Enums\Mode::Sync` | In the process that captured it, inside the call that caused it |
| `queue` | `Enums\Mode::Queue` | In a queue worker, from `Jobs\SettleAudit` |
| `buffered` | `Enums\Mode::Buffered` | In a batched flush out of a Redis list |

A fourth value is not a fourth mode. `Support\Config::mode()` casts the string through the enum and
raises `Exceptions\ConfigurationException` when it does not match:

```
Sentinel configuration key [sentinel.mode] has unknown value [async]. Accepted: sync, queue, buffered.
```

There is no silent fallback to `sync`. An installation that mistypes the key fails at the first
write, loudly, rather than running for a month in a mode nobody chose.

> 🧪 **Verify it.** `php artisan about` prints a Sentinel section whose **Mode** row is
> `Config::mode()->value` — the mode the package will actually use, not the one in the file you are
> reading.

### What does not change with the mode

This is the shorter and more important list.

- **The pipeline always runs in the capturing process.** Filtering, context resolution, label
  resolution, normalisation, masking, encryption and policy enforcement all happen before the
  dispatcher is reached, in every mode. What travels to a worker or waits in a buffer is a finished
  `Data\AuditData` — never an Eloquent model, and never an unresolved context. See
  [The write pipeline](../05-pipeline-and-security/01-the-write-pipeline.md).
- **Nothing outside the ledger proposes a position in the chain.** `sequence`, `hash` and
  `previous_hash` are read and assigned inside the same operation as the write. A job payload or a
  buffer element naming any of the three is refused with `Exceptions\DispatchException` in
  `AuditData::fromPayload()`. This is the single rule that makes asynchronous settlement and a
  tamper-evident chain compatible: arrival order is not the order facts happened in, and the ledger
  is the only thing that gets to number them. See [The hash chain](../07-integrity/01-the-hash-chain.md).
- **A rollback throws the entry away in every mode.** With `transactions.after_commit` on (the
  default), the dispatcher asks the *subject's* connection whether a transaction is open and, if so,
  registers the hand-over in `Connection::afterCommit()`. Nothing is enqueued and nothing is buffered
  until the commit lands.
- **The chain is byte-for-byte the same.** A batch settled by a flush produces the same chain as the
  same entries written one at a time — `tests/Dispatch/ChainUnderModesTest.php` asserts exactly that,
  and re-verifies frozen hashes after a full cycle in each mode.

---

## The decision table

| | `sync` | `queue` | `buffered` |
|---|---|---|---|
| **Route** | Capture → pipeline → ledger | Capture → pipeline → `SettleAudit` job → ledger | Capture → pipeline → Redis list → batched flush → ledger |
| **What the request pays** | Pipeline + tail read + insert | Pipeline + one enqueue | Pipeline + one `RPUSH` (+ a flush when a threshold is met) |
| **What the system pays end to end** | The write, once | The write plus the enqueue and the dequeue — deferring moves work, it does not remove it | The write, with one tail read and one transaction amortised over `buffer.size` entries |
| **What can be lost** | Nothing the database accepted | Nothing, if the queue is durable; what never lands ends in `failed_jobs` | Everything a dying process was holding in the buffer |
| **Can the caller be told the write failed?** | Yes — `on_write_failure` governs it | Only that the *enqueue* failed | Only that a *flush* failed, and it names the wrong entry (see pitfalls) |
| **`RestoreResult::$entry` after a restore** | The settled `Models\Audit` | `null` | `null` |
| **`Events\Audited::$entry`** | The `Audit` | `null` | `null` |
| **Where `Events\AuditCreated` fires** | In the request | In the worker | In whichever process ran the flush |
| **Batch behaviour** | One chain assignment for the whole batch | **One job per entry** — no batching | Pushed whole, settled in batches of `buffer.size` |
| **Extra infrastructure** | None | A queue connection | Redis, plus a scheduled `sentinel:flush` |

> 📌 **`sync` is the default, and it is the right default.** It is the only mode in which the caller
> can still be told the write did not work. Move off it when request latency is a *measured* problem,
> not a suspected one.

---

## What each mode costs

The package ships its own benchmark rather than a claim: `benchmarks/bench.php`, run with
`make bench`. It is **a report, never a gate** — the Makefile says so — and the figures it prints are
one machine's, on one engine, in one run. Run it on your hardware; do not carry someone else's
numbers into a capacity plan.

What the benchmark measures for this section is three separate questions, because they are three
separate numbers:

| Question | What it means to you |
|---|---|
| What the request pays | What the user waits for |
| What the worker or the flush pays | What the database sees, later |
| The two together | What the mode costs your infrastructure |

Its methodology, read from the script rather than reported:

- 1 000 measured writes per mode after 200 warm-up writes, one `create()` per iteration.
- SQLite with `pragma synchronous = off` and `journal_mode = memory`, so the baseline measures what
  the package costs and not what a container's `fsync` costs.
- The queued figures use the **`database` queue driver**, not a null one: a queue that discards the
  job is not measuring the enqueue. Redis would be faster; this is the slowest realistic floor.
- `sync` runs first on purpose. The table grows through the pass, so whichever mode runs last carries
  the larger index — running `sync` first makes the queued figure the conservative one.
- The worker figure is the payload read back and settled, with the worker loop, the reserve and the
  delete left out: those belong to the framework and are the same whatever is inside the job.
- A snapshot-less model variant is measured beside each mode, so "is the snapshot the expensive part?"
  is answered by the same run rather than by opinion.

### The figures the package has published

Two releases ran that harness and printed the result in `CHANGELOG.md`. Both are **medians of three
passes, on one machine, inside one run**. They are the only measured write-path figures this package
publishes for the modes; everything else on this page is mechanism.

`v0.16.1` — the only published table that carries all three modes:

| | Per write (µs) | vs. `sync` |
|---|---|---|
| Not audited | 179 | — |
| `sync`, in the request | 2068 | — |
| `queue`, what the request pays | 1077 | −48 % |
| `queue`, what the worker pays to settle one | 1161 | — |
| `buffered`, what the request pays | 1194 | −42 % |
| `buffered`, what the flush pays per entry | 655 | — |

`v0.16.0` had measured `sync` and `queue` alone, on SQLite with `synchronous` and the journal off,
and landed on the same shape:

| | Per write (µs) | vs. `sync` |
|---|---|---|
| Not audited | 160 | — |
| `sync`, in the request | 1991 | — |
| `queue`, what the request pays | 1041 | −48 % |
| `queue`, what the worker pays to settle one | 1071 | — |

What those two releases concluded from their own numbers, and nothing further: `queue` halves what the
request pays and adds to the end-to-end total — `v0.16.0` read that as about six per cent, `v0.16.1`
as about eight — while `buffered` is **the only mode whose end-to-end total came out below `sync`**,
by about eleven per cent.

The **mechanism** behind the shape of those numbers is what you can rely on without measuring:

- `queue` removes the tail read and the insert from the request and adds an enqueue. The total is
  therefore *more* than `sync`, not less — one job per entry means the fan-out cost is paid by
  whoever dequeues it.
- `buffered` removes the same work from the request and then settles `buffer.size` entries in **one**
  tail read and **one** transaction. That amortisation is the only mechanism among the three by which
  the end-to-end total can come out below `sync`. Whether it does on your hardware, at your batch
  size, is what `make bench` answers.

> ⚠️ **The benchmark is not a gate, and the reason matters when you read it.** Figures move between
> passes on the same machine, and the same harness publishes its own noise gauge: with the `v0.18.0`
> signing figures, a variant that adds one method call still moved between −10 % and +12 % across
> passes, and three passes of one unchanged build spanned 34 %. So read the tables above as
> directions and orders of magnitude, never as a capacity plan. Treat a single-digit percentage
> difference between two modes as noise; act only on differences that survive several runs — the kind
> you get from moving the insert out of the request altogether.

---

## What else changes under an asynchronous mode

This is where teams get surprised, so it is enumerated rather than summarised.

### Context is resolved at capture, not at settlement

The actor, the impersonator, the tenant, the source, the IP, the request id and the trace are all
resolved by the pipeline, in the process that captured the fact. They travel *inside* the entry.

This is not an implementation detail you could change: resolving context where the entry settles
would file every entry under a queue worker or a console command that did nothing. The tenant in
particular decides the **stream**, which is to say which chain signs the entry.
`tests/Dispatch/QueueModeTest.php` and `tests/Dispatch/BufferedModeTest.php` each carry a test named
for it — *"keeps the context of the request that captured it"*, not of the worker that wrote it and
not of the flush that settled it.

The consequence for you: **a resolver that reads request-scoped state keeps working under `queue` and
`buffered`.** See [Execution context](../04-context/01-execution-context.md) and
[Queues, commands and schedulers](../04-context/05-queues-commands-and-schedulers.md).

### The entry is not there when the call returns

`Dispatcher::dispatch()` returns `null` and `dispatchMany()` returns an empty collection under both
asynchronous modes. Two places in the public API show it:

```php
use App\Models\Invoice;
use ElPandaPe\Sentinel\Models\Audit;

$invoice->update(['status' => 'approved']);

$invoice->latestAudit();
// sync      → the entry that was just written
// queue     → whatever was there before this update, or null
// buffered  → the same, until a flush settles it

$audit = Audit::query()->findOrFail($auditId);
$result = $audit->restore(['total', 'status']);

$result->applied;   // the fields that were put back — correct in every mode
$result->entry;     // the entry describing the restoration:
                    // sync → Models\Audit; queue and buffered → null
```

`Restore\RestoreResult::$entry` is nullable and always was; asynchronous settlement is where the null
becomes the normal case rather than the failure case. A restoration under `queue` or `buffered` still
appends an entry — history stays append-only in every mode — you simply cannot name it from the call
that caused it.

### `Audited` no longer carries the entry — and null does not mean failure

`Dispatch\Handover` has three states and not two, deliberately: *settled here*, *accepted, settling
elsewhere*, and *refused*. Collapsing them into a nullable entry is exactly what would make a queued
audit indistinguishable from a lost one.

```php
use ElPandaPe\Sentinel\Events\Audited;
use ElPandaPe\Sentinel\Events\AuditCreated;
use Illuminate\Support\Facades\Event;

// Announced in the process that captured: "Sentinel is done with this entry here."
Event::listen(function (Audited $event): void {
    $event->audit->event;   // always present
    $event->entry;          // Audit under sync; null under queue and buffered
});

// Announced wherever the ledger assigned identity — the worker, or the flush.
Event::listen(function (AuditCreated $event): void {
    $event->entry->id;
    $event->entry->sequence;
});
```

A null `Audited::$entry` means **settled elsewhere**, never *not settled*. A write that did not
complete announces `Events\AuditWriteFailed` instead of `Audited` — except under `buffered`, where a
threshold flush that failed announces `AuditWriteFailed` for the entry that arrived and `Audited` for
that same entry, because it is safely in the buffer either way.
Move any listener that needs the settled `Audit` to `AuditCreated`. See
[Events and listeners](04-events-and-listeners.md).

### `audits_count` counts what was handed over, not what landed

`Transactions\TransactionScope::settled()` is called by the dispatcher on any **accepted** hand-over,
because a business transaction's header closes long before a worker or a flush runs. A refused
hand-over is not counted. Under `queue` and `buffered`, read
`sentinel_transactions.audits_count` as *"what this operation accepted for settlement"*.

### The write-failure policy governs the request only

`on_write_failure` (`throw` by default, `log` the alternative, forced to `throw` by compliance mode)
decides what a write that did not complete costs the request that caused it. Its reach is narrower
than most people assume:

| Failure | Governed by `on_write_failure`? | What you hear |
|---|---|---|
| A synchronous write that failed in the request | Yes | `AuditWriteFailed`, then the exception or a log line |
| An enqueue the queue refused | Yes | `AuditWriteFailed`, then the exception or a log line |
| A threshold-triggered flush that failed | Yes | `AuditWriteFailed` **naming the wrong entry**, plus `BufferFlushFailed` |
| Any write deferred to `afterCommit` | No — always announced and recorded, never thrown | `AuditWriteFailed` and a log line |
| A settlement that failed inside a real worker | No | The queue's own events and `failed_jobs` |
| A flush at `terminating` or `WorkerStopping` | No | `BufferFlushFailed`, plus the throwable reported to the application's exception handler by the `rescue()` around the hook |

The deferred case is not a preference. Laravel runs commit callbacks in a bare `foreach`, so throwing
there would stop every later entry of the same transaction from being attempted at all — an
append-only engine losing the rest of an operation — and would surface out of a `DB::transaction()`
that has already committed. See [Failure policy](05-failure-policy.md).

> ⚠️ **The package deliberately announces nothing from inside a real worker.** `Jobs\SettleAudit`
> catches nothing: in a worker the queue *is* the failure policy — it retries under the same
> `capture_id`, which the ledger refuses to settle twice, and what still does not land goes to
> `failed_jobs`. Do not wait for a Sentinel event that is not coming.

### `queue` does not batch

`QueueStrategy::inRequestBatch()` maps the single-entry path over the array: one job per entry, even
for a mass operation that produced thousands. A `Model::query()->auditing('individual')->update()`
over 5 000 rows enqueues 5 000 jobs under `queue`, while under `buffered` the same 5 000 entries are
pushed and settled in batches of `buffer.size`. This is a sizing fact, not a bug — a retry is per job
and arrival order is per job, so batching the enqueue would only move the fan-out to whoever dequeues
it. See [Mass operations](../03-capture/05-mass-operations.md).

---

## The two clocks

Two timestamps on the entry answer two different questions, and an asynchronous mode is where they
stop agreeing.

| Column | Stamped | By | Answers |
|---|---|---|---|
| `occurred_at` | At capture | Whatever built the `AuditData`; carried through untouched | When the fact happened. It never moves. |
| `created_at` | At settlement | `Ledger\EntryBuilder`, as `CarbonImmutable::now()` | When the entry landed |
| `sequence` | At settlement | The ledger, inside the write | Where the entry sits in its chain |

Under `sync` these are the same moment and nothing distinguishes them. Under `queue` and `buffered`
they are minutes apart, and **a lifeline built on `created_at` keeps working and quietly starts
answering a different question**.

The read-side fix is one method:

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

// The order entries settled — no longer the order things happened
Sentinel::audits()->for($invoice)->get();

// The order things happened — what a lifeline wants
Sentinel::audits()->for($invoice)->byOccurrence()->get();
Sentinel::timeline()->for($invoice)->get();
```

`Query\AuditQuery::byOccurrence()` sets a flag the ledger reads to pick the ordering column;
`Sentinel::timeline()` is `audits()->byOccurrence()` and nothing else.

> 📌 **`(stream, sequence)` is unaffected by the mode.** It stays dense and monotonic per stream, and
> it is what `verifyIntegrity()` walks. The chain's order is settlement order — which is correct,
> because the chain proves the *record* was not tampered with, not the order the world happened in.

**Before you switch modes, grep your application for `created_at` on the audits table and decide, for
each one, which question it meant to ask.** That is the single highest-value action on this page. See
[Order, paging and walking the trail](../06-reading/03-order-paging-and-walking.md) and
[The timeline](../06-reading/05-the-timeline.md).

---

## A retry is not a second entry

A queue retries and a flush repeats. Neither is a second fact, and the mechanism that says so is not
any process's memory — it is a column and a unique index.

**`capture_id`** is a ULID stamped in `Capture\Recorder::identify()`, at the one door every capture
passes through, with `??=`:

```php
$audit->capture_id ??= (string) Str::ulid();
```

The `??=` is load-bearing: an identifier already on the `AuditData` is kept rather than replaced.
That is what makes the **settlement leg** idempotent — the same job retried, the same batch flushed
twice — because the identifier names the fact, not the attempt.

> ⚠️ **It does not deduplicate a second capture.** `capture_id` is stamped when the capture happens.
> If your application re-runs the code that captured — a failed job retried, a webhook delivered
> twice, a user double-submitting — that is a **new** capture with a **new** identifier, and the
> unique index will accept it. Idempotency above the capture is yours to arrange.

The column is deliberately **outside** the canonical payload — the entry is about what happened, not
about how it travelled — so writing it changes no hash and costs no `payload_version` bump. It is
`char(26)`, nullable, and nothing else in the package writes to the ledger without a capture except
`Security\Rekeyer`, which carries one of its own.

Three things enforce settle-at-most-once, in order of authority:

| Layer | What it does | Guarantee? |
|---|---|---|
| `Contracts\Deduplicates` on the ledger | `Dispatch\Settlement` asks which captures already have an entry and drops them **before** opening the batch transaction | No — an optimisation |
| `Settlement::unsettled()` | Drops a `capture_id` the same batch names twice, before the write | No — protects the batch |
| `unique('capture_id')` in the audits migration | Refuses the second row | **Yes — the arbiter** |

The middle layer exists for a specific reason: two entries naming the same `capture_id` in one call
would hand the unique index two rows it is about to refuse *together*, taking the whole batch — the
legitimate entries included — down with them.

`Ledger\DatabaseLedger` closes the loop. On a `UniqueConstraintViolationException` it recomputes what
is still unsettled and retries, up to three attempts; if nothing is left to write, it hands the
violation back rather than reporting a silence the caller would read as success.

```php
use ElPandaPe\Sentinel\Contracts\Deduplicates;
use ElPandaPe\Sentinel\Contracts\Ledger;

final class ElasticLedger implements Deduplicates, Ledger
{
    /**
     * @param  non-empty-list<string>  $captureIds
     * @return list<string>  the subset that already has an entry
     */
    public function settled(array $captureIds): array
    {
        // …
    }

    // … the rest of the Ledger contract
}
```

Declaring `Deduplicates` turns a retry into one query instead of one sealed chain the database throws
away. A driver that cannot look a capture up is **no less correct** — it just pays more for the common
case. See [The Ledger contract](../11-extending/01-the-ledger-contract.md).

---

## Switching modes on a live system

### Any switch: the ordering artefact it leaves behind

Entries written before and after the switch sit in one table with one chain. `sequence` stays dense
and monotonic — the chain does not notice. What changes is the relationship between the two clocks
**for a bounded window around the switch**:

- Moving `sync` → `queue` or `buffered`: for as long as the queue or the buffer holds entries
  captured before the switch, entries settle out of `occurred_at` order. A query ordered by
  `created_at` shows a fact from 14:02 filed after a fact from 14:05.
- Moving `queue` or `buffered` → `sync`: entries captured *after* the switch settle immediately while
  older ones are still in flight, producing the same interleaving from the other direction.

Neither is corruption and neither is detectable as a chain fault, because arrival order was never
promised to be occurrence order. It is simply a window in which `created_at` and `occurred_at`
disagree by more than usual — one more reason to have moved your lifelines to `byOccurrence()`
first.

### Leaving `buffered`: flush before you switch, not after

```bash
# 1. Drain first. Both shutdown hooks and the command refuse to touch the buffer
#    once the mode is no longer 'buffered'.
php artisan sentinel:flush     # repeat until it prints "Settled 0 entries from the buffer."

# 2. Then change the mode and deploy.
SENTINEL_MODE=sync
```

This ordering is not a nicety. The `terminating` and `WorkerStopping` flush hooks registered by
`SentinelServiceProvider` return early unless the mode is `Mode::Buffered`, and `FlushCommand` exits
`INVALID` (2) with *"Sentinel is writing in sync mode, so nothing is waiting in a buffer"*. Entries
captured under `buffered` and left waiting are **never settled once the mode is flipped, and nothing
reports them**.

`sentinel:flush` exit codes:

| Code | Constant | Meaning |
|---|---|---|
| 0 | `SUCCESS` | The buffer was emptied; the count is in the output |
| 1 | `FAILURE` | The flush threw. The run happened — a batch was taken and put back — so run it again |
| 2 | `INVALID` | The mode is not `buffered`. Nothing ran and nothing can |

See [Artisan commands](06-artisan-commands.md) and [Exit codes](../99-reference/07-exit-codes.md).

### Entering `buffered`

Set up the ceiling **before** the traffic, not after:

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('sentinel:flush')->everyMinute()->withoutOverlapping();
```

Nothing in the package registers this for you. The two configured thresholds are evaluated only when
an entry arrives — nothing inside PHP watches a clock between requests — so a buffer that stops
receiving entries stops being checked. Running two flushes at once is safe: taking from the Redis
list is atomic and `capture_id` settles the rest. See [The buffered mode](02-the-buffered-mode.md)
and [Scheduling](07-scheduling.md).

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| The trail is out of order after switching to `queue` or `buffered` | The query orders by `created_at`, which is now settlement order | `byOccurrence()`, or `Sentinel::timeline()` |
| `$model->latestAudit()` returns the *previous* entry right after a save | The new entry has not settled yet — it lands in a worker or in a flush | Listen for `AuditCreated`, where identity is assigned, or stay on `sync` |
| `$audit->restore()` works but `$result->entry` is `null` | The restoration entry settles elsewhere. `$result->applied` and `$result->skipped` are still correct | Read `applied`/`skipped` for the outcome; the restoration entry records `source_audit_id` pointing back at this one |
| A listener on `Audited` stopped receiving the entry | `Audited::$entry` is null unless the entry settled in this process. Null means *settled elsewhere* | Move the listener to `AuditCreated` |
| `sentinel_transactions.audits_count` is higher than the entries you can find | It counts accepted hand-overs, not landed rows — the header closes before the worker or the flush runs | Read it as "accepted for settlement"; count the entries by `transaction_id` for what landed |
| A failed flush throws into the request that captured an entry which is safely in the buffer | `BufferStrategy` pushes first, then evaluates the thresholds; a failing flush reports through `WriteFailure::inRequest()`, which rethrows under `throw` | Expected. The arriving entry is buffered either way; `BufferFlushFailed` names what actually failed |
| `AuditWriteFailed` names an entry that is demonstrably fine | On a threshold-triggered flush the strategy holds only the arriving `AuditData` — by design the one entry that is safe | Alert on `BufferFlushFailed`, which carries `taken`/`settled`/`returned`/`skipped()` |
| `verifyIntegrity()` says the chain is intact but entries are missing | Correct and unavoidable: an entry that never reached the ledger consumed no sequence, so it leaves no gap and breaks no link | Detect loss out of band — `BufferFlushFailed`, the `sentinel:flush` count and exit code |
| Switching away from `buffered` silently loses waiting entries | The shutdown hooks return early and `sentinel:flush` exits 2 under any other mode | Flush to zero, confirm, then switch |
| `Queue::fake()` passes but the job still dispatches inside a transaction in production | The fake bypasses `Queue::enqueueUsing()`, so Laravel's own after-commit dispatch never runs. Here the waiting is done by the dispatcher, not the queue | Assert that nothing dispatched inside `DB::transaction()`, as `tests/Dispatch/QueueModeTest.php` does |
| A batch of 3 000 failed under `sync` and produced one event, not 3 000 | `SyncStrategy::batch()` reports once, naming `$audits[0]`, and refuses every hand-over. `BufferStrategy` also reports once and names the same entry, but its hand-overs stay accepted — the batch is in the buffer either way | Expected. Under `throw` only the first would ever be read anyway |
| `buffer.size => 0` flushes on every single entry | `Config::bufferSize()` and `bufferInterval()` floor at 1. Zero does not mean "never" | Set a real threshold; a non-numeric value raises `ConfigurationException` instead |
| An unknown `mode` or `buffer.store` takes the write path down | Both are refused at the point of use rather than falling back | Fix the value. A silent fallback would leave you believing you had durability you do not |

---

## ✅ Best practices

✅ **Do** — stay on `sync` until request latency is a measured problem. It is the only mode in which
the caller can still be told the write did not work.

```php
// config/sentinel.php
'mode' => env('SENTINEL_MODE', 'sync'),
```

❌ **Don't** — reach for `buffered` because it benchmarks fastest. It is the only mode that can lose a
fact outright, and the chain provably cannot tell you that it did.

```php
'mode' => 'buffered',   // shipped with no scheduled flush and no BufferFlushFailed listener
```

---

✅ **Do** — audit every query that rebuilds a lifeline from `created_at` **before** you switch, and
move it to the clock of the fact.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

Sentinel::audits()->for($patient)->byOccurrence()->get();
```

❌ **Don't** — leave `created_at` ordering in place and assume it still means what it meant. It keeps
working and starts answering a different question, with no error anywhere.

```php
Sentinel::audits()->for($patient)->get();   // now: the order entries settled
```

---

✅ **Do** — move listeners that need the settled entry to `AuditCreated`, and keep `Audited` for
"this process is done with this entry".

```php
use ElPandaPe\Sentinel\Events\AuditCreated;
use Illuminate\Support\Facades\Event;

Event::listen(fn (AuditCreated $event) => $this->index($event->entry->id, $event->entry->sequence));
```

❌ **Don't** — read `Audited::$entry` and treat null as a failure. Null means *settled elsewhere*; a
real failure announces `AuditWriteFailed`.

```php
Event::listen(function (Audited $event): void {
    if ($event->entry === null) {
        $this->alertAuditLost();   // fires on every write under queue and buffered
    }
});
```

---

✅ **Do** — make your own job idempotent about the *capture*, not just about the write. `capture_id`
makes settlement idempotent; it cannot deduplicate a second capture of the same fact.

```php
public function handle(): void
{
    $invoice = Invoice::query()->findOrFail($this->invoiceId);

    if ($invoice->status === 'approved') {
        return;   // no second capture, therefore no second entry
    }

    $invoice->update(['status' => 'approved']);
}
```

❌ **Don't** — assume `capture_id` protects you from an application-level retry. Re-running the code
that captured produces a **new** capture with a **new** identifier, and the unique index accepts it.

```php
public function handle(): void
{
    $this->invoice->update(['status' => 'approved']);   // retried job → second entry
}
```

---

✅ **Do** — give audits their own queue connection and queue name when the default connection is
where slow work lives.

```php
// config/sentinel.php
'queue' => ['connection' => 'audits', 'queue' => 'trail'],
```

❌ **Don't** — leave audits on the queue that holds video transcodes and report generation. An audit
that waits behind an hour of work arrives an hour after the fact it describes, and `created_at` says
so forever.

```php
'queue' => ['connection' => null, 'queue' => null],   // whatever the default is, including 'default'
```

---

✅ **Do** — leave `transactions.after_commit` on. It is what defers the hand-over to
`Connection::afterCommit()` in every mode, and it is additionally what marks `SettleAudit` with
`->afterCommit()`.

```php
'transactions' => ['after_commit' => true],
```

❌ **Don't** — turn it off to "make audits more reliable". It asks the ledger to keep claiming facts a
rollback undid, and it removes the queue-level guard on the job at the same time.

```php
'transactions' => ['after_commit' => false],
```

---

✅ **Do** — drain the queue before deploying a change to what an entry *means*. The array payload
already survives additive changes across a rolling deploy: `AuditData::fromPayload()` drops unknown
keys and lets missing ones take their constructor defaults.

```bash
php artisan queue:work audits --queue=trail --stop-when-empty
```

❌ **Don't** — deploy a change to the canonical payload, `sequence`, `hash` or `previous_hash` with
jobs in flight. Those are chain-bearing: a change to any of them bumps `payload_version` and needs a
backwards-compatibility test, and a worker settling an old payload under new rules is exactly the
race that costs you a verifiable chain.

```bash
# jobs still holding payloads written by the previous release
php artisan deploy   # the new workers now seal them under the new rules
```

---

**See also:** [The buffered mode](02-the-buffered-mode.md) ·
[Running audits on a queue](03-queues.md) · [Failure policy](05-failure-policy.md) ·
[Events and listeners](04-events-and-listeners.md) ·
[Monitoring and troubleshooting](08-monitoring-and-troubleshooting.md) ·
[The write path](../01-concepts/03-the-write-path.md) ·
[The hash chain](../07-integrity/01-the-hash-chain.md) ·
[Order, paging and walking the trail](../06-reading/03-order-paging-and-walking.md) ·
[Choosing your setup](../02-getting-started/05-choosing-your-setup.md) ·
[Configuration reference](../99-reference/02-configuration.md)
