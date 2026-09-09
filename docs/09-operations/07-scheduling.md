# ⚙️ Scheduling

> What Sentinel needs on your scheduler, in which order, with which locks — and what silently does
> not happen if you schedule none of it.

**On this page:** [The package schedules nothing](#the-package-schedules-nothing) ·
[The whole schedule](#the-whole-schedule) · [Cadence and cost](#cadence-and-cost-per-command) ·
[The ordering constraints](#the-ordering-constraints) ·
[Overlap, one server, background](#overlap-one-server-and-background) ·
[The first run on an existing trail](#the-first-run-on-an-existing-trail) ·
[When a scheduled run fails](#when-a-scheduled-run-fails) ·
[⚠️ Pitfalls](#️-pitfalls) · [✅ Best practices](#-best-practices)

---

## The package schedules nothing

`SentinelServiceProvider::boot()` registers eleven commands, and it does so inside a
`runningInConsole()` guard. It never resolves `Illuminate\Console\Scheduling\Schedule`, never calls
`Schedule::command()`, and never adds an entry to `routes/console.php` on your behalf. The only
`Schedule` class the provider binds is `ElPandaPe\Sentinel\Retention\Schedule`, which is the map of
retention policies — same word, unrelated thing.

> ⚠️ **Warning.** Install Sentinel, run the migrations, put the trait on a model, and you have a
> chain that grows forever, never gets anchored, never gets pruned and never gets verified. Every
> one of those is a command *your* application schedules. Nothing warns you: the trail keeps
> recording, correctly, and the maintenance simply does not happen.

Here is what an empty scheduler actually costs you, per command:

| Not scheduled | What silently does not happen |
|---|---|
| `sentinel:checkpoint` | No anchors are ever emitted. `sentinel:prune` releases nothing (`unanchored` hold) and both shallow verification depths fall back to a full entry-by-entry walk. |
| `sentinel:prune` | Retention policies in `config/sentinel.php` are read and never applied. Nothing is archived, nothing is removed, the hot table grows without bound. |
| `sentinel:flush` | **Buffered mode only.** `buffer.size` and `buffer.flush_interval` are evaluated when an entry *arrives*; a buffer nobody is writing to stops being evaluated. The last entries wait indefinitely. |
| `sentinel:partitions` | New months are not created ahead of the writes that need them, and old ones are never dropped. On PostgreSQL the rows land in the `DEFAULT` partition; on MySQL in the `MAXVALUE` one. |
| `sentinel:verify` | Nobody is checking the chain. A tampered row is discovered when somebody happens to run the command, which may be during the incident it was supposed to warn about. |

Two of those degrade quietly rather than failing, which is the dangerous shape: an unanchored trail
prunes nothing at **exit 0**, and an unmaintained partitioned table keeps accepting writes into one
fat catch-all partition. See [Retention and pruning](../08-lifecycle/01-retention-and-pruning.md)
and [Partitioning](../10-database-engines/06-partitioning.md).

---

## The whole schedule

This is the block to copy. It assumes `sync` or `queued` mode; the buffered line is commented and
explained below.

```php
<?php

// routes/console.php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

// 1. Anchor. Retention's unit is the anchored window, so this has to run ahead of the prune, and a
//    short unanchored tail is what keeps the shallow verification depths cheap.
Schedule::command('sentinel:checkpoint')
    ->hourly()
    ->withoutOverlapping(55)
    ->onOneServer();

// 2. Prune. The default action is archive: each window is written out, read back and rehashed
//    before a row is removed.
Schedule::command('sentinel:prune')
    ->dailyAt('03:10')
    ->withoutOverlapping(180)
    ->onOneServer();

// 3. A cheap daily check of the anchors, and a full rehash of every entry once a week.
Schedule::command('sentinel:verify --depth=anchors')
    ->dailyAt('04:00')
    ->withoutOverlapping(60)
    ->onOneServer();

Schedule::command('sentinel:verify')
    ->weeklyOn(7, '04:30')
    ->withoutOverlapping(360)
    ->onOneServer()
    ->onFailure(function (Event $event): void {
        Log::critical('sentinel:verify', ['exit' => $event->exitCode]);
    });

// 4. Partitioned tables only. Keeping the calendar supplied is cheap and idempotent, so run it
//    daily; retiring is a separate, monthly entry that runs after the prune has emptied the months.
Schedule::command('sentinel:partitions --table=audits --ahead=6')
    ->dailyAt('02:00')
    ->onOneServer();

Schedule::command('sentinel:partitions --table=audits --ahead=6 --retire="18 months"')
    ->monthlyOn(2, '05:00')
    ->onOneServer();

// 5. Buffered mode only. Delete this entry if sentinel.mode is not 'buffered' — the command exits 2
//    under every other mode, every minute.
// Schedule::command('sentinel:flush')
//     ->everyMinute()
//     ->withoutOverlapping(5);
```

> 📌 **Note.** The cadences above are recommendations reasoned from what each command reads and
> writes. **The package prescribes none of them** — nothing in `src/` names an interval. Pick yours
> from your retention periods, your write rate and your tolerance for a long walk.

`sentinel:show`, `sentinel:install`, `sentinel:export`, `sentinel:redact`, `sentinel:rekey` and
`sentinel:import` do not belong on a scheduler. Four are one-shot operator actions, one is
destructive, and `sentinel:export` is bounded by `--limit` rather than resumable. See
[Artisan commands](06-artisan-commands.md) for the whole surface.

---

## Cadence and cost per command

| Command | Suggested cadence | What one run reads and writes | Bounded by |
|---|---|---|---|
| `sentinel:checkpoint` | hourly | One index seek per stream to ask whether a window is complete; then, per window it owes, the `hash` column of `integrity.checkpoints.every` rows, folded, plus one anchor row | Nothing — it anchors **everything** owed, there is no `--limit` |
| `sentinel:prune` | daily, off-peak | One query over the anchors per stream; per window: a refold (`hash` only), then under `archive` a full read of every column plus labels, a write to the disk, a read back and a rehash; then `DELETE` statements | `prune.windows` (default 100) anchored windows **per stream** |
| `sentinel:verify --depth=anchors` | daily | The anchor rows, plus an entry-by-entry walk of the unanchored tail | The number of anchors + the tail |
| `sentinel:verify --depth=roots` | weekly, or instead of the deep walk | Every anchor, plus the `hash` column of every entry it covers refolded, plus the tail | The whole trail, two columns wide |
| `sentinel:verify` (`--depth=entries`) | weekly | Every entry, every column, canonicalised and rehashed | The whole trail, full width |
| `sentinel:partitions` | daily to create, monthly to retire | The catalogue, plus one `COUNT` per partition behind the `--retire` cutoff, plus DDL for the difference | One table per run |
| `sentinel:flush` | every minute (buffered mode only) | Takes batches of `buffer.size` from the buffer and settles them until it is empty | Nothing — it drains the whole buffer |

### `sentinel:checkpoint`

`Integrity\Checkpoints::issue()` loops until no complete window is left, one transaction per anchor.
In steady state that is one fold of `every` hashes for every `every` writes, which is why hourly is
comfortable: the work per run is proportional to what was written in the last hour, not to the trail.

Frequent anchoring is also what keeps verification cheap. Both shallow depths finish by walking the
**unanchored tail** entry by entry (`Verifier::overAnchors()` ends with `verify($name, $expected)`),
so the tail is the part nobody can take on an anchor's word. Anchor hourly and that tail is at most
one hour of writes plus the incomplete window.

> 💡 **Tip.** `integrity.checkpoints.enabled` does **not** govern this command. That flag controls
> anchoring on the write path and the compliance-mode boot check; `Checkpoints::pending()` reads only
> `checkpointsEvery()`. Leaving it `false` and scheduling the command is the arrangement that keeps
> the fold off the request that happened to cross the threshold.

### `sentinel:prune`

The frontier query is capped at `prune.windows` anchored windows **per stream, per run** (default
100). A backlog therefore needs repeated runs, and a window a long policy holds is re-examined on
every one of them. Daily is a sensible starting point; if the report never catches up, raise
`prune.windows` for a one-off run and put it back.

Inside a window, `Retention\Cascade::purge()` walks the sequence range in steps of `prune.batch`
(default 1000) and sleeps `prune.pause` **microseconds** after every batch — including the last one.
Both are the knobs for "do not compete with the writes this is making room for".

### `sentinel:verify`

Three depths, three different claims about what was proved:

| Depth | What it proves | What it does not |
|---|---|---|
| `entries` (default) | Every entry reproduces its own hash and links to the one before it | Nothing — this is the deep walk |
| `roots` | Every anchored range still folds to the root its anchor recorded | An entry whose canonical columns changed while its `hash` column did not folds back exactly as before |
| `anchors` | The anchors are contiguous from sequence 1 and their signatures verify | It reads no entry inside an anchored range at all |

The summary line says how many entries were taken on an anchor's word, which is not the same as
having read them. See [Verification](../07-integrity/06-verification.md) and
[the verification playbook](../07-integrity/07-the-verification-playbook.md).

### `sentinel:flush`

Only ever needed in `buffered` mode. `Buffer\Flusher::due()` is evaluated when an entry arrives, not
on a clock, so a buffer that stops receiving entries stops being evaluated. The provider also flushes
on `terminating` and on `WorkerStopping`, which covers a request or a worker that ends — the command
covers the gap those two leave. See [The buffered mode](02-the-buffered-mode.md).

### `sentinel:partitions`

`Partitions\Calendar::ahead()` returns the current month plus `--ahead` more, so `--ahead=3` keeps
four months on the catalogue. `Partitions\Maintainer` reads what exists from the catalogue and issues
only the difference, so a second run in the same minute changes nothing.

Retirement is separate and timid: `Maintainer::refusal()` drops a partition behind the cutoff only
when it holds **zero** entries — unless `--force`, which is ignored outright under compliance mode.

---

## The ordering constraints

Three of the four have a real order between them. Getting it wrong does not corrupt anything; it
makes the later command do nothing, or fail, while looking like it ran.

```
sentinel:checkpoint  ──►  sentinel:prune  ──►  sentinel:partitions --retire

sentinel:flush       ──►  (independent)
```

**Anchor before you prune.** `Retention\Frontiers::of()` only ever offers anchored windows. A stream
with no anchors reports the `unanchored` hold — *"Stream :stream has no anchors. A range is only
retired while an anchor still answers for it, so anchor the history before pruning it."* — and exits
**0**, having removed nothing. A schedule that runs the prune and not the anchoring is green forever
and does nothing forever.

**Archive before you retire a partition.** `sentinel:partitions --retire` keeps any partition behind
the cutoff that still holds rows, and `Maintenance::refused()` is true whenever *any* partition was
kept, which makes the run exit **1**. So a monthly `--retire` that runs before `sentinel:prune` has
archived those months wakes a watchdog every month over a perfectly correct state. Give the prune
enough runs to empty the range first — that is why the schedule above retires on the 2nd of the
month at 05:00, after a daily prune has had a full month to work.

**Flush is independent.** An entry that has not settled has no sequence and is not in any chain, so
it is simply not there for the other three; the next `sentinel:checkpoint` picks it up. Ordering
`sentinel:flush` ahead of the anchoring buys nothing except a marginally longer stream.

**Keep the deep verify away from the prune window.** `sentinel:verify` with the default depth is the
heaviest read the package does, and `sentinel:prune --action=archive` is the heaviest write. There is
no correctness problem in running them together — verification crosses an absence only when an anchor
and a manifest row both account for it — but they are the two runs most worth not putting on the same
minute.

---

## Overlap, one server, and background

| Command | `withoutOverlapping()` | `onOneServer()` | `runInBackground()` |
|---|---|---|---|
| `sentinel:checkpoint` | Recommended — a long first run must not be re-entered | Recommended | No — it must finish before the prune |
| `sentinel:prune` | **Yes** — nothing in the prune coordinates two runs | Recommended | No |
| `sentinel:verify` | **Yes** — a slow deep walk piling up is how a box falls over | Recommended | Only if nothing after it depends on the result |
| `sentinel:partitions` | Optional — it is idempotent, but DDL twice over is noise | Recommended | No |
| `sentinel:flush` | Optional — safe either way, see below | **No** — every server's buffer entries are in the same shared store, and more drainers is better | No |

### `withoutOverlapping()`

`ManagesAttributes::withoutOverlapping($expiresAt = 1440, $releaseOnTerminationSignals = true)`. The
default expiry is **1440 minutes — twenty-four hours**. That matters: a run killed with `SIGKILL`, or
a box that dies, leaves the lock held for a day and the task silently skipped for a day. Pass an
expiry a little longer than the run's worst case instead of taking the default.

The mutex name is `'framework/schedule-'.sha1($expression . $command)`. Two schedule entries for the same command with
different options or a different cron expression get **different** mutexes and do not exclude each
other — which is exactly why the partition example above is split into a create-only daily entry and
a retire monthly entry with no shared lock between them.

`sentinel:prune` is the one where this is not optional. Nothing in `Retention\Pruner` or
`Retention\Cascade` takes a lock or a leader election; two concurrent runs plan the same windows and
issue the same deletes.

`sentinel:checkpoint` is safe under concurrency by construction — `Integrity\CheckpointGate` locks the
tail of the anchors before deriving the next range (on PostgreSQL it also takes an advisory lock by
name, because there is no row to lock on a stream nobody has anchored), and the unique index is the
final arbiter, with the loser re-reading and taking the window after the one it lost. But
`Checkpoints` gives up after **three** collisions and rethrows, and a rethrow is exit **2**. Lock it
anyway.

### `onOneServer()`

Laravel's scheduling mutex is a cache entry named `mutexName() . $time->format('Hi')`, so
`onOneServer()` stops two machines starting the *same* event in the *same minute*. It does not stop a
long run from overlapping the next tick — that is `withoutOverlapping()`, and you generally want both.

It needs a cache store the servers actually share. `file` and `array` are per-machine and cannot
coordinate two of them.

`sentinel:flush` is the exception: two flushes running at once are safe by design. Taking from the
buffer is atomic, and every entry carries a `capture_id` the database will not accept twice, so each
run gets entries and neither gets the other's.

### `runInBackground()`

Laravel wraps a background event as `(command > output 2>&1 ; artisan schedule:finish "id" "$?") &`,
so the exit code still reaches `onFailure()`. What you lose is ordering: the scheduler does not wait,
so two background entries in the same minute run concurrently. Never background a command another
entry in that same minute depends on — backgrounding `sentinel:checkpoint` beside `sentinel:prune` is
how a prune ends up planning against anchors that have not been written yet.

---

## The first run on an existing trail

Two of these commands have an unbounded first pass. Stage them by hand, off the schedule, before you
add the block above.

**1. Confirm what is actually configured.**

```bash
php artisan about
```

The Sentinel section names version, mode, ledger driver, `payload_version`, compliance mode and
telemetry. It deliberately carries no key, no key identifier and no signer configuration, so it is
safe to paste into a ticket.

**2. Check the ledger can name its streams.**

`sentinel:checkpoint`, `sentinel:prune` and `sentinel:verify` all ask the ledger for the list of
streams when given no `--stream`, and a ledger that is not `Contracts\EnumeratesStreams` is refused
with exit **2** rather than reported as empty. `NullLedger` is the one shipped driver that does not
implement it, so under `ledger.default` of `null` all three scheduled entries go red on the first
tick. `memory` implements it and answers with the streams *this* process wrote — none, in a fresh
scheduled command — so those entries exit 0 having examined nothing. See
[the shipped drivers](../11-extending/02-shipped-drivers.md).

**3. Anchor the history by hand. This is the unbounded one.**

```bash
php artisan sentinel:checkpoint
```

`sentinel:checkpoint` has no `--limit` in its signature and anchors every complete window each stream
still owes. On a trail that predates anchoring, that is all of them: at the default
`integrity.checkpoints.every` of 1000, a ten-million-entry stream emits ten thousand anchors on the
first run, each one a fold over its window — so the first pass reads the whole trail. Run it in a
window you chose. If you need to stage it, run it per stream with `--stream=` and stop between
streams; anchors are contiguous as far as they go, and the next run carries on from there.

**4. Verify once, deeply, before you trust anything else.**

```bash
php artisan sentinel:verify --depth=entries
```

This is the reading you compare later runs against. Do it before the first prune, not after — once a
range has been archived and removed, the deep walk steps over it on the word of its anchor and its
manifest row.

**5. Rehearse the prune, and read the Note column.**

```bash
php artisan sentinel:prune --dry-run
```

The Note column names which of four holds stopped each stream — `undeclared`, `unanchored`, `tail`,
`retained` — and for `retained` it names the exact sequence and the policy holding it. A ninety-day
policy that frees nothing is usually correct, not broken.

> ⚠️ **Warning.** A dry run with `--action=delete` does **not** surface the compliance-mode refusal.
> `Retention\Pruner::retire()` returns the counted range before `refuseUnarchivedDelete()` is
> reached, so the rehearsal reports what it would remove and exits 0 while the real run throws
> `ComplianceException::unarchived` and exits 2. See [Compliance mode](../08-lifecycle/05-compliance-mode.md).

**6. Run the first real prune by hand, with a smaller slice.**

```bash
php artisan sentinel:prune --stream=tenant:acme --batch=200
```

`--batch` overrides `prune.batch` for one run only. Use it while somebody is watching the load;
editing the config would leave the smaller slice behind for every scheduled run afterwards.

**7. Then, and only then, add the schedule block.**

Add it one entry at a time and watch a full cycle of each before adding the next. If you are on a
partitioned table, run `sentinel:partitions --dry-run` first: unlike `redact` and `rekey`, its dry
run computes the refusal before the action, so it *can* exit 1 and tell you a partition would be kept.

---

## When a scheduled run fails

All eleven commands share one exit vocabulary, and a cron may branch on it:

| Code | Meaning | What to do |
|---|---|---|
| `0` | The ordinary outcome, **including having found nothing to do** | Nothing |
| `1` | A bad finding from a run that happened | A human looks at it. Do not retry blindly |
| `2` | A run that could not happen | Fix the cause, then retry |

Of the schedulable commands, `sentinel:checkpoint` has no exit 1 at all; `sentinel:verify`,
`sentinel:prune`, `sentinel:partitions` and `sentinel:flush` can all return one. Full table in
[Exit codes](../99-reference/07-exit-codes.md).

Branch on the code rather than treating every non-zero the same. A closure type-hinted with
`Illuminate\Console\Scheduling\Event` receives the event, and `$event->exitCode` carries the code —
for background events too, because `schedule:finish` is handed `$?`.

```php
<?php

// routes/console.php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Schedule::command('sentinel:prune')
    ->dailyAt('03:10')
    ->withoutOverlapping(180)
    ->onOneServer()
    ->onFailure(function (Event $event): void {
        // 1 = a window stopped folding to the root its anchor recorded. Every row is still there.
        // 2 = the run could not happen: an unknown --action, a ledger that cannot name its
        //     streams, or the compliance refusal of --action=delete over an unarchived range.
        $event->exitCode === 1
            ? Log::critical('sentinel:prune found a range that no longer folds to its anchor')
            : Log::error('sentinel:prune could not run', ['exit' => $event->exitCode]);
    });
```

Triage, per command:

| Command | Exit 1 means | Exit 2 means |
|---|---|---|
| `sentinel:verify` | A hash that does not reproduce, a broken link, an unaccounted sequence gap, a signature its own key does not verify, or a divergent relation index under `--projections`. **Stop and investigate; do not prune.** | `--from`/`--to` without `--stream`, an unknown `--depth`, a range on a depth that takes none, or a ledger that cannot enumerate streams |
| `sentinel:prune` | A window no longer folds to the root its anchor recorded. The run stopped on that stream and **left every row in place** | An unknown `--action`, a ledger that cannot enumerate streams, or any thrown exception — including the compliance refusal of `--action=delete` |
| `sentinel:partitions` | It kept a partition it was asked to retire: still holds entries (no `--force`), or compliance mode forbids the drop | A `--table` it does not maintain, a `--retire` period it cannot read, or a thrown exception |
| `sentinel:flush` | The flush did not settle. The batch was taken, refused and **put back at the head, in order** — run again | The mode is not `buffered`, so there is no buffer under it |
| `sentinel:checkpoint` | *(never returned)* | A ledger that cannot name its chains, a repeated unique-constraint collision, or any thrown exception |

`sentinel:flush` is the one command where a caught throwable is `FAILURE` and not `INVALID`, and it
is deliberate: the run happened, nothing was lost, and the right response is to run again — which is
what failure tells a cron.

> 🧪 **Verify it.** Reproduce the whole chain of scheduled work against a copy of production, in
> order, and read the exit codes: `php artisan sentinel:checkpoint; echo $?` then
> `php artisan sentinel:prune --dry-run; echo $?` then `php artisan sentinel:verify --depth=anchors; echo $?`.

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `sentinel:prune` runs green every night and removes nothing | Nothing is anchored, so the frontier offers no window. The Note column says `unanchored` and the command exits 0 | Schedule `sentinel:checkpoint` ahead of it, and run the first one by hand |
| The first scheduled `sentinel:checkpoint` runs for hours and blocks the tick | It anchors every complete window the stream owes, and there is no `--limit` | Run it by hand once, off the schedule, before adding the entry |
| `sentinel:partitions` exits 1 every month over a correct state | Any partition behind the `--retire` cutoff that still holds rows is kept, and any kept partition makes the run exit 1 | Let `sentinel:prune --action=archive` empty those months first; schedule the retiring run after it |
| `--force` on `sentinel:partitions` does nothing | Under compliance mode the `complianceEnabled()` arm of `Maintainer::refusal()` precedes the `$force` arm | Archive the range with `sentinel:prune`. `--force` is inert under compliance by design |
| The scheduled task silently stops running for a day | `withoutOverlapping()` defaults to a 1440-minute lock, and a `SIGKILL`ed run never releases it | Pass an explicit expiry: `withoutOverlapping(180)` |
| Two schedule entries for the same command run concurrently despite `withoutOverlapping()` | The mutex is `sha1($expression . $command)`; different options or a different expression is a different mutex. `->name()` does not change it — it is an alias of `->description()` | Give both entries the same mutex with `->createMutexNameUsing('sentinel-prune')`, or do not schedule the same work twice |
| Every scheduled Sentinel command exits 2 right after switching the ledger off | `ledger.default` is `null`, and `NullLedger` does not implement `EnumeratesStreams`; `checkpoint`, `prune` and `verify` refuse it (`memory` implements it and instead reports nothing at exit 0) | Turn capture off with the switches in [Turning auditing off](../02-getting-started/04-turning-auditing-off.md), not by swapping the ledger on a maintenance host |
| `sentinel:flush` exits 2 every minute | `sentinel.mode` is not `buffered`. The command short-circuits before touching anything | Remove the entry when you leave buffered mode |
| `sentinel:flush` reports `Settled 0 entries` while entries are clearly waiting | `buffer.store` is `memory`, which keeps everything on the instance — the scheduler process has its own empty array | Use the `redis` store, which is the default |
| `sentinel:verify --depth=anchors` costs as much as the deep walk | A stream with no anchors falls back to the full entry walk in `Verifier::overAnchors()`, and so does the unanchored tail of every stream | Anchor more often; the tail is what the shallow depths cannot skip |
| `--batch=abc` turns a prune into one statement per row | `sentinel:prune` reads `--batch` with a raw `(int)` cast, unlike every other numeric option; `0` is then clamped to `1` by `Cascade::purge()` | Pass a number, and check the run's rate column |
| `--ahead=abc` silently becomes 3 | Numeric options are read with `ReadsOptions::number()`, which treats a non-numeric value as absent and falls back to the documented default | Read the printed table, not just the exit code |
| The prune ran and the datafile did not shrink | Nothing in `src/` issues `OPTIMIZE TABLE` or `VACUUM`, on purpose — both take locks | Reclaim space in a DBA maintenance window, or retire whole partitions |

---

## ✅ Best practices

✅ **Do** — put `sentinel:checkpoint` on the scheduler before `sentinel:prune`, and give the anchor
run a shorter interval than the prune. Retention's unit is the anchored window, so an unanchored
stream releases nothing and reports it at exit 0.

```php
Schedule::command('sentinel:checkpoint')->hourly()->withoutOverlapping(55)->onOneServer();
Schedule::command('sentinel:prune')->dailyAt('03:10')->withoutOverlapping(180)->onOneServer();
```

❌ **Don't** — schedule the prune on its own and assume the retention config is doing something. It
is green, it is fast, and it removes nothing at all.

```php
Schedule::command('sentinel:prune')->daily();   // exit 0, every night, forever, zero rows
```

---

✅ **Do** — run the first `sentinel:checkpoint` and the first deep `sentinel:verify` by hand, in a
window you chose, before either goes on the scheduler. The first anchor pass over an existing trail
reads all of it and has no `--limit`.

```bash
php artisan sentinel:checkpoint
php artisan sentinel:verify --depth=entries
```

❌ **Don't** — add the hourly entry to `routes/console.php` on a trail that predates anchoring and
find out inside a cron window.

```php
Schedule::command('sentinel:checkpoint')->hourly();   // first tick: reads ten million rows
```

---

✅ **Do** — give `withoutOverlapping()` an explicit expiry sized to the run, and add `onOneServer()`
on every host in a multi-server deployment. The default lock lives for 1440 minutes, and a run killed
without a termination signal never releases it.

```php
Schedule::command('sentinel:verify')->weeklyOn(7, '04:30')->withoutOverlapping(360)->onOneServer();
```

❌ **Don't** — leave `sentinel:prune` unlocked because "it is idempotent". Nothing in
`Retention\Pruner` or `Retention\Cascade` coordinates two runs: they plan the same windows and issue
the same deletes.

```php
Schedule::command('sentinel:prune')->everyThirtyMinutes();   // two runs, same windows, no lock
```

---

✅ **Do** — split partition maintenance into a frequent create-only entry and an infrequent retiring
one, and put the retiring one after the prune has had time to empty those months. Keeping the
calendar supplied is cheap; retiring an occupied partition is an exit 1 every month.

```php
Schedule::command('sentinel:partitions --table=audits --ahead=6')->dailyAt('02:00')->onOneServer();
Schedule::command('sentinel:partitions --table=audits --ahead=6 --retire="18 months"')
    ->monthlyOn(2, '05:00')->onOneServer();
```

❌ **Don't** — reach for `--force` to get past that exit 1. It drops a range of the trail as a
catalogue operation, with nothing archived and nothing recorded that it went — and under compliance
mode it is silently inert anyway.

```bash
php artisan sentinel:partitions --retire="18 months" --force   # a range leaves with nothing behind it
```

---

✅ **Do** — branch your alerting on the three exit codes rather than on "non-zero". Exit 1 from
`sentinel:verify` or `sentinel:prune` is a finding a person must read; exit 2 is a run that could not
happen and is usually retryable.

```php
Schedule::command('sentinel:verify --depth=anchors')
    ->dailyAt('04:00')
    ->onFailure(fn (Event $event) => $event->exitCode === 1
        ? Log::critical('sentinel: the chain reported a break')
        : Log::error('sentinel: verification could not run', ['exit' => $event->exitCode]));
```

❌ **Don't** — retry an exit 1 from `sentinel:prune` on a timer. The run stopped because a window no
longer folds to the root its anchor recorded, it left every row in place on purpose, and running it
again will stop in the same place.

```bash
until php artisan sentinel:prune; do sleep 60; done   # loops forever over the same finding
```

---

✅ **Do** — schedule `sentinel:flush` when, and only when, `sentinel.mode` is `buffered`, and let it
run on every server. Two concurrent flushes are safe: taking from the buffer is atomic and every
entry carries a `capture_id` the database will not accept twice.

```php
Schedule::command('sentinel:flush')->everyMinute()->withoutOverlapping(5);
```

❌ **Don't** — lower `buffer.flush_interval` and expect it to bound how long an entry waits. It is
evaluated when an entry *arrives*; a buffer nobody is writing to is never evaluated at all.

```php
'buffer' => ['flush_interval' => 5],   // not a timer, and not a ceiling on waiting time
```

---

**See also:** [Artisan commands](06-artisan-commands.md) · [The buffered mode](02-the-buffered-mode.md) ·
[Failure policy](05-failure-policy.md) · [Monitoring and troubleshooting](08-monitoring-and-troubleshooting.md) ·
[Checkpoints and anchors](../07-integrity/05-checkpoints-and-anchors.md) ·
[Verification](../07-integrity/06-verification.md) ·
[The verification playbook](../07-integrity/07-the-verification-playbook.md) ·
[Retention and pruning](../08-lifecycle/01-retention-and-pruning.md) ·
[Cold archiving](../08-lifecycle/02-cold-archiving.md) ·
[Compliance mode](../08-lifecycle/05-compliance-mode.md) ·
[Partitioning](../10-database-engines/06-partitioning.md) ·
[Queues, commands and schedulers](../04-context/05-queues-commands-and-schedulers.md) ·
[Exit codes](../99-reference/07-exit-codes.md) · [Configuration](../99-reference/02-configuration.md) ·
[Production readiness](../13-best-practices/04-production-readiness.md)
