# 🐘 Choosing an engine

> Which database to put an audit trail on, what each of the three supported engines gives up, why
> MariaDB is refused by name, and what a move between engines costs once a chain has been written.

**On this page:** [The straight answer](#the-straight-answer) ·
[What "supported" means](#what-supported-means-here) · [MariaDB](#mariadb-is-refused-by-name) ·
[The capability matrix](#the-capability-matrix) · [JSON](#json-storage-and-indexing) ·
[JSON-path filters](#json-path-filters) · [Partitioning](#partitioning) ·
[Sequence assignment](#concurrency-on-sequence-assignment) · [Batch limits](#batch-limits) ·
[Full-text and GIN](#full-text-and-gin) · [Moving engines](#moving-engines-with-a-trail-already-written) ·
[Pitfalls](#-pitfalls) · [Best practices](#-best-practices)

---

## The straight answer

**Run a production trail on PostgreSQL 16.** Four mechanisms decide it, and none of them is a
benchmark:

1. **It is the only engine where a divided table keeps the chain's uniqueness.** PostgreSQL allows a
   unique index local to one partition, so the `pgsql-tenant` stub can put `unique (stream, sequence)`
   on every partition and lose nothing. MySQL's partitions are not tables: `ERROR 1503` rejects any
   unique key that does not carry the partitioning column, and there is no per-partition index to
   fall back on.
2. **It is the only engine where the first write of a brand-new stream is serialised on purpose.**
   `Ledger\StreamGate` issues `select pg_advisory_xact_lock(hashtext(?))` by stream name before it
   reads the tail, because PostgreSQL takes no row lock on a row that does not exist yet. On MySQL
   the same window is covered incidentally, by the InnoDB gap lock on the `lockForUpdate()` tail
   read. On SQLite the clause compiles to nothing at all.
3. **Its JSON path is the cheapest and the least invasive.** All seven JSON columns are `jsonb`;
   `whereIp()` and `whereRoute()` compile to `context->>'ip'`; the opt-in index is a plain B-tree
   over that expression. MySQL needs a generated column added to `sentinel_audits` and a second
   comparison clause for collation.
4. **Retiring a range is a catalogue operation.** `drop table` on a partition, against a batched
   `DELETE` on a flat one.

**MySQL 9 is a fully supported second choice**, and if the application already runs on it there is
usually no case for a second server. What you accept is a narrower safety net the day you partition
by date, a generated column on the audit table if you want the context filters indexed, and a
collation that forces the driver to emit two clauses where the other engines emit one.

**SQLite is supported, tested on every push, and not a production trail that grows.** It cannot
partition, `lockForUpdate()` is a no-op there, and it is the engine whose placeholder ceiling bounds
batch size for all three. It is the right choice for the test suite, for a single-process install and
for a trail that stays small.

> 📌 **Note.** Write throughput is not one of the four reasons above. On a flat table the two servers
> are close enough that the choice does not turn on it; the per-entry figures at one million and ten
> million entries are in the [Scaling playbook](08-scaling-playbook.md).

---

## What "supported" means here

Two different claims live in the same table, and only one of them is a support claim.

| Engine | Run on every push | What the emitted SQL needs |
|---|---|---|
| PostgreSQL | 16 | 9.4 — where `jsonb` and `jsonb_array_elements` arrive |
| MySQL | 9 | 8.0.4 — where `JSON_TABLE` arrives |
| SQLite | 3.45 | 3.38 — where JSON stops being a compile-time option |
| MariaDB | — | refused by name; see below |

The **middle column** is the support claim: `.github/workflows/run-tests.yml` runs the whole suite
against a real MySQL 9 and a real PostgreSQL 16 server on every push to `main` and every pull
request, and the SQLite matrix runs on PHP 8.4 and 8.5 at both `prefer-lowest` and `prefer-stable`.
The package does not declare compatibility it does not run.

The **right column** is where the constructs the driver emits first appeared. It is useful for
reading a failure on an older server, not a promise: nothing in CI runs a PostgreSQL 12 or a MySQL
8.0, so nothing proves the rest of the package works there.

> 🧪 **Verify it.** Every job of `run-tests.yml` prints the server version before the suite starts — SQLite via
> `sqlite_version()`, the other two via `PDO::ATTR_SERVER_VERSION` — so an engine-specific failure can
> be attributed from the log without rerunning it. Locally, `make test-dbs` runs the same suite
> against MySQL 9 and PostgreSQL 16 in parallel.

Redis appears in every one of those jobs and is **not** a ledger engine: the buffered performance mode
keeps entries there until a flush settles them, so the tests that cover it run against a real server
rather than a fake. See [Running audits on a queue](../09-operations/03-queues.md).

---

## MariaDB is refused by name

MariaDB is not in the support matrix, and the package declines rather than guessing.

**The mechanism.** `Ledger\ChangedFieldPredicate::sql()` and `Ledger\ContextPredicate::expression()`
each `match` on the connection's driver name, with arms for `sqlite`, `pgsql` and `mysql` and nothing
else. Laravel 11 and later give MariaDB its own connection driver, so `getDriverName()` returns
`mariadb`, the `default` arm is taken, and you get:

```
Sentinel has no field predicate for the [mariadb] engine, so whereFieldChanged()
cannot be answered there. Supported: mysql, pgsql, sqlite.
```

That is `Exceptions\LedgerException::cannotTranslateOn`. It is raised by `whereFieldChanged()`, by
the `Audit::field()` relation scope, and by `whereIp()` / `whereRoute()` — the four places the
package has to speak an engine's JSON dialect.

**What is worse than the refusal is what does *not* refuse.** The migrations run. Writes run. The
chain verifies. `sentinel:partitions` on a partitioned MariaDB table reports

```
The table [sentinel_audits] is not partitioned, so there was nothing to maintain.
```

and exits `0`, because `Partitions\Grammar::divides()` answers `false` for anything that is not
`pgsql` or `mysql`. So an installation can look healthy for months and then discover, at the first
field-history query, that four filters of the Query API are unavailable and that the scheduled
partition maintenance has been a no-op the whole time.

> ⚠️ **Warning.** Configuring a MariaDB server behind `'driver' => 'mysql'` gets past the refusal and
> makes the package emit MySQL 9 SQL — `JSON_TABLE` with `collate utf8mb4_bin`, a `VIRTUAL INVISIBLE`
> generated column if you publish the JSON-index stub. Nothing in the suite has ever run that
> combination. Do not do it to silence the exception.

**What a MariaDB shop should do.** Keep the application on MariaDB and give the trail a connection of
its own, on PostgreSQL 16 or MySQL 9:

```php
// config/sentinel.php
'database' => [
    'connection' => env('SENTINEL_DB_CONNECTION', 'audits'),
],
```

Two consequences come with a dedicated connection, and both are covered in
[A database of its own](07-a-database-of-its-own.md): the `after_commit` deferral hangs off the
*subject's* connection rather than the audit one, and a `whereHas()` that joins application tables to
audit tables stops working because the two live on different servers. The `morphMany` relation
`$model->audits()` still resolves.

---

## The capability matrix

| Axis | PostgreSQL 16 | MySQL 9 | SQLite 3.38+ |
|---|---|---|---|
| JSON column type (`jsonb()`) | `jsonb` | `json` | `text` |
| Timestamp columns | `timestamp(6) without time zone` | `datetime(6)` | `datetime` (no precision in the catalogue) |
| `whereIp()` / `whereRoute()` reading | `context->>'ip'` | `json_unquote(json_extract(…))`, twice — plain and `collate utf8mb4_bin` | `json_extract(…)` behind a `json_valid()` guard |
| Opt-in context index | B-tree over the expression | `VIRTUAL INVISIBLE` generated column + index | B-tree over the expression |
| `whereFieldChanged()` dialect | `jsonb_array_elements` behind a `jsonb_typeof = 'array'` guard | `JSON_TABLE` after a derived table, `collate utf8mb4_bin` | `json_each` behind a `json_valid()` guard |
| Partitioning | RANGE and LIST | RANGE only | none |
| Unique keys under partitioning | per-partition unique indexes available | impossible — `ERROR 1503` | n/a |
| Partition maintenance statement | `create table … partition of …` / `drop table` | `alter table … reorganize partition pmax` / `drop partition` | n/a |
| Stream lock before the tail read | `pg_advisory_xact_lock(hashtext(stream))` | InnoDB gap lock on `lockForUpdate()` | `lockForUpdate()` compiles to `''` |
| Placeholders per statement | 65 535 | 65 535 (prepared protocol) | 32 766 — and this is the one the package uses |
| GIN / full-text | not shipped, by decision | not shipped | not shipped |

Every cell is a property of the SQL this package emits, not a general statement about the engine.
`src/Ledger/ChangedFieldPredicate.php`, `src/Ledger/ContextPredicate.php` and
`src/Partitions/Grammar.php` each hold all three dialects in one `match`, so a dialect a given test
run cannot reach is still code somebody read.

---

## JSON storage and indexing

`Support\AuditSchema::columns()` declares seven JSON columns — `context`, `before`, `after`,
`changes`, `metadata`, `encryption` and `criteria` — with `$table->jsonb()`. Laravel compiles that to
`jsonb` on PostgreSQL, to `json` on MySQL, and to plain `text` on SQLite. The type was fixed in the
first migration and has never been converted, because changing a JSON column's type rewrites the
whole table.

Two consequences travel with that choice:

- **No engine gives you back the key order you wrote.** Values round-trip intact; order does not.
  This is why the chain canonicalises before hashing — `Integrity\JsonCanonicalizer` sorts object
  members by UTF-16 code unit (RFC 8785) and leaves arrays in the order they arrived, so the hash is
  the same whatever order the engine chose to store. Anything of yours that depends on key order has
  to canonicalise it itself. See [Canonicalization](../07-integrity/03-canonicalization.md).
- **SQLite has no CHECK on the column,** so a row can hold text that is not JSON at all. Both
  predicates guard the column (`json_valid(...)`, and `json_each`'s own `type`) because
  `json_extract` over unparseable content aborts the statement, and `PDOStatement::fetchAll()` —
  which Laravel's `Connection::select()` uses — answers such a statement with a *partial result and
  no exception*. Without the guard, one poisoned row makes an audit read quietly incomplete.

The shipped schema indexes no JSON column. The one optional index is
`vendor:publish --tag=sentinel-json-indexes`, which serves `whereIp()` and `whereRoute()` only — see
[Indexes and JSON](05-indexes-and-json.md) for what it costs and when to publish it.

> 🐘 **Engine.** Laravel offers a `use_native_jsonb` connection option that would make SQLite declare
> the column as `jsonb` instead of `text`. Nothing in this package's suite sets it, and
> the SQLite predicates are written for a text column. Leave it off.

---

## JSON-path filters

`whereIp()` and `whereRoute()` compare a value inside `context`; `whereFieldChanged()` asks whether
any element of `changes` carries a given JSON Pointer or one beneath it. Those are the three filters
that need an engine dialect, and the differences are worth knowing before you pick a server.

**MySQL's default collation changes the answer.** `utf8mb4_0900_ai_ci` is case- and
accent-insensitive, so `= 'invoices.show'` there returns entries PostgreSQL and SQLite do not.
`Ledger\ContextPredicate` therefore emits the comparison twice: the insensitive one first (a superset
an index can serve) and `collate utf8mb4_bin` behind it (which decides). A binary comparison alone
loses the index, because a generated column indexed under one collation cannot serve a comparison
under another.

**MySQL's changed-field predicate is `JSON_TABLE` after a derived table** — not `JSON_SEARCH`, which
restricts the root of a search but not its depth, and not a bare `JSON_TABLE` inside `EXISTS`, which
MySQL decorrelates into a semi-join and evaluates once for the whole scan, returning every row or
none.

The comparison is an equality plus a prefix over the element's own `path`, never a `LIKE`: `LIKE` is
ASCII case-insensitive on SQLite and MySQL and case-sensitive on PostgreSQL, so no amount of escaping
would make the three engines return the same entries. A `%` or `_` inside a field name is inert as a
side effect. Details are in [Field history](../06-reading/04-field-history.md) and the
[Filters reference](../06-reading/02-filters-reference.md).

---

## Partitioning

Partitioning is opt-in, is chosen before the first entry, and exists to make *retirement* cheap — not
to make writes fast. Three stubs ship; each **replaces** the base migration by landing under the same
file name, so the package stops loading its own.

| Stub | Engine | Division | Keeps `unique (stream, sequence)`? |
|---|---|---|---|
| `sentinel-partitioned-pgsql-tenant` | PostgreSQL 16 | `partition by list (tenant_id)` | **Yes** — per-partition unique indexes |
| `sentinel-partitioned-pgsql-range` | PostgreSQL 16 | `partition by range (created_at)` | No — the key gains `created_at` |
| `sentinel-partitioned-mysql-range` | MySQL 9 | `partition by range (to_days(created_at))` | No, and no way to get it back |

Under either **date** division both engines require every unique key to carry the partitioning
column, so `(stream, sequence)` and `capture_id` become `(stream, sequence, created_at)` and
`(capture_id, created_at)` — enforced inside one partition, which is one month under both stubs, not
across the table. What still holds the chain is the ledger's own sequence assignment and
`sentinel:verify`, which fails on exactly a planted duplicate; the safety net is narrower, not gone.

The **tenant** division on PostgreSQL gives up nothing, provided `integrity.stream` is `tenant`: every
entry of a stream then lands in one partition, so a per-partition unique on `(stream, sequence)` *is*
the guarantee a flat table had. It has no primary key at all — one would have to carry `tenant_id`,
PostgreSQL promotes primary-key columns to `NOT NULL`, and an entry recorded by a command or a queue
worker has no tenant. Filling `tenant_id` with a placeholder is worse: the column is inside the
canonical payload, so an empty string where the hash was sealed over `null` makes the entry fail its
own verification.

> ⚠️ **Warning.** `sentinel:partitions` maintains monthly *ranges*. Pointed at a `pgsql-tenant`
> table it tries to attach a RANGE bound to a LIST parent, catches the engine's refusal and exits
> `2`. Tenant partitions are added by hand, with their two unique indexes.

The full procedure, the maintenance command and the measured cost of too many partitions are in
[Partitioning](06-partitioning.md).

---

## Concurrency on sequence assignment

The hash of an entry covers its `sequence` and its `previous_hash`, so no `INSERT` can compute its
own link: `Ledger\StreamGate` has to read the tail of the stream first, and the writers of one stream
have to be serialised across that read. Each engine closes it differently.

| Engine | How the tail read is protected | Failure mode if it were not |
|---|---|---|
| PostgreSQL 16 | `select pg_advisory_xact_lock(hashtext(?))` on the stream name, then `lockForUpdate()` | No row lock exists on a row that has not been written, so two writers would both see an empty stream and both claim sequence 1 |
| MySQL 9 | InnoDB gap lock, taken by the `lockForUpdate()` tail read | Same race, closed as a side effect of the isolation level rather than by name |
| SQLite | Nothing — `lockForUpdate()` compiles to an empty string — but the engine serialises writers at database level | Not reachable in a single-writer engine |

`tests/Ledger/ConcurrencyTest.php` races a second connection against the gate and asserts that the
sequences come back as an unbroken range and that every entry still verifies against its own row. It
skips itself on an in-memory SQLite database (a second connection there is a different database), so
those assertions run under `make test-dbs` and in the `DB mysql` / `DB pgsql` CI jobs — against the two
engines where the race is real.

Sequence assignment happens inside one transaction per `writeMany()` call, and the retry on a unique
violation is bounded at three attempts, filtered by `capture_id` so an entry that already settled is
not written twice. See [The hash chain](../07-integrity/01-the-hash-chain.md).

---

## Batch limits

`Ledger\DatabaseLedger` bounds a statement at **32 766 placeholders** for all three engines — SQLite's
compile-time `SQLITE_MAX_VARIABLE_NUMBER`, which is narrower than the 65 535 of PostgreSQL and of the
MySQL prepared protocol. So the engine you pick does not change how a batch is divided; it only
changes how much headroom you leave on the table. The arithmetic, and the rows-per-statement it
produces, are on [SQLite](04-sqlite.md#the-placeholder-ceiling).

The division does not divide the chain. Sequences, hashes and links are all settled before any
statement runs, and every statement runs inside the one transaction that wraps them, so a batch split
into four still lands whole or not at all.

> 📌 **Note.** The crossing sits well inside what one
> [mass operation](../03-capture/05-mass-operations.md) writes in `individual` mode. On
> the default write path a failure there is announced and logged rather than thrown, because the
> business `UPDATE` has already committed by the time the deferred write runs — see
> [Failure policy](../09-operations/05-failure-policy.md).

---

## Full-text and GIN

**The package ships no GIN index and no full-text index on any engine, and none of its filters is a
text search.** That is a decision with numbers behind it, not an omission.

Over `context`, a GIN index costs eight points more per write than the expression index the
JSON-index stub publishes and four times the space, to serve the one plan this API publishes *worse*.
Over `changes` it is not used at all: `whereFieldChanged()` is a correlated `EXISTS` over the array
elements, and the plan with a GIN present is still a sequential scan.

That comparison was a one-off on PostgreSQL 16 over a ten-million-row table. No `make` target
rebuilds it, which is worth knowing before disputing it — every other figure in these pages comes
from `make bench` or `make bench-volume`.

If you need to search *inside* audit payloads by free text, that is an application-side index over a
projection you build, or a second destination behind the Ledger contract — see
[Fanout](../11-extending/05-fanout.md). It is not something to add to `sentinel_audits`, which the
package's own schema tests count columns and indexes on.

---

## Moving engines with a trail already written

A written chain is portable between the three supported engines. Nothing about it is engine-specific:
the hash covers `payload_version`, `stream`, `sequence`, `previous_hash` and the RFC 8785 canonical
JSON of twenty-seven columns, and canonicalisation sorts object members before hashing — so a `jsonb`
column that comes back from PostgreSQL in a different key order than MySQL stored it still produces
the same hash.

**There is no import route for this.** `sentinel:import` reads `owen-it/laravel-auditing` and
`altek/accountant` history only. `sentinel:export` writes NDJSON for somebody who does not have the
database. Moving your own trail from one engine to another is a dump and a restore of the seven
tables, followed by a verification.

```bash
# 1. Prove what you have, on the engine you are leaving.
php artisan sentinel:verify

# 2. Point SENTINEL_DB_CONNECTION at the destination and create the schema there.
#    Do not pass --database: every package migration overrides getConnection() with
#    sentinel.database.connection, so the flag is ignored and the run goes to the old server.
SENTINEL_DB_CONNECTION=audits_new php artisan migrate

# 3. Copy the rows with your engine's own tooling. Do not regenerate identifiers.

# 4. With the package now reading the new connection, prove it again.
php artisan sentinel:verify
```

Four things to get right in step 3:

| What | Why it matters |
|---|---|
| The three `datetime(6)` columns | `Models\Audit::getDateFormat()` is `Y-m-d H:i:s.u`, and `occurred_at` is inside the canonical payload. A dump that truncates microseconds changes the hash of every entry that had them |
| `id`, `capture_id`, `transaction_id`, `source_audit_id` | `char(26)`. PostgreSQL blank-pads a short value and MySQL and SQLite trim it, so a mis-sized identifier surfaces on one engine out of three. Never re-mint them: `sentinel_archives`, `sentinel_checkpoints` and `sentinel_access_log` point at entries by id |
| `sequence` and `affected_rows` | `bigint`. MySQL and PostgreSQL hand one back through PDO as a string and SQLite as an int; the models cast where it matters, a hand-written copier does not |
| Every JSON column | Copy the value, not a re-encoding of it. Re-encoding is safe for the hash — canonicalisation sorts anyway — but a lossy round trip through a client that turns `[]` into `{}` is not |

Order of operations, once verified: the companion tables carry **no foreign key** to `sentinel_audits`
(a cascade lives badly with date partitioning and batched pruning), so they can be restored in any
order. Keep the old database until the verification on the new one has passed and you have slept on
it.

> ⚠️ **Warning.** Do not combine the engine move with a move to a partitioned table. The three
> partitioned stubs are for a new installation; converting a table that already holds entries is a
> separate maintenance window, and the procedure is in `UPGRADE.md`. Two conversions at once leave
> nothing to blame a failed verification on.

If the destination is a *different connection on the same application* rather than a different
server, read [A database of its own](07-a-database-of-its-own.md) first — a dedicated connection
changes how `after_commit` behaves.

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `Sentinel has no field predicate for the [mariadb] engine…` | MariaDB is not a supported engine; `ChangedFieldPredicate` has no dialect for it | Put the trail on its own PostgreSQL or MySQL connection via `database.connection`. Do not relabel the driver as `mysql` |
| `sentinel:partitions` says "is not partitioned" and exits `0` on a table you partitioned | The engine is not `pgsql` or `mysql` — `Grammar::divides()` answers `false` for anything else, including MariaDB and SQLite | Nothing to fix on SQLite. On MariaDB the whole partitioning story is unavailable |
| `sentinel:partitions` exits `2` on a PostgreSQL table you divided by tenant | The maintainer builds monthly RANGE bounds and PostgreSQL rejects them against a LIST parent | Do not schedule the command against a `pgsql-tenant` table. Add tenant partitions by hand, with their two unique indexes |
| A `whereRoute('invoices.show')` returns entries on MySQL that PostgreSQL does not | Nothing — the driver already emits a `collate utf8mb4_bin` recheck behind the insensitive comparison, so the answers agree. If they disagree, the query did not go through the Query API | Route the read through `Sentinel::audits()`, not a hand-written `where` on `context` |
| An audit read comes back short on SQLite, with no error | A row holds text in a JSON column that is not JSON; without a guard `json_extract` aborts the statement and `fetchAll()` returns a partial result | Already guarded by `json_valid()` in both predicates. If you wrote your own JSON `where`, guard it the same way |
| A mass operation writes 1 000 entries in two `insert` statements | The placeholder ceiling divides at 936 rows | Expected. All statements run inside one transaction, so the batch still lands whole or not at all |
| Every entry fails verification after a database move | The dump truncated `occurred_at` to seconds, or re-minted `id` values | Re-copy from the source with microsecond precision preserved. `sentinel:verify` on the source *before* the move is what tells you the break came from the copy |
| A `pgsql-range` table accepts a duplicate `(stream, sequence)` | Under a date division that unique key carries `created_at` and is enforced only within a month | Nothing at the engine level can fix it. `sentinel:verify` is the net; run it on a schedule |
| Partition and index names ignore the table prefix set on the connection | The package's raw DDL builds names from `sentinel.tables` alone; only the Blueprint-compiled `create table` goes through the grammar that applies a connection prefix | Rename with `sentinel.tables.prefix`, never with a `prefix` on the audit connection |

---

## ✅ Best practices

✅ **Do** — give the trail a connection of its own when the application's engine is not one of the
three. It is the supported way to run Sentinel next to MariaDB, and it is one config key.

```php
// config/sentinel.php
'database' => [
    'connection' => env('SENTINEL_DB_CONNECTION', 'audits'),
],
```

❌ **Don't** — point a `mysql` driver at a MariaDB server to get past the refusal. The package then
emits `JSON_TABLE` with `collate utf8mb4_bin` and, if you publish the index stub, a
`VIRTUAL INVISIBLE` generated column — against a server nothing in the suite has ever run.

```php
// config/database.php — this silences the exception and buys an untested combination
'audits' => ['driver' => 'mysql', 'host' => 'mariadb.internal', /* … */],
```

---

✅ **Do** — decide about partitioning before the first entry, and prefer the tenant division when you
are multi-tenant on PostgreSQL. It is the only division that keeps `unique (stream, sequence)`.

```bash
php artisan vendor:publish --tag=sentinel-partitioned-pgsql-tenant
php artisan migrate
```

❌ **Don't** — publish two partitioned stubs, or a stub on a table that already holds entries. All
three land under the same file name as the base migration, so the second publish overwrites the first
and leaves no way to tell which shape the table has.

```bash
php artisan vendor:publish --tag=sentinel-partitioned-pgsql-range
php artisan vendor:publish --tag=sentinel-partitioned-mysql-range   # overwrote the file above
```

---

✅ **Do** — run `sentinel:verify` on both sides of an engine move, and keep the source database until
the second one passes. The chain re-derives every hash from the row, so a copy that lost microseconds
or re-minted an id says so immediately.

```bash
php artisan sentinel:verify            # before, on the engine you are leaving
php artisan sentinel:verify            # after, on the new one — same command, same answer expected
```

❌ **Don't** — move engines and convert to a partitioned table in the same window. A failed
verification afterwards has two candidate causes and no way to separate them.

```bash
php artisan vendor:publish --tag=sentinel-partitioned-pgsql-range && php artisan migrate
# ... then restore a MySQL dump into it, and hope
```

---

✅ **Do** — publish the JSON-index stub only if you actually filter by `whereIp()` or `whereRoute()`.
Both filters answer correctly without it, by scanning, and the index is a write cost paid on every
entry.

```bash
php artisan vendor:publish --tag=sentinel-json-indexes
php artisan migrate
```

❌ **Don't** — add a GIN index over `context` or `changes` because it sounds like the right tool. Over
`context` it costs more per write and four times the space to serve the published plan worse; over
`changes` the planner does not use it at all, because `whereFieldChanged()` is a correlated `EXISTS`.

```sql
-- serves nothing this API asks for
create index sentinel_audits_changes_gin on sentinel_audits using gin (changes);
```

---

✅ **Do** — rename tables through `sentinel.tables`, on any engine. Every migration, every model and
every raw partition statement resolves the name through `Support\Config::table()`.

```php
'tables' => [
    'prefix' => 'audit_',
    'audits' => 'entries',   // -> audit_entries, partitions included
    // … keep every remaining key: the config merge is one level deep
],
```

❌ **Don't** — set a `prefix` on the audit connection instead. Laravel applies it to the
Blueprint-compiled statements and not to the package's raw partition and index DDL, so half the
schema gets the prefix and half does not.

```php
// config/database.php
'audits' => ['driver' => 'pgsql', 'prefix' => 'audit_', /* … */],
```

---

**See also:** [PostgreSQL](02-postgresql.md) · [MySQL](03-mysql.md) · [SQLite](04-sqlite.md) ·
[Indexes and JSON](05-indexes-and-json.md) · [Partitioning](06-partitioning.md) ·
[A database of its own](07-a-database-of-its-own.md) · [Scaling playbook](08-scaling-playbook.md) ·
[Installation](../02-getting-started/01-installation.md) ·
[Choosing your setup](../02-getting-started/05-choosing-your-setup.md) ·
[Filters reference](../06-reading/02-filters-reference.md) ·
[The hash chain](../07-integrity/01-the-hash-chain.md) ·
[Canonicalization](../07-integrity/03-canonicalization.md) ·
[Verification](../07-integrity/06-verification.md) ·
[Multi-tenancy](../04-context/04-multi-tenancy.md) ·
[Artisan commands](../09-operations/06-artisan-commands.md) ·
[Schema](../99-reference/03-schema.md) · [Configuration](../99-reference/02-configuration.md) ·
[Exceptions](../99-reference/06-exceptions.md)
