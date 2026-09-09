# ⚙️ The buffered mode

> The mode for a write path that cannot afford the ledger: entries wait in a Redis list and settle in
> batches. It is the only one of the three that can lose a fact outright, and this page says exactly
> which facts, exactly how many, and why nothing in the package will tell you afterwards.

**On this page:** [What it actually does](#what-it-actually-does) · [The two stores](#the-two-stores) · [Configuration](#configuration) · [What it can lose, exactly](#what-it-can-lose-exactly) · [The five flush triggers](#the-five-flush-triggers) · [`sentinel:flush`](#sentinelflush) · [When a flush fails](#when-a-flush-fails) · [Sizing the two thresholds](#sizing-the-two-thresholds) · [Monitoring the buffer](#monitoring-the-buffer) · [What never to put behind it](#what-never-to-put-behind-it) · [⚠️ Pitfalls](#️-pitfalls) · [✅ Best practices](#-best-practices)

---

Set `sentinel.mode` to `buffered` and nothing in your application code changes. Capture still runs
where the fact happened, the whole pipeline — filters, context resolution, masking, encryption,
policies — still runs in the process that captured, and what leaves that process is a finished
`ElPandaPe\Sentinel\Data\AuditData`: never an Eloquent model, never an unresolved context. The only
thing this mode moves is the last step, the one that turns approved data into a sealed row.

```php
// config/sentinel.php
'mode' => env('SENTINEL_MODE', 'sync'),   // 'sync' | 'queue' | 'buffered'
```

> 📌 **Note.** `mode` is one setting for the whole installation. `Support\Config::mode()` reads a
> single key and `Dispatch\Dispatcher` resolves a strategy from it per entry — there is no per-model,
> per-event or per-audit-type override anywhere in the package. If one audit type in your application
> cannot afford this mode's loss window, the mode is wrong for all of them.

## What it actually does

`Dispatch\BufferStrategy` is what the dispatcher resolves under `buffered`. For each entry it does
two things, in this order:

1. **Pushes** the entry into the buffer. It is now waiting, with no `sequence`, no `hash` and no
   place in any chain.
2. **Asks whether either threshold has been reached** (`Buffer\Flusher::due()`), and if so, drains
   the whole buffer through `Buffer\Flusher::flush()`.

The order is deliberate: the entry that triggered a flush is already safe in the buffer before the
flush runs, so a flush that blows up can never cost the entry that caused it. It costs the ones that
were there before. The hand-over is reported as accepted either way — the entry is in the buffer
whatever the flush did, and reporting it as lost would name something that is going to settle on the
next trigger.

`Buffer\Flusher::flush()` is the single method all five triggers go through. It loops:

```text
take(buffer.size)  ──▶  settleBatch()  ──▶  next batch, until take() returns []
        │                     │
        │                     └─ throws ──▶ putBack(batch) ──▶ BufferFlushFailed ──▶ rethrow
        └─ throws ──▶ BufferFlushFailed (taken 0, settled 0, returned 0) ──▶ rethrow
```

Batched rather than taken whole, because a buffer nobody vacated for an hour is exactly the one a
flush must not try to settle in one transaction. `buffer.size` is therefore three things at once: the
size threshold, the number of entries one `take()` removes, and the size of each settled batch.

Three properties of the write survive the detour unchanged, and they are worth naming because they
are what people assume are lost:

| Property | Under `buffered` | Why |
|---|---|---|
| Where the chain is assigned | in the ledger, inside the same operation as the write | `Dispatcher` never proposes `sequence`, `hash` or `previous_hash`; a payload carrying one is refused with `DispatchException` |
| Whose context the entry carries | the request that captured it | the pipeline runs at capture, not at the flush — an entry captured in an HTTP request keeps that request's URL, actor and source |
| What a rollback does | nothing reaches the buffer | with `transactions.after_commit` on, the hand-over is registered in `Connection::afterCommit()`, so a rollback throws the capture away before it is ever pushed |

### What waits in the buffer across a deploy

An entry waiting in Redis was JSON-encoded by whatever release pushed it and is decoded by whatever
release flushes it. `AuditData::toPayload()` and `fromPayload()` are written out by hand for exactly
that reason: an unrecognised key is dropped and a missing one takes its constructor default, so a
worker running last week's code can read a payload this week's wrote. `occurred_at` goes out with
microseconds and an offset because it is inside the canonical payload, and a round trip that rounded
it would hash differently for the same fact.

What that tolerance does **not** cover is a change to what a field means, or a change to the
canonical payload itself — which bumps `payload_version` and costs a backwards-compatibility test,
because every entry already in the ledger was hashed under the old shape. A buffer holding entries
across such a deploy would settle facts described one way into a chain that now describes them
another.

> 📌 **Note.** Drain the buffer before deploying a change to what an entry *means*, as opposed to
> what it merely contains: `php artisan sentinel:flush`, then confirm the list is empty.

> ⚠️ **Warning.** The one thing you cannot infer from an entry is that it settled this way. Nothing
> on the row records the performance mode. The `metadata.mass.mode` field some entries carry is the
> *mass operation* mode (`summary` / `individual` / `hybrid`), not `sync` / `queue` / `buffered`. The
> only places the performance mode is visible are the configuration and `php artisan about`.

## The two stores

`buffer.store` names the driver. There are two, and only one of them is a store.

| Store | Class | What it is | Survives |
|---|---|---|---|
| `redis` (default) | `Buffer\RedisBuffer` | one Redis list, entries JSON-encoded, oldest at the head | the process; whatever your Redis deployment survives |
| `memory` | `Buffer\MemoryBuffer` | the contract over a plain PHP array | nothing |

The Redis implementation is five commands and no more:

| Contract method | Redis command | Note |
|---|---|---|
| `push()` | `RPUSH key <json>` | appended to the tail |
| `take($limit)` | `LPOP key <limit>` | destructive and atomic; taken from the head, so settlement order is capture order |
| `putBack($audits)` | `LPUSH key <json…>` | the batch reversed on the way in, so the head order survives |
| `size()` | `LLEN key` | raw count of elements, whatever wrote them |
| `oldest()` | `LINDEX key 0`, decoded | returns that entry's `occurred_at`, or `null` |

Atomic taking is not an optimisation, it is the correctness argument. Two processes flushing at once
is the normal case, not the edge one — a request terminating while the scheduled command runs — and
a single `LPOP key count` means each gets entries and neither gets the other's.

> ⚠️ **Warning — `memory` is a test double, never a store.** `MemoryBuffer` keeps everything on the
> instance, and the container binding for `Contracts\Buffer` is `scoped`. Laravel's queue worker calls
> `$app->forgetScopedInstances()` between jobs, and an HTTP request's container dies with the
> request. Whatever the memory buffer is holding at those moments is gone, with no event, no log line
> and no trace. It exists so the contract has a second implementation to be read against and so a
> test suite does not need a Redis server. It is reachable by configuration on purpose and named for
> what it is, because a driver that silently stood in for Redis would hand you durability nobody
> chose.

An unknown value raises rather than degrading:

```php
'buffer' => ['store' => 'memcached'],

// ElPandaPe\Sentinel\Exceptions\ConfigurationException
// Sentinel configuration key [sentinel.buffer.store] has unknown value [memcached].
// Accepted: redis, memory.
```

That is deliberate. An operator who asked for Redis and silently got the process has been told
nothing about the durability they actually have, which is the whole subject of this mode.

## Configuration

```php
// config/sentinel.php
'buffer' => [
    'store' => env('SENTINEL_BUFFER_STORE', 'redis'),
    'connection' => env('SENTINEL_BUFFER_CONNECTION'),
    'key' => 'sentinel:buffer',
    'size' => 500,
    'flush_interval' => 60,
],
```

| Key | Default | What it does | When you change it |
|---|---|---|---|
| `buffer.store` | `redis` | Names the driver. Anything but `redis` or `memory` is a `ConfigurationException` at resolution. | Only for tests. `redis` is the store. |
| `buffer.connection` | `null` | Which `database.redis.*` connection the buffer opens. `null` uses the application's default. | Point audits at a Redis whose persistence and eviction policy you chose deliberately, away from cache. |
| `buffer.key` | `sentinel:buffer` | The Redis list key. | To keep two applications sharing one Redis from sharing one buffer. Never share it with anything else. |
| `buffer.size` | `500` | Size threshold, take size and settled-batch size — all three. Floor of 1. | Lower it to shrink the loss window; raise it to amortise the tail read and the transaction over more entries. |
| `buffer.flush_interval` | `60` (seconds) | Age threshold, measured from the **oldest waiting entry's `occurred_at`**. Floor of 1. | Lower it for a tighter time bound *while traffic exists*. It promises nothing on a quiet buffer. |

> ⚠️ **Warning.** Both thresholds floor at 1. `Support\Config::bufferSize()` and `bufferInterval()`
> apply `max(1, …)`, so `'size' => 0` does not mean "never flush" — it means flush on every single
> entry, which is the slowest possible configuration of the fastest mode. A non-integer value is
> refused with a `ConfigurationException` rather than coerced.

The two keys that decide what a failure costs live outside the `buffer` block and are covered in
[Failure policy](05-failure-policy.md):

```php
'on_write_failure' => env('SENTINEL_ON_WRITE_FAILURE', 'throw'),   // 'throw' | 'log'
'log_channel' => env('SENTINEL_LOG_CHANNEL'),
'transactions' => ['after_commit' => true],
```

## What it can lose, exactly

There are four ways this mode drops a fact, and it is worth separating them because only one of them
is the one everybody quotes.

### 1. A process killed between the take and the write

`take()` is destructive. Between `LPOP` returning a batch and `settleBatch()` either succeeding or
`putBack()` completing, that batch exists **only in the flushing process's memory**. A `SIGKILL`, an
OOM kill or a fatal error in that window runs no shutdown hook and no `putBack()`. The batch is gone.

**Ceiling: `buffer.size` entries, per process that is mid-flush.**

### 2. The store losing what is waiting

Everything that reached `push()` is in Redis, so it survives the PHP process. It does not survive a
Redis that was not configured to survive: an instance with `--save ''` and `--appendonly no`
restarting, a `maxmemory-policy` that evicts under pressure, or somebody running `DEL` on the key.
The package's own test suite proves this shape — delete the key mid-flight and the entries never
appear, while the chain still verifies.

**Ceiling: everything accumulated since the last successful flush. Under a healthy ledger that is at
most `buffer.size − 1` entries. While the ledger is refusing, there is no ceiling at all** — every
failed flush puts its batch back and the list keeps growing. Nothing in the package bounds it.

### 3. A batch the buffer refuses to take back

The ledger throws, `Flusher::putBack()` runs, and the buffer throws too. That refusal is swallowed —
it must not replace the failure that caused it — and `putBack()` returns 0. Those entries are gone.
This is the only path on which the mode loses a fact it did not have to, and the only thing that will
ever tell you is `BufferFlushFailed` with `returned = 0` and `skipped() > 0`.

**Ceiling: `buffer.size` entries.**

### 4. The `memory` store, on every scope teardown

Covered above. Do not use it outside tests.

### A worked worst case

Take the defaults — `buffer.size = 500`, `buffer.flush_interval = 60`, `store = redis` — a fleet of
eight `queue:work` processes, and **no scheduled `sentinel:flush`**. The node running them is
terminated hard.

| What was where | How many | Fate |
|---|---|---|
| Entries each worker had taken mid-flush | up to 500 × 8 = **4 000** | gone; no hook runs on `SIGKILL` |
| Entries waiting in Redis | up to 499 | survive, if Redis is on another host with persistence on |
| Entries waiting in Redis, if Redis went with the node and had persistence off | up to 499 | gone |
| Entries already in the ledger | all of them | untouched; the chain over them still verifies |

Change one number and the worst case changes with it: at `buffer.size = 50` the first row becomes
400 entries instead of 4 000. That is the trade the two thresholds exist to let you price.

Under PHP-FPM the picture is much better than the table suggests, and for a reason worth knowing:
the package registers its flush on `Application::terminating()`, which runs at the end of **every**
HTTP request, unconditionally and without consulting either threshold. In a busy FPM application the
shared Redis list is drained continuously and rarely holds anything between requests. A long-lived
process — a queue worker, an Octane worker, a long-running command — never passes through
`terminating` between units of work, and that is where entries actually accumulate.

> ⚠️ **Warning — the chain cannot report the loss, and reports the shorter trail as intact,
> correctly.** An entry that never reached the ledger consumed no sequence. It leaves no gap in
> `(stream, sequence)` and breaks no link, so `verifyIntegrity()` and `verifyStream()` walk a shorter
> chain and find it whole. The chain proves that what settled was not tampered with. It has never
> proved that everything that happened settled — and no arrangement of hashes could. A trail with a
> hole in it looks exactly like the trail of a system where nothing happened.

Loss detection under this mode is therefore out of band. There are three signals, and none of them is
the chain: the `BufferFlushFailed` event, the count and exit code of `sentinel:flush`, and your own
comparison of what the application handed over against what landed.

> 📌 **Note.** The package emits no metrics or counters of its own. If you want a handed-over-versus-
> landed number, you build it — for example by counting `Events\Audited` in the capturing process
> against rows in the audits table.

## The five flush triggers

| # | Trigger | Registered in | Condition | Reports a failure through |
|---|---|---|---|---|
| 1 | Size threshold | `Dispatch\BufferStrategy::vacate()` | on push, `size() >= buffer.size` | `BufferFlushFailed` + `AuditWriteFailed`; `on_write_failure` decides whether it throws |
| 2 | Age threshold | `Dispatch\BufferStrategy::vacate()` | on push, `oldest() + buffer.flush_interval` is in the past | same as above |
| 3 | End of a request | `Application::terminating()` | mode is `buffered` | `BufferFlushFailed` + the application's error handler (the call is wrapped in `rescue()`) |
| 4 | Worker shutdown | listener on `Illuminate\Queue\Events\WorkerStopping` | mode is `buffered` | same as 3 |
| 5 | `php artisan sentinel:flush` | `Console\FlushCommand` | mode is `buffered`, otherwise exit 2 | `BufferFlushFailed` + exit 1 carrying the reason |

Triggers 1 and 2 are thresholds and flush only when they are met. Triggers 3, 4 and 5 flush
unconditionally: they do not consult `due()` at all, they just drain.

> ⚠️ **Warning — nothing in PHP watches the clock between requests.** `Flusher::due()` is called from
> exactly one place, `BufferStrategy::vacate()`, and `vacate()` runs only when an entry arrives. A
> buffer that stops receiving entries stops being evaluated. `buffer.flush_interval` bounds how long
> an entry waits **only while traffic continues**. What bounds a quiet buffer is triggers 3, 4 and 5
> — and 3 and 4 only fire when a process is on its way out.

Trigger 4 exists because trigger 3 cannot reach a worker: a queue worker is one long-lived process
and does not pass through `terminating` between jobs. `WorkerStopping` is dispatched by Laravel's
`Worker::stop()`, which is reached on a clean stop and on `SIGQUIT`, `SIGTERM` and `SIGINT` — the
signal handler only raises a flag and the worker stops at the next safe point. A `SIGKILL` reaches
neither.

> 🐘 **Engine.** `take()` issues `LPOP key count`. The count argument on `LPOP` arrives in Redis 6.2;
> the package's own environment runs `redis:8-alpine`. Sentinel declares no minimum Redis version and
> requires no Redis client at all — `ext-redis` or `predis/predis` is yours to provide, in keeping
> with the rule that optional backends stay optional.

## `sentinel:flush`

```bash
php artisan sentinel:flush
```

It takes no arguments and no options. It settles everything the buffer is holding, in batches of
`buffer.size`, and reports how many entries **landed**.

| Exit | Meaning | Output |
|---|---|---|
| `0` | The flush completed — including having found nothing to do | `Settled 128 entries from the buffer.` |
| `1` | The run happened and went badly: a batch was taken and refused | `The buffer could not be settled: …` |
| `2` | The run could not happen: the mode is not `buffered` | `Sentinel is writing in sync mode, so nothing is waiting in a buffer. …` |

A failed flush exits `1` and not `2`, which is the opposite of what the package's other commands do
with a caught throwable, and it is deliberate: the run happened, a batch was taken and put back, and
the answer to that is to run again — which is exactly what `1` tells a cron.

Two of these running at once is safe, and it is the normal case rather than the edge one: taking is
atomic, so each gets its own entries, and the unique index on `capture_id` settles anything the two
somehow both reached.

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('sentinel:flush')->everyMinute()->withoutOverlapping();
```

> 💡 **Tip.** Scheduling this is the only thing that puts a real ceiling on how long an entry waits,
> because nothing inside PHP is watching a clock between requests. The package registers nothing on
> your scheduler — this line is yours to write.

> ⚠️ **Warning.** `Settled 0 entries from the buffer.` does **not** mean the buffer was empty. The
> command reports entries that were *written*, and `Dispatch\Settlement` drops entries whose
> `capture_id` already has a row before opening the transaction. A flush that took 40 entries and
> found all 40 already settled reports 0 and exits 0. To ask whether anything is waiting, read the
> list length — see [Monitoring the buffer](#monitoring-the-buffer).

## When a flush fails

`Events\BufferFlushFailed` is the only announcement that names what was at stake. It is dispatched
from `Flusher`, which every trigger passes through, so it goes out for all five — and for two of them
it is the *only* package-level signal there is.

```php
use ElPandaPe\Sentinel\Events\BufferFlushFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

Event::listen(function (BufferFlushFailed $event): void {
    Log::channel('audit-alerts')->critical($event->message(), [
        'taken' => $event->taken,         // entries this flush removed from the buffer, in total
        'settled' => $event->settled,     // entries written to the ledger, in total
        'returned' => $event->returned,   // the failed batch, back in the buffer — not lost
        'skipped' => $event->skipped(),   // taken − settled − returned
        'exception' => $event->reason,
    ]);
});
```

`taken`, `settled` and `skipped()` describe the whole flush; `returned` describes only the batch that
failed, because a batch is put back whole. `skipped()` is derived rather than stored, so the four
counts cannot contradict each other:

```text
taken == settled + skipped() + returned      // always
```

Reading the counts:

| Shape | What happened | Where the entries are |
|---|---|---|
| `returned > 0` | the ledger refused a batch and the buffer took it back | in the buffer; the next trigger retries them |
| `taken = 0`, everything else 0 | the buffer could not even be read | still in the buffer; nothing was taken, nothing is in limbo |
| `skipped() > 0`, `returned > 0` | some entries in the batch already had a row | already settled; nothing to do |
| **`skipped() > 0`, `returned = 0` on a failing batch** | **the buffer refused to take the batch back** | **gone** |

> ⚠️ **Warning — two shipped strings overstate the outcome; do not build an alert on their wording.**
> `BufferFlushFailed::message()` renders `skipped` as "had already settled elsewhere", which is false
> in the last row of that table. And `sentinel:flush`'s failure line ends "Nothing was lost: what did
> not settle is back in the buffer", which does not hold when the buffer itself is what failed. Alert
> on the numbers — `skipped()` with `returned` — not on the sentence.

### The other event a failed flush fires, and why it names the wrong entry

Triggers 1 and 2 run inside a write. When the flush throws there, `Capture\WriteFailure` announces
`Events\AuditWriteFailed` — and the only entry the strategy has in hand at that moment is the one
that just arrived, which by design is the one entry the failure cannot have cost. It is already in
the buffer.

> 📌 **Note.** A listener that reads `AuditWriteFailed` as "this entry was lost" will name the wrong
> fact under `buffered`. `BufferFlushFailed` is the event that names what was actually at stake.

### What the failure costs the request

`on_write_failure` decides, and it applies only to triggers 1 and 2 — the ones reachable from a
request:

| Situation | Policy | What the caller sees |
|---|---|---|
| Threshold flush, outside a transaction | `throw` (default) | the exception propagates out of the `save()` that captured — even though *that* entry is safely buffered |
| Threshold flush, outside a transaction | `log` | one line on `log_channel`, the request continues |
| Threshold flush, deferred to a commit | either | always recorded, never thrown: the transaction has already committed, and throwing would report the failure of something that succeeded |
| Triggers 3 and 4 | either | `rescue()`: reported to the application's error handler, never thrown — there is nobody left to tell |
| Trigger 5 | either | exit 1 with the reason on stderr |

> ⚠️ **Warning.** Compliance mode forces `on_write_failure` to `throw` (`Config::writeFailurePolicy()`
> short-circuits on `complianceEnabled()`), but nothing in the package refuses `buffered` under
> compliance mode. `Compliance\Requirements::enforce()` checks signatures and checkpoints and nothing
> else. Choosing a mode that can lose entries an auditor will never see missing is a decision you can
> make and the package will not stop.

### Recovering after a failure

1. Read the event or the command output. `returned` entries are in the buffer and need nothing from
   you; `skipped()` on a failing batch is gone and needs a note in your incident record.
2. Fix whatever `reason` names — an unreachable database, a full disk, a migration mid-flight.
3. Run `php artisan sentinel:flush` and confirm exit 0.
4. Confirm the list is empty (`LLEN`), because a `Settled 0 entries` on its own does not prove that.
5. Do **not** reach for `verifyIntegrity()` to find out what you lost. It cannot tell you, and it will
   report the shorter chain as intact. See [Verification](../07-integrity/06-verification.md).

## Sizing the two thresholds

Size `buffer.size` as the loss window you can afford, not as a throughput knob. It is simultaneously
the flush batch size, the take size and the ceiling on what one dying process drops.

| You are willing to lose | `buffer.size` | `buffer.flush_interval` | Cost |
|---|---|---|---|
| a handful of entries | 25 | 10 | many small batches; each pays its own stream tail read and transaction |
| a few hundred | 500 (default) | 60 | the default trade |
| several thousand | 5 000 | 300 | fewer, larger writes; a mid-flush kill drops thousands |

Two hard bounds on the top end:

- **Placeholders.** `Ledger\DatabaseLedger` splits a batch to fit its `MAX_PLACEHOLDERS` of 32 766
  per statement, because that is SQLite's compiled `SQLITE_MAX_VARIABLE_NUMBER` and the narrowest of
  the three supported engines. `Ledger\EntryBuilder` writes 35 columns an entry, 37 with signing on,
  so one statement holds roughly 900. A `buffer.size` past that is split across statements — the
  batch still lands whole or not at all, since the statements share one transaction, but you have
  stopped buying anything with the extra size.
- **Transaction length.** A batch is one transaction against the audits table, holding whatever locks
  the engine takes to append. Very large batches under `buffered` are how a fast mode turns into a
  contention problem.

On the interval, remember what it measures. `Buffer::oldest()` returns the oldest waiting entry's
`occurred_at` — the clock of the fact, not of the push. Under `transactions.after_commit`, an entry
captured 90 seconds before its commit is already overdue by a 60-second interval the moment it
reaches the buffer, and flushes on arrival. That is deliberate: the window that matters to whoever is
counting losses starts when the thing happened.

## Monitoring the buffer

`Contracts\Buffer` and its implementations are marked `@internal`. The stable way to watch the buffer
is to watch the Redis list directly — that *is* the buffer:

```bash
redis-cli LLEN sentinel:buffer          # entries waiting
redis-cli LINDEX sentinel:buffer 0      # the oldest one, as the JSON payload the flush will read
```

Three things to alert on, in order of how much they tell you:

| Signal | What it means | Threshold worth setting |
|---|---|---|
| `LLEN` growing and not returning to zero | flushes are failing, or nothing is triggering them | anything above `buffer.size` for more than one flush interval |
| `BufferFlushFailed` with `skipped() > 0` and `returned = 0` | entries were lost outright | any occurrence |
| `sentinel:flush` exiting 1 | a batch was taken and refused | any occurrence |

> ⚠️ **Warning.** `LLEN` counts every element in the list, including any your application did not
> write. `RedisBuffer` drops an element it cannot decode rather than let it abort a flush — the
> entries behind it did nothing wrong — but that element still counts toward `size()`, and a foreign
> element sitting at the head makes `oldest()` return `null`, which silently disables the age
> threshold entirely. Keep `buffer.key` exclusive to Sentinel.

## What never to put behind it

Because `mode` is one global setting, this list is really a list of reasons not to choose `buffered`
at all:

- **Anything whose absence is itself the finding.** Authentication events, permission grants, access
  records, redaction trails. A missing entry in those trails is indistinguishable from a quiet system,
  and the chain will confirm the quiet system.
- **Anything a regulator, an auditor or a court will read as complete.** You would be asserting
  completeness over a store that admits a loss window nothing can measure after the fact.
- **Anything you bill, reconcile or settle money from.** Use `sync`, where the caller can still be
  told the write did not work, or `queue`, where the queue's retry and `failed_jobs` are your net.
- **A low-volume, high-value trail.** The mode's payoff is amortising one stream tail read and one
  transaction over many entries. A model that produces a handful of entries an hour gets almost none
  of that and pays the full loss window for it.
- **Anything running with `buffer.store = memory` outside a test.** That is not a smaller loss
  window, it is a much shorter fuse on the same one.

If some of your trail needs this mode's throughput and some of it cannot afford the loss, the answer
is not a mode: it is `sync` or `queue` for everything, plus the ordinary levers — fewer audited
fields, snapshots off where they earn nothing, a database of its own. See
[Choosing your setup](../02-getting-started/05-choosing-your-setup.md) and the
[Scaling playbook](../10-database-engines/08-scaling-playbook.md).

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| Entries stop appearing entirely after a deploy, with nothing in the logs | `mode` was changed away from `buffered` while the Redis list still held entries. Both shutdown hooks return early and `sentinel:flush` exits 2 under any other mode, so those entries are stranded and nothing reports them | Run `sentinel:flush` and confirm `LLEN` is 0 *before* switching the mode. Recovering afterwards means temporarily setting `mode` back to `buffered` and flushing |
| Entries sit in the buffer for hours on a low-traffic app | Both thresholds are evaluated only when an entry arrives. No entries, no evaluation | `Schedule::command('sentinel:flush')->everyMinute()` |
| A `save()` throws with a database exception, but the entry it was saving is in the buffer | Under `on_write_failure = throw`, a threshold-triggered flush that fails rethrows into the request. The failure belongs to the *earlier* entries in the batch | Expected. Read `BufferFlushFailed`, not the exception, to learn what was at stake. Use `log` if audit failures must not break requests |
| `AuditWriteFailed` names an entry that turns out to be present in the ledger | A threshold flush failed and the strategy had only the arriving entry to name — which is the one entry it cannot have cost | Listen for `BufferFlushFailed` instead when running `buffered` |
| `sentinel:flush` prints `Settled 0 entries` but the list is not empty | Everything taken had already settled — `Settlement` drops entries whose `capture_id` has a row — or the run raced another flush | Read `LLEN`; run again. Repeated non-zero `LLEN` with 0 settled means duplicates are being pushed under stale `capture_id`s |
| `verifyStream()` reports a chain intact after an incident you know lost entries | A missing entry consumed no sequence, so there is no gap and no broken link | Correct behaviour. Loss detection is `BufferFlushFailed`, the command's exit code, and your own counters |
| The Redis list grows without bound during a database outage | Every failed flush puts its batch back and every arrival re-triggers a flush that fails again. There is no back-pressure, no ceiling and no drop policy in the package | Alert on `LLEN`. Plan the memory headroom, or fail over to `sync` and accept the latency for the duration |
| The age threshold never fires, however long entries wait | Something that is not Sentinel wrote to `buffer.key`. `oldest()` decodes only the head element, and a foreign head returns `null` | Give the buffer a key of its own, and a `buffer.connection` of its own |
| Under `queue:work` with `buffer.store = memory`, entries vanish between jobs | The `Buffer` binding is `scoped` and Laravel resets scoped instances between jobs | `memory` is a test double. Use `redis` |
| `buffer.size => 0` makes every write slow instead of never flushing | `Config::bufferSize()` applies `max(1, …)`, so 0 becomes 1: a flush per entry | Set the number you actually want; there is no "never" |

## ✅ Best practices

✅ **Do** — schedule `sentinel:flush`. It is the only ceiling on how long an entry waits, because the
two thresholds are read on arrival and nothing else watches a clock.

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('sentinel:flush')->everyMinute()->withoutOverlapping();
```

❌ **Don't** — rely on `buffer.flush_interval` to bound the wait. It bounds nothing on a buffer that
stopped receiving entries, which is exactly the buffer you need bounded.

```php
'buffer' => ['flush_interval' => 60],   // 60 seconds of traffic, not 60 seconds
```

---

✅ **Do** — listen for `BufferFlushFailed` and alert on the counts. Two of the five triggers report
nothing else at the package level, and it is the only signal that says how many entries were at
stake.

```php
use ElPandaPe\Sentinel\Events\BufferFlushFailed;
use Illuminate\Support\Facades\Event;

Event::listen(function (BufferFlushFailed $event): void {
    if ($event->returned === 0 && $event->skipped() > 0) {
        // entries the buffer refused to take back: gone
    }
});
```

❌ **Don't** — treat `AuditWriteFailed` as the buffered mode's loss signal. Under a threshold flush it
names the entry that just arrived, which is the one entry that is safely buffered.

```php
use ElPandaPe\Sentinel\Events\AuditWriteFailed;

Event::listen(function (AuditWriteFailed $event): void {
    // names the entry that is safely in the buffer, not the batch that did not land
    Log::critical("lost audit for {$event->subjectType} {$event->subjectId}");
});
```

---

✅ **Do** — drain the buffer and confirm it is empty before changing `mode` away from `buffered`.

```bash
php artisan sentinel:flush        # exit 0
redis-cli LLEN sentinel:buffer    # must be 0
# only now: SENTINEL_MODE=sync
```

❌ **Don't** — deploy the mode change first. Both shutdown hooks check the mode and return early, and
`sentinel:flush` exits 2 under anything but `buffered`, so whatever is still in the list is stranded
with nothing reporting it.

```bash
# SENTINEL_MODE=queue    ← shipped while the list held 380 entries
```

---

✅ **Do** — give the buffer a Redis connection and a key of its own, and choose that instance's
persistence and eviction policy deliberately. What is waiting is exactly as durable as that
deployment.

```php
// config/database.php
'redis' => [
    'audits' => ['host' => env('SENTINEL_REDIS_HOST'), 'database' => 3, /* … */],
],

// config/sentinel.php
'buffer' => ['connection' => 'audits', 'key' => 'sentinel:buffer'],
```

❌ **Don't** — point the buffer at the cache connection, and never let anything else write to
`buffer.key`. A foreign element inflates `LLEN`, is silently discarded on the next flush, and at the
head it makes `oldest()` null — disabling the age threshold with no error anywhere.

```php
'buffer' => ['connection' => null, 'key' => 'laravel_cache'],   // eviction can drop audits
```

---

✅ **Do** — pick `buffer.size` as the number of entries you can afford to lose from one killed
process, then check that it still fits one statement (~900 entries at 35 columns, against the
32 766-placeholder ceiling).

```php
'buffer' => ['size' => 250, 'flush_interval' => 30],   // priced, not guessed
```

❌ **Don't** — raise it for throughput without pricing the loss. It is the take size, the batch size
and the ceiling on what a mid-flush kill drops, all at once.

```php
'buffer' => ['size' => 20_000],   // 20 000 entries per dying process, split across statements anyway
```

---

✅ **Do** — move any query that rebuilds a lifeline from `created_at` onto `byOccurrence()` or
`Sentinel::timeline()` before switching. `occurred_at` is stamped at capture and never moves;
`created_at` and `sequence` are stamped when the entry settled.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

Sentinel::audits()->for($invoice)->byOccurrence()->get();
```

❌ **Don't** — leave `created_at` ordering in place. It keeps working and quietly starts answering a
different question: the order entries reached the ledger, which under a batched flush is not the
order things happened.

```php
Sentinel::audits()->for($invoice)->get();   // settlement order, not history
```

---

✅ **Do** — use `buffer.store = memory` only in tests, and drain it explicitly there rather than
waiting for a threshold.

```php
config()->set('sentinel.mode', 'buffered');
config()->set('sentinel.buffer.store', 'memory');

new Invoice()->forceFill(['total' => 4_200])->save();

app()->terminate();   // runs the shutdown flush, exactly as a request would
```

❌ **Don't** — ship it. The binding is `scoped`, so its contents die with the request or the job, with
no event and no log line.

```php
'buffer' => ['store' => env('SENTINEL_BUFFER_STORE', 'memory')],   // durability nobody chose
```

---

**See also:** [Performance modes](01-performance-modes.md) · [Running audits on a queue](03-queues.md) · [Events and listeners](04-events-and-listeners.md) · [Failure policy](05-failure-policy.md) · [Artisan commands](06-artisan-commands.md) · [Scheduling](07-scheduling.md) · [Monitoring and troubleshooting](08-monitoring-and-troubleshooting.md) · [The write path](../01-concepts/03-the-write-path.md) · [Verification](../07-integrity/06-verification.md) · [The hash chain](../07-integrity/01-the-hash-chain.md) · [Order, paging and walking the trail](../06-reading/03-order-paging-and-walking.md) · [Compliance mode](../08-lifecycle/05-compliance-mode.md) · [Configuration](../99-reference/02-configuration.md) · [Events reference](../99-reference/05-events.md) · [Exit codes](../99-reference/07-exit-codes.md)
