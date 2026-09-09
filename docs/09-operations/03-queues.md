# ⚙️ Running audits on a queue

> What `mode = queue` actually does: the job, the payload that crosses the process boundary, what the
> worker still has to resolve for itself, and what a failed settlement costs the trail.

**On this page:** [Turning it on](#turning-it-on) ·
[Choosing a connection and a queue](#choosing-a-connection-and-a-queue) ·
[The job and its payload](#the-job-and-its-payload) ·
[What the worker resolves for itself](#what-the-worker-resolves-for-itself) ·
[Retries, backoff and idempotent settlement](#retries-backoff-and-idempotent-settlement) ·
[Failed jobs and the gap they leave](#failed-jobs-and-the-gap-they-leave) ·
[Ordering: sequence is assigned at settlement](#ordering-sequence-is-assigned-at-settlement) ·
[Worker configuration and memory](#worker-configuration-and-memory) ·
[What to alert on](#what-to-alert-on) ·
[⚠️ Pitfalls](#-pitfalls) · [✅ Best practices](#-best-practices)

---

## Turning it on

```php
// config/sentinel.php — ships as 'sync'
'mode' => env('SENTINEL_MODE', 'sync'),
```

```dotenv
SENTINEL_MODE=queue
```

That is the whole change. `Dispatch\Dispatcher` resolves `Dispatch\QueueStrategy` instead of
`SyncStrategy`, and every entry that would have been written in the request is dispatched as
`Jobs\SettleAudit` instead. No model, listener, query or migration is written differently.

The request pays for the pipeline plus one enqueue. The worker pays for the chain: the tail read of
the stream, the hash, the insert, the labels, the relation index and — if you have switched them on —
the signature and the anchor. That trade is the only reason the mode exists.

> 🧪 **Verify it.** `php artisan about` prints a **Sentinel** section whose **Mode** row is
> `Support\Config::mode()->value`. It reports the mode the package will use, not the string in the
> file you are reading.

An unrecognised value is not a silent fallback to `sync` — `Config::mode()` casts through
`Enums\Mode` and raises `Exceptions\ConfigurationException`. See
[Performance modes](01-performance-modes.md) for the decision between the three.

---

## Choosing a connection and a queue

| Key | Env | Default | What it does |
|---|---|---|---|
| `sentinel.queue.connection` | `SENTINEL_QUEUE_CONNECTION` | `null` — the application default | The connection `SettleAudit` is dispatched on (`->onConnection()`) |
| `sentinel.queue.queue` | `SENTINEL_QUEUE` | `null` — the application default | The queue it waits in (`->onQueue()`) |

Both are `null` out of the box, and `QueueStrategy::hand()` passes the nulls straight through, so an
installation that never thinks about this gets the application's own defaults. That is the shipped
behaviour and it is tested (`tests/Dispatch/QueueModeTest.php`, *"lets the application default decide
when neither is named"*).

**Give audits a queue of their own.** An audit entry is small, uniform, and latency-sensitive in one
specific way: the further `created_at` drifts from `occurred_at`, the less the trail resembles a
timeline. Put it behind a video transcode and it arrives long after the fact it describes — and while
it waits, the fact is not in the ledger at all.

```php
// config/queue.php
'connections' => [
    'audits' => [
        'driver' => 'redis',
        'connection' => 'audits',
        'queue' => 'trail',
        'retry_after' => 90,
        'after_commit' => false,
    ],
],
```

```php
// config/sentinel.php
'queue' => [
    'connection' => 'audits',
    'queue' => 'trail',
],
```

```bash
php artisan queue:work audits --queue=trail --tries=3 --timeout=30
```

> 📌 **Note.** A dedicated *connection* buys isolation of the backend; a dedicated *queue name* on the
> shared connection buys isolation of the worker pool. The second is usually enough and costs
> nothing. Name both when audits must not share a Redis or a database with the rest of your jobs.

> ⚠️ **Warning.** The audit queue must not be worked by the same pool that runs your long jobs. A
> worker occupied for four minutes by a report is a worker not settling audits for four minutes, and
> under `mode = queue` there is no second path — nothing else in the package writes those entries.

---

## The job and its payload

`Jobs\SettleAudit` is a `ShouldQueue` with one public property: `array $payload`. Its `handle()`
rebuilds the entry and settles it:

```php
public function handle(Settlement $settlement, Runtime $runtime): void
{
    $audit = AuditData::fromPayload($this->payload);

    $runtime->whileWritingAudit(static fn (): mixed => $settlement->settleOnce($audit));
}
```

**It carries an array, not the object, and not the model.** The array is
`Data\AuditData::toPayload()`, written out by hand, key by key. A model would be re-read in the
worker, which photographs the record as it is *now* rather than as it was *then*; a serialised object
would hand a worker running last week's code an instance with an uninitialised property that fails on
first access. `fromPayload()` drops keys it does not recognise and fills in missing ones with the
constructor's defaults, which is what makes a rolling deploy survivable.

### What travels

Everything the pipeline finished with. Grouped by what it answers:

| Group | Keys |
|---|---|
| What happened | `audit_type`, `event`, `severity`, `occurred_at`, `source` |
| To what | `subject_type`, `subject_id`, `stream` |
| Who | `actor_type`, `actor_id`, `impersonator_type`, `impersonator_id`, `tenant_id` |
| Correlation | `transaction_id`, `request_id`, `trace_id`, `span_id`, `source_audit_id`, `capture_id` |
| The data | `context`, `before`, `after`, `changes`, `metadata`, `encryption`, `criteria`, `affected_rows` |
| Labels | `tags` |

Three consequences worth stating outright:

- **Masking, hashing and encryption already happened.** `MaskSensitiveData` and
  `EncryptSensitiveData` are pipeline stages and the pipeline runs at capture, in every mode. Nothing
  sensitive is sitting untransformed in a job payload, and the worker needs no encryption key to
  *write* the entry. See [The write pipeline](../05-pipeline-and-security/01-the-write-pipeline.md).
- **The context describes the request, not the worker.** `tests/Dispatch/QueueModeTest.php` asserts
  it by name: *"keeps the context of the request that captured it, not of the worker that wrote it"* —
  an entry captured in an HTTP request keeps `Source::Http` and its `context['url']` after settling in
  a worker. See [Queues, commands and schedulers](../04-context/05-queues-commands-and-schedulers.md).
- **The trace travels inside the entry**, not in the job's `Context` envelope. Under `queue`,
  `trace_id` and `span_id` are resolved at capture and ride in the payload, so a flush or a settle
  never re-resolves them. See [Distributed tracing](../04-context/06-distributed-tracing.md).

`occurred_at` is serialised as `Y-m-d\TH:i:s.uP` — microseconds and offset — because it is inside the
canonical payload the chain hashes. A round trip that rounded it would produce a different hash for
the same fact depending on which mode wrote it.

### What does not travel, and is refused if you try

```php
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Exceptions\DispatchException;

AuditData::fromPayload([...$payload, 'sequence' => 42]);
// DispatchException — the payload proposed its own place in the chain
```

`sequence`, `hash` and `previous_hash` are the ledger's, assigned inside the same operation as the
write. A payload naming any of them is refused outright rather than trusted. `audit_type`, `event`
and `occurred_at` are the other direction: missing any of them raises
`DispatchException::incompletePayload()`, because an entry without them cannot be read at all. A
`severity` or `source` the worker does not recognise falls back to `Severity::Info` / `Source::System`
instead — those are lists that grow, and an old worker should lose the shade of meaning, not the
entry.

> ⚠️ **Warning.** The payload carries `before`, `after` and `changes` in full. A model with large
> text or JSON columns produces a large job. On the `database` driver that lands in a `longText`
> column; on Amazon SQS it meets SQS's own message size limit and the enqueue fails — which under the
> default `on_write_failure = throw` takes the request down with it. Narrow the snapshot on the model
> (`$auditExclude`, `$auditSnapshots = false`) before you narrow the queue.

---

## What the worker resolves for itself

Almost nothing about the entry is decided in the worker — but *almost* is not *nothing*, and the
exceptions are the deployment surprises.

| Assigned at settlement | By | Reads from |
|---|---|---|
| `id` | `Ledger\EntryBuilder` | A fresh ULID |
| `stream` | `Integrity\Stream::resolve()` | `sentinel.integrity.stream`, applied to the `AuditData` |
| `sequence`, `previous_hash` | `Ledger\StreamGate::tail()` | The chain's current tail |
| `hash`, `algorithm` | `Integrity\Hasher` | `sentinel.integrity.algorithm` |
| `signature`, `signature_key_id` | `Integrity\Signers::current()` | `sentinel.integrity.signature.*` |
| `version` | `DatabaseLedger` | `max(version)` for that subject, plus one |
| `payload_version` | `EntryBuilder::PAYLOAD_VERSION` | A constant |
| `created_at` | `EntryBuilder` | The worker's clock |

Read the third column as a deployment checklist. **The worker's environment, not the web process's,
is where the signing key has to be.** With `integrity.signature.enabled = true` and no resolvable
key, `Signers::current()` raises `SignatureException` — in the worker, on every job, while the web
tier reports nothing. Under the `openssl` signer it is `SENTINEL_SIGNING_PRIVATE_KEY` specifically:
the verifying half is not enough to write. `Compliance\Requirements::enforce()` runs in `boot()` of
every process too, so a worker with `compliance = true` and signatures off refuses to boot at all.

> ⚠️ **Warning.** A custom `Contracts\StreamResolver` or an `integrity.stream` closure is evaluated
> **in the worker**, and it is handed the `AuditData` and nothing else. A resolver that reaches for
> the current request, the container's tenant or a session key will read the worker's answer — which
> is usually "none" — and file entries in the wrong chain. The shipped strategies (`global`,
> `tenant`, `subject_type`) are safe because they read only fields that travelled. See
> [Streams](../07-integrity/02-streams.md).

Anchors are issued in the worker too, after the write transaction closes, once per stream the
batch touched. See [Checkpoints and anchors](../07-integrity/05-checkpoints-and-anchors.md).

---

## Retries, backoff and idempotent settlement

`SettleAudit` declares no `$tries`, no `$backoff`, no `$timeout` and no `$maxExceptions`. **The
connection's configuration and the worker's flags are the entire retry policy**, and Laravel's
`queue:work` defaults to `--tries=1` — one attempt, then `failed_jobs`. If you want a settlement
retried, you have to say so.

`handle()` catches nothing, and that is also deliberate: in a worker the queue *is* the failure
policy. It retries under the same `capture_id`, which the ledger refuses to settle twice, and what
still does not land ends up where an operator can see it.

### Why a retry is safe

`Settlement::settleOnce()` asks first and writes second:

```php
return $this->unsettled([$audit]) === [] ? null : $this->settle($audit);
```

`unsettled()` calls `Contracts\Deduplicates::settled()` when the ledger implements it —
`DatabaseLedger`, `MemoryLedger` and `FanoutLedger` (delegating to its primary) all do. A retry of a
capture that already landed therefore costs **one indexed lookup and no write at all**: the job
succeeds having done nothing.

Asking is not what makes it safe. The `unique('capture_id')` index on `sentinel_audits` is the
arbiter, and the pre-check exists only so the common case does not seal a chain the database is about
to throw away. See [A retry is not a second entry](01-performance-modes.md#a-retry-is-not-a-second-entry).

### The one case where a retry looks like a failure

Two workers holding the *same* job at once — a `retry_after` shorter than the write, a job released
while still in flight — is the narrow window the pre-check cannot close. Both see nothing settled,
both build a chain, and the unique index refuses the second. `DatabaseLedger::attempt()` then
recomputes what is still unsettled; finding nothing left, it **rethrows the violation** rather than
reporting a silence the caller would read as success.

The result is a job in `failed_jobs` whose entry is already in the ledger, correct and chained.

> 📌 **Note.** Set `retry_after` on the audit connection above the worker's `--timeout`, and both
> above how long a chained write takes on your busiest stream. A duplicate delivery costs you a false
> alarm, never a duplicate row.

---

## Failed jobs and the gap they leave

A settlement that fails permanently means one thing: **the fact happened and the trail does not
record it.**

Three properties of that, in the order they matter:

- **The chain has no gap.** The entry never reached the ledger, so it consumed no `sequence` and
  broke no link. `Sentinel::verifyIntegrity()` walks a shorter chain and reports it **intact** —
  correctly. The chain proves that what settled was not tampered with; it never proves that
  everything that happened settled. See [Verification](../07-integrity/06-verification.md).
- **The package announces nothing.** `AuditWriteFailed` is not fired from inside a real worker. It is
  fired in the *capturing* process when the enqueue itself fails, and exactly once
  (`tests/Dispatch/QueueModeTest.php`, *"says a write did not complete once, and not once per process
  that saw it"*). Do not wait for an event that is not coming — listen to
  `Illuminate\Queue\Events\JobFailed` and watch `failed_jobs`.
- **`failed_jobs` is the only place the entry still exists.** The serialised job carries the whole
  payload, `capture_id` included. Flushing that table discards the fact permanently.

### The recovery

```bash
php artisan queue:failed
php artisan queue:retry --queue=trail        # or an id, or "all"
```

`queue:retry` on a settlement is always safe. Either the entry is missing and the job writes it, or
the entry is already there and `settleOnce()` finds it by `capture_id` and does nothing. What you
must not do is `queue:flush` on a queue that holds audit settlements: that is the delete button for
facts that are nowhere else.

If a retry keeps failing, read the exception before touching the ledger. The three that recur are a
missing signing key, an unreachable audit database, and a stream name a custom resolver produced that
is empty or longer than 64 characters — all configuration in the worker, none of them anything wrong
with the entry.

> 🔒 **Security.** Under `compliance = true`, `on_write_failure` is forced to `throw` regardless of
> the configured value, so an enqueue that fails takes the operation down with it. It does **not**
> reach into the worker: a job that fails after a successful enqueue is still just a failed job. If
> your regime says no fact may go unrecorded, `mode = sync` is the mode that can refuse the operation.
> See [Compliance mode](../08-lifecycle/05-compliance-mode.md) and
> [Failure policy](05-failure-policy.md).

---

## Ordering: sequence is assigned at settlement

`occurred_at` is stamped by `Capture\Recorder` and never moves. `created_at` and `sequence` are
stamped by `Ledger\EntryBuilder` and `StreamGate` when the entry lands. Under `sync` they are the
same moment; under `queue` they are however long the job waited.

**Entries settle out of order, and the chain records settlement order.** Two facts about the same
invoice, captured a second apart, can land in either order — one worker was quicker, one job was
retried, one queue was longer. `(stream, sequence)` stays dense and monotonic either way, and that is
what `verifyIntegrity()` walks. It is the order the *records* were written, not the order the world
happened in, and it is correct for what it claims.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

// Settlement order (created_at) — the default
Sentinel::audits()->for($invoice)->get();

// The order things happened (occurred_at) — what a lifeline wants
Sentinel::audits()->for($invoice)->byOccurrence()->get();
Sentinel::timeline()->for($invoice)->get();
```

### `version` follows settlement order too

This one is easy to miss. `DatabaseLedger` assigns the per-subject `version` as `max(version) + 1`
for that `subject_type`/`subject_id`, read at settlement. So if two updates to one order settle out
of order, **version 5 can hold the later fact**, and `whereVersion()` and `compare($from, $to)` will
answer accordingly.

```php
// Under `queue`, this compares two settlement positions, not two moments in the record's life.
Sentinel::audits()->for($order)->compare(4, 5);
```

Where version numbers are shown to a user or used to reconstruct a state, order by `occurred_at` and
read the versions off that list rather than assuming they are ascending in time. See
[Field history and comparing versions](../06-reading/04-field-history.md).

### Adding workers does not multiply throughput on one stream

`StreamGate::tail()` serialises the writers of a stream before they read it, because the hash covers
`sequence` and `previous_hash` — no INSERT can compute its own link. Ten workers all writing to the
`global` stream queue up behind that read.

> 🐘 **Engine.** The gate is one mechanism per engine. PostgreSQL takes
> `pg_advisory_xact_lock(hashtext(stream))`, because it locks no row that is not there yet. MySQL
> relies on InnoDB's gap lock over the `lockForUpdate()` on the tail. SQLite ignores the clause
> entirely and serialises at the file. If audit write throughput is your ceiling, the lever is the
> **stream strategy** — `tenant` or `subject_type` gives you concurrent chains — not the worker
> count. See [Streams](../07-integrity/02-streams.md) and
> [Scaling playbook](../10-database-engines/08-scaling-playbook.md).

---

## Worker configuration and memory

| Setting | Why it matters here |
|---|---|
| `--queue=trail` | The audit queue, worked by a pool that holds nothing slow |
| `--tries` | `SettleAudit` sets none. The default is 1 — one attempt and the fact is in `failed_jobs` |
| `--backoff` | A transient database failure is worth a second attempt a few seconds later; a missing signing key is not |
| `--timeout` | Must exceed a chained write on your busiest stream, including the tail-read wait |
| `retry_after` (connection) | Must exceed `--timeout`, or the job is released while still settling and you buy the duplicate-delivery case above |
| `--memory` | One payload at a time; nothing accumulates between jobs under `queue` |
| `after_commit` (connection) | Redundant for Sentinel: the Dispatcher already defers the hand-over and the job is already marked — see below |

**Memory is not a `queue`-mode problem.** Laravel's worker forgets scoped instances between jobs, and
`SettleAudit` holds exactly one payload for the length of one `handle()`. The mode that accumulates
in a process is `buffered`, not this one. See [The buffered mode](02-the-buffered-mode.md).

### The commit boundary

With `sentinel.transactions.after_commit = true` (the default), the *Dispatcher* registers the
hand-over in `Connection::afterCommit()` on the subject's connection — so a rollback dispatches
nothing at all, and there is no job to fail:

```php
use Illuminate\Support\Facades\DB;

DB::transaction(function () use ($invoice): void {
    $invoice->update(['status' => 'approved']);
    // No SettleAudit has been dispatched yet.
});
// Now it has.
```

`QueueStrategy::hand()` additionally marks the job `->afterCommit()` whenever that setting is on, so
the package and the queue never disagree. That is belt and braces: it covers the entry captured
against one connection while a second one still has a transaction open. Turn the setting off and the
mark goes with it, because an operator who turned it off asked for the entry to be written whatever
the transaction decides.

> ⚠️ **Warning.** `Queue::fake()` does not prove a job waited for the commit — the fake bypasses
> `Queue::enqueueUsing()`, so Laravel's own after-commit dispatch never runs. In this package the
> waiting is done by the Dispatcher, not by the queue, so a test that means to assert the deferral
> must assert that nothing was dispatched *inside* `DB::transaction()`, as the shipped test does.

---

## What to alert on

The package emits no metrics of its own. These are the signals that exist:

| Signal | Where it comes from | What it means |
|---|---|---|
| `Illuminate\Queue\Events\JobFailed` on the audit queue | The framework | A fact may not be recorded |
| Rows in `failed_jobs` for that queue | The framework | The same, accumulated |
| `Illuminate\Queue\Events\QueueBusy` | `php artisan queue:monitor audits:trail --max=1000` | Settlement is falling behind capture |
| `Events\AuditWriteFailed` | Sentinel, in the **capturing** process | The enqueue was refused — not a worker failure |
| `Events\AuditCreated` | Sentinel, in the **worker** | An entry exists, with its sequence and hash |
| `created_at − occurred_at` on the newest entry | The two clocks | How far settlement is behind the facts |

```php
use ElPandaPe\Sentinel\Events\AuditWriteFailed;
use ElPandaPe\Sentinel\Models\Audit;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

// A settlement that never landed. Filter by queue, not by class: Jobs\SettleAudit is @internal.
Event::listen(function (JobFailed $event): void {
    if ($event->job->getQueue() !== 'trail') {
        return;
    }

    Log::channel('audit-alerts')->critical('An audit entry did not settle.', [
        'connection' => $event->connectionName,
        'job' => $event->job->getJobId(),
        'exception' => $event->exception,
    ]);
});

// The enqueue itself was refused. This one fires in the request.
Event::listen(function (AuditWriteFailed $event): void {
    Log::channel('audit-alerts')->critical($event->message(), [
        'subject_type' => $event->subjectType,
        'subject_id' => $event->subjectId,
    ]);
});
```

```php
// Settlement lag, in seconds, from the newest entry the ledger holds.
$newest = Audit::query()->latest('created_at')->first();

$lag = $newest?->occurred_at->diffInSeconds($newest->created_at) ?? 0;
```

> 📌 **Note.** Loss detection under an asynchronous mode is out of band by construction. Count what
> you handed over against what landed — `Events\Audited` in the request against `Events\AuditCreated`
> in the worker — and treat a growing difference as the signal. No verification walk can report it.
> See [Monitoring and troubleshooting](08-monitoring-and-troubleshooting.md).

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| Every entry has a null `trace_id` or the wrong tenant after switching to `queue` | A custom resolver or stream strategy that reads request-scoped state and is evaluated in the worker | Derive everything from the `AuditData` the job carried; the shipped resolvers already do |
| `whereSource(Source::Queue)` returns almost nothing under `mode = queue` | `Source::Queue` means *"captured while Sentinel was settling an entry"*, not *"settled by a worker"*. A queued entry keeps the source of the process that captured it — `http`, `api`, `cli` | Filter by what captured the fact; the mode leaves no mark on the entry at all |
| A job in `failed_jobs` with a unique-constraint violation on `capture_id`, but the entry is in the ledger | Duplicate delivery: the job was released while the first attempt was still settling | Raise `retry_after` above `--timeout`. The failed job can be retried (it will no-op) or forgotten |
| `verifyIntegrity()` reports the chain intact while entries are visibly missing | A lost settlement consumed no `sequence`, so there is no gap and no broken link | Detect loss out of band — `failed_jobs`, queue depth, handed-over vs landed counts |
| Entries stop appearing entirely; the web tier logs nothing | The worker cannot boot or cannot sign: a missing `SENTINEL_SIGNING_KEY`, or `compliance = true` with signatures off | Read the worker's own log. `AuditWriteFailed` is never fired from inside a worker |
| A mass update of 5 000 rows floods the queue with 5 000 jobs | `mass_operations.mode` was moved off the shipped `summary`, and `QueueStrategy::inRequestBatch()` maps the single-entry path — `queue` does not batch, by design | Keep `summary` where a per-row entry is not required, or use `mode = buffered`, where the same rows settle in batches of `buffer.size` |
| A model with big JSON columns fails to enqueue on SQS | The payload carries `before`, `after` and `changes` in full and exceeds the driver's message size limit | Narrow the snapshot on the model, or move that connection to Redis or the database driver |
| `sentinel_transactions.audits_count` is higher than the entries carrying that `transaction_id` | The counter records accepted hand-overs, because the header closes long before a worker runs | Read it as *"what this operation accepted for settlement"*, and reconcile against `failed_jobs` |
| Version numbers of one record are not in chronological order | `version` is `max(version) + 1`, assigned at settlement | Order by `occurred_at` and read versions off that list |
| Two audit workers on separate boxes give no more throughput than one | All entries share one stream and `StreamGate` serialises its writers | Split the chain with `integrity.stream` — `tenant` or `subject_type` — rather than adding workers |

---

## ✅ Best practices

✅ **Do** — give the audit queue its own name, and work it with a pool that holds nothing slow. An
audit waiting behind a transcode arrives long after the fact it describes, and until it settles the
fact is not in the ledger at all.

```php
// config/sentinel.php
'queue' => ['connection' => 'audits', 'queue' => 'trail'],
```

```bash
php artisan queue:work audits --queue=trail --tries=3 --backoff=5 --timeout=30
```

❌ **Don't** — leave both keys null on an application whose default queue does the heavy work. The
nulls mean "the application default", which is exactly the pool you did not want.

```php
'queue' => ['connection' => null, 'queue' => null],   // fine on a quiet app, a trap on a busy one
```

✅ **Do** — set `--tries` explicitly on the audit worker. `SettleAudit` declares none, so Laravel's
default of one attempt applies: a single transient database blip permanently loses that entry.

```bash
php artisan queue:work audits --queue=trail --tries=3 --backoff=5
```

❌ **Don't** — assume the package retries for you because it deduplicates. Idempotency is what makes a
retry *safe*; it is not what makes one *happen*.

```php
// Jobs\SettleAudit — the whole retry policy is what is NOT here:
// no $tries, no $backoff, no $timeout, no $maxExceptions.
```

✅ **Do** — listen to the queue's own failure events, filtered by queue name, and alert on
`failed_jobs` depth. That is the only place a lost settlement is visible.

```php
use Illuminate\Queue\Events\JobFailed;

Event::listen(function (JobFailed $event): void {
    if ($event->job->getQueue() === 'trail') {
        // page someone
    }
});
```

❌ **Don't** — build the alert on `Events\AuditWriteFailed`, or on the internal job class. The event
is never fired from inside a real worker, and the whole `Jobs` namespace is marked `@internal` — it
carries no API-stability promise.

```php
use ElPandaPe\Sentinel\Events\AuditWriteFailed;
use ElPandaPe\Sentinel\Jobs\SettleAudit;      // @internal — not a public name

Event::listen(fn (AuditWriteFailed $e) => alert($e));   // silent for every worker-side failure
```

✅ **Do** — put the signing key, the audit database credentials and the full `sentinel` config in the
worker's environment. The worker is where the chain is actually built.

```bash
SENTINEL_MODE=queue
SENTINEL_SIGNING_KEY=…      # the worker signs; the web tier does not
SENTINEL_DB_CONNECTION=audits
```

❌ **Don't** — assume the web tier's configuration is enough because that is where the request ran.
A worker missing the signing key fails every job while the request path reports success.

```php
'integrity' => ['signature' => ['enabled' => true, 'keys' => ['default' => env('SENTINEL_SIGNING_KEY')]]],
// …and SENTINEL_SIGNING_KEY absent from the worker's environment
```

✅ **Do** — retry a failed settlement before you investigate the ledger. `settleOnce()` looks the
capture up first, so a retry either writes the missing entry or finds it already there and does
nothing.

```bash
php artisan queue:retry --queue=trail
```

❌ **Don't** — flush the failed jobs of the audit queue to tidy up. Those payloads are the only copy
of facts that are in no chain and no table.

```bash
php artisan queue:flush     # deletes audit entries that were never written anywhere else
```

✅ **Do** — audit your reads before you switch. Anything ordering or filtering on `created_at` is
asking about settlement; move it to `byOccurrence()` or `Sentinel::timeline()` if it meant to ask
about the facts.

```php
Sentinel::audits()->for($patient)->byOccurrence()->get();
```

❌ **Don't** — read `Events\Audited::$entry`, or a restore's `RestoreResult::$entry`, as proof an
entry exists. Under `queue` both are null by design; null means *settled elsewhere*, and a failure
announces `AuditWriteFailed` instead.

```php
use ElPandaPe\Sentinel\Events\Audited;

Event::listen(function (Audited $event): void {
    if ($event->entry === null) {
        // Wrong: under `queue` this is the normal path, not a lost entry.
    }
});
```

---

**See also:** [Performance modes](01-performance-modes.md) ·
[The buffered mode](02-the-buffered-mode.md) · [Failure policy](05-failure-policy.md) ·
[Events and listeners](04-events-and-listeners.md) ·
[Monitoring and troubleshooting](08-monitoring-and-troubleshooting.md) ·
[Queues, commands and schedulers](../04-context/05-queues-commands-and-schedulers.md) ·
[Streams](../07-integrity/02-streams.md) · [The hash chain](../07-integrity/01-the-hash-chain.md) ·
[Order, paging and walking the trail](../06-reading/03-order-paging-and-walking.md) ·
[Configuration](../99-reference/02-configuration.md)
