# 🐘 MySQL

> What Sentinel's schema, chain and query surface look like on MySQL 9 — the column types you
> actually get, the two clauses a route filter emits, what range partitioning takes away, how a
> sequence is assigned under concurrency, and what does and does not shrink the file after a prune.

**On this page:** [Support and the SQL floor](#support-and-the-sql-floor) ·
[The table on MySQL](#the-table-on-mysql) · [JSON and generated columns](#json-and-generated-columns) ·
[Collation](#collation-why-whereroute-emits-two-clauses) ·
[Range partitioning](#range-partitioning) · [Keeping partitions supplied](#keeping-partitions-supplied) ·
[Concurrency and locking](#concurrency-and-locking) · [Placeholder limits](#placeholder-limits) ·
[Reclaiming space](#reclaiming-space-after-a-prune) ·
[MariaDB is not MySQL here](#mariadb-is-not-mysql-here) · [Tuning checklist](#tuning-checklist) ·
[Pitfalls](#-pitfalls) · [Best practices](#-best-practices)

---

## Support and the SQL floor

| Claim | Value | Where it comes from |
|---|---|---|
| Run on every push | MySQL 9 | `.github/workflows/run-tests.yml` runs the suite against a `mysql:9` service on `push` to `main` and on every pull request, printing the server's real version before it starts |
| What the emitted SQL needs | 8.0.4 | `Ledger\ChangedFieldPredicate` compiles `whereFieldChanged()` to `JSON_TABLE`, which arrives in MySQL 8.0.4 |
| Partitioning | `mysql-range` stub, plus `sentinel:partitions` | `Partitions\Grammar::divides('mysql')` is `true` |
| MariaDB | Not supported, refused by name | `Ledger\ChangedFieldPredicate`, `Ledger\ContextPredicate`, `Partitions\Grammar` |

Only the first row is a support claim. The 8.0.4 floor says what the statements the package writes
require, not what has been run — the package does not declare compatibility it does not test.

> 📌 **Note.** MySQL is the faster flat write of the two engines the package's volume benchmark
> covers: 1.93 ms per entry at one million rows and 1.97 ms at ten million, against PostgreSQL 16's
> 2.47 ms and 2.20 ms. Measured on one machine — an i7-12700KF with 15 GB, both engines on disk with
> `fsync` off — and reproducible with `make bench-volume`. It is a report, not a gate.

---

## The table on MySQL

`Support\AuditSchema::columns()` is the single definition of the forty columns, and the base
migration is what runs it. What Laravel's MySQL grammar compiles them to:

| Declared in `AuditSchema` | MySQL column | Notes |
|---|---|---|
| `char('id', 26)` | `char(26)` | A ULID. Never auto-increment, on any engine |
| `string('stream', 64)` + `unsignedBigInteger('sequence')` | `varchar(64)`, `bigint unsigned` | The chain's order. Two entries with the same clock still have one order |
| `string('subject_id', 64)` and the other morph keys | `varchar(64)` | Wide enough for an `int`, a UUID and a ULID without a later `ALTER` |
| `string('severity', 8)` | `varchar(8)` | `critical` is exactly eight characters. Nothing longer fits, by design |
| `jsonb('context')` and the six other JSON columns | `json` | `Blueprint::jsonb()` compiles to `json` on MySQL |
| `dateTime('occurred_at', 6)` / `dateTime('created_at', 6)` | `datetime(6)` | Microsecond precision. `Models\Audit::getDateFormat()` returns `Y-m-d H:i:s.u` to match |

There is no `updated_at`: `Models\Audit::UPDATED_AT` is `null` and the model throws
`ImmutableAuditException` on an update or a delete. Nothing in the schema has a foreign key to
`sentinel_audits`, deliberately — a cascade lives badly with partitioning and with a batched prune.

Thirteen non-primary indexes are created across **two** migrations. Eleven come from the base
migration, which declares the two unique keys — `(stream, sequence)` and `capture_id` — itself and
then calls `Support\AuditSchema::indexes()` for the other nine; the keys are stated by the migration
rather than by the schema class because a partitioned alternative has to state its own. Two more come
from `…_add_occurrence_indexes_to_sentinel_audits_table.php`, which exist so that ordering a timeline
by `occurred_at` does not sort outside an index. See
[Indexes and JSON](05-indexes-and-json.md) for the full list.

> 🐘 **Engine.** Neither MySQL's `json` nor PostgreSQL's `jsonb` preserves the key order you wrote.
> Values round-trip intact, order does not — which is exactly why the chain canonicalises (RFC 8785)
> before hashing, and why `Models\Audit::toArray()` re-orders diff entries, relation lines, pivot maps
> and labels on the way out. See [Canonicalization](../07-integrity/03-canonicalization.md).

---

## JSON and generated columns

`whereIp()` and `whereRoute()` read inside the `context` column, which no shipped index covers. Both
answer correctly without an index, by scanning. The index is a migration you publish:

```bash
php artisan vendor:publish --tag=sentinel-json-indexes
php artisan migrate
```

On MySQL this does something the other two engines do not: it **adds two real columns** to
`sentinel_audits`.

```php
// What the stub builds on MySQL 9, once per filter (Filter::Ip, Filter::Route):
$table->string('context_ip', 255)
    ->virtualAs("json_unquote(json_extract(`context`, '$.ip'))")
    ->invisible()
    ->nullable();

$table->index('context_ip', 'sentinel_audits_context_ip_index');
```

The index is named by hand because the generated name would be built from a column only one of the
three engines has, and the table prefix in front of it is yours to choose.

Both column properties are deliberate, and the reasons are in the stub's own docblock:

- **`VIRTUAL`** — `virtualAs()` emits `as (…)` with no `stored`, which is MySQL's default for a
  generated column. `STORED` would rewrite the table to add itself and then widen every row.
- **`INVISIBLE`**, because a generated column that answers `select *` would ride along in the
  attributes of an entry read back out — and the next `insert` of that entry somewhere else, a fanout
  destination or a rehydration, would be handing MySQL a value for a column it computes itself, which
  it refuses.

The expression is never written in the migration. It is asked of `Ledger\ContextPredicate::expression()`,
the same object the driver asks when it compiles the filter, because two copies of a JSON path is an
index that silently stops being used the day somebody edits one of them.

> ⚠️ **Warning.** Publishing this costs about **21 % per write on MySQL 9** at the engine level,
> measured over 200 000 writes on a table with these forty columns and thirteen indexes. An
> installation that never asks where an entry was recorded from should not pay it. End to end through
> the package the same delta lands inside benchmark noise — the write path's cost is the pipeline,
> the canonicalisation and the hash, not the index.

> 🧪 **Verify it.** After migrating, run `analyze table sentinel_audits` and re-read the plan. The
> package's own test does exactly that before asserting the plan flipped from a scan to an index read.

Rolling back drops the index and the column, and leaves the table as it found it —
`tests/Database/ContextIndexesTest.php` asserts the column listing is identical before and after, and
that no hash the index sits over changes.

---

## Collation: why `whereRoute()` emits two clauses

MySQL's default collation, `utf8mb4_0900_ai_ci`, is case- and accent-insensitive. A plain
`= 'invoices.show'` there answers with entries that PostgreSQL and SQLite would not return, and a
trail whose answer depends on the engine is not a trail.

`Ledger\ContextPredicate::for()` therefore emits the comparison twice on MySQL and only once
elsewhere:

```sql
json_unquote(json_extract(`context`, '$.route')) = ?
and json_unquote(json_extract(`context`, '$.route')) collate utf8mb4_bin = ?
```

The insensitive clause matches a superset of the sensitive one, which makes it a safe prefilter the
index can serve; the binary clause then rechecks and decides. A binary collation on its own would be
correct and would lose the index — measured, the plan goes from an index lookup to a table scan,
because a generated column indexed under one collation cannot serve a comparison under another.

The same reasoning shows up in `Ledger\ChangedFieldPredicate`, whose MySQL dialect compares the
element's `path` under `collate utf8mb4_bin`; without it a query for `/email` would come back with
`/Email` as well.

> 📌 **Note.** Do not "fix" this by changing the collation of `sentinel_audits` or of the generated
> columns. Both clauses go out regardless of what the column is collated as, and the insensitive one
> is the half the index serves.

---

## Range partitioning

One partitioned stub ships for MySQL. It **replaces** the base migration rather than adding to it:
the published file carries the same name, `…_create_sentinel_audits_table.php`, so
`Support\PackageMigrations` stops offering the package's own.

```bash
# On a NEW installation, before the first entry:
php artisan vendor:publish --tag=sentinel-partitioned-mysql-range
php artisan migrate
```

The stub declares three lines the base migration does not, and asks `Support\AuditSchema` for
everything else:

```php
$table->primary(['id', 'created_at']);
$table->unique(['stream', 'sequence', 'created_at']);
$table->unique(['capture_id', 'created_at']);
// ... partition by range (to_days(created_at)) (…)
```

### What MySQL takes away

MySQL's partitions are not tables. There is no per-partition index to fall back on, and
`ERROR 1503` rejects any unique key that does not carry the partitioning column. That is the end of
it — it is not a configuration you can talk MySQL out of.

| Key on a flat table | Key under `mysql-range` | What is lost |
|---|---|---|
| `primary (id)` | `primary (id, created_at)` | `id` alone is no longer enforced unique. The ULID is what makes it unique |
| `unique (stream, sequence)` | `unique (stream, sequence, created_at)` | The chain's position is unique only *within one partition*, and the stub's partitions are monthly. The engine will accept a duplicate sequence planted in another month |
| `unique (capture_id)` | `unique (capture_id, created_at)` | Retry idempotency is no longer enforced across partitions. `Deduplicates::settled()` — a plain read of that index — still runs in front of the retry, so the window narrows to two captures straddling a boundary rather than disappearing |

What still holds the chain is the ledger's own sequence assignment and `sentinel:verify`. The suite
plants exactly the duplicate the engine now accepts and asserts the verification fails on it
(`tests/Database/PartitionedTrailTest.php`, *"takes a duplicate sequence the engine no longer refuses,
and fails the verification on it"*). The safety net is narrower here than on a flat table, and that is
the trade.

> ⚠️ **Warning.** There is no tenant division for MySQL. `pgsql-tenant` keeps the full guarantee only
> because PostgreSQL allows a unique index local to one partition; MySQL has no equivalent. If a
> per-tenant chain guarantee is what you are after, that is a PostgreSQL decision — see
> [PostgreSQL](02-postgresql.md) and [Partitioning](06-partitioning.md).

### The calendar and `pmax`

MySQL takes its partition list inside the `create table` and nowhere else, so the stub writes this
month and the three after it, then a catch-all:

```sql
partition by range (to_days(created_at)) (
  partition p2026_09 values less than (to_days('2026-10-01')),
  partition p2026_10 values less than (to_days('2026-11-01')),
  partition p2026_11 values less than (to_days('2026-12-01')),
  partition p2026_12 values less than (to_days('2027-01-01')),
  partition pmax     values less than maxvalue
)
```

Those four months are counted from `CarbonImmutable::now()` **at migrate time**, not at publish time.

`MAXVALUE` is what keeps a forgotten `sentinel:partitions` from becoming a failed insert: an entry
whose `created_at` falls outside every declared month lands in `pmax` instead of erroring. The suite
writes an entry stamped 2099 and asserts it lands (`PartitionedTrailTest`, *"takes an entry whose clock
falls outside every declared range"*).

---

## Keeping partitions supplied

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('sentinel:partitions --ahead=6 --retire="18 months"')->monthly();
```

`Partitions\Grammar` says what maintenance means on MySQL, and it is not what it means on PostgreSQL:

| Operation | MySQL statement |
|---|---|
| List partitions | `select partition_name as name from information_schema.partitions where table_schema = database() and table_name = ? and partition_name is not null order by partition_ordinal_position` |
| Add a month | `alter table <t> reorganize partition pmax into (partition p2026_09 values less than (to_days('2026-10-01')), partition pmax values less than maxvalue)` |
| Count what one holds | `select count(*) as total from <t> partition (p2026_09)` |
| Retire one | `alter table <t> drop partition p2026_09` |

Two consequences fall straight out of the second row:

1. **The catch-all must be named exactly `pmax`.** `Grammar::CATCH_ALL` is that literal. If it was
   created under another name, or dropped, the statement fails, `PartitionsCommand` catches the
   `Throwable` and exits `INVALID` (2).
2. **Reorganising rewrites whatever `pmax` holds.** A forgotten cron does not just degrade to one fat
   partition — it makes the catch-up run expensive, because the first `reorganize` has to move every
   row that piled up in the catch-all.

A partition's range is read out of its **name**, not out of the engine's bounds:
`Partitions\Partition::named()` matches `/p(\d{4})_(\d{2})$/`. A partition called anything else —
`pmax`, `p2026_9`, `archive_2026_09` — parses as a catch-all: it is never retired, and the maintainer
will happily create the correctly-named one beside it. That is deliberate, so maintenance only ever
touches partitions it would have created itself.

Retirement is timid on purpose. `Partitions\Maintainer::refusal()` drops a partition behind the cutoff
when it holds **no rows**, and otherwise only when `--force` says so; under compliance mode an occupied
partition is never dropped, whatever `--force` says. The working order is therefore:

```bash
php artisan sentinel:prune --action=archive
php artisan sentinel:partitions --retire="18 months"
```

`--table` takes a **config key**, not a table name, and accepts only `audits` or `access_log`
(`PartitionsCommand::TABLES`). `sentinel_access_log` is created as a plain table by its own migration,
so the command reports it undivided and exits 0 until you divide it by hand.

---

## Concurrency and locking

Every write reads the tail of its stream before it can build a row: the hash covers the sequence and
the previous hash, so no `INSERT` can compute its own link. `Ledger\StreamGate::tail()` issues that
read inside the sealing transaction:

```sql
select `sequence`, `hash` from `sentinel_audits`
where `stream` = ? order by `sequence` desc limit 1 for update
```

On MySQL there is no advisory lock and none is taken — `StreamGate` sends
`pg_advisory_xact_lock` only when the driver is `pgsql`, and the suite asserts it sends nothing on
`mysql` and `sqlite`. What serialises writers here is InnoDB itself:

- **A stream that already has entries** is covered by the row lock `for update` takes on the tail.
- **A stream nobody has written to** is covered by the InnoDB **gap lock**, which is what makes the
  first write of a brand-new stream safe. `tests/Ledger/ConcurrencyTest.php` proves it, and the test
  is skipped on every other engine: *"holds an outside writer on the gap lock while a gate owns the
  stream — only InnoDB extends the lock over the gap an outside insert would land in."*

Two things follow that you have to own:

> ⚠️ **Warning.** InnoDB takes gap locks under `REPEATABLE READ`, MySQL's default isolation level.
> Under `READ COMMITTED` it largely does not, and the guard that covers the first write of a new
> stream goes with it. Sentinel does not check the isolation level and there is no test at
> `READ COMMITTED`. If your application sets it globally, that is a decision to make consciously.

> ⚠️ **Warning.** Writers of one stream wait for each other. A long business transaction that holds
> the gate pushes the others toward `innodb_lock_wait_timeout` (50 s by default) and they surface as
> `Lock wait timeout exceeded; try restarting transaction`. Writers of a *different* stream are not
> blocked — the suite asserts that too. If one stream is the bottleneck, split it with
> `integrity.stream` (`global`, `tenant`, `subject_type`, a closure, or a `StreamResolver`), not by
> raising the timeout.

Nothing in the repository asserts that the gap lock still serialises the tail read once the table is
**partitioned** — gap locks are per partition and the tail read spans them. Treat a partitioned MySQL
trail's empty-stream race as unproven and let `sentinel:verify` be the check.

---

## Placeholder limits

**MySQL's own limit is 65 535** bound parameters, from the prepared protocol. The package never
reaches it: `Ledger\DatabaseLedger::MAX_PLACEHOLDERS` is **32 766**, which is SQLite's number, because
one batch size for three engines has to be the narrowest one. So a MySQL installation is batched by a
constant MySQL did not set, and roughly half its headroom goes unused.

The constant, the divisor and the resulting rows per statement are derived once, on
[SQLite](04-sqlite.md#the-placeholder-ceiling). The consequence on this page is that a batch arrives
as several `INSERT` statements — all inside the one transaction `chain()` opened, so it still lands
whole or not at all.

---

## Reclaiming space after a prune

**Pruning reclaims no disk space on any engine**; the general rule and the per-engine table are on
[Retention and pruning](../08-lifecycle/01-retention-and-pruning.md#it-does-not-reclaim-disk-space).
What matters here is the InnoDB shape of it: `sentinel:prune` issues `DELETE` by sequence range and
nothing else — no DDL, no partition drop, no table rebuild — so the freed pages stay inside the
tablespace and `OPTIMIZE TABLE sentinel_audits` is the command that returns them.

| Operation | What it does to the `.ibd` file |
|---|---|
| `DELETE` (what `sentinel:prune` does) | **Nothing.** Freed pages go on the tablespace's free list and are reused by later inserts. The file keeps its size |
| `alter table … drop partition` (what `sentinel:partitions --retire` does) | Returns the space to the filesystem. With `innodb_file_per_table` on — MySQL's default — each partition is its own tablespace, so dropping it removes its file |
| `OPTIMIZE TABLE` | Returns space by rebuilding the table. InnoDB maps it to an `ALTER TABLE … FORCE`; it needs room for a full copy and it is a DBA operation with a window attached. **The package will not run it for you** |

The gap between the first two rows is the strongest argument for partitioning a MySQL trail. Removing
about 260 000 entries at ten million rows was measured at **59 029 ms as a `DELETE`** against **71 ms as
a `DROP PARTITION`** — and at one million rows, 16 698 ms against 22 ms. Same machine and same run as
the write-path numbers above; `make bench-volume` reproduces them.

Partitioning is not free, though, and on MySQL it is paid on the write: 2.08 ms per entry at one
million rows and 3.06 ms at ten million, against 1.93 ms and 1.97 ms flat. Partition to make
retirement cheap, not to make writes fast.

---

## MariaDB is not MySQL here

MariaDB is **not a supported engine** and is refused by name rather than guessed at. It surfaces in
three places, each with its own message:

| Where | Trigger | What you see |
|---|---|---|
| `Ledger\ChangedFieldPredicate` | `whereFieldChanged()`, `Models\Audit::field()` | `LedgerException`: *Sentinel has no field predicate for the [mariadb] engine, so whereFieldChanged() cannot be answered there. Supported: mysql, pgsql, sqlite.* |
| `Ledger\ContextPredicate` | `whereIp()`, `whereRoute()` | The same exception naming `ip` or `route` and `whereIp()` / `whereRoute()` |
| `Partitions\Grammar` | `sentinel:partitions` | `ConfigurationException`: *The [mariadb] engine does not partition a table, so there is nothing for Sentinel to maintain there. Partitioning is supported on mysql and pgsql.* — caught by the command and reported as exit code 2 (`INVALID`) |

The refusal is the point. MariaDB has `JSON_TABLE` from 10.6 and names the binary collation something
else; answering with a predicate that might not mean the same thing is worse than declining. See
[Exit codes](../99-reference/07-exit-codes.md) and [Exceptions](../99-reference/06-exceptions.md).

> ⚠️ **Warning — the refusal is by configured driver, not by server.** `getDriverName()` returns the
> `driver` key you wrote in `config/database.php`. A MariaDB server configured as `'driver' => 'mysql'`
> gets the MySQL dialect and is **not refused** — it will run `JSON_TABLE` and `collate utf8mb4_bin`
> and whatever happens, happens. Configure it as `'driver' => 'mariadb'` so the refusal is explicit,
> and put the trail on a supported engine.

> 📌 **Note.** The three JSON refusals surface when the query **executes**, inside
> `Ledger\DatabaseLedger::query()`, not when you call `whereFieldChanged()`. A page renders and then
> throws. If that matters, check the connection's driver at boot.

---

## Tuning checklist

| Setting | Default | Why it matters for Sentinel |
|---|---|---|
| `transaction_isolation` | `REPEATABLE-READ` | The gap lock that covers the first write of a new stream only exists here. `READ COMMITTED` removes it |
| `innodb_lock_wait_timeout` | 50 s | Writers of one stream serialise on the `for update` tail read. This is what a stalled gate surfaces as |
| `innodb_file_per_table` | `ON` | What makes `sentinel:partitions --retire` return space to the filesystem rather than to a free list |
| `innodb_buffer_pool_size` | 128 MB | Every single write seeks into `(stream, sequence)`. Keeping that index resident is most of what keeps a write near 2 ms |
| `binlog_format` | `ROW` | A prune of 260 000 entries writes 260 000 row events. `prune.batch` and `prune.pause` are the levers if replication cannot take it |
| Table/column collation | `utf8mb4_0900_ai_ci` | Leave it. `ContextPredicate` already emits a binary recheck; changing the collation loses the index the insensitive prefilter uses |
| `sentinel:partitions --ahead` | 3 | Keep it in months. A partition count that grows with the age of the installation is a cost paid on every write |

The dev containers in `compose.yaml` run MySQL with `--innodb-flush-log-at-trx-commit=0`,
`--innodb-doublewrite=OFF`, `--skip-log-bin` and a tmpfs data directory. Those exist so a suite that
recreates the schema per test does not fsync through a Docker VM's disk. **They are not production
settings** — durability is off on purpose because that data is disposable.

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `ERROR 1503: A UNIQUE INDEX must include all columns in the table's partitioning function` | You published `mysql-range` and then tried to restore `unique(stream, sequence)` or `unique(capture_id)` | You cannot. Under `partition by range (to_days(created_at))` every unique key must carry `created_at`. The ledger's sequence assignment and `sentinel:verify` are the net |
| `sentinel:verify` reports a duplicate sequence the engine accepted | Under `mysql-range` the key is only unique within one partition, and those are monthly | Expected, and exactly what the verification exists for. Investigate the writer, not the schema |
| `sentinel:partitions` exits 2 with a `reorganize partition pmax` statement in the message | The catch-all is missing or is not named `pmax` | Recreate it under that exact name. `Grammar::CATCH_ALL` is a literal |
| The monthly run suddenly takes minutes after months of being instant | `pmax` filled up while the cron was not running, and `reorganize` rewrites everything it holds | Nothing to fix retroactively. Put the schedule back and keep `--ahead` covering the gap between runs |
| A partition named `p2026_9` or `audits_2026_09` is never created or retired | `Partition::named()` only parses `p(\d{4})_(\d{2})` at the end of the name; anything else reads as a catch-all | Rename it to the pattern, or leave it alone deliberately — that is the behaviour the parser exists to give you |
| Publishing `sentinel-json-indexes` added two columns to `sentinel_audits` | MySQL cannot index a bare expression; the stub adds `context_ip` and `context_route` as generated columns | Expected on MySQL only. They are `VIRTUAL INVISIBLE` and `migrate:rollback` removes them |
| `select *` on the audit table does not show `context_ip` | The generated column is `INVISIBLE`, so a re-read entry never carries a value MySQL computes itself | Name the column explicitly if you want to see it |
| The JSON index exists and the plan still scans | The optimizer has stale statistics | `analyze table sentinel_audits` |
| `whereRoute('invoices.show')` does not return an entry recorded as `Invoices.Show` | The `collate utf8mb4_bin` clause decides, so the match is case- and accent-sensitive — the same answer PostgreSQL and SQLite give | Match the value the resolver recorded. Route names are matched exactly on all three engines |
| `Lock wait timeout exceeded` on `sentinel_audits` under load | Writers of one stream serialise on the tail read, and something is holding the gate | Shorten the surrounding transaction, or split the stream with `integrity.stream` |
| Disk usage does not fall after `sentinel:prune` | `DELETE` returns pages to the tablespace free list, not to the filesystem | Drop a partition, or schedule a rebuild in a window. The package runs neither |
| `affected_rows` on a mass update is lower than the rows you targeted | MySQL counts rows **changed**, not rows matched, and counts two for an upsert that updated | Nothing. It is stored unnormalised on purpose — see [Mass operations](../03-capture/05-mass-operations.md) |
| A batch of 2 000 entries became three `insert` statements | The placeholder ceiling is 32 766 taken from SQLite, so 936 rows per statement | Expected. All of them run inside one transaction, so the batch still lands whole or not at all |
| `whereFieldChanged()` throws on a connection everything else works on | The driver is `mariadb` | Move the trail to a supported engine. There is no dialect to fall back on |

---

## ✅ Best practices

✅ **Do** — decide about partitioning before the first entry, and publish exactly one stub.

```bash
php artisan vendor:publish --tag=sentinel-partitioned-mysql-range
php artisan migrate
```

❌ **Don't** — publish two partitioned stubs, or publish one onto a table that already holds entries.
All three land under the same file name and all three replace the base migration, so publishing two
leaves one file and no way to tell which. Converting a populated table is a maintenance window with a
documented procedure in `UPGRADE.md`, not a `vendor:publish`.

```bash
# Both of these write …_create_sentinel_audits_table.php. The second silently wins.
php artisan vendor:publish --tag=sentinel-partitioned-mysql-range
php artisan vendor:publish --tag=sentinel-partitioned-pgsql-range
```

---

✅ **Do** — schedule maintenance with the months bounded, and archive before you retire.

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('sentinel:prune --action=archive')->dailyAt('03:00');
Schedule::command('sentinel:partitions --ahead=6 --retire="18 months"')->monthly();
```

❌ **Don't** — reach for `--force` when a partition refuses to go. It refuses because it still holds
entries, and dropping it would remove a range of the trail as a catalogue operation, unarchived and
unrecorded. Under compliance mode the flag does nothing at all: `Maintainer::refusal()` checks
compliance before it checks `--force`.

```bash
php artisan sentinel:partitions --retire="18 months" --force   # under compliance: exit 1, and rightly so
```

---

✅ **Do** — publish the JSON index only if you actually filter by address or route, then refresh the
statistics.

```bash
php artisan vendor:publish --tag=sentinel-json-indexes
php artisan migrate
```

```sql
analyze table sentinel_audits;
```

❌ **Don't** — ship it because it looks like a good default. It costs about 21 % per write on MySQL 9,
and both filters answer identically without it — they refine by scanning.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

// Correct with or without the index. The index buys the seek, not the answer.
Sentinel::audits()->whereIp('203.0.113.7')->take(50)->get();
```

---

✅ **Do** — rename tables through Sentinel's own configuration.

```php
// config/sentinel.php
'tables' => [
    'prefix' => 'audit_',
    'audits' => 'entries',           // -> audit_entries
    'audit_tags' => 'labels',
    'audit_relations' => 'relations',
    'transactions' => 'operations',
    'checkpoints' => 'checkpoints',
    'archives' => 'archives',
    'access_log' => 'access_log',
],
```

❌ **Don't** — set a `prefix` on the audits connection in `config/database.php`. The Blueprint-compiled
`create table` goes through the grammar and picks the prefix up; every raw statement the package pins
around it — `Partitions\Grammar`, the JSON-index stub's `create index` — builds the name from
`Support\Config::table()` alone and does not. No test in the suite covers a prefixed connection.

```php
// config/database.php — do not do this to the audits connection
'mysql_audits' => ['driver' => 'mysql', 'prefix' => 'audit_', /* … */],
```

---

✅ **Do** — split the chain when one stream's gate is the bottleneck.

```php
// config/sentinel.php
'integrity' => [
    'stream' => 'subject_type',   // global | tenant | subject_type | Closure | StreamResolver
],
```

❌ **Don't** — raise `innodb_lock_wait_timeout` to make the errors stop. The gate is what keeps two
concurrent writers from claiming the same sequence; a longer timeout hides the queue instead of
shortening it, and every writer of that stream now waits longer.

---

✅ **Do** — configure a MariaDB server as MariaDB, so the refusal is explicit and immediate.

```php
// config/database.php
'connections' => [
    'audits' => ['driver' => 'mariadb', /* … */],
],
```

❌ **Don't** — point a `'driver' => 'mysql'` connection at MariaDB. The dialect check reads the
configured driver, not the server, so it will happily emit `JSON_TABLE` and `collate utf8mb4_bin` at a
server that is not in the support matrix.

---

✅ **Do** — treat `sentinel:verify` as the guarantee once the table is divided.

```bash
php artisan sentinel:verify
```

❌ **Don't** — assume the engine is still refusing a duplicate sequence. Under `mysql-range` it is not,
and the entire point of `(stream, sequence)` losing its cross-partition uniqueness is that something
else has to notice.

---

**See also:** [Choosing an engine](01-choosing-an-engine.md) · [PostgreSQL](02-postgresql.md) ·
[SQLite](04-sqlite.md) · [Indexes and JSON](05-indexes-and-json.md) ·
[Partitioning](06-partitioning.md) · [A database of its own](07-a-database-of-its-own.md) ·
[Scaling playbook](08-scaling-playbook.md) ·
[Filters reference](../06-reading/02-filters-reference.md) ·
[Field history](../06-reading/04-field-history.md) ·
[The hash chain](../07-integrity/01-the-hash-chain.md) ·
[Verification](../07-integrity/06-verification.md) ·
[Retention and pruning](../08-lifecycle/01-retention-and-pruning.md) ·
[Artisan commands](../09-operations/06-artisan-commands.md) ·
[Scheduling](../09-operations/07-scheduling.md) · [Schema](../99-reference/03-schema.md)
