# 🐘 PostgreSQL

> What the schema compiles to on PostgreSQL, the two things the package does there and nowhere else,
> and the operational work — indexes, partitions, vacuum, pooling — that is yours rather than the
> package's.

**On this page:** [Why it is the recommended engine](#why-it-is-the-recommended-engine) ·
[What the schema compiles to](#what-the-schema-compiles-to) ·
[The 9.4 floor](#the-94-floor) · [JSON: what is indexed](#json-what-is-indexed-and-what-is-not) ·
[The GIN that is not there](#the-gin-index-that-is-deliberately-not-there) ·
[Partitioning](#partitioning) · [How a sequence is assigned](#how-a-sequence-is-assigned) ·
[Placeholder limits](#placeholder-limits) · [Vacuum and bloat](#vacuum-and-bloat-after-a-prune) ·
[Connections and pooling](#connections-and-pooling) · [Tuning checklist](#tuning-checklist) ·
[⚠️ Pitfalls](#️-pitfalls) · [✅ Best practices](#-best-practices)

---

## Why it is the recommended engine

Sentinel runs its suite against SQLite, MySQL 9 and PostgreSQL 16 on every push. Only PostgreSQL
gives all four of the following at once, and each one is a line of code rather than a preference:

| What you get | Where it lives | What the other engines do |
|---|---|---|
| A unique index that lives on **one partition** | `database/stubs/partitioned/pgsql-tenant/…create_sentinel_audits_table.php` | MySQL rejects it — `ERROR 1503` — because its partitions are not tables |
| An **explicit** write lock for a stream that has no rows yet | `Ledger\StreamGate` — `select pg_advisory_xact_lock(hashtext(?))` | MySQL relies on the InnoDB gap lock; SQLite ignores `lockForUpdate()` and serialises writers database-wide |
| The cheapest published JSON path — a B-tree over an expression | `database/stubs/json-indexes/…add_context_indexes…php` | MySQL needs a generated column; SQLite needs a `json_valid` guard |
| A range delete at volume measured in **seconds**, not minutes | `Retention\Cascade` | See [Choosing an engine](01-choosing-an-engine.md) for the comparison |

> 📌 **Note.** "Recommended" is not "required". Every invariant — the chain, the canonical payload,
> `payload_version`, the ordering by `(stream, sequence)` — holds identically on all three engines,
> and the same golden chain of frozen entries is re-verified on each. What changes is cost, and what
> the engine still enforces for you once the table is divided.

---

## What the schema compiles to

`Support\AuditSchema` declares the table once, in Laravel's schema builder, and both the base
migration and the two PostgreSQL partitioning stubs ask it for the same forty columns. This is what
the PostgreSQL grammar emits for them:

| Blueprint call | PostgreSQL type | Columns |
|---|---|---|
| `char($c, 26)` | `char(26)` | `id`, `transaction_id`, `capture_id`, `source_audit_id` |
| `char($c, 64)` | `char(64)` | `hash`, `previous_hash`, `redacted_hash` |
| `string($c, n)` | `varchar(n)` | `stream` (64), `severity` (8), `source` (16), `trace_id` (32), `span_id` (16), … |
| `string($c)` | `varchar(255)` | `subject_type`, `actor_type`, `impersonator_type`, `redaction_reason` |
| `unsignedBigInteger` | `bigint` | `sequence`, `affected_rows` |
| `unsignedInteger` | `integer` | `version` |
| `unsignedSmallInteger` | `smallint` | `payload_version` |
| `jsonb` | `jsonb` | `context`, `before`, `after`, `changes`, `metadata`, `encryption`, `criteria` |
| `text` | `text` | `signature` |
| `dateTime($c, 6)` | `timestamp(6) without time zone` | `occurred_at`, `created_at`, `redacted_at` |

Three of those rows carry a consequence.

**PostgreSQL has no unsigned integers**, so `sequence` lands as a signed `bigint` and no check
constraint against negatives is created. Not a limit anybody reaches; worth knowing it is signed.

**The clocks carry no offset.** `timestamp(6) without time zone` stores exactly what Laravel hands
it, formatted by `Models\Audit::getDateFormat()` as `Y-m-d H:i:s.u`. Whatever `app.timezone` was at
write time is baked into the digits and nothing in the row records which zone that was. Changing
`app.timezone` after entries exist silently reinterprets every old row, and the reinterpretation is
invisible to the chain — `occurred_at` and `created_at` are inside the canonical payload as strings,
so the hash still verifies while the meaning has moved.

**`char(n)` is blank-padded on PostgreSQL and only on PostgreSQL.** MySQL and SQLite trim the padding
on read. Every identifier the package mints is exactly the declared width — a ULID is 26 characters,
a SHA-256 hex digest is 64 — so this never bites the package's own writes. It bites hand-written
fixtures, imports and tooling that puts a short value into `capture_id` or `source_audit_id`: it
comes back space-padded and compares unequal to what you wrote.

> 🧪 **Verify it.** After `php artisan migrate`, ask the catalogue rather than trusting this table:
>
> ```sql
> select column_name, data_type, character_maximum_length, datetime_precision
> from information_schema.columns
> where table_name = 'sentinel_audits'
> order by ordinal_position;
> ```

The base migration also creates a primary key on `id` and two unique keys — `(stream, sequence)` and
`capture_id`. Counting the two uniques and the two the occurrence migration adds, a flat installation
ends with **thirteen non-primary indexes**, pinned by name and column order in
`tests/Database/AuditsTableTest.php`. Nothing in the schema is `ALTER`ed by a later minor: columns a
future version will write are already there and empty. See [Schema](../99-reference/03-schema.md) for
the column-by-column reference.

---

## The 9.4 floor

The package is run on PostgreSQL 16. The SQL it emits needs **9.4**, which is where `jsonb` and
`jsonb_array_elements` arrive. Two predicates are what set that floor:

`Ledger\ContextPredicate` reads a context key with the `->>` operator:

```sql
"sentinel_audits"."context"->>'ip' = ?
```

`Ledger\ChangedFieldPredicate` answers "this entry touched that field" by walking the `changes`
array, guarded so it never raises over a non-array:

```sql
exists (
  select 1
  from jsonb_array_elements(
    case when jsonb_typeof("sentinel_audits"."changes") = 'array'
         then "sentinel_audits"."changes"
         else '[]'::jsonb end
  ) e
  where e->>'path' = ?
     or substr(e->>'path', 1, length(?)) = ?
)
```

The guard is not decoration: `jsonb_array_elements` raises on anything that is not an array, and one
unparseable row would abort a read of the whole trail. The `substr` comparison rather than a `LIKE`
is also deliberate — `LIKE` is case-sensitive on PostgreSQL and ASCII case-insensitive on the other
two, so the three engines would not answer with the same entries.

> 📌 **Note.** Only `mysql`, `pgsql` and `sqlite` have a dialect. Anything else — MariaDB included —
> makes `whereFieldChanged()`, `whereIp()` and `whereRoute()` throw
> `LedgerException::cannotTranslateOn` naming the driver, rather than guessing at an answer.

---

## JSON: what is indexed, and what is not

Seven columns are `jsonb`. **None of them carries an index in the shipped schema.** That is the
default and it is a decision: an index over a JSON column that changes on every row is the most
expensive thing you can put on a write path, and the only two published filters that read inside JSON
are `whereIp()` and `whereRoute()`.

Without an index those two still work — they refine by scanning. With one they seek. The index is an
opt-in migration:

```bash
php artisan vendor:publish --tag=sentinel-json-indexes
php artisan migrate
```

On PostgreSQL it creates two expression B-trees:

```sql
create index sentinel_audits_context_ip_index    on sentinel_audits (("context"->>'ip'));
create index sentinel_audits_context_route_index on sentinel_audits (("context"->>'route'));
```

Two details in that statement matter. The parentheses are **doubled** because PostgreSQL reads a
single pair as a column list and refuses the operator inside it. And the expression is not written in
the migration at all — it asks `Ledger\ContextPredicate::expression()`, the same object the query
compiles through, because two copies of a JSON path is an index that stops being used the day one of
them is edited.

The measured cost at the engine, over 200 000 writes on PostgreSQL 16 against a table with this
schema's forty columns and its indexes: **10 303 ms without, 11 880 ms with — +15.3 %**. That is why
it is published rather than loaded. An installation that never asks where an entry came from should
not pay it. End to end through the package the same change lands inside benchmark noise — a Sentinel
write is dominated by the pipeline and the hash, not by the `INSERT`.

> 💡 **Tip.** On PostgreSQL the migration is purely additive and reversible — `down()` is two
> `drop index` statements, and tests assert the column list is unchanged afterwards and every hash
> still verifies. Publishing it later costs nothing you cannot undo.

`before` and `after` get no index and never will: they are read by `id` and never filtered.

---

## The GIN index that is deliberately not there

A GIN index over `context` is the obvious thing to reach for and the package measured it before
deciding against it. Same table, same 200 000 writes on PostgreSQL 16:

| Indexes present | Write cost | Plan for the published equality | Size on `context` |
|---|---|---|---|
| Today's thirteen | 10 303 ms | `Seq Scan` | — |
| \+ two expression B-trees | 11 880 ms (+15.3 %) | `Index Scan`, 0.067 ms | 5.7 MB |
| \+ a GIN over `context` | 12 845 ms (+24.7 %) | `Bitmap Heap Scan`, 1.18 ms | 22 MB |

Eight points more per write and four times the space, to serve the one plan this API publishes about
seventeen times slower. Over `changes` a GIN is worse than useless: `whereFieldChanged()` compiles to
a correlated `EXISTS` over `jsonb_array_elements`, and with a GIN present the measured plan is still
a `Seq Scan` — an index that nothing ever touches.

> ⚠️ **Warning — provenance.** Those four figures come from a one-off comparison on PostgreSQL 16
> over a ten-million-row table, run in September 2026. **No `make` target rebuilds it.** Every other
> number on this page is reproducible; this one is a decision not to ship something, and it is
> recorded here so you can disagree with it knowingly.

**If you want one anyway**, nothing in the package stops you. Write your own migration — do not edit
the published stub, which the package's own tests and the `down()` path assume:

```php
use Illuminate\Support\Facades\DB;

DB::statement("create index concurrently sentinel_audits_context_gin on sentinel_audits using gin (context jsonb_path_ops)");
```

Two things to know before you do. `create index concurrently` cannot run inside a transaction, so the
migration needs `public $withinTransaction = false;`. And the Query API will not use it: the shipped
predicate is an equality over `->>`, which no GIN opclass serves. A GIN here only pays off for queries
**you** write against the trail with containment — `@>` under `jsonb_path_ops`, plus the key-existence
operators under the default `jsonb_ops` — outside `Sentinel::audits()`.

---

## Partitioning

Two of the three partitioning stubs are PostgreSQL's. Both **replace** the base migration rather than
following it — the published file carries the same name the package's own does, and
`Support\PackageMigrations` stops offering its own the moment it sees that name in the application's
migrations directory.

| | `sentinel-partitioned-pgsql-range` | `sentinel-partitioned-pgsql-tenant` |
|---|---|---|
| Clause | `partition by range (created_at)` | `partition by list (tenant_id)` |
| Primary key | `(id, created_at)` | **none** — a plain index on `id` |
| `unique (stream, sequence)` | Becomes `(stream, sequence, created_at)`, enforced **per month only** | Stays exactly that, created **on each partition** |
| `unique (capture_id)` | Becomes `(capture_id, created_at)` | Stays, per partition |
| Created by the migration | This month plus three, plus a `_default` | `_untenanted` (`for values in (null)`) and `_default` |
| Maintained by `sentinel:partitions` | Yes | **No — it fails** |

```bash
# On a NEW installation, before the first entry:
php artisan vendor:publish --tag=sentinel-partitioned-pgsql-range
php artisan migrate
```

### Why the tenant stub has no primary key

Putting `tenant_id` into the primary key works on paper and breaks twice. PostgreSQL promotes every
primary-key column to `NOT NULL`, so an entry recorded with no tenant — a console command, a queue
worker, the scheduler — would stop being writable. Filling it with a placeholder is worse:
**`tenant_id` is inside the canonical payload**, so an empty string where the hash was sealed over
`null` makes the entry fail its own verification. See [The hash chain](../07-integrity/01-the-hash-chain.md).

So the unique keys live on each partition instead — which PostgreSQL allows and MySQL does not — and
with `integrity.stream` on `'tenant'` (which is the shipped default), every entry of a stream lands in
exactly one partition. A per-partition `unique (stream, sequence)` is then precisely the guarantee the
flat table had.

Every partition you add later needs both indexes, and nothing creates them for you:

```php
use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Support\Facades\DB;

/** @var Config $config */
$config = app(Config::class);

$table = $config->table('audits');
$connection = DB::connection($config->connection());
$partition = "{$table}_acme";

$connection->statement("create table {$partition} partition of {$table} for values in ('acme')");
$connection->statement("create unique index {$partition}_stream_sequence on {$partition} (stream, sequence)");
$connection->statement("create unique index {$partition}_capture on {$partition} (capture_id)");
```

### The DEFAULT partition is not optional

Under `pgsql-range` an insert whose `created_at` falls outside every declared range **fails**, and
that failure surfaces in the application's write path. `_default` is what turns a forgotten cron into
one fat partition instead of a broken write. It has a cost of its own: attaching a new range to a
table whose default already holds rows makes PostgreSQL scan the default first.

### Keeping months supplied

```bash
php artisan sentinel:partitions --table=audits --ahead=6 --retire="18 months"
```

`--ahead=3` means this month **and** three more — four partitions. Partitions are named
`<table>_pYYYY_MM`, and `Partitions\Partition` reads a partition's range out of its **name**, never
out of the engine's bounds. Anything named otherwise (`_default`, `archive_2026_09`, `p2026_9`)
parses as a catch-all: never retired, and the correctly-named one is happily created beside it.

`--retire` drops a partition only when its month ended before the cutoff **and** it holds no rows.
`--force` lifts the "occupied" refusal — except under compliance mode, where the refusal is
unconditional. The working order is always archive first, retire second:

```bash
php artisan sentinel:prune --action=archive
php artisan sentinel:partitions --table=audits --retire="18 months"
```

Exit codes: `0` success (including "this table is not partitioned"), `1` a partition was kept, `2` a
run that could not happen. See [Exit codes](../99-reference/07-exit-codes.md).

### What partitioning actually costs on PostgreSQL

It is not storage and it is not `INSERT` routing. **It is planning, on every write.** Every write
reads the tail of its stream — the hash covers the sequence and the link, so no `INSERT` can compute
its own — and `where stream = ?` tells the planner nothing about which partition holds the highest
sequence. So it plans a `Merge Append` over all of them.

Measured with `make bench-volume` on an i7-12700KF, 20 threads, 15 GB, engine on disk with fsync off:

| Per write | 1 M entries | 10 M entries |
|---|---|---|
| Flat | 2.47 ms | 2.20 ms |
| Partitioned (41 partitions) | 3.13 ms | **8.34 ms** |

The tail read alone at 41 partitions: **13.4 ms planning against 0.58 ms** over a single partition.

> ⚠️ **Warning.** Partition to make *retirement* cheap, not to make writes fast. Volume by itself
> costs almost nothing — 2.20 ms per write at ten million rows against 2.47 ms at one million. Keep
> `--ahead` in months, never years, and always give `--retire` a period so the count settles.

`pg_partman` is a supported alternative where you can install it: it replaces `sentinel:partitions`,
not the stub. Full detail on all three divisions is in [Partitioning](06-partitioning.md).

---

## How a sequence is assigned

Sequence assignment is the one place the package writes PostgreSQL-specific SQL on the hot path.

`Ledger\DatabaseLedger::chain()` opens a transaction; inside it, `Ledger\StreamGate::tail()` runs:

```sql
select pg_advisory_xact_lock(hashtext(?));                      -- pgsql only, bound to the stream name
select "sequence", "hash" from "sentinel_audits"
 where "stream" = ? order by "sequence" desc limit 1 for update;
```

The advisory lock exists because **PostgreSQL takes no row lock on a row that does not exist yet**.
`for update` covers a stream that already has entries; it covers nothing at all for the first write
of a brand-new stream, which is exactly the moment two writers would both read "sequence 0" and both
try to insert 1. `Integrity\CheckpointGate` takes the same lock under a `checkpoint:` prefix for the
same reason on the anchor table.

Four consequences worth knowing:

1. **The lock is transaction-scoped.** `pg_advisory_xact_lock` is released at commit or rollback —
   there is no unlock call anywhere in the package, and none is needed.
2. **It serialises writers of one stream, not of the table.** A writer of another stream passes
   straight through; there is a test asserting exactly that.
3. **It only binds writers who ask for it.** A rival that inserts into `sentinel_audits` directly,
   without going through the ledger, is not held — the package's test for this on PostgreSQL asserts
   that the ledger takes the *next* sequence after such a writer, rather than that the writer was
   blocked. MySQL's gap lock happens to catch that case; PostgreSQL does not.
4. **`hashtext` is a 32-bit hash.** Two different stream names can hash to the same value and then
   serialise against each other. That costs concurrency and never correctness — the tail read and the
   unique index still decide the sequence.

The last line of defence is the unique index. `DatabaseLedger::attempt()` catches
`UniqueConstraintViolationException`, asks which captures already settled, and makes at most three
attempts before it propagates. Under a date-partitioned table that index is per-partition, so the engine is no longer the
backstop across months — the ledger's own assignment and `sentinel:verify` are.

> 🧪 **Verify it.** With a write in flight, from a second session:
>
> ```sql
> select locktype, objid, granted from pg_locks where locktype = 'advisory';
> ```

The concurrency tests live in `tests/Ledger/ConcurrencyTest.php`; they skip on an in-memory SQLite
database, where a second connection is a different database, and run under `make test-pgsql`.

---

## Placeholder limits

**PostgreSQL's own limit is 65 535** bound parameters per statement. The package caps at **32 766** —
SQLite's compile-time `SQLITE_MAX_VARIABLE_NUMBER` — because one batch size for three engines has to
be the narrowest one, so roughly half of PostgreSQL's headroom goes unused. That is the accepted
price.

The constant, the divisor and the resulting rows per statement are derived once, on
[SQLite](04-sqlite.md#the-placeholder-ceiling). The consequence on this page is that a batch arrives
as several `INSERT` statements, invisibly to the chain: sequences, hashes and links are computed
before any statement runs, and every statement of a batch is inside the one transaction, so a batch
divided in four still lands whole or not at all.

---

## Vacuum and bloat after a prune

**Pruning reclaims no disk space on any engine**, and `sentinel:prune` runs no `VACUUM` — the general
rule, and what each engine leaves behind, is on
[Retention and pruning](../08-lifecycle/01-retention-and-pruning.md#it-does-not-reclaim-disk-space).
What it leaves on PostgreSQL is **dead tuples**, and returning that space is a DBA operation with a
lock attached.

Start by measuring rather than guessing:

```sql
select relname,
       n_live_tup,
       n_dead_tup,
       last_vacuum, last_autovacuum,
       last_analyze, last_autoanalyze
from pg_stat_user_tables
where relname like 'sentinel_%';

select pg_size_pretty(pg_total_relation_size('sentinel_audits')) as total,
       pg_size_pretty(pg_relation_size('sentinel_audits'))       as heap,
       pg_size_pretty(pg_indexes_size('sentinel_audits'))        as indexes;
```

Then pick the smallest tool that does the job:

| Goal | Command | Lock it takes |
|---|---|---|
| Make dead space reusable, refresh stats | `vacuum (analyze) sentinel_audits;` | `SHARE UPDATE EXCLUSIVE` — concurrent reads and writes continue |
| Return space to the filesystem | `vacuum full sentinel_audits;` | `ACCESS EXCLUSIVE` for the whole run — nothing reads or writes the trail |
| Return space without the long exclusive lock | `pg_repack` (external extension, not shipped) | Brief exclusive locks at start and end |
| Return space with no rebuild at all | `php artisan sentinel:partitions --retire=…` | A `drop table` on one partition |

The fourth row is the point. A `DROP` of an empty partition took **24 ms** at ten million rows where
the equivalent `DELETE` of ~260 000 entries took **1 997 ms** — and the `DELETE` leaves dead tuples
that a `VACUUM FULL` then has to rewrite, while the `DROP` leaves nothing at all.

An append-only table also has a vacuum problem of its own: autovacuum's classic trigger counts *dead*
tuples, of which a table nobody updates has none, so the visibility map and statistics can go stale
for months and then a prune produces millions of dead tuples at once. PostgreSQL has had an
insert-driven trigger since 13; check what yours is set to rather than assuming, and consider tuning
the audit table specifically:

```sql
show autovacuum_vacuum_insert_threshold;
show autovacuum_vacuum_insert_scale_factor;

alter table sentinel_audits set (
  autovacuum_vacuum_scale_factor  = 0.01,
  autovacuum_vacuum_threshold     = 10000,
  autovacuum_analyze_scale_factor = 0.005
);
```

> ⚠️ **Warning.** Never schedule `vacuum full sentinel_audits` next to `sentinel:prune` in the same
> window. The prune exists to make room for writes; an `ACCESS EXCLUSIVE` rebuild of the one table
> every write in the application touches will block all of them for the duration.

More on what a prune does and does not remove: [Retention and pruning](../08-lifecycle/01-retention-and-pruning.md).

---

## Connections and pooling

Audits can live on their own connection. `database.connection` in `config/sentinel.php` is read by
every model and every package migration:

```php
// config/sentinel.php
'database' => [
    'connection' => env('SENTINEL_DB_CONNECTION', 'audits'),
],
```

Two consequences that are easy to miss, both documented in
[A database of its own](07-a-database-of-its-own.md): a cross-connection `whereHas` over audits stops
working, and the `after_commit` deferral still hangs off the **subject's** connection rather than the
audit one — so with `transactions.after_commit` turned off on a dedicated connection, audits survive
a business rollback.

### PgBouncer

What the package does, verifiable in `Ledger\DatabaseLedger` and `Ledger\StreamGate`:

- One chain write is **one transaction**, opened and closed inside `DatabaseLedger::chain()`.
- The stream lock is `pg_advisory_xact_lock`, released by that transaction's commit. There is no
  session-level `pg_advisory_lock` anywhere, and no `SET` that has to survive between transactions.

| PgBouncer `pool_mode` | Verdict |
|---|---|
| `session` | Works. Nothing needed. |
| `transaction` | Works for the locking, because the lock is transaction-scoped. See the prepared-statement note below. |
| `statement` | **Do not.** A multi-statement transaction cannot survive it, and every chain write is one. |

The one thing to check under `transaction` mode is prepared statements. Laravel runs every query
through `PDO::prepare()`, and older poolers reject the reuse of a server-side named statement across
pooled connections. Two levers, in order of preference: raise `max_prepared_statements` on the pooler
if your version supports it, or push the connection to emulate prepares in Laravel:

```php
// config/database.php
'audits' => [
    'driver' => 'pgsql',
    // …
    'options' => [
        PDO::ATTR_EMULATE_PREPARES => true,
    ],
],
```

> 📌 **Note.** Emulated prepares interpolate values client-side. Sentinel never puts caller input into
> SQL as text — every criterion arrives as a binding against a column the driver names itself, and the
> only string interpolated into the JSON predicates is a column name the grammar just escaped — so
> this changes performance characteristics, not the safety of the read path.

---

## Tuning checklist

For an audit table under sustained writes on PostgreSQL 16. Work down it; stop where the numbers say
you can.

| # | Check | Why |
|---|---|---|
| 1 | Leave the table flat until retirement hurts | Volume costs 2.20 ms/write at 10 M against 2.47 ms at 1 M; partitioning costs 8.34 ms |
| 2 | Do **not** publish `sentinel-json-indexes` unless you filter by `whereIp()`/`whereRoute()` | +15.3 % per `INSERT` at the engine, for a question you never ask |
| 3 | Add no index of your own that no published filter uses | Every index is paid on every write; `before`/`after` are never filtered |
| 4 | Keep the audit table off the same disk contention as the business tables where you can | Every business write produces one audit write |
| 5 | If you partition, size `--ahead` in months and schedule the command monthly | The partition count is a per-write planning cost |
| 6 | Give `--retire` a period, always | Without one nothing is ever retired and the count grows with the age of the install |
| 7 | Archive before you retire | `--retire` refuses an occupied partition, and under compliance mode `--force` cannot lift it |
| 8 | Tune autovacuum for this table, not globally | An append-only table then a bulk prune is the worst case for the default trigger |
| 9 | Confirm `analyze` has run after a bulk import | The `whereIp()`/`whereRoute()` index test itself runs `analyze` before re-checking the plan |
| 10 | Watch write latency, not table size | The two are only weakly related here — the number of partitions matters more than the number of rows |

Verify the plan for a filter you actually run before and after any change:

```sql
explain (analyze, buffers)
select * from sentinel_audits
where "context"->>'ip' = '203.0.113.7'
order by created_at, id
limit 50;
```

The full volume story, across both engines and both shapes, is in
[Scaling playbook](08-scaling-playbook.md).

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `sentinel:partitions` exits `2` with an engine error, on a healthy tenant-partitioned table | The maintainer sees `_untenanted` and `_default`, decides the table is divided, and issues a RANGE bound against a LIST parent | Do not run it against `pgsql-tenant`. Create tenant partitions by hand, with both unique indexes |
| A tenant's chain accepts a duplicate `(stream, sequence)` | A partition was added by hand without its two unique indexes — they live per partition and nothing creates them for you | Add both indexes, then `php artisan sentinel:verify --stream=acme` |
| `whereIp()` still scans after publishing the JSON-index stub | Statistics are stale, so the planner has not chosen the new index | `analyze sentinel_audits;` then re-`explain` |
| Writes got ~4× slower and nothing else changed | The partition count grew. Every write reads its stream's tail and plans a `Merge Append` across all partitions | Retire old months; keep `--ahead` in months |
| `insert … violates partition constraint` in the application's write path | A `pgsql-range` table with no `_default` partition, and a clock outside every declared month | Create the default partition; then schedule `sentinel:partitions` |
| A `capture_id` you wrote by hand never matches on lookup | `char(26)` is blank-padded on PostgreSQL; a 25-character value comes back with a trailing space | Write full-width values, or compare with a trim on both sides |
| Disk usage unchanged after a large prune | Dead tuples are reusable, not returned | `vacuum` for reuse, `vacuum full`/`pg_repack` to return it — or partition and drop |
| Partition maintenance touched a table you did not expect | The PostgreSQL catalogue query matches `pg_class.relname` with no schema filter | Do not keep two same-named audit tables in two schemas of one database |
| Old entries' timestamps shifted meaning after a deployment | `app.timezone` changed; `timestamp(6) without time zone` records no offset | Fix the application timezone and leave it fixed; the hash cannot detect this |
| `LedgerException: … cannot translate …` on a filter that works elsewhere | The connection is not `pgsql`/`mysql`/`sqlite` — MariaDB is refused by name | Use a supported engine for the audit connection |

---

## ✅ Best practices

✅ **Do** — decide about partitioning **before** the first entry, and publish exactly one stub. The
published file replaces the base migration by name, so the choice is made at install time.

```bash
php artisan vendor:publish --tag=sentinel-partitioned-pgsql-tenant
php artisan migrate
```

❌ **Don't** — publish two stubs, or rename a published one. They all land under
`…_create_sentinel_audits_table.php`; two publishes leave one file and no way to tell which, and a
rename makes the package load its own again so the migration runs twice.

```bash
php artisan vendor:publish --tag=sentinel-partitioned-pgsql-range
php artisan vendor:publish --tag=sentinel-partitioned-pgsql-tenant   # overwrote the first, silently
```

---

✅ **Do** — pair the tenant division with a tenant stream — the shipped default — so a per-partition
unique key is the same guarantee a flat table had. Confirm it before you migrate.

```php
// config/sentinel.php
'integrity' => [
    'stream' => 'tenant',   // every entry of a stream then lives in exactly one partition
],
```

❌ **Don't** — put `tenant_id` in a primary key or fill it with a placeholder to make one work.
PostgreSQL would promote it to `NOT NULL` and stop a command, a worker or the scheduler recording
anything; a placeholder changes a column inside the canonical payload and the entry fails its own
`verifyIntegrity()`.

```sql
alter table sentinel_audits alter column tenant_id set not null;   -- the write path is now broken
update sentinel_audits set tenant_id = '' where tenant_id is null; -- and these entries no longer verify
```

---

✅ **Do** — schedule partition maintenance yourself, with a retirement period. The package registers
nothing on your scheduler.

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('sentinel:partitions --ahead=6 --retire="18 months"')->monthly();
Schedule::command('sentinel:partitions --table=access_log --ahead=6')->monthly();
```

❌ **Don't** — create a year or two of partitions "to be safe". The count is a planning cost paid on
every single write, not a storage detail: at 41 partitions the tail read plans in 13.4 ms against
0.58 ms over one.

```bash
php artisan sentinel:partitions --ahead=36   # 37 partitions, paid on every audit write
```

---

✅ **Do** — publish the JSON-index stub only once you know you filter by address or route, and
`analyze` afterwards so the planner uses it.

```bash
php artisan vendor:publish --tag=sentinel-json-indexes
php artisan migrate
psql -c 'analyze sentinel_audits'
```

❌ **Don't** — reach for a GIN over `context` or `changes` instead. It costs eight points more per
write and four times the space to serve the published equality worse, and over `changes` the plan
with it present is still a sequential scan.

```sql
create index sentinel_audits_ctx_gin on sentinel_audits using gin (context);  -- 22 MB, and slower
```

---

✅ **Do** — let `sentinel:prune --action=archive` empty a partition and then retire the shell. That is
the cheap path to disk space on this engine.

```bash
php artisan sentinel:prune --action=archive
php artisan sentinel:partitions --table=audits --retire="18 months"
```

❌ **Don't** — bolt `VACUUM FULL` onto the prune schedule. It takes `ACCESS EXCLUSIVE` on the one
table every write in the application touches, for as long as the rebuild runs.

```bash
php artisan sentinel:prune && psql -c 'vacuum full sentinel_audits'   # nightly, blocking every write
```

---

✅ **Do** — run the pooler in `session` or `transaction` mode. The stream lock is
`pg_advisory_xact_lock`, scoped to the transaction the chain write already opens.

```ini
; pgbouncer.ini
pool_mode = transaction
max_prepared_statements = 200
```

❌ **Don't** — put the audit connection behind `statement` pooling. Every chain write is a
multi-statement transaction — advisory lock, tail read, inserts, label and relation projections — and
statement pooling cannot carry one.

```ini
pool_mode = statement   ; the ledger's transaction cannot survive this
```

---

✅ **Do** — keep the whole `tables` block when you publish `config/sentinel.php`, and rename tables
through it rather than through a connection-level `prefix`.

```php
'tables' => [
    'prefix' => 'audit_',
    'audits' => 'entries', 'audit_tags' => 'labels', 'audit_relations' => 'relations',
    'transactions' => 'operations', 'checkpoints' => 'checkpoints',
    'archives' => 'archives', 'access_log' => 'access_log',
],
```

❌ **Don't** — set `prefix` on the audits connection in `config/database.php`. The raw DDL the
partition stubs and `Partitions\Grammar` emit builds names from `Support\Config::table()` alone and
never sees a connection prefix, so the partitions would be created against a table name that does not
exist.

```php
'audits' => ['driver' => 'pgsql', 'prefix' => 'sentinel_'],   // the partition DDL ignores this
```

---

**See also:** [Choosing an engine](01-choosing-an-engine.md) · [MySQL](03-mysql.md) ·
[SQLite](04-sqlite.md) · [Indexes and JSON](05-indexes-and-json.md) ·
[Partitioning](06-partitioning.md) · [A database of its own](07-a-database-of-its-own.md) ·
[Scaling playbook](08-scaling-playbook.md) · [Schema](../99-reference/03-schema.md) ·
[Retention and pruning](../08-lifecycle/01-retention-and-pruning.md) ·
[Artisan commands](../09-operations/06-artisan-commands.md) ·
[The hash chain](../07-integrity/01-the-hash-chain.md) ·
[Filters reference](../06-reading/02-filters-reference.md)
