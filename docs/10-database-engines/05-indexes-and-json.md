# 🐘 Indexes and JSON

> Every index the package creates, the question it answers, and the filter that reaches it — then
> the JSON half: what lives inside `context`, which two filters read into it, and the opt-in
> migration that turns them from a scan into a seek.

**On this page:** [What ships indexed](#what-ships-indexed) ·
[The two indexes that are not for reading](#the-two-indexes-that-are-not-for-reading) ·
[The companion tables](#the-companion-tables) ·
[The order is part of the index](#the-order-is-part-of-the-index) ·
[The seven JSON columns](#the-seven-json-columns) ·
[The two filters that read inside `context`](#the-two-filters-that-read-inside-context) ·
[The json-indexes stub](#the-json-indexes-stub) ·
[`whereIp()` and `whereRoute()` at volume](#whereip-and-whereroute-at-volume) ·
[Filters no index serves](#filters-no-index-serves) ·
[Reading a query plan](#reading-a-query-plan) ·
[Adding an index of your own](#adding-an-index-of-your-own) ·
[⚠️ Pitfalls](#-pitfalls) · [✅ Best practices](#-best-practices)

---

## What ships indexed

`sentinel_audits` is created whole: forty columns and **thirteen non-primary indexes**, and the
package never `ALTER`s it in a later version. Eleven of those indexes come from the base migration
(`database/migrations/…_create_sentinel_audits_table.php`, which asks `Support\AuditSchema` for the
column and index list); two more come from a separate additive migration,
`…_add_occurrence_indexes_to_sentinel_audits_table.php`. The count is a test —
`tests/Database/AuditsTableTest.php` asserts thirteen, over exactly these columns, with the primary
key filtered out first because SQLite materialises a non-integer primary key as its own
`sqlite_autoindex`.

| Index | Columns | What it serves | Filter that reaches it |
|---|---|---|---|
| unique | `stream`, `sequence` | The tail read on every write; ordering a chain walk | — (chain, not a filter) |
| unique | `capture_id` | Idempotency of a retried capture | — (retry path) |
| index | `subject_type`, `subject_id`, `id` | The history of one record, in write order | `for()` / `forModel()` |
| index | `actor_type`, `actor_id`, `id` | What one person did | `by()` / `byActor()` |
| index | `tenant_id`, `created_at` | One tenant's trail, by the ledger clock | `forTenant()` |
| index | `transaction_id` | Every entry of one business operation | `inTransaction()` |
| index | `request_id` | Every entry of one HTTP request | **none** — see below |
| index | `trace_id` | Every entry under one W3C trace | `withTrace()` |
| index | `audit_type`, `created_at` | One kind of entry, by the ledger clock | `whereType()` |
| index | `event` | Entries named by one event | `whereEvent()` |
| index | `severity`, `created_at` | Everything critical, by the ledger clock | `whereSeverity()` |
| index | `occurred_at`, `id` | The timeline of the whole trail | ordering under `byOccurrence()` |
| index | `subject_type`, `subject_id`, `occurred_at`, `id` | The timeline of one record | `Sentinel::timeline()->for(…)` |

> 📌 **Note.** `request_id` is indexed and **no published filter reads it**. There is no
> `whereRequest()`; `Enums\Filter` has nineteen cases and none of them is the request. The index is
> there for a direct query on the model — `Audit::query()->where('request_id', $id)` — which is the
> one correlation the Query API does not publish. See [Execution
> context](../04-context/01-execution-context.md).

Which index a planner actually picks is its business, and it moves between versions of the same
engine. The package's plan tests (`tests/Query/QueryPlanTest.php`) assert that the engine **seeks
rather than walks**, never which index answered — a gate that named an index would pin a planner
version instead of the filter's cost.

---

## The two indexes that are not for reading

Two of the thirteen exist for the write path, and they are the two you must not drop.

**`unique(stream, sequence)`** is read by `Ledger\StreamGate::tail()` before every single entry:

```sql
select "sequence", "hash" from "sentinel_audits"
where "stream" = ? order by "sequence" desc limit 1 for update
```

The hash covers the sequence and the link, so no `INSERT` can compute its own position — the tail
has to be read first. That makes this the busiest index in the schema: one seek per entry written,
plus the uniqueness that is the last arbiter of two writers racing for the same sequence. See
[The hash chain](../07-integrity/01-the-hash-chain.md).

**`unique(capture_id)`** is what makes a retried capture idempotent under the queue and buffered
modes: the second attempt violates it instead of writing the same fact twice. See
[Running audits on a queue](../09-operations/03-queues.md).

> ⚠️ **Warning.** Under a date-partitioned table both uniques gain `created_at` and stop being
> enforced *across* partitions — both MySQL and PostgreSQL require every unique key of a partitioned
> table to carry the partitioning column. What still holds the chain there is the ledger's own
> sequence assignment and `sentinel:verify`. [Partitioning](06-partitioning.md) spells out the trade.

Neither index is part of the canonical payload. Adding or dropping an index changes no hash and does
not bump `payload_version` — `tests/Database/ContextIndexesTest.php` pins exactly that for the
shipped JSON index ("changes no hash it indexes over"). `sequence`, `hash` and `previous_hash` are
chain-bearing columns; an index over them is not.

---

## The companion tables

Six smaller tables travel beside the entries. None carries a foreign key to `sentinel_audits`, on
purpose: a cascade lives badly with date partitioning and batched pruning.

| Table | Indexes | The question they answer |
|---|---|---|
| `sentinel_audit_tags` | unique(`audit_id`, `tag`) · index(`tag`, `audit_id`) | The labels of an entry; and, reversed, which entries carry a label — `whereTag()` / `whereAnyTag()` |
| `sentinel_audit_relations` | index(`audit_id`) · index(`related_type`, `related_id`, `audit_id`) · index(`relation`, `audit_id`) | The lines of an entry; who was related; which relation — `whereRelated()` / `whereRelation()` |
| `sentinel_transactions` | index(`name`, `started_at`) · index(`started_at`) · index(`tenant_id`, `started_at`) | Operations by name, by when they ran, by tenant |
| `sentinel_checkpoints` | unique(`stream`, `sequence_from`) · index(`stream`, `sequence_to`) | The last anchor of a stream, and the one that ends where the next begins |
| `sentinel_archives` | index(`stream`, `sequence_from`) · index(`stream`, `sequence_to`) | Which range left the hot table, and where the next question carries on from |
| `sentinel_access_log` | index(`actor_type`, `actor_id`, `created_at`) · index(`audit_id`) | What one person has been reading, and when |

Two things those rows do **not** say. `sentinel_archives` has no date axis and no subject axis, so an
erasure request over one person's history is answered range by range rather than by lookup — see
[Redaction and tombstones](../08-lifecycle/04-redaction-and-tombstones.md). And neither the labels
table nor the relations table is evidence: both are indexable projections of lines that already live
inside the entry's hashed payload, so removing a row from either leaves `verifyIntegrity()` untouched.

---

## The order is part of the index

Every read of the trail is ordered by a clock and then by the entry's ULID —
`created_at, id` by default, `occurred_at, id` under `byOccurrence()` (`Ledger\DatabaseLedger::query()`).
That tie-break is why the shipped composites *end* in `id` or in a clock: an index that finds the
rows but cannot deliver them in that order leaves the engine sorting whatever it matched.

The two occurrence indexes exist for exactly that reason. Their migration says it plainly: measured
over two hundred thousand entries, ordering by `occurred_at` sorted outside every index on all three
engines, and narrowing first only shrank what got sorted. `(occurred_at, id)` removes the sort for the
whole trail; `(subject_type, subject_id, occurred_at, id)` removes it for the timeline of one record —
the shape that actually gets run.

> 📌 **Note.** `between()` bounds `created_at` even under `byOccurrence()` and `Sentinel::timeline()`.
> Narrowing and ordering follow different clocks on purpose: `created_at` is the partitioning column
> of both published range plans and the clock retention counts from. See
> [Order, paging and walking the trail](../06-reading/03-order-paging-and-walking.md).

---

## The seven JSON columns

`AuditSchema` declares seven of them with `$table->jsonb(…)`, which compiles to `jsonb` on
PostgreSQL, `json` on MySQL and `text` on SQLite:

| Column | Holds | Reachable from the Query API? |
|---|---|---|
| `context` | Everything a resolver returned that is not a promoted column | Yes — `whereIp()`, `whereRoute()` |
| `changes` | The diff entries, or the relation lines of a relation entry | Yes — `whereFieldChanged()`, and the `field()` scope |
| `before` / `after` | The two snapshots | **No** |
| `metadata` | What you attached to the entry | **No** |
| `criteria` | The criteria of a mass operation | **No** |
| `encryption` | The envelope describing what was encrypted | **No** |

`sentinel_audit_relations.pivot_before` / `pivot_after` are declared with `$table->json()`, not
`jsonb()` — plain `json` on PostgreSQL. Nothing filters into them either.

### What lands inside `context`

Nine names are promoted to columns of their own (`Context\ContextEngine::PROMOTED`): `actor_type`,
`actor_id`, `impersonator_type`, `impersonator_id`, `tenant_id`, `request_id`, `trace_id`, `span_id`,
`source`. **Every other key a resolver returns lands in the `context` JSON**, together with anything
you added through the manual execution context:

| Key | Written by |
|---|---|
| `ip`, `user_agent`, `url`, `route`, `method` | `Context\Resolvers\RequestResolver` |
| `hostname`, `environment` | `HostResolver` |
| `session_id` | `SessionResolver` |
| `command`, `arguments` | `CommandResolver` |
| `job`, `queue`, `attempts`, `batch_id` | `JobResolver` |
| `service_name`, `tracestate` | `TraceResolver` |

`route` is the route's **name**, or its `uri()` when the route has none. Full list and semantics in
[The ten resolvers](../04-context/02-resolvers-reference.md).

> ⚠️ **Warning.** Neither MySQL `json` nor PostgreSQL `jsonb` preserves the key order you wrote.
> Values round-trip intact, order does not — which is the whole reason the chain canonicalises
> (RFC 8785) before hashing. See [Canonicalization](../07-integrity/03-canonicalization.md).

---

## The two filters that read inside `context`

`whereIp()` and `whereRoute()` are the only published filters that reach into a JSON column by key.
Both are exact and case-sensitive on all three engines, and both are compiled by one class,
`Ledger\ContextPredicate`, from the `Enums\Filter` case's own value — so the key interpolated into
the SQL is a literal the enum declares, never a caller's string.

| Engine | The reading `ContextPredicate::expression()` produces |
|---|---|
| PostgreSQL | `"context"->>'ip'` |
| MySQL | ``json_unquote(json_extract(`context`, '$.ip'))`` |
| SQLite | `json_extract(case when json_valid("context") then "context" else '{}' end, '$.ip')` |
| anything else | throws `LedgerException::cannotTranslateOn` naming the driver |

Two engine details are load-bearing.

> 🐘 **Engine.** **MySQL emits the comparison twice.** Its default collation
> (`utf8mb4_0900_ai_ci`) is case- and accent-insensitive, so `= 'invoices.show'` alone would answer
> there with entries PostgreSQL and SQLite would not. `ContextPredicate::for()` emits
> `reads = ? and reads collate utf8mb4_bin = ?`: the insensitive clause matches a superset an index
> can serve, the binary clause rechecks and decides. A binary comparison on its own would be correct
> and would lose the index — a column indexed under one collation cannot serve a comparison under
> another.

> 🐘 **Engine.** **SQLite guards the column before reading it.** `$table->jsonb()` gives SQLite a
> bare `text` column with no `CHECK`, and `json_extract` over unparseable content aborts a statement
> that `PDOStatement::fetchAll()` — which Laravel's `Connection::select()` uses — then answers with a
> **partial result and no exception**. The `json_valid(...)` guard is what stops one poisoned row
> making an audit read quietly incomplete. `Ledger\ChangedFieldPredicate` carries the same guard for
> `changes`.

Without the opt-in migration below, both filters are correct and both **scan**:
`tests/Query/QueryPlanTest.php` asserts that each of them alone reaches no index, while a filter
placed in front of it does.

---

## The json-indexes stub

The index behind those two filters is published, not shipped:

```bash
php artisan vendor:publish --tag=sentinel-json-indexes
php artisan migrate
```

It is never loaded automatically. The package's own migrations are loaded from
`database/migrations`; this file lives in `database/stubs/json-indexes` and reaches an application
only when someone asks for it. `php artisan sentinel:install` names the tag among its six optional
publishes and does nothing else about it.

**What it creates, per engine:**

| Engine | What it adds | Why that shape |
|---|---|---|
| PostgreSQL 16 | `create index sentinel_audits_context_ip_index on sentinel_audits (("context"->>'ip'))`, and the same for `route` | A B-tree over the expression. The doubled parentheses are required: PostgreSQL reads a single pair as a column list and refuses the operator inside it |
| SQLite | The same statement with SQLite's guarded `json_extract` reading | The extra parentheses are harmless here |
| MySQL 9 | Two `varchar(255)` generated columns, `context_ip` and `context_route`, **VIRTUAL** and **INVISIBLE**, each with a hand-named index | MySQL cannot index a bare expression the query writes. `STORED` would rewrite the table and widen every row; a *visible* generated column would ride along in `select *`, and the next thing that re-inserted that entry — a fanout, a rehydration — would be handing MySQL a value for a column it computes itself, which it refuses |

The expression is **not written in the migration**. It calls `ContextPredicate::expression()` — the
same object the driver asks when it compiles the filter. Two copies of a JSON path is an index that
silently stops being used the day one of them is edited.

### What it costs

| Measurement | Figure | Provenance |
|---|---|---|
| Write cost at the engine, PostgreSQL 16 | 200 000 writes: 10 303 ms without it, 11 880 ms with it (**+15.3 %**) | One-off comparison against a table with the shape the published migrations create |
| Write cost at the engine, MySQL 9 | **+21 %** | Same comparison |
| Write cost end to end through the package | between **−5.4 %** and **+7.2 %** — noise | `make bench-volume`, which publishes the stub mid-run and measures writes either side of it |
| Adding the generated columns to an existing MySQL table | `INSTANT`, ~300 ms over 100 000 rows | Same run |

Both write figures are true and they answer different questions. The engine figure is what the index
costs. The end-to-end figure is that the index is not what a Sentinel write pays for: the pipeline,
the canonicalisation and the hash are. Full context in the
[Scaling playbook](08-scaling-playbook.md).

### What it does not do

- **It adds nothing over `changes`.** `whereFieldChanged()` is a correlated `EXISTS` over the array,
  and no index in this package serves it on any engine.
- **There is no GIN, and that is measured.** Over `context`, a GIN costs roughly eight points more per
  write than the expression B-tree (12 845 ms in the same 200 000-write comparison) and 22 MB against
  the B-tree's 5.7 MB, to serve the one plan this API publishes *worse* — `Bitmap Heap Scan` at
  1.18 ms against `Index Scan` at 0.067 ms. Over `changes`, the plan measured with a GIN present is
  still a sequential scan. *Provenance: a one-off comparison on PostgreSQL 16 over a ten-million-row
  table, September 2026. No target in this repository rebuilds it — every other number on this page
  is reproducible.*
- **It does not change an answer.** `tests/Database/ContextIndexesTest.php` asserts the same entries
  come back before and after, that `down()` leaves the column listing exactly as it found it, that
  the frozen v0.3.0 entries still verify, and that no hash moves.

> 🧪 **Verify it.** After publishing, ask the planner — and give it statistics first, which is what
> the package's own test does before re-reading the plan:
> ```sql
> analyze sentinel_audits;        -- PostgreSQL; `analyze table sentinel_audits` on MySQL, `analyze` on SQLite
> ```

---

## `whereIp()` and `whereRoute()` at volume

An index changes how rows are *found*. It does not change how many rows a filter matches, and a
filter that names a **category** matches a lot of them — then the order costs.

`make bench-volume` at ten million entries, taking fifty, with the JSON index published, on
PostgreSQL 16 and MySQL 9 (an i7-12700KF, 20 threads, 15 GB, both engines on disk with `fsync` off):

| Filter | PostgreSQL flat | PostgreSQL partitioned | MySQL flat | MySQL partitioned |
|---|---|---|---|---|
| `whereRoute()` | 113.4 ms | 88.2 ms | **5 118 ms** | 183.5 ms |
| `whereEvent()` | 301.7 ms | 57.7 ms | **32 589 ms** | 2 460 ms |

The seed spreads routes over three hundred values, so one route is roughly thirty-three thousand
entries out of ten million. The index finds them; sorting them is what you are paying for. That is
the same shape `whereEvent()` has, and it wants the same treatment: put an indexed, *selective*
filter in front of it — a subject, an actor, a tenant, a transaction.

> ⚠️ **Warning.** There is **no published figure for `whereIp()` at volume that survives review**.
> The run those numbers came from used a seed in which one address matched 154 rows out of ten
> million — `benchmarks/volume.php` now says so in its own comment and spreads addresses over four
> hundred values, and no run of the corrected harness has been published. Read an address the way
> you read a route: it is selective only if it is actually rare in *your* trail. Behind a NAT or a
> gateway it is a category like any other.

---

## Filters no index serves

These are the filters the package calls **refiners**. Each narrows a result; none finds one. Used
alone, each of them walks the trail on purpose, and `tests/Query/QueryPlanTest.php` asserts it.

| Filter | Why no index reaches it | Put this in front |
|---|---|---|
| `whereSource()` | Nine possible values, and `source` is not indexed | Any filter above |
| `between()` | `created_at` is only ever the tail of a composite, never its head | A subject, tenant, type or severity |
| `whereFieldChanged()` | A correlated `EXISTS` over the `changes` array, per-engine JSON functions | A subject, above all |
| `whereVersion()` | `version` is not indexed | `for()` — a version only means anything for one subject |
| `whereOperation()` | No index of the relations table begins with `operation` | `whereRelation()` or `whereRelated()` |
| `whereIp()` / `whereRoute()` | Inside `context` — until you publish the stub above | A tenant or a period |

Two more that reach an index and still deserve the warning, because they name a **category** rather
than an entity: `whereEvent()` and `whereType('model')`. They find by index and then sort most of the
table.

> 🐘 **Engine.** SQLite is the one engine that will skip-scan an index that is not about the filter:
> `between()` alone reads *an* index there, where MySQL and PostgreSQL walk
> the table. The suite asserts that difference rather than pretending the three engines agree — which
> is also why you should never conclude anything about production plans from a SQLite run.

Full method-by-method reference in [Filters reference](../06-reading/02-filters-reference.md).

---

## Reading a query plan

The Query API takes no column names and hands back no Eloquent builder, so there is nothing to call
`->toSql()` on. Capture the statement as it runs, then explain it — this is what the package's own
test helper (`planFor()` in `tests/helpers.php`) does:

```php
use ElPandaPe\Sentinel\Facades\Sentinel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;

$captured = null;

DB::listen(function (QueryExecuted $query) use (&$captured): void {
    // The read of the trail itself, not the eager load of the labels behind it.
    if ($captured === null && str_contains($query->sql, 'sentinel_audits')) {
        $captured = [$query->sql, $query->bindings];
    }
});

Sentinel::audits()->forTenant('acme')->whereRoute('invoices.show')->take(50)->get();

[$sql, $bindings] = $captured;

dump(DB::select('explain '.$sql, $bindings));
```

| Engine | Command | A walk reads | A sort reads |
|---|---|---|---|
| PostgreSQL | `explain <sql>` → column `QUERY PLAN`; add `analyze, buffers` for real timings | `Seq Scan on sentinel_audits` | `Sort` |
| MySQL 9 | `explain <sql>` → column `EXPLAIN` (the tree format; `explain format=tree` asks for it explicitly) | `Table scan on sentinel_audits` | `Sort:` |
| SQLite | `explain query plan <sql>` → column `detail` | `SCAN sentinel_audits` | `USE TEMP B-TREE FOR …` |

> 📌 **Note.** SQLite prints `SCAN` for a walk and `SEARCH` for a seek, and prints `USING INDEX` for
> **both**. Matching on the index name rather than the verb calls a whole pass over the trail an
> index read.

> 🧪 **Verify it.** `make bench-volume ENGINE=pgsql ROWS=10000000` prints, after the filter table, a
> section headed *what the indexes weigh* — every index of the trail with its size in MB, read from
> `pg_stat_user_indexes` or `mysql.innodb_index_stats`. It is the only honest input to a decision
> about dropping one.

---

## Adding an index of your own

You may add indexes. Two rules keep you out of trouble, and both are about the *migration*, not the
index.

**1. Do not collide with a package migration's name.** `Support\PackageMigrations` decides, per file,
whether to load the package's copy: it strips the `YYYY_MM_DD_HHMMSS_` prefix and asks whether the
application's `database/migrations` already holds a file ending in that name. A migration of yours
called `…_create_sentinel_audits_table.php` makes the package stop shipping its own table. These
eight names are taken:

```
create_sentinel_audits_table              create_sentinel_transactions_table
create_sentinel_audit_tags_table          create_sentinel_checkpoints_table
create_sentinel_audit_relations_table     create_sentinel_archives_table
add_occurrence_indexes_to_sentinel_audits_table
create_sentinel_access_log_table
```

**2. Resolve the table and the connection through `Support\Config`.** The table name is
`tables.prefix` + `tables.audits`, and the connection is `database.connection` — both are the
installation's to choose:

```php
// database/migrations/2026_10_01_000000_add_event_clock_index_to_audits.php
use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection($this->getConnection())->table($this->table(), function (Blueprint $table): void {
            $table->index(['event', 'created_at', 'id'], $this->name('event_clock'));
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->table($this->table(), function (Blueprint $table): void {
            $table->dropIndex($this->name('event_clock'));
        });
    }

    public function getConnection(): ?string
    {
        return $this->config()->connection();
    }

    private function name(string $suffix): string
    {
        return "{$this->table()}_{$suffix}_index";
    }

    private function table(): string
    {
        return $this->config()->table('audits');
    }

    private function config(): Config
    {
        /** @var Config $config */
        $config = app(Config::class);

        return $config;
    }
};
```

That index is the same argument the shipped occurrence indexes are built on: the trail is ordered by
a clock and then by `id`, so an index that ends in `created_at, id` can deliver the order instead of
leaving the engine to sort. Whether it is worth its write cost on *your* trail is a plan you measure,
not a promise this page makes. Hand-name anything over two columns: Laravel's generated name runs
long, and the table prefix in front of it is yours.

Three things to know before you run it:

- **An index over ten million rows is a maintenance window.** Use `CONCURRENTLY` on PostgreSQL (which
  means raw SQL and no wrapping transaction), or `ALGORITHM=INPLACE` on MySQL.
- **Raw DDL ignores a connection-level prefix.** Every statement this package pins around the
  Blueprint — the partition DDL, the JSON index — interpolates `Config::table()` alone. Rename tables
  with `sentinel.tables.*`, never with a `prefix` on the audit connection. No test in the suite covers
  a prefixed connection.
- **Do not add a column.** The schema rule is that a column a future version will write exists today;
  seven of the forty were born empty for versions that landed much later. A column you add to
  `sentinel_audits` is yours forever, and the table is the one that grows without bound.

Under a partitioned table, add the index the same way and check what the engine did with it:
PostgreSQL creates a partitioned index that new partitions inherit; MySQL rejects any *unique* key
that does not carry the partitioning column (`ERROR 1503`). See [Partitioning](06-partitioning.md).

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| Published the JSON index, and the plan still shows a scan | The planner has no statistics for the new expression or generated column | `analyze sentinel_audits` on PostgreSQL, `analyze table …` on MySQL, `analyze` on SQLite — the package's own test does this before re-reading the plan |
| `select *` on MySQL does not show `context_ip` / `context_route` | The generated columns are `INVISIBLE` by design, so a re-read entry does not carry them into a fanout or rehydration insert MySQL would refuse | Nothing to fix. Name the column explicitly if you want to see it |
| `whereRoute()` is fast on PostgreSQL and seconds on MySQL at volume | Not the index: a route names a category, and the sort over what it matched is the cost | Put a selective filter in front, or narrow the period |
| A read that used an index at ten thousand rows walks the table at ten million | Both MySQL and PostgreSQL are cost-based, and a plan verified on a small table is not a plan | Re-measure on production volume; never infer a production plan from SQLite |
| Your own migration ran, and the package's `sentinel_audits` migration silently disappeared | `PackageMigrations` matches by file name with the timestamp stripped, so a name-collision replaces the package's copy | Rename your migration to anything not on the list of eight above |
| Renamed the audit tables with a `prefix` on the connection, and the JSON index migration failed or indexed the wrong table | Raw DDL is built from `Config::table()` and never sees a connection prefix | Use `sentinel.tables.prefix` / `tables.*` instead |
| A read on SQLite came back with fewer entries than it should, and raised nothing | A raw JSON predicate of your own with no `json_valid` guard: `json_extract` over unparseable text aborts a statement `fetchAll()` then answers partially | Guard the column the way `ContextPredicate` and `ChangedFieldPredicate` do |
| `whereFieldChanged('members')` finds nothing after a `sync()` | A relation is not an attribute; a relation entry's lines live in `changes` as lines, not as diff paths | Use `whereRelation()` / `whereRelated()` — see [Relationship auditing](../03-capture/04-relationships.md) |
| Dropped the two occurrence indexes to save write cost, and the timeline got slow | No other index of the table is ordered by `occurred_at`, so that order sorts outside all of them — measured over two hundred thousand entries on all three engines | Put them back, or stop using `byOccurrence()` / `Sentinel::timeline()` |
| Added a unique index of your own on a partitioned MySQL table and got `ERROR 1503` | MySQL partitions are not tables; every unique key must carry the partitioning column | Make it non-unique, or move to PostgreSQL, where a unique index local to one partition is allowed |

---

## ✅ Best practices

✅ **Do** — publish `sentinel-json-indexes` only when you actually filter by address or route, and
run `ANALYZE` afterwards. Both filters answer correctly either way; what the index buys is the seek.

```bash
php artisan vendor:publish --tag=sentinel-json-indexes
php artisan migrate
```

❌ **Don't** — publish it "for completeness" on an installation that never asks those two questions.
It is 15 % per write on PostgreSQL 16 and 21 % on MySQL 9 at the engine, paid on every entry forever,
for a plan nobody runs.

```bash
# published on day one, used never
php artisan vendor:publish --tag=sentinel-json-indexes
```

---

✅ **Do** — put an indexed, selective filter in front of every refiner. Behind a subject, an actor or
a tenant, a refiner costs almost nothing.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

Sentinel::audits()
    ->for($invoice)                       // the index does the finding
    ->whereFieldChanged('total')          // the refiner does the narrowing
    ->take(50)
    ->get();
```

❌ **Don't** — run a refiner alone on a table that grows. This walks ten million rows and evaluates a
JSON predicate on every one of them.

```php
Sentinel::audits()->whereFieldChanged('total')->take(50)->get();
```

---

✅ **Do** — resolve the table name and the connection through `Support\Config` in any migration of
your own, exactly as the package's do.

```php
use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Support\Facades\Schema;

$config = app(Config::class);

Schema::connection($config->connection())->table($config->table('audits'), $definition);
```

❌ **Don't** — hard-code the table, or rename tables with a `prefix` on the audit connection. The
package's raw partition and index DDL builds names from the config alone and will miss it.

```php
Schema::table('sentinel_audits', $definition);   // wrong table on any installation that renamed one
```

---

✅ **Do** — name your own migration so that, with the timestamp stripped, it collides with none of the
eight the package ships.

```
2026_10_01_000000_add_event_clock_index_to_audits.php
```

❌ **Don't** — reuse a package migration's name. `PackageMigrations` reads that as "the application
has taken this file over" and stops loading the package's own — which for the audits table means the
chain's two unique keys never get created.

```
2026_10_01_000000_create_sentinel_audits_table.php
```

---

✅ **Do** — measure the plan on the engine you run in production, with the statement the driver
actually issues, captured through `DB::listen`.

```php
DB::listen(fn (QueryExecuted $q) => $captured ??= [$q->sql, $q->bindings]);
```

❌ **Don't** — reason about production from a SQLite plan. SQLite skip-scans an index that is not
about the filter and commits to the occurrence index where the other two are cost-based; the suite
asserts that difference rather than hiding it.

```php
// green on SQLite, a full pass on MySQL 9
Sentinel::audits()->between($from, $to)->take(500)->get();
```

---

✅ **Do** — leave `unique(stream, sequence)` and `unique(capture_id)` alone, and treat any change to
them as a change to the chain. The first is read once per entry written; the second is what makes a
retry idempotent.

```sql
-- what every write does before it can hash anything
select "sequence", "hash" from "sentinel_audits" where "stream" = ? order by "sequence" desc limit 1;
```

❌ **Don't** — drop or redefine either to speed up writes. Under a date partition they already degrade
to per-partition, and that degradation is documented rather than chosen — see
[Partitioning](06-partitioning.md).

```sql
drop index sentinel_audits_capture_id_unique;   -- retries now write the same fact twice
```

---

**See also:** [Choosing an engine](01-choosing-an-engine.md) · [PostgreSQL](02-postgresql.md) ·
[MySQL](03-mysql.md) · [SQLite](04-sqlite.md) · [Partitioning](06-partitioning.md) ·
[Scaling playbook](08-scaling-playbook.md) ·
[Filters reference](../06-reading/02-filters-reference.md) ·
[The Query API](../06-reading/01-the-query-api.md) ·
[Field history and comparing versions](../06-reading/04-field-history.md) ·
[The timeline](../06-reading/05-the-timeline.md) ·
[The ten resolvers](../04-context/02-resolvers-reference.md) ·
[Schema](../99-reference/03-schema.md) · [Configuration](../99-reference/02-configuration.md)
