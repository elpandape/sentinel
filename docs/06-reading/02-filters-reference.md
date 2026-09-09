# 🔎 Filters reference

> Every filter the query surface publishes, one row each: what it narrows, whether an index finds it
> or merely refines a set someone else found, how it behaves per engine, and what happens when the
> driver underneath cannot answer it at all.

**On this page:** [Filters and refiners](#filters-and-refiners) ·
[The filters that find](#the-filters-that-find) · [The refiners](#the-refiners) ·
[Category filters](#category-filters-indexed-and-still-unbounded) ·
[The two inside the context](#the-two-that-live-inside-the-context) ·
[whereFieldChanged()](#wherefieldchanged) · [The relation filters](#the-relation-filters) ·
[The label filters](#the-label-filters) · [What each one refuses](#what-each-one-refuses) ·
[When a driver cannot answer](#when-a-driver-cannot-answer-a-filter) ·
[Pitfalls](#-pitfalls) · [Best practices](#-best-practices)

---

## Filters and refiners

Every criterion on `Query\AuditQuery` is one case of `Enums\Filter`. There are nineteen cases, reached
by twenty public methods — `whereTag()` and `whereAnyTag()` share `Filter::Tag` — plus the two aliases
`forModel()` and `byActor()`. Nothing on the surface takes a column name, an operator or a direction:
`Ledger\DatabaseLedger::query()` names every column itself, and the caller's value only ever arrives
as a binding.

The package divides those nineteen into two groups, and the division is about one thing only:

- A **filter that finds** reaches an index of its own. Used alone against an empty query it still
  seeks rather than walks.
- A **refiner** reaches no index of its own. Alone it costs a pass over the whole table (or over the
  whole projection); behind a filter that finds, it costs almost nothing, because it is applied to
  rows an index already located.

That division is not editorial. `tests/Query/QueryPlanTest.php` runs each filter, captures the SQL the
driver actually issued, and asks the engine's own `EXPLAIN` whether it sought or walked — on SQLite
under `make ci`, and on MySQL 9 and PostgreSQL 16 under `make test-dbs`. A refiner is a filter whose
plan the suite asserts to be a walk.

> 🧪 **Verify it.** `make test ARGS=tests/Query/QueryPlanTest.php` on SQLite;
> `make test-dbs` for the same assertions against MySQL 9 and PostgreSQL 16.

What the distinction costs at volume is the difference between reading the rows a filter matches and
reading every row the table holds. A trail only grows, so a refiner that was free at ten thousand
entries is a table scan at ten million, and no configuration changes that — the fix is always to put
a filter that finds in front of it. The one thing the package will not do is quietly downgrade the
answer: a refined read is slow, never incomplete.

---

## The filters that find

Each of these reaches an index of its own — every one of them created by the shipped migrations,
except the two that read inside `context`, which reach one only once you publish their migration and
are refiners until you do. The index column names the key in `Support\AuditSchema::indexes()` or in
the companion table's migration.

| Method | Narrows by | `Filter` case | Index that serves it |
|---|---|---|---|
| `for($subject, $id = null)` · alias `forModel()` | `subject_type` + `subject_id` | `Subject` | `(subject_type, subject_id, id)` |
| `by($actor, $id = null)` · alias `byActor()` | `actor_type` + `actor_id` | `Actor` | `(actor_type, actor_id, id)` |
| `whereEvent(AuditEvent\|string $event)` | `event` | `Event` | `event` |
| `whereType(string $type)` | `audit_type` | `Type` | `(audit_type, created_at)` |
| `whereSeverity(Severity $severity)` | `severity` | `Severity` | `(severity, created_at)` |
| `forTenant(string $tenant)` | `tenant_id` | `Tenant` | `(tenant_id, created_at)` |
| `inTransaction(AuditTransaction\|string $t)` | `transaction_id` | `Transaction` | `transaction_id` |
| `withTrace(string $trace)` | `trace_id` | `Trace` | `trace_id` |
| `whereTag(array\|string $tag)` | labels — **all** named | `Tag` | `(tag, audit_id)` on `sentinel_audit_tags` |
| `whereAnyTag(array\|string $tag)` | labels — **any** named | `Tag` | `(tag, audit_id)` on `sentinel_audit_tags` |
| `whereRelation(string $relation)` | a line's `relation` | `Relation` | `(relation, audit_id)` on `sentinel_audit_relations` |
| `whereRelated($related, $id = null)` | a line's `related_type` + `related_id` | `Related` | `(related_type, related_id, audit_id)` |
| `whereIp(string $ip)` | `context->ip` | `Ip` | **none shipped** — see [below](#the-two-that-live-inside-the-context) |
| `whereRoute(string $route)` | `context->route` | `Route` | **none shipped** — see [below](#the-two-that-live-inside-the-context) |
| `after(string $id)` | `id > ?`, ordered by `id` alone | `After` | the primary key |

`after()` is in this table but is not a criterion about what an entry *is* — it is a place in a walk.
It compiles to `where('id', '>', $after)` and orders by `id` alone; `byOccurrence()` and `latest()`
are refused beside it, whichever was asked for first. See
[Order, paging and walking](03-order-paging-and-walking.md).

Two methods change the order and are **not** `Filter` cases at all, so no driver can refuse them:
`byOccurrence()` (order by `occurred_at` instead of `created_at`) and `latest()` (reverse it).

---

## The refiners

| Method | Narrows by | `Filter` case | Why no index reaches it |
|---|---|---|---|
| `whereSource(Source $source)` | `source` | `Source` | The column carries no index. Nine values over the whole table is a set an index cannot usefully divide. |
| `between($from, $to)` | `created_at`, both ends inclusive | `Period` | `created_at` is only ever the **second** column of a composite (`(tenant_id, created_at)`, `(audit_type, created_at)`, `(severity, created_at)`), so it is reachable only behind the leading column. |
| `whereFieldChanged(string $path)` | a `path` inside the `changes` JSON | `FieldChanged` | No index covers `changes`. The predicate is a correlated `exists` over the array. |
| `whereVersion(int ...$versions)` | `version` | `Version` | The counter carries no index. |
| `whereOperation(...$operations)` | a line's `operation` | `Operation` | No index of `sentinel_audit_relations` begins with `operation`, so one of the two tables is walked in full. |

```php
use ElPandaPe\Sentinel\Enums\Source;
use ElPandaPe\Sentinel\Facades\Sentinel;

// A pass over the whole table on MySQL and PostgreSQL.
Sentinel::audits()->whereSource(Source::Cli)->take(50)->get();

// The same question, seeking through the subject index and refining what it found.
Sentinel::audits()->for($invoice)->whereSource(Source::Cli)->take(50)->get();
```

> 🐘 **Engine.** `between()` alone reads no index on MySQL 9 or PostgreSQL 16. SQLite still reaches
> one for it, which is a property of that planner and not of the schema — the
> suite asserts the difference rather than pretending the three agree.

> 📌 **Note.** `between()` always bounds `created_at`, the ledger's clock, even under `byOccurrence()`
> or `Sentinel::timeline()`, which order by `occurred_at`. Narrowing and ordering follow different
> clocks on purpose: `created_at` is the partition key of both published range plans and the clock
> retention counts from, so a window on the clock of the fact would not line up with the one a prune
> works in. See [Partitioning](../10-database-engines/06-partitioning.md) and
> [Retention and pruning](../08-lifecycle/01-retention-and-pruning.md).

---

## Category filters: indexed and still unbounded

Four of the filters that find name a *category* rather than an entity: `whereEvent()`,
`whereType()`, `whereSeverity()` and — once its index migration is published — `whereRoute()`. Their
index locates the rows, but nothing bounds how many rows a category holds. `whereEvent('updated')` on
a busy trail is most of the table; `whereType('model')` is very nearly all of it.

They are deliberately **not** reclassified as refiners — the index does find the rows — but the same
advice applies: they earn their keep beside another filter, not on their own.

```php
// Indexed, and still most of the table.
Sentinel::audits()->whereType('model')->paginate(50);

// Indexed, and bounded by a subject.
Sentinel::audits()->for($invoice)->whereType('model')->paginate(50);
```

---

## The two that live inside the context

`whereIp()` and `whereRoute()` are the only filters that read a key inside `context` rather than a
column of their own. Their `Filter` values (`'ip'`, `'route'`) *are* the keys they read in `context`, so
a driver translating them into its own JSON dialect has the path without a lookup table.

Both values are written by `Context\Resolvers\RequestResolver`: `ip` from `$request->ip()`, and
`route` from the route's **name**, falling back to its `uri()` when it has none. With no request in
the runtime — a queue worker, a console command, the scheduler — the resolver returns nothing at all,
so those entries carry neither key and answer neither filter.

Both match **exactly and case-sensitively on all three engines**. That costs MySQL an extra clause:

| Engine | SQL `Ledger\ContextPredicate` emits |
|---|---|
| SQLite | `json_extract(case when json_valid(context) then context else '{}' end, '$.ip') = ?` |
| PostgreSQL | `context->>'route' = ?` |
| MySQL | `json_unquote(json_extract(context, '$.ip')) = ? and json_unquote(json_extract(context, '$.ip')) collate utf8mb4_bin = ?` |

MySQL's default collation is accent- and case-insensitive, so `= 'invoices.show'` there would answer
with entries PostgreSQL and SQLite would not return. A binary collation alone fixes the answer and
loses the index, because a generated column indexed under one collation cannot serve a comparison
under another. So both clauses go in: the insensitive one matches a superset and is what an index can
serve, and the binary one behind it decides.

### The index is a migration you publish

Nothing in the shipped schema indexes `context`. Without the migration both filters are **refiners**,
and that is exactly what `tests/Query/QueryPlanTest.php` asserts.

```bash
php artisan vendor:publish --tag=sentinel-json-indexes
php artisan migrate
```

| Engine | What the migration creates |
|---|---|
| PostgreSQL · SQLite | A B-tree over the expression itself, written in doubled parentheses — PostgreSQL reads a single pair as a column list and refuses the operator inside it. |
| MySQL | A `VIRTUAL INVISIBLE` generated column per filter (`context_ip`, `context_route`) plus a B-tree over it. `STORED` would rewrite the table and widen every row; a *visible* generated column would ride along in `select *` and then be handed back to MySQL on a fanout or a rehydration insert, which it refuses. |

The migration never writes the JSON path itself — it asks `Ledger\ContextPredicate::expression()` for
the same string the driver compiles, so the index and the query cannot drift apart.

> ⚠️ **Warning.** The index is not free and that is why it is opt-in. The migration records the cost
> it was measured at: **+15 % per write on PostgreSQL 16 and +21 % on MySQL 9**, against a table
> carrying the columns and indexes the published migrations create. An installation that never asks
> where an entry came from should not pay it.

If you filter by address or route only occasionally, skip the migration and put an indexed filter in
front instead — the read is then a seek plus a refinement, and the write path stays untouched.

---

## `whereFieldChanged()`

```php
Sentinel::audits()->for($patient)->whereFieldChanged('profile.address.city')->get();
```

The argument is read by `Diff\Pointer::of()`: a string starting with `/` passes through as a literal
JSON Pointer, anything else is read as dot notation and converted. The comparison is then **the
pointer itself, or anything beneath it** — `Diff\Pointer::covers()`, the same reading
`$audit->diffFor()` uses, so the package has one definition of "touched this field" and not two.

| You ask for | It matches | It does not match |
|---|---|---|
| `email` | `/email` | `/email_verified_at`, `/emails`, `/Email` |
| `profile` | `/profile`, `/profile/address/city` | `/profile_photo` |
| `profile.address.city` | `/profile/address/city` | `/profile`, `/profile/address` |

The slash is what keeps `/email` from reaching `/email_verified_at`. Letter case is significant on all
three engines. `%` and `_` in a field name are inert, because the comparison is an equality and a
substring rather than a `LIKE` — `LIKE` is ASCII case-insensitive on SQLite and MySQL and
case-sensitive on PostgreSQL, so no amount of escaping would make the three answer alike.

The same predicate is reachable from the relation a model already has, through an Eloquent scope:

```php
$patient->audits()->field('email')->get();   // Models\Audit::field()
```

### Per engine, and the engines it refuses

| Engine | How `Ledger\ChangedFieldPredicate` reads the array |
|---|---|
| SQLite | `json_each` behind a `json_valid()` guard, filtered on `je.type = 'object'` |
| PostgreSQL | `jsonb_array_elements` behind a `jsonb_typeof(col) = 'array'` guard |
| MySQL | `JSON_TABLE` after a derived table, with `json_type(...) = 'STRING'` and `collate utf8mb4_bin` |
| anything else | `LedgerException::cannotTranslateOn()` — *"Sentinel has no field predicate for the [x] engine … Supported: mysql, pgsql, sqlite."* |

> 🐘 **Engine.** SQLite's guard is not decoration. `$table->jsonb()` gives SQLite a bare `text` column
> with no `CHECK`, so a row the package did not write can hold something `json_each` cannot walk —
> and a statement that aborts mid-scan is answered by `PDOStatement::fetchAll()` with a **partial
> result and no exception**. Without the guard, one poisoned row would make an audit read quietly
> incomplete. `tests/Ledger/PoisonedChangesTest.php` plants eight kinds of such row and asserts the
> answer is unchanged.

### MariaDB is refused by name

`Ledger\ChangedFieldPredicate` and `Ledger\ContextPredicate` support `mysql`, `pgsql` and `sqlite`.
Anything else — MariaDB included — is refused rather than guessed at, because MariaDB names the binary
collation differently and answering with something that might not mean the same thing is worse than
declining.

Two things to know about how that refusal lands:

1. It is thrown **when the read executes**, not when you add the filter. `whereFieldChanged()` itself
   only checks that the *driver* declares the filter; the *engine* is consulted by
   `DatabaseLedger::query()`. So the exception surfaces at `get()`, `paginate()` or `compare()`.
2. The engine is identified by `Connection::getDriverName()`, which returns your configured `driver`
   key. A MariaDB server configured under `'driver' => 'mariadb'` is refused; the same server
   configured under `'driver' => 'mysql'` is not — it is handed the MySQL dialect, outside the
   support matrix and untested.

---

## The relation filters

`whereRelation()`, `whereRelated()` and `whereOperation()` do not travel as three criteria. They
accumulate into one `Query\RelationCriteria`, and an entry answers only when **a single line**
satisfies all of the parts at once.

```php
use ElPandaPe\Sentinel\Enums\RelationOperation;

// When was this carer detached from this patient's care team?
Sentinel::audits()
    ->for($patient)
    ->whereRelation('carers')
    ->whereRelated($carer)
    ->whereOperation(RelationOperation::Detach)
    ->get();
```

Asked as three independent predicates, that query would also be answered by an entry that *attached*
the carer and *detached* somebody else — a different fact. `DatabaseLedger::narrowByRelation()` puts
all three inside one correlated `exists` into `sentinel_audit_relations`; an array-backed driver calls
`RelationCriteria::matches()`, which walks the entry's own lines. The two agree because
`Ledger\RelationProjection` is the single definition of how a line becomes a row.

| | Behaviour |
|---|---|
| Repeated calls | `whereRelation()` and `whereRelated()` overwrite their part; `whereOperation()` **accumulates** and deduplicates, so naming several asks for any of them |
| Accepted operations | `RelationOperation::Attach\|Detach\|Update`, or the strings `attach`, `detach`, `update` |
| Where the answer comes from | `sentinel_audit_relations` on `DatabaseLedger`; the entry's `changes` lines on any array-backed driver |
| Shorthand | `$patient->relationHistory('carers')` is `Sentinel::audits()->for($patient)->whereRelation('carers')` |

`whereRelation()` and `whereRelated()` both seek into the projection. `whereOperation()` alone forces a
full pass over one of the two tables, and *which* table differs by planner — SQLite walks the trail and
seeks the projection, PostgreSQL walks both, MySQL rewrites the correlated `exists` into a semi-join.
The property the suite asserts is that the pair is never both sought.

> 📌 **Note.** `whereFieldChanged()` finds nothing on a relation entry, and says so by returning an
> empty set. The predicate reads a `path` key; a relation line carries `relation` and `operation` and
> no `path` at all. A field is an attribute; a relation is not one. See
> [Relationship auditing](../03-capture/04-relationships.md).

---

## The label filters

```php
Sentinel::audits()->whereTag('finance')->whereTag('reviewed')->get();   // both labels
Sentinel::audits()->whereTag(['finance', 'reviewed'])->get();           // the same question
Sentinel::audits()->whereAnyTag(['finance', 'payroll'])->get();         // at least one
```

Both spellings accumulate into one `Query\TagCriteria`, which keeps two separate lists — `all` and
`any` — and applies both. A repeated call therefore keeps narrowing rather than replacing, which is the
opposite of every single-value criterion on the surface. Labels are deduplicated and order is
preserved.

`DatabaseLedger` compiles one correlated `exists` per required label and one for the optional set,
each a seek into `(tag, audit_id)`. How selective that is depends entirely on the label: a planner is
free to walk the small side instead when a label covers a large share of it, which is the right plan
for a label that broad.

> ⚠️ **Warning.** On an array-backed driver (`MemoryLedger`, `ArchiveLedger`, any third-party driver
> using `Ledger\ArrayQuery`), `ArrayQuery::labelsOf()` returns `[]` unless the entry's `tags` relation
> is loaded. An entry appended without it reads as an entry carrying *no* labels, not as one whose
> labels are unknown — so it silently fails every label filter. See [Labels](06-labels.md).

---

## What each one refuses

Every method validates its argument, and every one of these is an
`Exceptions\QueryException` (an `InvalidArgumentException`) — a developer-facing usage error, in plain
English, not a translated string.

| Method | Throws | When |
|---|---|---|
| `for()` · `by()` · `whereRelated()` | `QueryException::unsavedModel()` | a model with no key |
| | `QueryException::missingKey()` | a type string with no `$id` |
| | `QueryException::unreferenceable()` | an object that is not an Eloquent model |
| `whereType()` | `QueryException::noType()` | `''` |
| `whereIp()` · `whereRoute()` | `QueryException::noContextValue()` | `''` |
| `between()` | `QueryException::backwardsPeriod()` | `$to < $from` |
| `whereTag()` · `whereAnyTag()` | `QueryException::noLabels()` | `[]` |
| `whereFieldChanged()` | `QueryException::noField()` | a path whose pointer resolves to `''` |
| `whereOperation()` | `QueryException::unknownOperation()` | a string that is not `attach`, `detach` or `update` |
| `after()` | `QueryException::noCursor()` | `''` |
| `after()` · `byOccurrence()` · `latest()` | `QueryException::cursorOffItsAxis()` | a cursor beside an order by clock, whichever was asked for first |
| any filter | `LedgerException::cannotFilterBy()` | the driver does not declare it — **as you call it** |
| `whereFieldChanged()` · `whereIp()` · `whereRoute()` | `LedgerException::cannotTranslateOn()` | the engine has no dialect — **when the read executes** |

`whereVersion()` and `whereOperation()` are variadic and have **no** emptiness guard, and called with
no arguments they do not behave alike. `whereVersion()` leaves `versions` empty and narrows nothing —
a silent no-op, unlike `whereTag([])`. `whereOperation()` still installs a `Query\RelationCriteria`
with none of its three parts set, and `DatabaseLedger` compiles that into a bare `exists` into
`sentinel_audit_relations`: the read narrows to entries that touched *some* relation. Neither throws.

---

## When a driver cannot answer a filter

The query surface is stated against `Contracts\Ledger`, and not every backend can translate every
filter. The package's answer is to refuse loudly and early rather than to drop a criterion quietly — a
trail that shows the wrong history is worse than one that declines to answer.

### The declaration

A driver declares what it can translate through an optional interface:

```php
use ElPandaPe\Sentinel\Contracts\DeclaresFilters;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Enums\Filter;

final class RedisLedger implements DeclaresFilters, Ledger
{
    /** @return list<Filter> */
    public function supportedFilters(): array
    {
        return [Filter::Subject, Filter::Actor, Filter::Tenant, Filter::Period];
    }

    // write(), writeMany(), append(), find(), query(), stream() …
}
```

`Contracts\DeclaresFilters` is deliberately **not** part of `Contracts\Ledger`. Adding a method to a
published contract would break every third-party driver that does not need it.

### The fallback, which is not "everything"

`Filter::answeredBy($ledger)` returns `supportedFilters()` when the driver implements the interface,
and `Filter::assumed()` when it does not. `Filter::assumed()` is the set as it stood when the contract
was published, and **it does not grow**:

| Assumed of an undeclaring driver | Never assumed |
|---|---|
| `Subject`, `Actor`, `Event`, `Severity`, `Source`, `Tenant`, `Transaction`, `Trace`, `Period` | `Tag`, `FieldChanged`, `Version`, `Relation`, `Related`, `Operation`, `Type`, `Ip`, `Route`, `After` |

A driver written against the original contract never named the ten on the right, and assuming it can
translate them would have it dropping a criterion instead of refusing it — the one failure the
interface exists to prevent.

### Where the refusal lands

`AuditQuery::accepting()` runs on **every** filter method, before the clone. So the exception is
thrown at the call site that added the filter, with the offending method named:

```
LedgerException: RedisLedger cannot filter by tag, so whereTag() is not part of
the query it answers.
```

That is a `BadMethodCallException`. It is not a failure of the read; it is a statement that the
question cannot be asked of this ledger.

### What the shipped drivers declare

| Driver | `supportedFilters()` | Notes |
|---|---|---|
| `Ledger\DatabaseLedger` | every case | Two of them additionally need a supported engine |
| `Ledger\MemoryLedger` | every case | Answered by `Ledger\ArrayQuery` walking what it holds |
| `Ledger\NullLedger` | every case | Answers all of them with an empty collection — refusing one would claim it cannot translate it |
| `Ledger\ArchiveLedger` | every case | Answered by scanning the batches it wrote itself, and only those |
| `Ledger\FanoutLedger` | its **primary's** set | A primary that does not declare falls back to *every* case here, not to the assumed nine |

See [The shipped drivers](../11-extending/02-shipped-drivers.md).

### Writing code that degrades instead of breaking

Ask before you narrow. `Filter::answeredBy()` is public and takes the resolved ledger:

```php
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Enums\Filter;
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Query\AuditQuery;

$answerable = Filter::answeredBy(app(Ledger::class));

$query = Sentinel::audits()->for($invoice);

if (in_array(Filter::Tag, $answerable, true)) {
    $query = $query->whereTag('finance');
}

// Or offer the user only the filters this installation can answer:
$offer = array_map(static fn (Filter $filter): string => $filter->method(), $answerable);
```

`Filter::method()` names the public method that reaches each case, which is also what the refusal
message interpolates — so a UI built from `answeredBy()` and a refusal message read the same way.

The alternative is catching, which is right when the ledger is swappable at run time and wrong when it
is a loop:

```php
use ElPandaPe\Sentinel\Exceptions\LedgerException;

try {
    $query = $query->whereRoute($route);
} catch (LedgerException $unanswerable) {
    // Degrade: the read is broader, not wrong. Say so in the UI.
    report($unanswerable);
}
```

> 🔒 **Security.** Degrading a filter widens what comes back. If a filter is the thing keeping one
> tenant's entries out of another tenant's screen, do not catch its refusal — fail the request.
> Narrowing by `forTenant()` is a criterion, not an authorisation boundary; see
> [Multi-tenancy](../04-context/04-multi-tenancy.md).

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `whereIp()` returns nothing for entries written by a queue worker or a command | `RequestResolver` returns nothing at all with no request in the runtime, so those entries carry no `ip` or `route` key | Filter those entries by `whereSource()` behind an indexed filter instead |
| `whereRoute('Invoices.Show')` returns nothing on MySQL, where you expected a case-insensitive match | The predicate adds a `collate utf8mb4_bin` recheck so all three engines answer alike | Pass the route name exactly as the resolver recorded it — the route's name, or its `uri()` when it has none |
| `whereFieldChanged('email')` also returns entries that only changed `/email/work` | The match is the pointer **or anything beneath it**, the same reading `diffFor()` uses | Nothing to fix if you meant the subtree; use the complete pointer when you mean a leaf |
| `whereFieldChanged('carers')` returns an empty set on a relation entry | Relation lines carry `relation`/`operation` and no `path`; the predicate reads `path` | Use `whereRelation('carers')` |
| `Audit::query()->field('')` matches every entry that has a diff | The scope calls `Pointer::of()` with no emptiness guard, so the predicate becomes "any path starting with `/`" — unlike `whereFieldChanged('')`, which throws | Validate the field name before reaching the scope |
| `whereVersion()` narrows nothing and raises nothing | It is variadic with no emptiness guard; `versions` stays `[]` and the driver's `when()` never fires on an empty list | Guard the argument list yourself |
| `whereOperation()` with no arguments returns only entries that touched a relation | It installs an empty `RelationCriteria` all the same, which compiles into a bare `exists` into `sentinel_audit_relations` | Pass at least one operation, or leave the filter off |
| `LedgerException: … cannot filter by tag` from a third-party driver that used to work | The driver does not implement `DeclaresFilters`, so it is assumed to answer only the nine of `Filter::assumed()` | Implement `DeclaresFilters` on the driver and name the set honestly |
| `LedgerException: … no field predicate for the [mariadb] engine` only when the page renders | The engine check runs inside `DatabaseLedger::query()`, not in `whereFieldChanged()` | Move the check forward: verify the connection's driver at boot, or keep the trail on a supported engine |
| A MariaDB server is *not* refused | `getDriverName()` returns your configured `driver` key, so `'driver' => 'mysql'` pointed at MariaDB gets the MySQL dialect | Configure MariaDB as `'driver' => 'mariadb'` so the refusal is explicit |
| `for(Invoice::class, 500)` returns nothing while `for($invoice)` works | `Support\Reference::to()` normalises a **model** through `getMorphClass()` but passes a class-string through unchanged; under a registered morph map the column holds the alias | Pass the model, or the alias the entry actually recorded |
| `whereTag()` answers nothing on `MemoryLedger` or `ArchiveLedger` for entries that were appended | `ArrayQuery::labelsOf()` reads only a **loaded** `tags` relation and reads an unloaded one as no labels | Load `tags` before appending, or ask the label question of the primary ledger |
| `whereOperation('attach')` alone takes seconds on a large trail | No index of the projection begins with `operation`, so one of the two tables is walked | Put `whereRelation()` or `whereRelated()` in front of it |
| A fanout reports filters its primary cannot answer | `FanoutLedger::supportedFilters()` falls back to *every* case when the primary does not declare, where the same primary used directly would be assumed to answer nine | Implement `DeclaresFilters` on the primary |

---

## ✅ Best practices

✅ **Do** — put a filter that finds in front of every refiner. Behind an index, a refiner costs
almost nothing; alone, it is a pass over the whole table on MySQL and PostgreSQL.

```php
Sentinel::audits()
    ->for($invoice)                                   // seeks (subject_type, subject_id, id)
    ->whereFieldChanged('total')                      // refines what the seek found
    ->between($from, $to)
    ->get();
```

❌ **Don't** — lead with a refiner and hope the period bounds it. `between()` reaches no index of its
own, so this reads the table and then discards most of it.

```php
Sentinel::audits()
    ->between($from, $to)
    ->whereFieldChanged('total')
    ->take(50)
    ->get();
```

✅ **Do** — ask `Filter::answeredBy()` which filters this installation can answer, and build the UI
from that. `Filter::method()` names the method behind each case, so the list and the refusal message
agree.

```php
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Enums\Filter;

$answerable = Filter::answeredBy(app(Ledger::class));

if (in_array(Filter::Route, $answerable, true)) {
    $query = $query->whereRoute($route);
}
```

❌ **Don't** — wrap every filter in a `try`/`catch` and carry on. A caught refusal widens the answer
silently, which is the failure `DeclaresFilters` exists to prevent.

```php
foreach ($requested as $method => $value) {
    try {
        $query = $query->{$method}($value);
    } catch (LedgerException) {
        // the read is now broader than the user asked for, and nothing says so
    }
}
```

✅ **Do** — publish `sentinel-json-indexes` only when you filter by address or route regularly, and
know the write cost you are buying: +15 % on PostgreSQL 16, +21 % on MySQL 9.

```bash
php artisan vendor:publish --tag=sentinel-json-indexes
php artisan migrate
```

❌ **Don't** — publish it "just in case" on a write-heavy installation that never asks the question.
Every write pays for it; no read benefits until one is issued.

✅ **Do** — slice by kind of entry with `whereType()`, not by event name. An application is free to
call its own custom event `updated`, and only the type tells the two apart.

```php
Sentinel::audits()->for($invoice)->whereType('transition')->get();
```

❌ **Don't** — use `whereEvent()` as a proxy for a kind. Four of the six pivot APIs record
`event = 'synced'`, and a custom event may carry any name at all.

```php
Sentinel::audits()->whereEvent('synced')->get();   // toggle() and updateExistingPivot() too
```

✅ **Do** — chain the three relation filters when you mean one line. They fold into a single
existence check, which is almost always the question you meant.

```php
Sentinel::audits()
    ->whereRelation('carers')
    ->whereRelated($carer)
    ->whereOperation(RelationOperation::Detach)
    ->get();
```

❌ **Don't** — ask them as three separate reads and intersect the results. An entry that attached one
record and detached another would answer a question about the second being attached.

```php
$byRelation = Sentinel::audits()->whereRelation('carers')->get();
$byRecord   = Sentinel::audits()->whereRelated($carer)->get();   // a different fact
```

✅ **Do** — pass the full JSON Pointer when you mean a leaf, and dot notation when you mean a subtree.
Both spellings reach the same predicate.

```php
Sentinel::audits()->for($patient)->whereFieldChanged('/profile/address/city')->get();
```

❌ **Don't** — read `whereFieldChanged('email')` as an exact match. It is pointer-or-beneath, exactly
like `$audit->diffFor('email')`.

```php
// also matches /email/work and /email/home
Sentinel::audits()->whereFieldChanged('email')->get();
```

✅ **Do** — declare `Contracts\DeclaresFilters` on any driver you write, and name only what the
backend really translates. Refusing a filter is a supported answer.

```php
public function supportedFilters(): array
{
    return [Filter::Subject, Filter::Actor, Filter::Tenant, Filter::Period, Filter::After];
}
```

❌ **Don't** — ship a driver that silently ignores a criterion it does not recognise. It will hand
back entries nobody asked for, and the caller has no way to tell.

```php
public function query(AuditQuery $query): AuditCollection
{
    return $this->everything();   // every filter dropped, no refusal
}
```

---

**See also:** [The Query API](01-the-query-api.md) ·
[Order, paging and walking](03-order-paging-and-walking.md) ·
[Field history and comparing versions](04-field-history.md) · [The timeline](05-the-timeline.md) ·
[Labels](06-labels.md) · [Relationship auditing](../03-capture/04-relationships.md) ·
[Diffs](../03-capture/03-diffs.md) · [Execution context](../04-context/01-execution-context.md) ·
[Indexes and JSON](../10-database-engines/05-indexes-and-json.md) ·
[MySQL](../10-database-engines/03-mysql.md) · [PostgreSQL](../10-database-engines/02-postgresql.md) ·
[SQLite](../10-database-engines/04-sqlite.md) ·
[The Ledger contract](../11-extending/01-the-ledger-contract.md) ·
[Writing a ledger driver](../11-extending/03-writing-a-ledger-driver.md) ·
[Schema](../99-reference/03-schema.md) · [Enums](../99-reference/04-enums.md) ·
[Exceptions](../99-reference/06-exceptions.md)
