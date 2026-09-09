# ⚙️ Monitoring and troubleshooting

> What to watch on a Sentinel installation, what each signal means when it moves, and the checks to run — in order — for the eight ways an audit trail goes wrong.

**On this page:** [First two diagnostics](#the-first-two-diagnostics) · [What to monitor](#what-to-monitor) · [Collecting the harder signals](#collecting-the-harder-signals) · [Exit codes as a contract](#exit-codes-as-a-monitoring-contract) · [Troubleshooting](#troubleshooting-symptom-first) · [⚠️ Pitfalls](#️-pitfalls) · [✅ Best practices](#-best-practices)

---

## The first two diagnostics

Before reading a log or opening a table, run these two. Between them they answer "what is this
installation configured to do" and "is it doing it for this record".

```bash
php artisan about
```

The package registers a `Sentinel` section (`src/Console/About.php`) with exactly six rows:

| Row | What it answers |
|---|---|
| Version | What Composer recorded at install time, or the literal `dev` for a path repository or a checkout |
| Mode | `sync`, `queue` or `buffered` — which of the three settlement paths is in force |
| Ledger | The configured default driver: `database`, `memory`, `null` or `fanout` — `archive` is refused as a default and only ever a fanout destination |
| Payload version | The canonical payload format the entries being written now are hashed under |
| Compliance mode | `ENABLED` or `OFF` — four commands and every read change behaviour with it |
| Telemetry | `ENABLED` or `OFF` — whether trace context is read, opened and forwarded |

> 🔒 **Security.** `about` carries no encryption key, no key identifier and no signer configuration,
> deliberately: its output is pasted into issues and captured by deploy logs. It is safe to attach
> to a bug report as it stands.

```bash
php artisan sentinel:show --subject=invoice:4211 --limit=20
```

`sentinel:show` reads through the public Query API ordered by `occurred_at`, newest last, and prints
the presenter's timeline. Three outcomes matter:

- entries print — capture works for this model, and the problem is narrower than "nothing is recorded";
- `Nothing has been recorded about invoice:4211.` at exit `0` — the query ran and matched nothing;
- `Nothing was read: <reason>` at exit `2` — the query itself failed (missing table, unreachable connection).

`--subject` is `type:id`, split on the **last** colon, and the type is taken verbatim so a morph-map
alias works as written. Passing both an entry id and `--subject`, or neither, is refused with exit `2`
rather than resolved.

---

## What to monitor

| Signal | How to collect it | What normal looks like | Alert when |
|---|---|---|---|
| **Buffer depth** (`buffered` only) | `LLEN` on the Redis key named by `sentinel.buffer.key` (default `sentinel:buffer`), on the connection named by `sentinel.buffer.connection` | A sawtooth between 0 and `buffer.size` | It stays above `buffer.size` between samples, or never returns towards 0 |
| **Flush failures** (`buffered` only) | A listener on `BufferFlushFailed`; the exit code of `sentinel:flush` | No events; the command exits `0` | Any event at all. `skipped() > 0` **with** `returned == 0` means entries were lost outright |
| **Write failures** | A listener on `AuditWriteFailed`; under `on_write_failure = log`, the channel named by `log_channel` | No events | Any event. It fires under both policies, so the policy decides what the request pays, not whether you hear about it |
| **Fanout destination failures** | A listener on `LedgerDestinationFailed` | No events | Any event under `on_failure = strict`; a rising rate under `primary` |
| **Verification result** | The exit code of `sentinel:verify` on a schedule; a listener on `IntegrityVerificationFailed` | Exit `0`, and a `Chain: intact` column per stream | Exit `1` — page a human. Exit `2` — nothing was checked, which is not the same as nothing being wrong |
| **Unanchored tail length** | `max(sequence)` per stream minus `max(sequence_to)` from the checkpoints table; or the range count `sentinel:checkpoint` reports | Below `integrity.checkpoints.every` (default 1000) right after each run | It exceeds a few windows — the scheduled `sentinel:checkpoint` is not running or is failing |
| **Table growth** | Row count and on-disk size per `sentinel_*` table, sampled from the database | Growth tracks write volume | The rate changes without traffic changing; or `sentinel_audits` grows while `sentinel:prune` reports nothing removed |
| **Access-log growth** (compliance only) | Row count of `sentinel_access_log`, and the count of `audit_type = 'access'` entries | Grows with **reads**, not writes | It outgrows the trail it describes — every Query API read writes two records |
| **Partition headroom** | `sentinel:partitions --dry-run` on a schedule, reading its exit code and its `Would create N partitions` line | Exit `0` and `Nothing to do` | A run that would still create partitions (headroom consumed), or exit `1` (partitions kept because they still hold entries) |
| **Job queue depth** (`queue` only) | `Queue::size()` on `sentinel.queue.connection` / `sentinel.queue.queue`; the `failed_jobs` table | Near zero, drained as fast as it fills | A backlog that grows, or `SettleAudit` jobs landing in `failed_jobs` |
| **Discards** | A listener on `AuditDiscarded`, counted by `reason` | A steady `unchanged` rate; nothing else | `policy` or `cancelled` appearing where you did not put a policy or a listener |

> 📌 **Note.** `AuditPage` has no total, and that is a decision rather than an omission: counting the
> rows a filter matches on a table that only ever grows is the one question in the read API whose
> cost is unbounded. Table growth is a database question, not a Query API question.

---

## Collecting the harder signals

### Buffer depth

`Contracts\Buffer` is `@internal`, so read the list the driver reads. `Buffer\RedisBuffer` pushes
with `RPUSH`, takes with `LPOP key count` and reports `size()` as a raw `LLEN` on that one key.

```php
use Illuminate\Support\Facades\Redis;

$depth = Redis::connection(config('sentinel.buffer.connection'))
    ->llen(config('sentinel.buffer.key'));
```

Two things this number does **not** tell you. It counts elements the package did not write — the key
is Sentinel's alone, and anything else sharing it inflates the depth and is silently dropped on the
next flush. And a depth of zero does not mean the last entries settled; it also describes a process
that died holding them, which is the one loss the buffered mode admits.

### The flush outcome

`Buffer\Flusher::flush()` is the single method all five triggers pass through — the size threshold,
the interval threshold, `terminating`, `WorkerStopping` and `sentinel:flush` — so one listener covers
every one of them. Two of those triggers announce nothing else at all.

```php
use ElPandaPe\Sentinel\Events\BufferFlushFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

Event::listen(function (BufferFlushFailed $event): void {
    Log::channel('audit-alerts')->critical($event->message(), [
        'taken' => $event->taken,
        'settled' => $event->settled,
        'returned' => $event->returned,   // back in the buffer, whole: a retry
        'skipped' => $event->skipped(),   // deduplicated, OR lost — see below
        'exception' => $event->reason,
    ]);
});
```

`skipped()` is derived as `taken - settled - returned`, so the four counts cannot contradict each
other. It counts two different things: entries the ledger had already settled (harmless) and entries
the buffer refused to take back (gone). `returned == 0` on a failed batch is the second case. The
shipped sentence renders `:skipped` as *"had already settled elsewhere"*, which is wrong exactly
then — read the numbers, not the sentence.

### Write failures

```php
use ElPandaPe\Sentinel\Events\AuditWriteFailed;
use ElPandaPe\Sentinel\Events\LedgerDestinationFailed;
use Illuminate\Support\Facades\Event;

Event::listen(function (AuditWriteFailed $event): void {
    OnCall::page($event->message(), [
        'audit_type' => $event->auditType,
        'event' => $event->event,
        'subject' => $event->subjectType.'#'.$event->subjectId,
        'transaction_id' => $event->transactionId,
        'exception' => $event->failure,
    ]);
});

// Under a strict fanout this is the only event naming the entry that DID land.
Event::listen(function (LedgerDestinationFailed $event): void {
    OnCall::page($event->message(), [
        'destination' => $event->destination,
        'coordinate' => $event->stream.'#'.$event->sequence,
        'audit_id' => $event->auditId,
    ]);
});
```

`AuditWriteFailed` carries identity and the exception, never a payload. It does **not** mean "no
entry exists": under a strict fanout it is raised after the primary has already sealed and stored
the entry. And in `queue` mode it only ever means the enqueue was refused — a failure inside a real
worker announces nothing, because there the queue's retry and `failed_jobs` are the failure policy.

### Verification, on a schedule

```bash
php artisan sentinel:verify --depth=anchors   # cheap: reads only the anchors
php artisan sentinel:verify                   # deep: rehashes every entry
php artisan sentinel:verify --projections     # additionally re-checks the relation index
```

The table has five columns — Stream, Entries, Chain, Anchors, Signatures. `Chain` reads `intact` or
`BROKEN`; the `Anchors` column and the second signature tally appear only where there is one, so an
installation that has never anchored does not read as one whose anchors failed. `Unsigned` and
`unknown key` are reported in the Signatures column and do **not** fail the run; only `INVALID` does.

In application code the same walk is `Sentinel::verifyEverything()`:

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

$report = Sentinel::verifyEverything();

$report->isIntact();      // nothing came back wrong — not "everything was read"
$report->checked();       // entries actually read and rehashed
$report->covered();       // entries taken on the word of an anchor
$report->firstBreak()?->message();
```

### The unanchored tail

Anchors are emitted a whole window at a time; the trailing incomplete window is never anchored, and
a verification walks it entry by entry. So a tail shorter than `integrity.checkpoints.every` is the
healthy steady state, and a tail of several windows means the emission is not running.

```sql
select a.stream,
       max(a.sequence) - coalesce(c.reach, 0) as unanchored
from sentinel_audits a
left join (
    select stream, max(sequence_to) as reach from sentinel_checkpoints group by stream
) c on c.stream = a.stream
group by a.stream, c.reach;
```

> ⚠️ **Warning.** `integrity.checkpoints.enabled` governs only threshold emission on the write path.
> `sentinel:checkpoint` ignores it completely and anchors whether it is `true` or `false`, using
> `integrity.checkpoints.every` purely as the window size. Reading `enabled => false` as "anchoring
> is off" is wrong about the command.

### Partition headroom

```bash
php artisan sentinel:partitions --table=audits --ahead=6 --retire="18 months" --dry-run
```

Idempotent by construction: what should exist comes from the clock, what does exist comes from the
catalogue, and only the difference is issued. On an undivided table it prints
`The table [sentinel_audits] is not partitioned, so there was nothing to maintain.` and exits `0` —
which is why it is safe to schedule everywhere.

> 🐘 **Engine.** Only MySQL and PostgreSQL partition. On SQLite the command always takes the
> undivided path. On PostgreSQL, keep `--ahead` small: reading the tail of a stream is a Merge Append
> across every partition, and the package's own volume benchmark measured 13.4 ms of planning over 41
> partitions against 0.58 ms over one — paid on every write.

---

## Exit codes as a monitoring contract

Three codes, one meaning each, across all eleven commands. Branch a cron on the vocabulary, not on
the command.

| Code | Meaning | Watchdog action |
|---|---|---|
| `0` SUCCESS | The ordinary outcome, **including having found nothing to do** | Nothing |
| `1` FAILURE | A bad finding from a run that happened | A human looks at it |
| `2` INVALID | A run that could not happen | Retry, or page the operator |

Only `sentinel:verify`, `sentinel:prune`, `sentinel:partitions`, `sentinel:redact`, `sentinel:import`
and `sentinel:flush` can ever return `1`. `sentinel:install`, `sentinel:show`, `sentinel:checkpoint`,
`sentinel:export` and `sentinel:rekey` have no exit `1` at all, so a watchdog written for one
command's vocabulary does not transfer unexamined to another's.

Two commands invert the intuition. `sentinel:flush` exits `1` — not `2` — when the flush itself did
not settle, precisely because the answer is *run again*. `sentinel:redact` exits `1` for a refusal
that will never succeed on this version, and `2` for the retryable case.

---

## Troubleshooting, symptom-first

### Nothing is being written

1. `php artisan about` — read **Mode** and **Ledger**. `Ledger: null` builds and seals the entry and keeps nothing: queries come back empty however narrow they were.
2. `config('sentinel.enabled')` — `false` makes `Sentinel::isRecording()` false, and nothing is captured or dispatched. Check for a stray `Sentinel::pause()` or an enclosing `Sentinel::withoutAuditing()`.
3. Does the model `use ElPandaPe\Sentinel\Concerns\Auditable`? The trait registers observers for `created`, `updated`, `deleted` and `forceDeleted`; without it Eloquent fires nothing Sentinel hears. See [What a model declares](../02-getting-started/03-what-a-model-declares.md).
4. Was it a `Builder::update()` / `delete()` / `upsert()`? Eloquent fires no model event for those; they are captured only through `->auditing()` on the query. See [Mass operations](../03-capture/05-mass-operations.md).
5. Did the update change anything audited? `FilterUnchanged` discards an `updated` whose diff is empty, writing no entry and consuming no sequence. See [Discarding entries](../05-pipeline-and-security/06-discarding-entries.md).
6. Was it inside a transaction that rolled back? With `transactions.after_commit` on (the default), the entry is discarded on rollback, by design.
7. Under `queue` or `buffered`, the entry exists somewhere other than the table: check queue depth and `failed_jobs`, or run `php artisan sentinel:flush`.
8. Still nothing? Listen on `AuditDiscarded` for one request — it is the single door every stopped entry leaves through, and `$reason` names which of `unchanged`, `policy`, `cancelled` or `unspecified` applies.

### Entries have no actor

`actor_type` and `actor_id` are empty when `ActorResolver` found no authenticated user, which is the
ordinary state of a queue worker, an Artisan command and the scheduler.

1. Read `source` on the entry: `queue`, `job`, `scheduler`, `cli` or `console` explains it immediately.
2. Check `resolvers.actor.guard`. `null` means the application default guard; a guard name the auth factory does not know raises `ConfigurationException` rather than resolving to nobody.
3. If the actor should travel with the job, carry it explicitly — see [Queues, commands and schedulers](../04-context/05-queues-commands-and-schedulers.md).
4. If you set an actor with `->actor()` on a custom event and a policy still saw somebody else, you are on a release before `v1.0.0-rc.2`, where a named actor was reapplied after the pipeline. From that candidate on `ResolveContext` applies it, and `Sentinel::filter()` sees the actor you named.
5. `sentinel:import` removes the `ResolveContext` stage for the length of a run on purpose, so an imported history is not attributed to whoever ran the migration.

Impersonation and the `impersonated_by` session key are covered in
[Actor and impersonation](../04-context/03-actor-and-impersonation.md).

### Verification fails after a deploy

An upgrade cannot invalidate old entries by itself: `Integrity\Hasher` reads `payload_version` and
`algorithm` **off the row**, not from configuration, so yesterday's entries are rehashed the way they
were written. Work through what actually changed.

1. Read the failure. `sentinel:verify` prints the break's own sentence, and `IntegrityBreak` says which kind it is — `HashMismatch`, `LinkMismatch`, `SequenceGap`, `SignatureMismatch`, `CheckpointMismatch` or `ProjectionMismatch`.
2. `ProjectionMismatch` is **not** a chain break. Its own sentence says so: the indexed relation lines are a projection, and the chain does not cover them. Rebuild the index; do not page anyone.
3. `SignatureMismatch` means a signature its own key does not verify. A key merely missing from `integrity.signature.keys` reports as `unknown key` in the Signatures column and passes — check whether the deploy dropped a key from the ring rather than replaced it.
4. `HashMismatch` on a row you did not touch: something wrote to `sentinel_audits` outside the package. The `Audit` model refuses `update()` and `delete()` with `ImmutableAuditException`; a raw `DB::table()` statement or a data migration does not go through it.
5. A deploy that changed `models.audit` to a subclass with different casts changes how attributes come back, and the canonical payload is built from those attributes. Revert the cast, not the entry.

### The trail has a gap

A `SequenceGap` means the walk expected sequence *n* and found *n+1* with nothing accounting for the
absence. The verifier steps over an absence only when **two** things hold at once: the archive
manifest says that range was retired, **and** the anchors reach past it. Neither alone is enough —
the manifest row is unsigned and unhashed, so on its own it would be a way of laundering a gap.

1. Was the range pruned? `sentinel:prune --action=archive` writes the batch out, reads it back and rehashes it before a row goes, and leaves a manifest row. `--action=delete` leaves none, and a range removed that way is indistinguishable from one somebody removed by hand.
2. Was a partition dropped? `sentinel:partitions --force` drops a range as a catalogue operation — nothing archived, nothing recorded that it went.
3. Otherwise, rows were removed outside the package. Redaction is not a candidate: a tombstone empties the content columns and leaves the row, its sequence, its hash and its link exactly where they were.
4. A buffered installation that lost entries produces **no** gap. An entry that never reached the ledger consumed no sequence, so the chain verifies and is shorter. That loss is detectable only through `BufferFlushFailed` and out-of-band counters.

See [Verification](../07-integrity/06-verification.md) and
[The verification playbook](../07-integrity/07-the-verification-playbook.md).

### Writes got slow

1. `php artisan about` — **Mode**. `sync` settles in the request; that is the point of the mode and the price of being able to tell the caller the write failed.
2. Are mass operations running under `individual`? The package's write-path benchmark, over 500 rows on SQLite, measured 4.6 µs/row for `summary` against 889.7 µs/row for `individual`. `summary` is a fixed cost for a set of any size.
3. Is `integrity.checkpoints.enabled` on? That puts the fold on the write path, once per window. Turn it off and schedule `sentinel:checkpoint` instead.
4. Is signing on, and with which signer? The same benchmark measured HMAC at +1.5 % over an unsigned write and OpenSSL RSA-2048 at +35.5 %.
5. Is compliance mode on? Every Query API read then writes an `access` entry plus an access-log row — measured at about +2.5 ms per read, roughly 1.8× what a read cost before.
6. Is the table partitioned with many partitions ahead? See the Merge Append figures above.
7. Are your listeners cheap? Sentinel's events dispatch **inline on the write path**; whatever you hang off them is charged to the request that saved the model.

Numbers above come from the package's own benchmarks (`make bench`, `make bench-volume`) on one
machine. They are ratios to reason with, not a promise about your hardware.

### The buffer keeps growing

1. `php artisan sentinel:flush`. Exit `2` (`Sentinel is writing in :mode mode…`) means the mode is no longer `buffered` and those entries are stranded — both shutdown hooks refuse to touch the buffer too. Exit `1` means the flush ran and did not settle: read the `BufferFlushFailed` counts.
2. If the ledger is unreachable, the buffer grows without bound. Every failed flush puts its batch back at the head and every later arrival re-triggers a flush that fails again; there is no ceiling, no back-pressure and no drop policy.
3. If depth is high but nothing is failing, nothing is triggering a flush. Both thresholds are evaluated **only when an entry arrives** — there is no timer. Schedule `sentinel:flush` (`->everyMinute()`), which is the only real ceiling on how long an entry waits.
4. If `LLEN` disagrees with what a flush settles, something else is writing to `sentinel.buffer.key`. Foreign elements inflate `size()`, are dropped on decode, and one at the head makes `oldest()` null — which silences the interval threshold entirely.
5. `buffer.store = memory` is a reference implementation and a test double. Its binding is `scoped`, so its contents die with the request or job.

### A restore does nothing

`restore()` returns a `RestoreResult`, never a boolean, because a partial restoration is neither
success nor failure.

```php
$result = $audit->restore();

$result->refused;              // an Omission, or null — the whole restoration was declined
$result->applied;              // keys put back (keys, never values)
$result->skipped;              // array<string, Omission> — one reason per key
$result->reason('email');      // falls back to $refused, which answers for every key
```

Read the `Omission`. The whole-restoration refusals are `SubjectMissing`, `EntryRedacted`,
`EntryTampered`, `EntryStateless`, `Cancelled` and `EntryImported`; the per-key ones are
`UnknownField`, `UnrecordedField`, `IdentityField`, `RedactedField`, `HashedField`,
`KeyUnavailable`, `RelatedMissing` and `Unchanged`. Two are worth calling out:

- `Cancelled` means an `AuditRestoring` listener returned `false`. That hook is the **whole** of Sentinel's answer to "who may restore" — the package imposes no gate of its own.
- `EntryImported` is conditional, not absolute: it refuses only when no fields were named. Name the fields and the restoration proceeds, which is what its own message tells you to do.

See [Restoring state](../06-reading/08-restoring-state.md).

### A filter returns nothing on one engine

1. Is the ledger a `DatabaseLedger`? `memory` and `null` answer queries from what they hold, which is nothing or almost nothing.
2. Does the driver declare the filter? A driver implementing `Contracts\DeclaresFilters` refuses an unsupported filter with `LedgerException::cannotFilterBy` **as you add it**, not when it runs. A driver that declares nothing is taken to answer only the nine filters that existed in v0.9.0, and that set never grows.
3. For `whereIp()` and `whereRoute()`: both read the `context` JSON and match **exactly and case-sensitively on all three engines**. MySQL's default collation is case- and accent-insensitive, so the package adds a `collate utf8mb4_bin` recheck — which means a value differing only in case now matches on none of the three, where it previously matched on MySQL alone.
4. On SQLite, an entry whose `context` column holds text that is not valid JSON is treated as `{}` and never matches. The guard is there because `json_extract` over unparseable text aborts a statement that PDO then answers partially, with no exception at all.
5. `whereFieldChanged()` matches the RFC 6901 pointer or anything beneath it — `/profile` finds `/profile/address/city` — and is an equality plus a prefix, never a `LIKE`, so `%` and `_` in a field name are inert.
6. Check where the values come from. Both `ip` and `route` are written by `RequestResolver`, and `route` is the route's **name**, falling back to its URI when it has none — so `whereRoute('invoices/{invoice}')` and `whereRoute('invoices.show')` are different questions. Entries captured outside a request have neither key, and a replaced `resolvers.request` may write neither.

See [Filters reference](../06-reading/02-filters-reference.md) and
[Indexes and JSON](../10-database-engines/05-indexes-and-json.md).

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| A nightly `sentinel:partitions --retire` exits `1` every month over a correct state | Any partition kept makes the run refuse, and a partition behind the cutoff that still holds rows is always kept | Run `sentinel:prune` to archive the range first, then `sentinel:partitions` |
| `sentinel:verify` exits `2` on every scheduled run | `ledger.default` is `null`, which is the one shipped driver that cannot enumerate its streams, so `checkpoint`, `prune` and `verify` refuse rather than report "nothing is broken" (`memory` enumerates, but only what the running process wrote, so it reports nothing at exit `0`) | Do not switch the ledger to `null` to turn auditing off on a host that runs maintenance; use `sentinel.enabled` |
| A flush failure logs "Nothing was lost: what did not settle is back in the buffer", but entries are gone | `Flusher::putBack()` swallows a refusal from the buffer and returns 0; the command still says the batch is safe | Trust `BufferFlushFailed::skipped()` with `returned == 0`, not the sentence |
| An `IntegrityVerificationFailed` pages on-call for an intact chain | `IntegrityBreak::ProjectionMismatch` is a stale relation index, not a chain break | Match on the enum case, and route `ProjectionMismatch` to a warning |
| A write-failure log line says "The deferred write … did not complete" for a failure in the request | The event has one translation key, written when it was the deferred branch's only | Read the exception carried on the event, not the sentence |
| `sentinel:export --disk=exports` wrote no file and exited `0` | The file branch requires **both** `--disk` and `--path`; with either missing the whole body goes to standard output | Always pass both, and keep the `.manifest.json` beside the body |
| A dry run said "Would remove N entries" and the real run exited `2` | `prune --dry-run` returns before the compliance check, so it never rehearses the `--action=delete` refusal | Under compliance mode use `--action=archive`; treat that dry run as a count, not a rehearsal |
| `sentinel:verify --from=…` verified the whole stream | A numeric option whose value is not numeric is treated as **absent**, not as zero, and nothing says so | Check the values you pass; `--from`/`--to` also require `--stream` and the `entries` depth |
| The trail verifies but entries are missing | A buffered entry that never reached the ledger consumed no sequence, so there is no gap | Detect loss out of band: `BufferFlushFailed`, the `sentinel:flush` count, and handed-over-vs-landed counters |
| `sentinel_audits` grows fast on a read-heavy system | Compliance mode records every Query API read as an `access` entry plus an access-log row | Expect it, size for it, and partition `sentinel_access_log` if the volume warrants |

---

## ✅ Best practices

✅ **Do** — branch a watchdog on the three exit codes as one vocabulary. `0` is fine, `1` is a finding a human reads, `2` is retry-or-page.

```bash
php artisan sentinel:verify --depth=anchors
case $? in
  0) exit 0 ;;
  1) page "sentinel: chain verification found a break" ;;
  2) page "sentinel: verification could not run" ;;
esac
```

❌ **Don't** — treat every non-zero exit as the same alarm. A broken chain and an unreachable database must not look alike to a cron, which is the entire reason there are three codes and not two.

```bash
php artisan sentinel:verify || page "sentinel failed"   # loses the distinction
```

✅ **Do** — alert on `BufferFlushFailed` and read its counts, because two of the five flush triggers announce nothing else at all.

```php
Event::listen(function (BufferFlushFailed $event): void {
    $lost = $event->returned === 0 ? $event->skipped() : 0;

    Metrics::gauge('sentinel.buffer.lost', $lost);
});
```

❌ **Don't** — monitor `AuditWriteFailed` as the buffered mode's loss signal. On a threshold-triggered flush it names the entry that just arrived, which is by design the one entry that is safe.

```php
Event::listen(fn (AuditWriteFailed $e) => Metrics::increment('sentinel.lost')); // wrong fact
```

✅ **Do** — schedule the maintenance commands in the order retention needs, and check the exit codes of each.

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('sentinel:checkpoint')->hourly();          // anchor first
Schedule::command('sentinel:prune')->dailyAt('03:20');       // retention's unit is the anchored window
Schedule::command('sentinel:verify --depth=anchors')->dailyAt('04:00');
Schedule::command('sentinel:verify')->weeklyOn(7, '04:30');
```

❌ **Don't** — run `sentinel:prune` on a stream nobody has anchored. It removes nothing and exits `0`, which reads as success; the Note column says `unanchored` and nobody looks at it.

```php
Schedule::command('sentinel:prune')->dailyAt('03:20');       // with no checkpoint schedule above it
```

✅ **Do** — read the Note column of `sentinel:prune` before concluding retention is broken. It names which of the four holds stopped each stream: `undeclared`, `unanchored`, `tail` or `retained`.

```bash
php artisan sentinel:prune --dry-run
```

❌ **Don't** — infer "anchoring is off" from `integrity.checkpoints.enabled => false`. That flag governs only threshold emission on the write path; `sentinel:checkpoint` ignores it entirely.

```php
// config/sentinel.php — this is the recommended production shape, not a disabled feature
'integrity' => ['checkpoints' => ['enabled' => false, 'every' => 1000]],
```

✅ **Do** — put a real ceiling on how long a buffered entry waits, with a scheduled flush. Nothing in PHP watches a clock between requests, so both thresholds are evaluated only when an entry arrives.

```php
Schedule::command('sentinel:flush')->everyMinute()->withoutOverlapping();
```

❌ **Don't** — change `mode` away from `buffered` with entries still waiting. Both shutdown hooks and `sentinel:flush` refuse to touch the buffer under any other mode, and those entries are stranded with no report.

```bash
# wrong order: flush until it prints "Settled 0 entries", then change the mode
SENTINEL_MODE=sync php artisan config:cache
```

✅ **Do** — attach `php artisan about` to any support conversation or bug report. It names version, mode, ledger, payload version, compliance and telemetry, and carries no key material.

```bash
php artisan about --only=sentinel
```

❌ **Don't** — build monitoring on the `@internal` classes. `Console`, `Buffer`, `Dispatch`, `Ledger` and `Integrity/Verifier` are all internal; what is frozen is the command names, their options, their exit codes, the published events and the facade.

```php
app(\ElPandaPe\Sentinel\Integrity\Verifier::class)->verifyEverything();  // use Sentinel::verifyEverything()
```

---

**See also:** [Artisan commands](06-artisan-commands.md) · [Scheduling](07-scheduling.md) · [Failure policy](05-failure-policy.md) · [Events and listeners](04-events-and-listeners.md) · [The buffered mode](02-the-buffered-mode.md) · [Verification](../07-integrity/06-verification.md) · [The verification playbook](../07-integrity/07-the-verification-playbook.md) · [Exit codes](../99-reference/07-exit-codes.md) · [Events reference](../99-reference/05-events.md) · [Production readiness](../13-best-practices/04-production-readiness.md)
