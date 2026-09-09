# 🐘 Partitioning

> The three shipped alternatives to the base audit migration, exactly what each one costs in keys
> and in query plans, how to keep a divided table supplied with months, and how to convert a table
> that already holds entries without losing the chain.

**On this page:** [What it is for](#what-partitioning-is-for) · [The three stubs](#the-three-stubs) ·
[What you give up](#what-you-give-up) · [Faster and slower](#what-gets-faster-what-gets-slower) ·
[Key and window](#choosing-the-key-and-the-window) · [Installing](#installing-a-partitioned-trail) ·
[Keeping partitions supplied](#keeping-partitions-supplied) ·
[Running out of partitions](#running-out-of-partitions) ·
[The access log](#partitioning-the-access-log) ·
[Converting an existing table](#converting-a-table-that-already-has-entries) ·
[Pruning together](#partitioning-and-pruning-together) ·
[Pitfalls](#-pitfalls) · [Best practices](#-best-practices)

---

## What partitioning is for

An audit trail only grows. Nothing updates a row of `sentinel_audits` — the model throws on
`update` and `delete` — so the operational question is never "how do I make this table smaller",
it is "what does it cost to keep, and what does it cost to let a range of it go".

Partitioning answers the second half. On a flat table, releasing two hundred and sixty thousand
entries is a batched `DELETE`; on a divided one it is a catalogue operation that touches no rows.
That is the whole case for it.

It is **not** a way to make writes faster. Volume alone costs the write path almost nothing —
2.20 ms per entry at ten million rows against 2.47 ms at one million on PostgreSQL — while dividing
the table costs 8.34 ms per entry at forty-one partitions. Partition to make retirement cheap. If
retirement is not a problem you have, leave the table flat.

> 📌 **Note.** Partitioning is opt-in, it is chosen **before the first entry**, and the package
> never turns it on for you. Three migration stubs ship; publishing one is how you choose it.

> 🐘 **Engine.** PostgreSQL 16 and MySQL 9 divide a table. SQLite does not, and the package does not
> pretend otherwise: `Partitions\Grammar::divides()` answers `false` for it, every other method of
> that class throws `ConfigurationException::doesNotPartition`, and `sentinel:partitions` reports
> that the table is not partitioned and exits `0`.

> 🧪 **Verify it.** Every measurement on this page came from `make bench-volume` on one machine — an
> Intel i7-12700KF, 20 threads, 15 GB of RAM, both engines on real disk with `fsync` off — against
> PostgreSQL 16.15 and MySQL 9.7.2, at one and ten million entries, flat and partitioned. A report,
> not a gate, and not your numbers:
> `make bench-volume ENGINE=pgsql ROWS=10000000 SHAPE=partitioned`

---

## The three stubs

| Publish tag | Engine | Clause | Partition names | Keeps `unique (stream, sequence)` |
|---|---|---|---|---|
| `sentinel-partitioned-pgsql-range` | PostgreSQL 16 | `partition by range (created_at)` | `sentinel_audits_pYYYY_MM`, plus `sentinel_audits_default` | **No** |
| `sentinel-partitioned-mysql-range` | MySQL 9 | `partition by range (to_days(created_at))` | `pYYYY_MM`, plus `pmax` | **No** |
| `sentinel-partitioned-pgsql-tenant` | PostgreSQL 16 | `partition by list (tenant_id)` | one per tenant, plus `sentinel_audits_untenanted` and `sentinel_audits_default` | **Yes**, per partition |

All three define the same forty columns and the same nine shared indexes, because all three ask
`Support\AuditSchema` for them. What a stub states for itself is only the three lines an engine
constrains under partitioning: the primary key, the two unique keys, and the clause.

### Publishing one *replaces* the base migration

```bash
php artisan vendor:publish --tag=sentinel-partitioned-pgsql-range
php artisan migrate
```

The published file lands under exactly the name the package's own migration carries —
`…_create_sentinel_audits_table.php`. `Support\PackageMigrations` strips the `YYYY_MM_DD_HHMMSS_`
prefix from every file it ships and asks whether the application's `database/migrations` directory
already holds one ending in that name. It does, so the package stops offering its own. The decision
is per file, so the other seven package migrations still load.

> ⚠️ **Warning.** Rename the published file and the match breaks: the package starts loading its own
> base migration again and `migrate` tries to create `sentinel_audits` twice. Keep the file name the
> stub gave you, timestamp included.

> ⚠️ **Warning.** Do not publish two stubs. They all land under that same file name, so the second
> overwrites the first and nothing in the directory records which division you chose.

---

## What you give up

### The unique keys that guard the chain

Both engines require **every unique key of a partitioned table to carry the partitioning column**.
On MySQL the refusal is `ERROR 1503`. That is not negotiable and it is not something the package can
work around, so the two date stubs state the widened keys openly:

| Key | Flat table | Under a date division |
|---|---|---|
| Primary | `(id)` | `(id, created_at)` |
| Chain position | `unique (stream, sequence)` | `unique (stream, sequence, created_at)` |
| Retry idempotency | `unique (capture_id)` | `unique (capture_id, created_at)` |

Read that second row carefully. **The pair `(stream, sequence)` is no longer unique at all.** The
engine now rejects a second entry only when its `stream`, its `sequence` *and* its `created_at` all
match — and `created_at` is `datetime(6)`, so two rows that differ by one microsecond both stand.
On MySQL it is narrower still, because MySQL's partitions are not tables and every index is local to
one of them: the three-column key is only checked inside a single partition.

The package's own suite plants exactly this. `tests/Database/PartitionedTrailTest.php` copies an
entry with `sequence = 2` into another month, the engine accepts it, and the assertion is that
`Integrity\Verifier` then reports the stream broken. That is the honest statement of the trade:

- **What is gone.** The engine no longer refuses a duplicate sequence.
- **What still holds.** `Ledger\StreamGate` reads the tail of the stream under a lock and the ledger
  assigns the next sequence itself; `sentinel:verify` re-derives every hash and fails on a
  duplicate. The safety net is narrower, not absent.

The `capture_id` key is the same story. Under the queue and buffered modes a retry is made
idempotent by that unique index, with `Deduplicates::settled()` — a plain read — in front of it.
Widen the key and the read still works; what disappears is the engine as the last backstop for two
captures that straddle a partition boundary.

> 🔒 **Security.** If you need the engine itself to hold the chain's uniqueness, the only shipped
> option is `pgsql-tenant` on PostgreSQL. It is the one division where the unique indexes live on
> each partition rather than on the parent.

### Foreign keys

Nothing changes here, because there was never one to lose. No table in the schema carries a foreign
key to `sentinel_audits` — a cascade lives badly with date partitioning and with batched pruning,
and `Retention\Cascade` deletes labels and relation lines by sequence range instead. Do not add one
now: under a date division the referenced key is `(id, created_at)`, and a child row would have to
carry the parent's clock.

### The primary key, under `pgsql-tenant`

The tenant stub declares **no primary key**, only a plain index on `id`. A primary key would have to
include `tenant_id`, PostgreSQL promotes every primary-key column to `NOT NULL`, and an entry
recorded by a console command, a queue worker or the scheduler legitimately has no tenant — so the
write path would simply stop. Filling the column with a placeholder is worse: `tenant_id` is inside
the canonical hashed payload, so an empty string where the hash was sealed over `null` makes the
entry fail its own `verifyIntegrity()`.

The consequence to know: `id` uniqueness across partitions rests entirely on ULID generation, not on
the engine.

---

## What gets faster, what gets slower

### Slower: every write, on PostgreSQL

The hash of an entry covers its `sequence` and its `previous_hash`, so no `INSERT` can compute its
own link. `Ledger\StreamGate::tail()` reads the last row of the stream first:

```sql
select sequence, hash from sentinel_audits
 where stream = ? order by sequence desc limit 1 for update
```

Nothing in `where stream = ?` tells the planner which partition holds the highest sequence, so
PostgreSQL plans a `Merge Append` over every one of them. That planning is paid on **every single
write**:

| Table | Planning | Execution |
|---|---|---|
| 41 partitions | 13.4 ms | 1.0 ms |
| 1 partition | 0.58 ms | 0.05 ms |

Twenty-three times the planning, for a read whose execution barely moved. It shows up end to end:

| Engine | 1M flat | 10M flat | 1M partitioned | 10M partitioned |
|---|---|---|---|---|
| PostgreSQL 16 | 2.47 ms | 2.20 ms | 3.13 ms | 8.34 ms |
| MySQL 9 | 1.93 ms | 1.97 ms | 2.08 ms | 3.06 ms |

This is the reason `--ahead` is measured in months and `--retire` is worth setting: the cost is the
*number* of partitions, not the size of the table.

### Slower: reads that do not name the partitioning column

| Read | Under a date division | Under `pgsql-tenant` |
|---|---|---|
| `Sentinel::audits()->between($from, $to)` | **Pruned** — `between()` bounds `created_at` | Not pruned |
| `Sentinel::audits()->forTenant('acme')` | Not pruned | **Pruned** — `where tenant_id = ?` |
| `Ledger::find($id)` | Scans every partition; there is no unique index on `id` alone | Scans every partition |
| `stream()->range()`, `sentinel:verify` | Merges every partition, ordered by `(stream, sequence)` | One partition per stream |
| `whereSubject()`, `whereActor()`, `whereEvent()`, `whereTag()` | Local index per partition, then merged | Local index per partition, then merged |

The walk itself stays correct — the suite spreads a stream over four months and asserts the walk
still yields `1, 2, 3, 4, 5, 6, 7, 8` in order — it just reads from more places.

> 💡 **Tip.** `between()` deliberately bounds `created_at` and not `occurred_at`. That is the
> partition key of both range stubs and the clock retention counts from, so a window on the fact's
> own clock would not line up with the one a prune works in. See
> [Filters reference](../06-reading/02-filters-reference.md).

### Faster: letting a range go

Removing roughly 260 000 entries:

| Engine | Rows in table | `DELETE` by range | `DROP PARTITION` |
|---|---|---|---|
| PostgreSQL 16 | 1M | 424 ms | 1 031 ms |
| PostgreSQL 16 | 10M | 1 997 ms | 24 ms |
| MySQL 9 | 1M | 16 698 ms | 22 ms |
| MySQL 9 | 10M | 59 029 ms | 71 ms |

The MySQL row at ten million entries is the single strongest argument for dividing a MySQL trail:
fifty-nine seconds against seventy-one milliseconds. Note the PostgreSQL row at one million, where
the drop costs more than the delete — a catalogue operation takes locks, and when the `DELETE` is
already cheap there is nothing to win.

---

## Choosing the key and the window

**If you are multi-tenant and on PostgreSQL, partition by tenant.** It is the only division that
gives up nothing, and it gives up nothing for one specific reason: with `integrity.stream` set to
`tenant` — which is the shipped default — `Integrity\Stream` resolves an entry's stream to
`tenant:<id>`, so every entry of a stream lands in exactly one partition and a per-partition
`unique (stream, sequence)` *is* the guarantee a flat table had.

> ⚠️ **Warning.** That equivalence depends entirely on the stream following the tenant. Set
> `integrity.stream` to `global` under `pgsql-tenant` and one stream spans every partition, so the
> per-partition unique index guards nothing. Read [Streams](../07-integrity/02-streams.md) and
> [Multi-tenancy](../04-context/04-multi-tenancy.md) before choosing.

**Otherwise partition by month.** `created_at` is the clock the ledger settled the entry on, it is
strictly the axis retention works in, and it is the only column a range filter can prune on.

For the window, the arithmetic runs the other way from most capacity planning. You are not sizing
partitions to be small; you are keeping their **count** low, because the count is what the planner
pays on every write. Six months ahead and a retirement period behind is a settled installation of
seven to eight partitions. Three years ahead is thirty-seven, and the tail read pays for all of them.

---

## Installing a partitioned trail

On a **new** installation, before the first entry:

```bash
php artisan vendor:publish --tag=sentinel-partitioned-pgsql-range
php artisan migrate
```

The `pgsql-range` and `mysql-range` stubs pre-create **this month and the three after it**, counted
from the clock at the moment `migrate` runs, plus the catch-all. If you publish today and migrate
next quarter, the pre-created months are behind you and everything lands in the catch-all until
`sentinel:partitions` runs — so run it right after `migrate`, always.

### Adding a tenant partition by hand

`sentinel:partitions` maintains monthly ranges and cannot invent the name of a tenant, so under
`pgsql-tenant` every partition after the first two is yours to create — **with its two unique
indexes**, which is the entire point of that division:

```php
use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Support\Facades\DB;

/** @var Config $config */
$config = app(Config::class);

$table = $config->table('audits');            // sentinel_audits, prefix included
$connection = DB::connection($config->connection());
$tenant = 'acme';
$partition = "{$table}_{$tenant}";

$connection->statement("create table {$partition} partition of {$table} for values in ('{$tenant}')");
$connection->statement("create unique index {$partition}_stream_sequence on {$partition} (stream, sequence)");
$connection->statement("create unique index {$partition}_capture on {$partition} (capture_id)");
```

A tenant with no partition of its own is not an error: it lands in `sentinel_audits_default`, which
the stub created *with* both unique indexes. Giving a tenant its own partition is a retirement and
locality decision, not a correctness one.

> ⚠️ **Warning.** PostgreSQL scans the default partition before it accepts a new one, to prove no
> row already there belongs in it. Create a tenant's partition early, or expect the scan to grow
> with the default.

---

## Keeping partitions supplied

`sentinel:partitions` is the published surface of the whole `Partitions` namespace. It is
idempotent by construction: what should exist comes from the clock (`Partitions\Calendar`), what
does exist comes from the engine's catalogue (`Partitions\Grammar::partitions()`), and only the
difference is issued.

```bash
php artisan sentinel:partitions --ahead=6 --retire="18 months"
```

| Option | Default | What it does |
|---|---|---|
| `--table=` | `audits` | A **config key**, not a table name. Only `audits` and `access_log` are accepted; anything else exits `2`. The real name is resolved through `sentinel.tables`. |
| `--ahead=` | `3` | How many months *beyond* this one to have ready. `--ahead=3` means four partitions: this month and three more. A non-numeric value silently falls back to `3`. |
| `--retire=` | none | A span such as `18 months` or `P18M`. Without it, nothing is ever retired. A relative date (`next tuesday`) is refused, not guessed at. |
| `--force` | off | Retire a partition that still holds entries. Ignored under compliance mode. |
| `--dry-run` | off | Report what a run would do and issue no statement. |

| Exit code | Meaning |
|---|---|
| `0` | Maintained, or there was nothing to maintain — including a table that is not partitioned. |
| `1` | A refusal: at least one partition behind the cutoff was kept. Nothing was removed. |
| `2` | The run could not happen: an unknown `--table`, an unreadable `--retire`, or any engine error. |

### How it decides

- **Create.** `Calendar::ahead()` names this month and the next `--ahead`, comparing by the name it
  would have written. The current month is included on purpose: a first run against a table created
  before you started partitioning must not leave the write path broken until next month.
- **Retire.** `Calendar::behind()` selects partitions whose **whole month** ended before the cutoff.
  A month that merely *started* before the cutoff still holds entries the policy keeps, and a
  partition is retired whole or not at all. A catch-all is never a candidate — it has no end, and
  dropping the floor of a divided table is not maintenance.
- **Refuse.** `Maintainer::refusal()` answers in this order: an empty partition always goes; under
  compliance mode an occupied one never goes, whatever `--force` says; otherwise `--force` decides.

> 📌 **Note.** Partition ranges are read out of the **name**, matched against `/p(\d{4})_(\d{2})$/`,
> never out of the engine's own bounds. A partition named anything else — `pmax`,
> `sentinel_audits_default`, `sentinel_audits_acme`, `archive_2026_09` — parses as a catch-all: it
> is never retired, and the correctly named one will be created beside it. This is deliberate, so
> maintenance only ever touches partitions it would have created itself.

### The scheduled job

The package registers nothing on your scheduler. Add it yourself:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('sentinel:prune --action=archive')->dailyAt('03:00');
Schedule::command('sentinel:partitions --ahead=6 --retire="18 months"')->monthlyOn(1, '04:00');
```

Order matters and is covered in [Partitioning and pruning together](#partitioning-and-pruning-together).
See [Scheduling](../09-operations/07-scheduling.md) for the rest of the calendar.

---

## Running out of partitions

There are two failure modes and they look nothing like each other.

**With the catch-all in place — the shipped state.** Nothing fails. Writes whose `created_at` falls
outside every declared month land in `sentinel_audits_default` (PostgreSQL) or `pmax` (MySQL). The
suite asserts exactly this: an entry stamped `2099-06-01` is accepted. The symptom is silent and
slow — one partition swelling while the others stay small, and eventually:

- **On PostgreSQL**, creating the next month gets slower every run, because PostgreSQL scans the
  default partition to prove nothing in it belongs in the new range.
- **On MySQL**, worse. A month is added by
  `alter table … reorganize partition pmax into (…)`, which **rewrites everything `pmax` currently
  holds**. A cron forgotten for a year does not merely degrade; the catch-up run is expensive and
  takes the table with it.

**With the catch-all removed.** An insert outside every declared range fails, and it fails inside
your application's write path, on a business operation that had nothing to do with auditing. Never
drop `sentinel_audits_default` or `pmax`. The maintenance command will not do it for you — both
parse as catch-alls and are excluded from retirement.

### Catching it before it happens

```bash
# What the next run would create. Changes nothing.
php artisan sentinel:partitions --ahead=6 --dry-run
```

```sql
-- PostgreSQL: how much has fallen through. Should be 0.
select count(*) from sentinel_audits_default;

-- MySQL: the same question, asked of the parent.
select count(*) from sentinel_audits partition (pmax);
```

Alert on either count being non-zero. That is the same statement `Partitions\Grammar::count()`
issues, and a non-zero answer means writes have already stopped being routed where you intended.

---

## Partitioning the access log

`sentinel_access_log` grows with **reads**, not with writes, and in compliance mode a busy trail can
outgrow the audit table. No stub ships for it, because it is a projection — the evidence for a read
is an entry in `sentinel_audits` with `audit_type` `access`, chained and hashed like any other — so
dividing it is cheap and losing a range of it costs nothing hashed.

Partition it by hand, then let the same command keep it supplied:

```sql
create table sentinel_access_log (
    id          char(26)     not null,
    audit_id    char(26)     not null,
    actor_type  varchar(255),
    actor_id    varchar(64),
    tenant_id   varchar(64),
    query       jsonb        not null,
    results     integer      not null,
    context     jsonb        not null,
    created_at  timestamp(6) without time zone not null,
    primary key (id, created_at)
) partition by range (created_at);

create index sentinel_access_log_actor on sentinel_access_log (actor_type, actor_id, created_at);
create index sentinel_access_log_audit on sentinel_access_log (audit_id);
create table sentinel_access_log_default partition of sentinel_access_log default;
```

```bash
php artisan sentinel:partitions --table=access_log --ahead=6 --retire="12 months"
```

The partitions must be named `sentinel_access_log_pYYYY_MM` for the command to recognise them, which
is what `--ahead` will create. Replacing the flat table drops its rows: do it in a window, or copy
them across first.

---

## Converting a table that already has entries

The package converts nothing. Turning a table that already holds a chain into a partitioned one is a
maintenance window with a real chance of losing rows, and it is not a decision a package makes for
you. The stubs are for a new installation.

If you are converting anyway, this is the shape of it on PostgreSQL 16, where an existing plain table
can be *attached* as a partition. MySQL has no attach, so step 3 copies instead.

**0 — Prove what you have.** Do not start from a chain that does not verify; whatever is wrong will
still be wrong afterwards and you will have a conversion to blame it on.

```bash
php artisan sentinel:verify
```

**1 — Publish the stub, and do not migrate.** Read the file it writes: it creates the partitioned
table under the name yours already has, which is not yet what you want.

**2 — Build the partitioned table beside the old one, under a temporary name.** Copy the stub's
`up()` and change the table it names. Give it the same primary key and the same two unique keys the
stub declares — under a date division those carry `created_at`.

**3 — Attach or copy.**

```sql
-- The constraint is what lets ATTACH skip a full validation scan.
alter table sentinel_audits add constraint sentinel_audits_range
    check (created_at >= '2020-01-01' and created_at < '2026-09-01') not valid;
alter table sentinel_audits validate constraint sentinel_audits_range;

alter table sentinel_audits_new attach partition sentinel_audits
    for values from ('2020-01-01') to ('2026-09-01');
```

On MySQL: `insert into sentinel_audits_new select * from sentinel_audits`, in ranges if it is large.

**4 — Swap the names, with nothing writing.**

```sql
alter table sentinel_audits rename to sentinel_audits_old;   -- if it was not attached
alter table sentinel_audits_new rename to sentinel_audits;
```

**5 — Prove it again, then supply it.**

```bash
php artisan sentinel:verify
php artisan sentinel:partitions --ahead=6
```

**The rollback point is step 4.** Until the rename, the live table is untouched and the way back is
to drop the new one. After it, keep the old table until the verification has passed and you have
slept on it.

Nothing in a partitioned trail is *stored* differently — only in a different place — so the copy is
byte-identical in every hashed column and `id` values do not change. Rows in `sentinel_archives`,
`sentinel_checkpoints` and `sentinel_access_log` that name an entry still name it. **The way back**
is the same procedure inverted: build a plain table with the base migration's shape, copy the
partitions into it, verify, swap.

---

## Partitioning and pruning together

This is where the division pays. `sentinel:prune` never drops a partition — `Retention\Cascade`
deletes by sequence range in batches of `prune.batch`, inside a transaction per slice, taking the
entry's labels and relation lines with it. `sentinel:partitions --retire` reclaims the empty shell
afterwards. Two commands, in this order:

```bash
php artisan sentinel:prune --action=archive          # writes the range out, proves it, removes it
php artisan sentinel:partitions --retire="18 months" # drops what is now empty
```

Run them the other way round and the second one refuses, correctly, with exit `1`:

```
Kept  sentinel_audits_p2024_11  Still holds entries. Archive them with sentinel:prune,
                                or pass --force to drop the range anyway.
```

Under compliance mode that refusal is unconditional and `--force` does not lift it — the message
changes to name the reason, and the exit code is still `1`. A range may not leave without a copy of
it existing somewhere first. See [Compliance mode](../08-lifecycle/05-compliance-mode.md).

> 📌 **Note.** A month partition only empties once **every** stream's entries in that month have
> been released and pruned, because retention policies are keyed by audit type or subject class and
> the prune deletes by `(stream, sequence)` range. One long-lived policy on one subject class keeps
> a whole partition occupied. `--dry-run` will show you which.

> ⚠️ **Warning.** `--force` on an occupied partition removes a range of the trail as a catalogue
> operation: nothing is archived, and nothing is written to `sentinel_archives` to account for the
> gap. `sentinel:verify` will then meet an absence in the chain with no licence for it. Use it only
> on a range you have already proved you do not need.

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `migrate` created a flat table although you published a stub | The published file was renamed, so `PackageMigrations` no longer matches it and the package loaded its own | Keep the stub's file name exactly as published |
| `migrate` fails: `sentinel_audits` already exists | Same cause, seen from the other side — both files ran | Restore the published file's name, roll back, migrate again |
| `sentinel:partitions` exits `2`: `Nothing was maintained: …` on a tenant table | The maintainer sees `_untenanted`/`_default`, decides the table is divided, then issues a RANGE bound against a LIST parent | Do not run it against `pgsql-tenant`. Create tenant partitions by hand, with their two unique indexes |
| `The table [sentinel_audits] is not partitioned` on a table you partitioned | SQLite; or the catalogue found no children — wrong connection, wrong `sentinel.tables` name, or a PostgreSQL table in a schema the query did not reach | Check `sentinel.database.connection` and `sentinel.tables`; on SQLite there is nothing to maintain |
| Partitions of a same-named table in another schema are maintained too | `Grammar::partitions()` matches `pg_class.relname` with no `pg_namespace` filter | Do not keep two `sentinel_audits` tables in one database |
| Exit `1`, `Refused to retire`, nothing removed | Partitions behind the cutoff still hold entries | `sentinel:prune --action=archive` first, then retire |
| `--force` still refuses, message mentions compliance | `Maintainer::refusal()` checks compliance mode **before** `--force` | Archive the range; the refusal is unconditional by design |
| Writes got several times slower after partitioning | The tail read plans a `Merge Append` across every partition — 13.4 ms planning at 41 partitions against 0.58 ms at one | Lower `--ahead`, set `--retire`, keep the partition count in single digits |
| A duplicate `(stream, sequence)` exists and the engine accepted it | Under a date division the key gained `created_at`, so only an exact three-column match is refused | Nothing to fix in the schema; this is the trade. `sentinel:verify` is the detector |
| `sentinel:partitions` created `sentinel_audits_p2026_09` beside your `sentinel_audits_2026_09` | Ranges are read from the name via `/p(\d{4})_(\d{2})$/`; a name that does not match parses as a catch-all | Name hand-made monthly partitions the way the command would |
| MySQL: `Nothing was maintained` when adding a month | The catch-all must be named exactly `pmax`; `reorganize partition pmax` fails otherwise | Recreate the catch-all as `pmax` |
| `--ahead=six` quietly behaved as `--ahead=3` | `ReadsOptions::number()` returns `null` for a non-numeric option and the command defaults to `3` | Pass digits |
| Raw partition DDL names the wrong table | Every raw statement builds its name from `Config::table()` alone and does not apply a connection-level `prefix` | Rename with `sentinel.tables.prefix`, never with a `prefix` on the audit connection |
| The catch-all keeps growing | The stubs pre-create four months from the clock at `migrate` time and nothing else runs on its own | Schedule `sentinel:partitions`, and alert on the catch-all's row count |

---

## ✅ Best practices

✅ **Do** — decide before the first entry, publish exactly one stub, and run the maintenance command
straight after `migrate`. The stubs pre-create four months from the clock at migration time; nothing
extends that on its own.

```bash
php artisan vendor:publish --tag=sentinel-partitioned-pgsql-range
php artisan migrate
php artisan sentinel:partitions --ahead=6
```

❌ **Don't** — publish two stubs to "compare them". They land under the same file name, so the second
silently overwrites the first and the directory no longer records which division you chose.

```bash
php artisan vendor:publish --tag=sentinel-partitioned-pgsql-range
php artisan vendor:publish --tag=sentinel-partitioned-pgsql-tenant   # overwrote the first
```

✅ **Do** — keep `integrity.stream` following the tenant when you choose `pgsql-tenant`. That is the
only reason the per-partition `unique (stream, sequence)` equals the guarantee a flat table had.

```php
// config/sentinel.php
'integrity' => [
    'stream' => 'tenant',   // the shipped default; an entry resolves to stream tenant:<id>
],
```

❌ **Don't** — partition by tenant and then scope the chain globally. One stream would span every
partition, and a unique index local to one partition guards nothing.

```php
'integrity' => [
    'stream' => 'global',   // every partition holds part of one stream: the index is decorative
],
```

✅ **Do** — keep `--ahead` in months and always give `--retire` a span, so the partition count
settles instead of growing with the age of the installation.

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('sentinel:partitions --ahead=6 --retire="18 months"')->monthlyOn(1, '04:00');
```

❌ **Don't** — create years of partitions "to be safe". The per-write cost is planning the tail read
across every partition; forty-one partitions already cost twenty-three times the planning of one.

```bash
php artisan sentinel:partitions --ahead=60   # five years of Merge Append on every write
```

✅ **Do** — archive before you retire, and let the refusal stop you when you have the order wrong.

```bash
php artisan sentinel:prune --action=archive
php artisan sentinel:partitions --retire="18 months"
```

❌ **Don't** — reach for `--force` to get past a refusal. It removes a range of the trail as a
catalogue operation, with nothing archived and no row in `sentinel_archives` to account for the gap
the verification will meet.

```bash
php artisan sentinel:partitions --retire="18 months" --force   # a hole in the chain, unrecorded
```

✅ **Do** — create both unique indexes with every tenant partition you add by hand. Nothing creates
them for you, and without them that tenant loses exactly what the stub exists to keep.

```php
$connection->statement("create table {$partition} partition of {$table} for values in ('{$tenant}')");
$connection->statement("create unique index {$partition}_stream_sequence on {$partition} (stream, sequence)");
$connection->statement("create unique index {$partition}_capture on {$partition} (capture_id)");
```

❌ **Don't** — create the partition alone and assume the parent's indexes apply. A unique key on the
parent would have to carry `tenant_id`, which is the whole thing this division avoids.

```php
$connection->statement("create table {$partition} partition of {$table} for values in ('{$tenant}')");
// and nothing else — this tenant's chain is now unguarded by the engine
```

✅ **Do** — rename tables through `sentinel.tables`, so the raw partition DDL and the Blueprint agree
on the name.

```php
'tables' => ['prefix' => 'audit_', 'audits' => 'entries'],   // -> audit_entries, everywhere
```

❌ **Don't** — set a `prefix` on the audit connection in `config/database.php`. The compiled
`create table` goes through the grammar and gets the prefix; every raw partition statement builds its
name from `Config::table()` and does not.

```php
// config/database.php — the partition DDL will not see this
'connections' => ['audits' => [/* … */ 'prefix' => 'audit_']],
```

---

**See also:** [Choosing an engine](01-choosing-an-engine.md) · [PostgreSQL](02-postgresql.md) ·
[MySQL](03-mysql.md) · [Indexes and JSON](05-indexes-and-json.md) ·
[Scaling playbook](08-scaling-playbook.md) ·
[Retention and pruning](../08-lifecycle/01-retention-and-pruning.md) ·
[Cold archiving](../08-lifecycle/02-cold-archiving.md) ·
[Compliance mode](../08-lifecycle/05-compliance-mode.md) ·
[Monitoring and troubleshooting](../09-operations/08-monitoring-and-troubleshooting.md) ·
[Streams](../07-integrity/02-streams.md) · [Verification](../07-integrity/06-verification.md) ·
[Multi-tenancy](../04-context/04-multi-tenancy.md) ·
[Artisan commands](../09-operations/06-artisan-commands.md) ·
[Scheduling](../09-operations/07-scheduling.md) ·
[Schema](../99-reference/03-schema.md) · [Exit codes](../99-reference/07-exit-codes.md)
