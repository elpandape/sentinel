# 🐘 Scaling playbook

> What grows in an audit trail, in what proportion, and the order in which to reach for each lever
> as the table gets bigger.

**On this page:** [What actually grows](#what-actually-grows) · [The levers, ranked](#the-levers-ranked-by-effect) · [Stage 1 — the first million](#stage-1--up-to-the-first-million) · [Stage 2 — one to ten million](#stage-2--one-to-ten-million) · [Stage 3 — beyond ten million](#stage-3--beyond-ten-million) · [Measuring on your own data](#measuring-on-your-own-data) · [Pitfalls](#-pitfalls) · [Best practices](#-best-practices)

---

Sentinel never deletes an entry to make room. There is no ring buffer, no automatic expiry and no
size cap anywhere in the package: what is written stays until a retention policy you declared
releases it and a prune you scheduled removes it. So the question at scale is not "how fast is a
write" — it is "what does this table cost to keep, and what do I turn on before it costs too much".

Two of the answers on this page **cannot be applied late**: the stream scope
(`integrity.stream`) and partitioning. Both are decisions taken before the first entry exists. Read
[Stage 1](#stage-1--up-to-the-first-million) before you deploy, not when the table hurts.

---

## What actually grows

### Rows

`sentinel_audits` grows with **captures**, not with requests. Six smaller tables grow beside it, each
in its own proportion:

| Table | One row per | Grows with |
|---|---|---|
| `sentinel_audits` | Entry | Every audited create, update, delete, restore, relation change, transition, custom event, mass operation and redaction |
| `sentinel_audit_tags` | Label on an entry | Labels declared per entry — zero rows if you use none |
| `sentinel_audit_relations` | Relation line inside an entry | Pivot lines: one `sync()` of fifty records is one entry and fifty rows here |
| `sentinel_transactions` | Business operation | `Sentinel::transaction()` scopes, not entries |
| `sentinel_checkpoints` | Anchored window | `integrity.checkpoints.every` entries per stream (default 1000), and only while anchoring is on |
| `sentinel_archives` | Retired range | Prune runs with `--action=archive` |
| `sentinel_access_log` | Read of the trail | Query API reads — **only** under [compliance mode](../08-lifecycle/05-compliance-mode.md), where it grows with reads rather than with writes |

None of the six carries a foreign key to `sentinel_audits`, deliberately: a cascade lives badly with
date partitioning and with batched deletes. The prune removes labels and relation lines by range
itself.

### Payload width

The row is fixed at forty columns and thirteen non-primary indexes, created whole by the first
migration and never `ALTER`ed by a later version (see [Schema](../99-reference/03-schema.md)). What
varies from installation to installation is the **width of seven JSON columns**: `context`, `before`,
`after`, `changes`, `metadata`, `encryption` and `criteria`.

The one that multiplies is the snapshot pair. An `updated` entry stores the **complete** state of the
model before and after — not the dirty attributes — with the model's own casts applied, and the diff
on top of that. A wide model therefore writes roughly two copies of itself per update, plus the
changed subset:

```php
namespace App\Models;

use ElPandaPe\Sentinel\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

final class Invoice extends Model
{
    use Auditable;

    // Drops `before` and `after`. The entry, its place in the chain and its diff all survive.
    protected bool $auditSnapshots = false;
}
```

`SnapshotBuilder::retains()` asks two questions — the global `snapshots.enabled` and the model's own
`$auditSnapshots` — and the pair is **built either way**, because without it there is nothing to diff.
Turning it off changes what is stored, not what is computed. See
[Snapshots](../03-capture/02-snapshots.md) for `$auditInclude` / `$auditExclude`, which narrow the
pair instead of removing it.

> 📌 **Note.** Sentinel publishes no bytes-per-row figure, and neither should you infer one: the row's
> width is your models' width. `make bench-volume` prints the weight of every index in MB against
> your own schema — that is the number to reason from.

### What a write costs as the table grows

Two reads happen inside every write, and only one of them grows with anything:

| Read | Where | Grows with |
|---|---|---|
| The tail of the stream (`sequence`, `hash`) | `Ledger\StreamGate::tail()` | Nothing on a flat table — one index seek. On a **partitioned** table, the number of partitions |
| `max(version)` for the subject | `Ledger\DatabaseLedger::version()` | That subject's own history |

Neither can be skipped: the hash covers the sequence and the link, so no `INSERT` can compute its
own place. Both are amortised by batching — one tail read and one version read per subject for a
whole batch — which is what makes the [buffered mode](../09-operations/02-the-buffered-mode.md)
cheaper end to end than `sync`.

> 🧪 **Verify it.** The subject-history effect is a row of `make bench-volume`: writes onto a subject
> that already carries 1, 10, 100 and 1000 entries, five passes each, printed with its spread. On the
> package's reference machine the move from 1 to 1000 prior entries was about +20 % — the same order
> as the run-to-run dispersion, so read the spread beside it before calling it a cost.

---

## The levers, ranked by effect

Ranked by how much they change the cost of keeping the table, biggest first. Nothing here is on by
default.

| # | Lever | What it fixes | What it costs | Can it be applied late? |
|---|---|---|---|---|
| 1 | [Retention](../08-lifecycle/01-retention-and-pruning.md) | Rows. It is the only thing that makes the table stop growing | Nothing until you declare a policy; requires anchors | Yes |
| 2 | [Cold archiving](../08-lifecycle/02-cold-archiving.md) | Keeps the evidence while the rows leave | One NDJSON file per window on a `Storage` disk | Yes |
| 3 | [Partitioning](06-partitioning.md) | Makes retiring a range a catalogue operation instead of a `DELETE` | Planning cost on **every** write; weaker unique keys under a date division | **No** — new installation only |
| 4 | Stream scope (`integrity.stream`) | Divides the chain, the anchoring unit and the retention unit | Changing it later forks history into two chains | **No** — before the first entry |
| 5 | [The buffered mode](../09-operations/02-the-buffered-mode.md) | Write throughput: one tail read, one transaction and one sequence assignment per batch | It is the only mode that can lose an entry | Yes |
| 6 | [A connection of its own](07-a-database-of-its-own.md) | Takes the trail's I/O off the application's database | Cross-connection joins stop working; after-commit semantics change | Yes, with care |
| 7 | [The JSON index](05-indexes-and-json.md) | `whereIp()` and `whereRoute()` seek instead of scan | Measured at +15 % per `INSERT` on PostgreSQL 16 and +21 % on MySQL 9, at the engine | Yes — it is additive and reversible |

**Query shape is not on that list because it is not a lever, it is a prerequisite.** A refiner —
`whereSource()`, `between()`, `whereFieldChanged()` or `whereVersion()` — reaches no index of its own
and walks the table when it is the only criterion, and `whereOperation()` alone forces a full pass
over the relation projection. No amount of hardware fixes that. See
[Filters reference](../06-reading/02-filters-reference.md).

---

## Stage 1 — up to the first million

**Volume alone is not your problem yet, and will not be for a while.** On the package's reference
machine (i7-12700KF, 20 threads, 15 GB, PostgreSQL 16.15 and MySQL 9.7.2 on disk with `fsync` off),
2000 writes straight through the ledger onto a table already holding the stated volume:

| Per entry | PostgreSQL 1M | PostgreSQL 10M | MySQL 1M | MySQL 10M |
|---|---|---|---|---|
| Flat table | 2.47 ms | 2.20 ms | 1.93 ms | 1.97 ms |

Ten times the rows for no measurable change: a B-tree gains a level and little else. Spend this stage
on the two decisions you cannot revisit, and on the switches a later stage needs to already be on.

### 1. Fix the stream scope before the first entry

`integrity.stream` ships as `tenant`, which behaves like `global` until a tenant actually resolves —
and the moment one does, entries move to a `tenant:<id>` chain whose `sequence` restarts at 1.

```php
// config/sentinel.php
'integrity' => [
    'stream' => 'global',   // 'global' | 'tenant' | 'subject_type' | a Contracts\StreamResolver class-string
],
```

The stream name is inside the hash prefix, so changing it later does not rewrite anything: old
entries keep verifying under their old genealogy and simply stop growing, and you are left with two
independent chains. See [Streams](../07-integrity/02-streams.md).

### 2. Decide about partitioning before the first entry

All three published stubs replace the base migration and are for a **new** installation. Converting a
table that already holds entries is a maintenance window, described in the repository's `UPGRADE.md`,
not something the package does for you. If you are multi-tenant and want partitioning without giving
anything up, the tenant division plus `stream = tenant` is the combination that keeps the chain's
unique keys — see [Partitioning](06-partitioning.md).

### 3. Turn anchoring on now, even if you will not prune for a year

Retention's unit is the **anchored window**, not the entry. A stream with no anchors reports
`unanchored` and releases nothing, however old its entries are.

```php
// config/sentinel.php
'integrity' => [
    'checkpoints' => ['enabled' => true, 'every' => 1000],
],
```

### 4. Declare retention policies while the list is short

What no policy names is kept forever. Retention is opt-in, one logical type at a time, and switching
it on does not start deleting.

```php
// config/sentinel.php
'retention' => [
    'model:App\Models\Session' => '90 days',
    'auth' => '1 year',
    // App\Models\Invoice is not named here, so its entries are kept forever.
],
```

**The warning sign you were late:** you have data and you still have not chosen a stream scope or a
table shape. Both doors are shut behind the first entry.

---

## Stage 2 — one to ten million

The table is now big enough that a bad read is visible and a prune is a real operation. Nothing here
requires a migration except the optional JSON index.

### Schedule the prune, and archive rather than delete

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('sentinel:checkpoint')->hourly();
Schedule::command('sentinel:prune --action=archive')->dailyAt('03:10');
```

`--action=archive` is the default: it writes the window out as NDJSON, reads it back, re-digests it
and rehashes every entry against the hash it is entitled to reproduce — and only then removes a row.
`--action=delete` skips all of that, and under compliance mode it is refused for a range with no
archive batch.

One run looks at at most `prune.windows` anchored windows **per stream** (default 100), so a large
backlog needs repeated runs rather than one heroic one:

```php
// config/sentinel.php
'prune' => [
    'windows' => 100,   // anchored windows examined per stream, per run
    'batch' => 1000,    // sequence span one DELETE statement covers
    'pause' => 0,       // MICROSECONDS between two statements — not milliseconds
],
```

> ⚠️ **Warning.** Pruning does not reclaim disk, on any engine, and the package runs no reclaim
> command — see
> [Retention and pruning](../08-lifecycle/01-retention-and-pruning.md#it-does-not-reclaim-disk-space).
> If space coming back matters, that is an argument for [partitioning](06-partitioning.md), not for
> pruning harder.

### Fix the reads before you blame the table

`get()` refuses rather than truncates once an uncapped read matches more than 500 entries — exactly
500 is still answered whole — and that refusal is usually the first thing an installation notices at
this stage. It is telling you the truth; the probe behind it is in
[Order, paging and walking the trail](../06-reading/03-order-paging-and-walking.md#how-much-comes-back).

```php
use ElPandaPe\Sentinel\Enums\Source;
use ElPandaPe\Sentinel\Facades\Sentinel;

// ❌ QueryException::unbounded the moment this matches more than 500 entries.
Sentinel::audits()->whereSource(Source::Cli)->get();

// ✅ An indexed filter narrows the set; the refiner refines what is left.
Sentinel::audits()
    ->forTenant('acme')
    ->whereSource(Source::Cli)
    ->take(100)
    ->get();
```

For a whole-trail walk, `after()` costs the same at any depth where `paginate()` makes the engine
count past an offset:

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

$cursor = null;

do {
    $query = Sentinel::audits()->whereType('model')->take(1000);
    $batch = ($cursor === null ? $query : $query->after($cursor))->get();

    // ... ship it somewhere

    $cursor = $batch->last()?->id;
} while ($batch->count() === 1000);
```

See [Order, paging and walking](../06-reading/03-order-paging-and-walking.md). `after()` refuses
`latest()` and `byOccurrence()`: a cursor is cut from the identifier and walks along it, in that
order alone.

### Publish the JSON index only if you actually use it

`whereIp()` and `whereRoute()` read inside the `context` JSON and work either way — without the index
they refine by scanning. Publishing it costs every write, forever, and an installation that never
filters by address pays that for nothing.

```bash
php artisan vendor:publish --tag=sentinel-json-indexes
php artisan migrate
```

Measured at the engine over 200 000 writes on this schema: **+15 % on PostgreSQL 16, +21 % on
MySQL 9**. Measured end to end through the package across eight volume runs, the same change lands
between −5.4 % and +7.2 %, which is noise — the pipeline, the canonicalisation and the hash dominate
a Sentinel write and the `INSERT` is a fraction of it. Both figures are true; use the first to reason
about a bulk load or a flush, the second to decide whether to publish at all.

### Move settlement off the request, if the request is what hurts

Measured on the write-path baseline (SQLite, 1000 iterations after 200 warm-ups, median of three
passes on one machine):

| | Per write | vs `sync` |
|---|---|---|
| Not audited | 179 µs | — |
| `sync` | 2068 µs | — |
| `queue`, what the request pays | 1077 µs | −48 % |
| `buffered`, what the request pays | 1194 µs | −42 % |
| `buffered`, what a flush pays per entry | 655 µs | — |

`queue` moves work rather than removing it — about 8 % more in total. `buffered` is the only mode
that is cheaper end to end, and the only one that can lose an entry. See
[Performance modes](../09-operations/01-performance-modes.md).

**The warning signs you were late:** the first `unbounded` refusal in production; a nightly prune that
hits its `prune.windows` ceiling on every run and never catches up; a `whereEvent()` or `whereRoute()`
page that takes seconds; a `sentinel:verify` walk that no longer finishes in its window.

---

## Stage 3 — beyond ten million

At this size the operation that hurts is not the write — it is retiring a range and walking the
chain.

### Retiring: the argument for partitioning, in one table

The same ~260 000 entries removed, as a `DELETE` on a flat table and as a `DROP PARTITION` on a
divided one, on the reference machine:

| ~260 000 entries | PostgreSQL 1M | PostgreSQL 10M | MySQL 1M | MySQL 10M |
|---|---|---|---|---|
| `DELETE` | 424 ms | 1 997 ms | 16 698 ms | **59 029 ms** |
| `DROP PARTITION` | 1 031 ms | **24 ms** | **22 ms** | 71 ms |

At a million rows on PostgreSQL the `DELETE` wins on the clock — and leaves 260 000 dead tuples for
`VACUUM`, which that number does not include and the drop does not create. Everywhere else the
catalogue operation wins by two or three orders of magnitude.

`sentinel:prune` never drops a partition, whatever the table looks like: `Retention\Cascade` deletes
by sequence range either way. The working order is two commands, and it does not commute:

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('sentinel:prune --action=archive')->dailyAt('03:10');
Schedule::command('sentinel:partitions --ahead=6 --retire="18 months"')->monthly();
Schedule::command('sentinel:partitions --table=access_log --ahead=6')->monthly();
```

The prune makes the range provable and empties it; `--retire` reclaims the empty shell. `--retire`
drops a partition only when its month is behind the cutoff **and it holds no entries** — `--force`
lifts that, except under compliance mode, where the refusal is unconditional.

### The cost partitioning adds, on every single write

| Per entry | PostgreSQL 1M | PostgreSQL 10M | MySQL 1M | MySQL 10M |
|---|---|---|---|---|
| Flat | 2.47 ms | 2.20 ms | 1.93 ms | 1.97 ms |
| Partitioned | 3.13 ms | **8.34 ms** | 2.08 ms | 3.06 ms |

> 🐘 **Engine.** On PostgreSQL the cost lands on **planning**, not on execution, which is why it is
> invisible to anything that times a statement. Every write reads the tail of its stream, and
> `where stream = ?` tells the planner nothing about which partition holds the highest sequence — so
> it plans a `Merge Append` over all of them. Measured over 41 partitions: 13.4 ms planning against
> 0.58 ms over one. Keep `--ahead` in months, give `--retire` a period, and the partition count
> settles instead of growing with the age of the installation.

### Verification: budget the walk, not the hash

`LedgerStream` over a whole stream — what `sentinel:verify --depth=entries` walks — on the reference
machine:

| | 1M | 10M |
|---|---|---|
| PostgreSQL, flat | 35.3 s | 351.5 s |
| PostgreSQL, partitioned | 37.9 s | 427.0 s |
| MySQL, flat | 37.8 s | 392.4 s |
| MySQL, partitioned | 38.5 s | 444.9 s |

Linear, with roughly a fifth added by partitioning. **That row measures the walk, not the
verification**: it hydrates each entry and discards it. Canonicalising and hashing — which is the
whole of what verifying an entry is — was measured at 257–311 µs per entry against the walk's 40 µs,
so budget several times the figures above for a full-depth pass. Verify by stream and by range, or
at a shallower depth, rather than walking everything nightly. See
[Verification](../07-integrity/06-verification.md).

### Reading at ten million

Taking fifty entries, on the reference machine:

| Filter | PostgreSQL flat | MySQL flat |
|---|---|---|
| `forTenant()` | 5.1 ms | 19.4 ms |
| `whereIp()` | 4.2 ms | 31.6 ms |
| `for()` | 19.6 ms | 182.9 ms |
| `whereRoute()` | 113.4 ms | **5 118 ms** |
| `whereEvent()` | 301.7 ms | **32 589 ms** |

The two outliers are the same outlier: a filter that selects a **category** rather than an entity
reaches its index, matches tens of thousands of rows, and then has to order them. Put an indexed
filter in front of either, or select an entity instead.

**The warning signs you were late:** a prune whose `DELETE` phase runs for a minute per range; disk
that never comes back; a partition count that grows every month because nothing retires; a
verification job that is still running when the next one starts.

---

## Measuring on your own data

Numbers from someone else's machine tell you the shape of a cost, never its size on yours. Both
harnesses are reports, never gates.

### `make bench` — the write-path baseline

Runs `benchmarks/bench.php`: SQLite on a temp file with `synchronous = off` and
`journal_mode = memory`, so the baseline measures what the package costs rather than what a
container's `fsync` costs. Twelve tables — the variant sweep (not audited, snapshots on and off,
protected fields, labels, fanout, the null ledger, and diff/context/pipeline in isolation), then
pivot operations, hand-over, after-commit deferral, transitions, restores, listeners, performance
modes, mass operations, signatures, anchoring and trace context. 200 warm-up writes then 2000
iterations for the main table. Redis is required for the buffered rows.

```bash
make bench
```

### `make bench-volume` — what the trail costs once it is big

Runs `benchmarks/volume.php` against the two `bench` compose services, which put PostgreSQL and MySQL
on real disk volumes rather than the tmpfs the test databases use — a ten-million-row seed is large
enough that it has to. The dataset is seeded with raw SQL on purpose: what is being measured is what
an operation costs **on** a table of that size, not how long it takes to build one.

```bash
make bench-volume ENGINE=pgsql ROWS=1000000  SHAPE=flat
make bench-volume ENGINE=mysql ROWS=10000000 SHAPE=partitioned WRITES=2000
```

| Section | What it reports |
|---|---|
| Seeding and `analyze` | Wall time; the table is dropped and rebuilt so a half-seeded run cannot measure itself |
| The write path | `WRITES` entries through the ledger, with and without the JSON index published, and the delta |
| A write onto a subject with history | 1, 10, 100 and 1000 prior entries; five passes, median with low, high and spread |
| Every published filter | Median, low, high, **rows matched** and the plan the engine chose |
| Walking a stream | Walk only, walk + rehash, and both signers, over a window of `min(rows, 100 000)` |
| Index weights | Every index of the trail, in MB |
| A read under compliance mode | The same read with the access entry and row, and without |
| Partition planner cost | Planning and execution of the tail read, and how many partitions the planner considered |
| Retiring a range | `DROP PARTITION` on a divided table, or the equivalent `DELETE` on a flat one |

It asserts exactly one thing and fails the pass on it: **a filter the documentation does not call a
refiner whose plan walks the table**. Everything else it prints is a report.

### The noise floor — read this before quoting a delta

Every timed row of the volume pass is warmed, then run five times (three for the stream walk), and
reported as a median with its low and high. That spread is not decoration: it is the detection
threshold of that run.

> ⚠️ **Warning.** On the reference machine the write path moved by up to **24.5 %** between passes on
> the identical configuration and the identical subject. The threshold is of that order, so a delta
> below it is not a result. A −3.7 % median with overlapping ranges means "the effect, if it exists,
> cannot be measured here" — not a speed-up. This series can detect a large accumulated regression;
> it cannot detect a 3 % regression from one change.

Dispersion varies by row and by session, which is why the control row of *your* run sets *your*
threshold rather than any constant printed here. When you want to know whether a change moved
anything, measure the unchanged configuration in the same pass and compare bands, not medians.

> 📌 **Note.** The package emits no metrics or counters of its own. Operational visibility is the
> `sentinel:prune`, `sentinel:partitions`, `sentinel:verify` and `sentinel:flush` reports and exit
> codes, the events, and whatever your own instrumentation counts around them. See
> [Monitoring and troubleshooting](../09-operations/08-monitoring-and-troubleshooting.md).

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| Writes got slower after partitioning on PostgreSQL, and no statement looks slow | The tail read plans a `Merge Append` across every partition; the cost is planning, not execution | Keep `--ahead` in months, give `--retire` a period so partitions stop accumulating |
| `sentinel:prune` reports "nothing was removed" every night | One of four holds, named in the note beside the stream: `undeclared` (no policy), `unanchored` (no anchors), `retained` (a window still holds a kept entry), `tail` (the window holds the entry the next write links to) | Read the note; declare a policy, enable anchors, or accept that the range is held |
| Rows left but disk did not come back | InnoDB does not return freed pages; PostgreSQL leaves dead tuples | A DBA operation in a maintenance window, or partition and drop instead of delete |
| `QueryException::unbounded` from a read that used to work | An uncapped `get()` matched more than 500 entries; it refuses rather than hand back a prefix shaped like a complete answer | Narrow, `take(n)`, or `paginate()` |
| A `whereRoute()` or `whereEvent()` page takes seconds | Category filter: the index finds tens of thousands of rows and the ordering is what costs | Put an indexed filter in front, or select an entity — `whereIp()`, `forTenant()`, `for()` |
| `sentinel:partitions` exits 2 on a tenant-partitioned table | It maintains monthly RANGE partitions and issues a RANGE bound against a LIST parent | Maintain tenant partitions by hand, with their two unique indexes each |
| A benchmark says the change made writes 8 % faster | Below the run-to-run dispersion, measured at up to 24.5 % on the write path | Compare the low/high bands of the same run; report "not measurable here" |
| The buffer grows without bound while the database is down | Every failed flush puts its batch back and every arrival retriggers it; nothing in the package bounds the list | Watch `LLEN` yourself and alert on `BufferFlushFailed` |
| Duplicate `sequence` accepted on a date-partitioned table | Both engines require every unique key to carry the partitioning column, so `(stream, sequence)` and `capture_id` stop being enforced across partitions | The ledger's own assignment and `sentinel:verify` are the net; use the tenant division if that is not enough |
| A prune run keeps re-examining the same held windows | `prune.windows` (default 100) is per stream, per run, and a permanently held window is examined every time | Raise it for a backlog, or run the prune more often |

---

## ✅ Best practices

✅ **Do** — turn anchoring on at install, long before you intend to prune. Retention's unit is the
anchored window, so a stream with no anchors releases nothing however old its entries are.

```php
// config/sentinel.php
'integrity' => [
    'checkpoints' => ['enabled' => true, 'every' => 1000],
],
'retention' => [
    'auth' => '1 year',
],
```

❌ **Don't** — declare retention and expect it to work with anchoring off. Every stream reports
`unanchored`, the prune removes nothing, and the table keeps growing while a scheduled job says it is
handling it.

```php
'integrity' => ['checkpoints' => ['enabled' => false]],
'retention' => ['auth' => '1 year'],   // releases nothing, silently
```

✅ **Do** — archive first and retire second. The prune is what makes the range provable and empties
the partition; `--retire` is what gives the space back.

```php
Schedule::command('sentinel:prune --action=archive')->dailyAt('03:10');
Schedule::command('sentinel:partitions --ahead=6 --retire="18 months"')->monthly();
```

❌ **Don't** — reach for `--force` to drop a partition that still holds entries. That removes a range
of the trail as a catalogue operation, with nothing archived and nothing recorded saying it went — and
under compliance mode it is refused anyway.

```bash
php artisan sentinel:partitions --retire="18 months" --force   # ❌ the range leaves no trace
```

✅ **Do** — put an indexed filter in front of every refiner, and walk with a cursor.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

Sentinel::audits()
    ->for($invoice)                       // indexed: (subject_type, subject_id, id)
    ->whereFieldChanged('total')          // refiner: no index covers it
    ->take(50)
    ->get();
```

❌ **Don't** — run a refiner alone at volume, or page deep into the trail with an offset. The first
walks the table; the second makes the engine count past everything ahead of the page.

```php
Sentinel::audits()->whereFieldChanged('total')->take(50)->get();   // ❌ full pass
Sentinel::audits()->paginate(50, 8000);                            // ❌ 400 000 rows counted past
```

✅ **Do** — publish the JSON index only once `whereIp()` or `whereRoute()` is a query you actually
run, and re-measure end to end afterwards. Both filters work without it.

```bash
php artisan vendor:publish --tag=sentinel-json-indexes && php artisan migrate
make bench-volume ENGINE=pgsql ROWS=1000000 SHAPE=flat
```

❌ **Don't** — publish it at install "to be safe". Measured at the engine it is +15 % per `INSERT` on
PostgreSQL 16 and +21 % on MySQL 9, paid by every write of an installation that may never filter by
address.

✅ **Do** — size `buffer.size` as the loss window you can afford, and keep a batch well inside the
32 766-placeholder statement ceiling the ledger enforces for the narrowest engine.

```php
'buffer' => ['size' => 500, 'flush_interval' => 60],
```

❌ **Don't** — raise it to several thousand to "amortise more". The ledger splits the insert into
several statements from around nine hundred entries on, and the number you raised is simultaneously
the batch size, the take size and everything one dying process can drop.

✅ **Do** — measure a change against the unchanged configuration in the same pass, and quote the
band.

```bash
make bench-volume ENGINE=pgsql ROWS=1000000 SHAPE=flat > before.txt
# ... change one thing ...
make bench-volume ENGINE=pgsql ROWS=1000000 SHAPE=flat > after.txt
```

❌ **Don't** — quote a median from one pass as a result. With dispersion measured at up to 24.5 % on
the write path, a single-pass delta under about twenty per cent says nothing at all.

---

**See also:** [Partitioning](06-partitioning.md) · [Indexes and JSON](05-indexes-and-json.md) · [A database of its own](07-a-database-of-its-own.md) · [Choosing an engine](01-choosing-an-engine.md) · [Retention and pruning](../08-lifecycle/01-retention-and-pruning.md) · [Cold archiving](../08-lifecycle/02-cold-archiving.md) · [Performance modes](../09-operations/01-performance-modes.md) · [The buffered mode](../09-operations/02-the-buffered-mode.md) · [Order, paging and walking](../06-reading/03-order-paging-and-walking.md) · [Filters reference](../06-reading/02-filters-reference.md) · [Verification](../07-integrity/06-verification.md) · [Scheduling](../09-operations/07-scheduling.md) · [Configuration](../99-reference/02-configuration.md)
