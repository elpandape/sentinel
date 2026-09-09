# 🐘 SQLite

> Where SQLite is the right ledger — tests, single-process tools, embedded installs — where it is
> not, and the one limit it imposes on every installation whether you run it or not.

**On this page:** [What SQLite is good for here](#what-sqlite-is-good-for-here) ·
[The version floor](#the-version-floor) · [The placeholder ceiling](#the-placeholder-ceiling) ·
[Concurrency, WAL and the busy timeout](#concurrency-wal-and-the-busy-timeout) ·
[The JSON columns are text](#the-json-columns-are-text) ·
[Dates, identifiers and what the catalogue says](#dates-identifiers-and-what-the-catalogue-says) ·
[Query plans differ, and so do index names](#query-plans-differ-and-so-do-index-names) ·
[Unavailable and degraded, in one table](#unavailable-and-degraded-in-one-table) ·
[Configuring a SQLite trail](#configuring-a-sqlite-trail) ·
[⚠️ Pitfalls](#-pitfalls) · [✅ Best practices](#-best-practices)

---

## What SQLite is good for here

SQLite is a first-class engine for Sentinel in the sense that matters: every dialect the package
speaks has a SQLite branch, and the whole suite runs against it on every push. `phpunit.xml.dist`
sets `DB_CONNECTION=testing`, which is testbench's in-memory SQLite, so `make test`, the CI quality
job and the whole PHP × Laravel × stability matrix exercise the ledger, the chain, the query surface
and both JSON predicates on this engine. MySQL and PostgreSQL are covered by separate jobs.

That is also the boundary. SQLite serialises writers at the database level, and the audit table is
the one table in a Sentinel installation that only grows.

| Use it for | Do not use it for |
|---|---|
| The package's own suite, and yours | Any application with more than one process writing |
| A single-process CLI tool that keeps its own trail | A queue worker settling entries beside a web process |
| An embedded or desktop install | A trail expected to reach millions of entries |
| A local reproduction of a bug you saw on MySQL | Rehearsing a query plan you intend to trust in production |
| A short-lived export or import staging database | Anything you plan to partition or retire by range |

> ⚠️ **Warning.** SQLite has no partitioning, no advisory lock and no row lock the package can ask
> for. All three of those are how a trail is kept cheap to maintain and safe to write concurrently.
> If your installation will ever need one of them, choose the engine now — see
> [Choosing an engine](01-choosing-an-engine.md).

---

## The version floor

The SQL Sentinel emits needs **SQLite 3.38**, the release where JSON stopped being a compile-time
option. Below that, `json_valid()`, `json_each()` and `json_extract()` — the three functions both
JSON predicates are built from — may simply not exist in the library PHP is linked against, and
`whereFieldChanged()`, `whereIp()`, `whereRoute()` and the `field()` scope fail as unknown functions.

The floor is a property of **libsqlite3**, not of PHP and not of Laravel. Two machines running the
same PHP 8.4 build can be linked against different SQLite versions.

```bash
php -r 'echo new PDO("sqlite::memory:")->query("select sqlite_version()")->fetchColumn(), PHP_EOL;'
```

> 🧪 **Verify it.** That is the same one-liner both GitHub workflows run before the suite
> (`.github/workflows/quality.yml`, `.github/workflows/run-tests.yml`). They print the version rather
> than pinning it, so when a JSON filter fails on a runner the log already says which library it
> failed on.

The version the package claims to *run on* every push is in the support table on
[Choosing an engine](01-choosing-an-engine.md). This page is about the floor and the ceiling, which
are different numbers from different causes.

---

## The placeholder ceiling

This is the one limit SQLite imposes on **every** installation, including the ones that never run it.

`SQLITE_MAX_VARIABLE_NUMBER` is a compile-time constant of libsqlite3 that bounds how many bound
parameters one prepared statement may carry. It has been **32 766** since SQLite 3.32, and
`Ledger\DatabaseLedger` hard-codes exactly that as `MAX_PLACEHOLDERS`:

```
PostgreSQL      65 535 placeholders per statement
MySQL           65 535 (the prepared protocol)
SQLite          32 766 (SQLITE_MAX_VARIABLE_NUMBER, since 3.32)
                ↓
Sentinel        32 766 — the narrowest, for all three engines
```

The package batches to the narrowest engine rather than the widest. One number for three engines is
one number too few, and the alternative — a per-driver ceiling — would mean a batch that works in
your test suite and fails in production, or the reverse.

### The arithmetic

`DatabaseLedger::perStatement()` divides the ceiling by how many placeholders one item spends:

```
intdiv(32766, placeholders_per_item)
```

An audit row spends one placeholder per column it carries. `Ledger\EntryBuilder::build()` fills
**35 attributes** on the entry it hands back — 34 in the `forceFill()`, plus the `hash` computed over
them. The three `redacted_*` columns are never set at build time, and the two signature columns are
added by `EntryBuilder::seal()` only when a signer returns something. So:

| What is being written | Placeholders each | Per statement |
|---|---|---|
| An unsigned audit entry | 35 | `intdiv(32766, 35)` = **936** |
| A signed audit entry (`signature` + `signature_key_id`) | 37 | `intdiv(32766, 37)` = **885** |
| A capture id in `settled()`'s `where in` | 1 | **32 766** |

Three tests in `tests/Ledger/PlaceholderCeilingTest.php` pin it: *"fits an entry in thirty-five
columns, which is what the ceiling divides"*, *"divides a batch across statements at the narrowest
engine ceiling"* (936 → one statement) and *"opens a second statement one row past it"* (937 → two).

### The split is safe, and where it is not applied

`DatabaseLedger::insertInStatements()` divides the rows, never the chain. Sequences, hashes and links
are settled before the first statement runs, and every statement runs inside the one transaction
`chain()` opened — so a batch split into four still lands whole or not at all.

Three call sites can hand the ledger more than 936 entries at once:

| Path | Bounded by | Default | Crosses 936 when |
|---|---|---|---|
| `->auditing(MassMode::Individual)->update(…)` | the size of the matched set | unbounded | The statement matches ≥ 937 rows |
| `sentinel:import --size=` | `--size` | 500 | You raise `--size` past 936 |
| Buffered flush | `buffer.size` | 500 | You raise `buffer.size` past 936 |

```php
use App\Models\Invoice;
use ElPandaPe\Sentinel\Enums\MassMode;

// 5 000 drafts voided: one entry each, 5 000 rows, six insert statements, one transaction.
Invoice::query()
    ->where('status', 'draft')
    ->auditing(MassMode::Individual)
    ->update(['status' => 'void']);
```

One path does **not** divide: `Archive\Rehydrator` asks the hot table which captures of an archived
batch it already holds, one placeholder per entry, bounded by `archive.batch` — 1000 out of the box,
thirty-two times under the ceiling. Raising `archive.batch` past 32 766 is what would reach it.

> ⚠️ **Warning.** The ceiling is a constant the package assumes, not one it reads back from the
> library. A libsqlite3 compiled with `-DSQLITE_MAX_VARIABLE_NUMBER` **below** 32 766 — an old
> distribution build predating 3.32, or a deliberately narrowed one — will refuse the statement with
> `too many SQL variables` and Sentinel will not adapt to it. Distributions generally ship the
> default or higher; a build of your own may not.

> 📌 **Note.** Crossing the ceiling on the default write path is not loud. With
> `transactions.after_commit` on, the business statement has already committed by the time the
> deferred audit write runs, so a failure there is announced and logged rather than raised, whatever
> `on_write_failure` says. See [Failure policy](../09-operations/05-failure-policy.md).

---

## Concurrency, WAL and the busy timeout

`Ledger\StreamGate` exists because the hash covers the sequence and the previous hash, so the tail of
a stream must be **read** before the next row can be built. No `INSERT` can compute its own link.
Each engine is asked to serialise that read differently:

| Engine | How the tail read is serialised |
|---|---|
| PostgreSQL | `select pg_advisory_xact_lock(hashtext(?))` on the stream name, because no row lock covers a stream with no rows yet |
| MySQL | The InnoDB gap lock taken by `lockForUpdate()` on the tail read |
| **SQLite** | **Nothing.** Laravel's SQLite query grammar compiles `lockForUpdate()` to the empty string, so the clause never reaches the statement |

What actually keeps a SQLite trail consistent is `unique(stream, sequence)` — the index the base
migration creates — plus the retry in `DatabaseLedger::attempt()`. A writer that lost a race gets a
`UniqueConstraintViolationException`, the batch is re-offered (minus whatever has settled since,
identified by `capture_id`), and the tail is read again. `MAX_ATTEMPTS` is 3, counting the first try;
the third collision propagates the violation to whoever asked for the write.

### What WAL buys, and what it does not

Laravel reads three SQLite pragmas straight off the connection config
(`Illuminate\Database\Connectors\SQLiteConnector`): `journal_mode`, `busy_timeout` and `synchronous`.

- **`journal_mode` = `WAL`** lets readers proceed while one writer is writing. It is worth setting
  for a trail, because reading the trail is the common operation and blocking a report behind a write
  is the common annoyance. It does not give you a second writer.
- **`busy_timeout`** is how long a statement that finds the database locked will wait before giving
  up with SQLite's `database is locked`. It converts an immediate failure into a wait.
- **`synchronous`** trades durability for speed. On a ledger, do not.

> ⚠️ **Warning.** A busy timeout does not solve sequence assignment. The tail read takes no write
> lock on this engine, so two connections can read the *same* tail, compute the *same* next sequence,
> and both believe they own it. Waiting longer does not change what either of them read. The
> collision is caught afterwards by `unique(stream, sequence)` and resolved by `attempt()`'s three
> attempts — the index is the arbiter, not the timeout.

> 📌 **Note.** Nothing in the suite races two writers on SQLite. `tests/Ledger/ConcurrencyTest.php`
> and `tests/Integrity/CheckpointConcurrencyTest.php` both skip when the database is `:memory:`,
> because a second connection to an in-memory SQLite database is a different database. The MySQL and
> PostgreSQL gate behaviours are covered; the SQLite one is read from the code and reasoned about.

---

## The JSON columns are text

`AuditSchema::columns()` declares seven JSON columns — `context`, `before`, `after`, `changes`,
`metadata`, `encryption`, `criteria` — with `$table->jsonb()`. On PostgreSQL that is `jsonb`, on
MySQL `json`, and on SQLite it compiles to **`text`**, with no validity constraint of any kind.
`tests/Database/AuditsTableTest.php` pins exactly that: *"resolves the json type from the engine
grammar"* asserts `text` on anything that is not pgsql or mysql.

The consequence is not theoretical. A row that Sentinel did not write — an import gone wrong, a
manual `UPDATE`, a restored dump — can hold something `json_each()` cannot walk. Unguarded, that does
not fail the query: `PDOStatement::fetchAll()`, which is what `Connection::select()` reads with, hands
back a **partial result and no exception**. An audit that answers with fewer entries than it should
while looking complete is the one failure this package cannot have.

So both predicates guard the column before touching it:

| Predicate | SQLite guard |
|---|---|
| `Ledger\ChangedFieldPredicate` | `json_each(case when json_valid(col) then col else '[]' end)`, then `je.type = 'object'` before reading `$.path` |
| `Ledger\ContextPredicate` | `json_extract(case when json_valid(col) then col else '{}' end, '$.ip')` |

`tests/Ledger/PoisonedChangesTest.php` walks eight shapes of poison through
`whereFieldChanged('email')` — a scalar element, an object instead of an array, a bare scalar, a
number, an element with no path, a path that is not a string, unparseable text, the empty string —
and asserts the query still answers with the entry that legitimately matched. The last two cases are
skipped everywhere but SQLite, *"only SQLite stores this column as text, so only there can it hold
something that is not JSON"*.

> 📌 **Note.** A guarded row is **skipped**, not reported. `whereFieldChanged()` on SQLite answers
> with the entries it could read and says nothing about the ones it could not parse. If you have
> reason to suspect the column, verify the chain (`php artisan sentinel:verify`) rather than trusting
> a filter to tell you — see [Verification](../07-integrity/06-verification.md).

> ⚠️ **Warning.** Do not set `use_native_json` or `use_native_jsonb` on the audit connection. Laravel
> reads both and, when set, declares the columns as `json`/`jsonb` rather than `text`. The package's
> SQLite dialects are written for the text form and no test in the suite covers the native one.

---

## Dates, identifiers and what the catalogue says

| Declared | SQLite gets | Consequence |
|---|---|---|
| `dateTime('occurred_at', 6)` | `datetime` — no precision in the catalogue | Microseconds survive anyway, because `Models\Audit::getDateFormat()` returns `'Y-m-d H:i:s.u'` and the model writes and reads the string |
| `char('id', 26)` | `varchar` — no length, no padding | SQLite trims nothing because it pads nothing; PostgreSQL pads `char(26)` and keeps it, so a short identifier reads back differently on the two engines |
| `unsignedBigInteger('sequence')` | `integer` | Handed back as an int by PDO, where MySQL and PostgreSQL hand back a string — which is why the range columns of `Models\AuditArchive` carry explicit integer casts |

`tests/Database/AuditsTableTest.php` asserts the first two directly: *"resolves the microsecond date
type from the engine grammar"* expects `datetime` on SQLite against `datetime(6)` and
`timestamp(6) without time zone` elsewhere.

The schema itself is identical on all three engines otherwise: 40 columns and 13 non-primary indexes,
both pinned by test. SQLite materialises a non-integer primary key as a `sqlite_autoindex`, which is
why every schema test in the suite filters `primary === false` before counting — "thirteen indexes"
means thirteen that are not the primary key. See [Schema](../99-reference/03-schema.md).

---

## Query plans differ, and so do index names

`tests/Query/QueryPlanTest.php` runs the engine's own `EXPLAIN` over the statement the driver really
issues, and three of its assertions branch on the driver name — because pretending the three engines
agree would make the test a lie:

| Query | SQLite | MySQL / PostgreSQL |
|---|---|---|
| `between($from, $to)` alone | Skip-scans an index that is not about the filter, so it reads *an* index | Walks the table; this is why `between()` is documented as a refiner |
| `Sentinel::timeline()` unnarrowed | Commits to the occurrence clock's index and does not sort outside it | Cost-based: at suite size both prefer to read and top-N sort |
| `whereOperation('attach')` alone | Walks the trail and seeks the projection by entry | PostgreSQL walks both tables; MySQL rewrites the correlated `EXISTS` into a semi-join, walks the projection and seeks the trail by key |

Three shapes, one property: on none of them is the pair both sought. That property — not the shape —
is what makes `whereOperation()` a refiner, and it is the only thing the test asserts.

The lesson is one sentence: **an `EXPLAIN` taken on SQLite tells you nothing about production.**
The filters reference documents the classification (indexed vs. refiner), and that classification
holds on all three engines; the plan behind it does not. See
[Filters reference](../06-reading/02-filters-reference.md).

### Why the label filter asserts a seek and not an index name

`whereTag('audited')` compiles to one correlated `EXISTS` per required label into the labels table.
Which index answers it on SQLite is **not the same index across versions of SQLite**:

- **From 3.51 on**, SQLite turns the correlated `EXISTS` into a semi-join and seeks the reversed
  index on the labels table.
- **Before 3.51**, it evaluates the `EXISTS` per row with `audit_id` already fixed, where the unique
  pair is the right index to seek.

Both are a seek. Naming either one would be a gate that moves with the patch version of a library
underneath the test. So the helper `reachesByIndex()` in `tests/helpers.php` asserts two things and
no more: that the plan **names the table at all**, and that it does not contain `SCAN <table>` (or
`Table scan on`/`Seq Scan on` for the other two engines). The question it is asking is *"is this a
seek rather than a walk"* — which is the question that has an answer that stays true.

> 💡 **Tip.** If you write plan assertions of your own over the trail, copy that shape. An index name
> is the planner's business and it changes underneath you; the presence or absence of a full scan is
> the property you actually care about.

---

## Unavailable and degraded, in one table

| Capability | On SQLite | What you observe | Where it is decided |
|---|---|---|---|
| Range partitioning (`mysql-range`, `pgsql-range`) | **Unavailable** | No stub applies; the base migration is the only shape | `src/Partitions/Grammar.php` |
| Tenant partitioning (`pgsql-tenant`) | **Unavailable** | PostgreSQL only | `src/Partitions/Grammar.php` |
| `php artisan sentinel:partitions` | **No-op, exit 0** | *"The table [sentinel_audits] is not partitioned, so there was nothing to maintain."* Safe to leave in a schedule | `Partitions\Maintainer::maintain()` short-circuits on `Grammar::divides()` before any dialect is reached |
| `DROP PARTITION` as a retirement strategy | **Unavailable** | `sentinel:prune` deletes by sequence range in `prune.batch` statements, which is the only route here | `src/Retention/Cascade.php` |
| Advisory lock on the stream name | **Unavailable** | PostgreSQL only | `Ledger\StreamGate` |
| `lockForUpdate()` on the tail read | **Unavailable** | The clause is compiled to the empty string; the unique index is the arbiter | Laravel's SQLite query grammar |
| Native JSON column type | **Degraded** | `jsonb()` → `text`, no validity constraint; both predicates guard the column | `Support\AuditSchema` |
| `datetime(6)` | **Degraded** | Declared `datetime`; precision carried by `Audit::getDateFormat()` | `Support\AuditSchema`, `Models\Audit` |
| `char(26)` | **Degraded** | Declared `varchar`, no length, no padding | `Support\AuditSchema` |
| Placeholders per statement | **32 766** | 936 entries per insert — and every other engine gets the same division | `Ledger\DatabaseLedger::MAX_PLACEHOLDERS` |
| Concurrent writers | **One** | A second writer waits (`busy_timeout`) or fails with `database is locked` | The engine |
| `whereFieldChanged()`, `whereIp()`, `whereRoute()` | **Available** | Full dialect, guarded | `ChangedFieldPredicate`, `ContextPredicate` |
| `vendor:publish --tag=sentinel-json-indexes` | **Available** | An expression index over `json_extract(…)`; SQLite takes the doubled parentheses PostgreSQL requires without complaint | `database/stubs/json-indexes/…` |
| String comparison in `whereRoute()` | **Exact** | Case- and accent-sensitive, the same answer PostgreSQL gives. MySQL is the engine that needs a second clause | `Ledger\ContextPredicate` |
| The whole Ledger contract | **Available** | `Testing\LedgerContractTestCase` passes here; only `PartitionedLedgerContractTest` skips | `tests/Testing/` |

---

## Configuring a SQLite trail

A file-backed audit database on its own connection, with the three pragmas Laravel exposes:

```php
// config/database.php
'connections' => [

    'audits' => [
        'driver' => 'sqlite',
        'database' => database_path('audits.sqlite'),
        'prefix' => '',
        'foreign_key_constraints' => false,
        'busy_timeout' => 5000,
        'journal_mode' => 'WAL',
        'synchronous' => 'FULL',
    ],

],
```

```php
// config/sentinel.php
'database' => [
    'connection' => env('SENTINEL_DB_CONNECTION', 'audits'),
],
```

`foreign_key_constraints` is off because no table in the Sentinel schema carries a foreign key to
`sentinel_audits` — a cascade lives badly with batched pruning — so there is nothing for the pragma to
enforce and no reason to pay for it. `synchronous` stays at `FULL`: a ledger that loses its last write
to a power cut has lost the only copy of that fact.

> ⚠️ **Warning.** Giving audits their own connection changes two things that have nothing to do with
> SQLite, and both bite. The `after_commit` deferral hangs off the **subject's** connection, not the
> audit one; and cross-connection joins stop working. Read
> [A database of its own](07-a-database-of-its-own.md) before you do it.

> 📌 **Note.** `:memory:` is a per-connection database, not a shared one. A queue worker, a second
> PHP-FPM process, or a `DB::connection('other')` resolved from the same config all get their **own
> empty** database. That is exactly why `tests/helpers.php::rivalConnection()` returns `null` for
> `:memory:` and the concurrency tests skip. Use it for tests; never for `mode => 'queue'`.

### Reading the trail

Nothing about the read surface changes on this engine. The bound on `get()`, the cursor, the pages
and the refusals are all identical:

```php
use ElPandaPe\Sentinel\Facades\Sentinel;
use App\Models\Invoice;

$page = Sentinel::audits()
    ->for($invoice)
    ->whereFieldChanged('total')
    ->latest()
    ->paginate(25);
```

See [The Query API](../06-reading/01-the-query-api.md).

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `too many SQL variables` on an insert into `sentinel_audits` | libsqlite3 compiled with `SQLITE_MAX_VARIABLE_NUMBER` below 32 766, which the package assumes and does not read back | Rebuild or reinstall SQLite from a distribution default, or lower the batch that reached it (`archive.batch`, `--size`) |
| `too many SQL variables` during a rehydration (`Archive\Rehydrator::restore()`) | `archive.batch` raised past 32 766 — the rehydrator's capture lookup is one placeholder per entry and is not divided across statements | Bring `archive.batch` back under the ceiling; 1000 is the shipped value |
| A mass `individual` update over thousands of rows leaves no entries and no exception | The audit write is deferred to commit under `transactions.after_commit`, so a failure there is logged, not thrown | Check the log channel named by `log_channel`; see [Failure policy](../09-operations/05-failure-policy.md) |
| `database is locked` under load | A second writer. SQLite serialises writers at the database level and `lockForUpdate()` is compiled away | Set `busy_timeout`, or move the trail to MySQL or PostgreSQL. The timeout buys patience, not concurrency |
| `UniqueConstraintViolationException` on `(stream, sequence)` reaches the caller | The tail was taken from under the writer on three attempts running; `DatabaseLedger::attempt()` makes three and then propagates | This is the arbiter working. It means the write volume has outgrown an engine with no lock to take |
| `whereFieldChanged()` returns fewer entries than you expect, silently | A row holds something in `changes` that is not a JSON array of objects. The SQLite guard skips it rather than aborting the statement | Find it with `sentinel:verify`; a row the package wrote always parses |
| A query is fast on SQLite and walks the table on MySQL | `between()` skip-scans an index here that the other two engines do not use | Never carry a SQLite plan into a production decision — put an indexed filter in front of the refiner |
| A plan assertion of your own goes red after a SQLite upgrade | The planner changed which index it seeks for the label `EXISTS` at 3.51 | Assert the absence of a scan, not the name of an index — `reachesByIndex()` in `tests/helpers.php` shows the shape |
| `sentinel:partitions` prints "not partitioned" and exits 0, in a schedule you expected to do work | The engine does not partition; the maintainer short-circuits before reaching any dialect | Expected. Partitioning needs MySQL or PostgreSQL — see [Partitioning](06-partitioning.md) |
| A queue worker fails every `SettleAudit` job with `no such table: sentinel_audits` | The connection is `:memory:`. The worker opened its own database, and nothing migrated it | Point `database` at a file. `:memory:` is per connection, not per application |
| `char(26)` identifiers compare equal on SQLite and not on PostgreSQL | PostgreSQL pads `char(26)` and keeps the padding; SQLite declares `varchar` and pads nothing | Always mint identifiers at full width — the package's ULIDs are 26 characters by construction |

---

## ✅ Best practices

✅ **Do** — point a SQLite audit trail at a file, and turn on WAL. Readers stop queueing behind the
one writer, which is what makes a trail usable while it is being written.

```php
// config/database.php
'audits' => [
    'driver' => 'sqlite',
    'database' => database_path('audits.sqlite'),
    'journal_mode' => 'WAL',
    'busy_timeout' => 5000,
    'synchronous' => 'FULL',
],
```

❌ **Don't** — run `mode => 'queue'` against `:memory:`. The worker opens its own connection, which
is its own empty database, and every `Jobs\SettleAudit` fails on a table that was never migrated
there.

```php
// config/sentinel.php — every settled entry dies in the worker
'mode' => 'queue',
// config/database.php
'audits' => ['driver' => 'sqlite', 'database' => ':memory:'],
```

---

✅ **Do** — keep `archive.batch`, `buffer.size` and `sentinel:import --size` under 936 if you want
one statement per batch, and under 32 766 always. The ledger divides its own inserts; the rehydrator's
capture lookup does not.

```php
// config/sentinel.php
'buffer' => ['size' => 500],
'ledger' => ['ledgers' => ['archive' => ['batch' => 1000]]],
```

❌ **Don't** — raise a batch setting to "make the import faster" without checking what spends a
placeholder per item. At `--size=50000` the ledger still divides the insert, but every path that
does not — and every engine, because the ceiling is SQLite's for all three — is now one statement
away from refusing.

```bash
php artisan sentinel:import --from=owenit --size=50000   # 54 statements per batch, for nothing
```

---

✅ **Do** — read a query plan on the engine you will deploy on. The classification of a filter as
indexed or refiner holds everywhere; the plan does not.

```bash
make test-pgsql ARGS=tests/Query/QueryPlanTest.php
```

❌ **Don't** — conclude from a fast `between()` on SQLite that a period filter is indexed. SQLite
skip-scans an index that has nothing to do with the filter; MySQL and PostgreSQL walk the table. Put
an indexed filter in front of it.

```php
// Fast on SQLite, a full pass in production
Sentinel::audits()->between($from, $to)->take(100)->get();

// Portable: the subject index finds it, the period refines it
Sentinel::audits()->for($invoice)->between($from, $to)->get();
```

---

✅ **Do** — let the unique index be the arbiter, and let `attempt()` retry. A collision on
`(stream, sequence)` is the design working: the writer that lost re-reads the tail and takes the next
position.

```php
// Nothing to wrap. StreamGate reads the tail, unique(stream, sequence) decides,
// and attempt() re-reads, making at most three attempts before it gives up.
$invoice->update(['total' => 100]);
```

❌ **Don't** — try to serialise SQLite writers yourself with an application lock around the audit
write. It cannot cover the mass, import or flush paths, it does not survive a second process that
does not take it, and it hides the collision the index would have reported.

```php
use Illuminate\Support\Facades\Cache;

// Not a fix. The buffered flush, the queue worker and sentinel:import do not pass through here.
Cache::lock('audits')->get(fn () => $invoice->update(['total' => 100]));
```

---

✅ **Do** — verify the chain on both sides of a move off SQLite. The schema is identical on all three
engines, so the move is a dump and a `migrate` — and the only thing worth proving afterwards is that
every entry still reproduces its own hash.

```bash
php artisan sentinel:verify --depth=entries --projections
```

❌ **Don't** — plan to partition later. All three partitioned stubs are for a new installation, and
none of them exists for SQLite. Deciding to divide the table is a decision about which engine you are
on, and it is cheapest before the first entry.

```bash
# There is no SQLite equivalent of this, and it is not a conversion either
php artisan vendor:publish --tag=sentinel-partitioned-pgsql-range
```

---

**See also:** [Choosing an engine](01-choosing-an-engine.md) · [PostgreSQL](02-postgresql.md) ·
[MySQL](03-mysql.md) · [Indexes and JSON](05-indexes-and-json.md) ·
[Partitioning](06-partitioning.md) · [A database of its own](07-a-database-of-its-own.md) ·
[Scaling playbook](08-scaling-playbook.md) · [Schema](../99-reference/03-schema.md) ·
[Filters reference](../06-reading/02-filters-reference.md) ·
[The hash chain](../07-integrity/01-the-hash-chain.md) ·
[Mass operations](../03-capture/05-mass-operations.md) ·
[Rehydration](../08-lifecycle/03-rehydration.md) ·
[Failure policy](../09-operations/05-failure-policy.md) ·
[The contract test suite](../11-extending/04-the-contract-test-suite.md)
