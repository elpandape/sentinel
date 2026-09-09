# 📥 Mass operations

> How Sentinel records `Builder::update()`, `Builder::delete()` and `Builder::upsert()` — the three
> statements Eloquent fires no model event for — and what each of the three recording modes costs.

**On this page:** [Opt-in per query](#opt-in-per-query-and-never-global) · [The three modes](#the-three-modes) · [What a summary says](#what-a-summary-entry-says) · [The criteria](#the-criteria) · [An entry per row](#an-entry-per-row) · [`delete()` and `upsert()`](#delete-and-upsert) · [`affected_rows`](#affected_rows-is-stored-unnormalised) · [Expressions](#a-column-written-from-an-expression) · [The global macro](#auditing-is-an-un-namespaced-global-macro)

---

## The blind spot

`$invoice->update([...])` fires `updating` and `updated`, and Sentinel's observer is there for both.
`Invoice::query()->where(...)->update([...])` fires nothing. The same is true of `Builder::delete()`
and `Builder::upsert()`: the rows change, the database is happy, and no model event announces it.
Every auditing package in this ecosystem hits that wall, and most document it as a limitation.

Sentinel closes it, one query at a time, with `auditing()`:

```php
use App\Models\Invoice;

$affected = Invoice::query()
    ->where('due_at', '<', now())
    ->auditing()
    ->update(['status' => 'overdue']);
```

`auditing()` is a macro on `Illuminate\Database\Eloquent\Builder`, registered in
`src/SentinelServiceProvider.php`. It returns a wrapper that exposes exactly three methods —
`update()`, `delete()`, `upsert()` — and each returns the same `int` the underlying builder method
returns. Chain off it; do not assign it to a variable and do not type-hint it. The class is
`ElPandaPe\Sentinel\Mass\AuditedQuery` and it carries `@internal`, as does everything else under
`src/Mass/`.

## Opt-in per query, and never global

|  |  |
|---|---|
| ✅ | You ask for it, on the query that should be recorded |
| ✅ | Every other query in the application is untouched and pays nothing |
| ❌ | Sentinel does **not** intercept mass statements globally |
| ❌ | There is no configuration key that turns that on |

A query that never calls `auditing()` executes one statement and writes no entry — there is no
listener on the query builder, no extra `select`, no branch. That is checked, not asserted: the
suite counts the statements a plain `Builder::update()` issues and expects exactly one.

The reason is arithmetic. Recording every mass statement in `individual` would turn a one-line
`update` into as many inserts as it touched rows, on queries that have nothing to do with auditing,
and it would put this package on the execution path of every query the application makes. So the
switch is per query and there is nothing to flip.

Two things are refused at the `auditing()` call itself, before `update()`/`delete()`/`upsert()` is
even reached:

| Condition | Exception | Message you will see |
|---|---|---|
| The builder's model does not use `Concerns\Auditable` | `Exceptions\ConfigurationException` | `App\Models\Invoice does not use the Auditable trait, so a mass operation over it has no declarations saying which of its columns may be written down.` |
| `auditing('thorough')` — a string no `MassMode` case matches | `Exceptions\ConfigurationException` | `Sentinel configuration key [sentinel.auditing] has unknown value [thorough]. Accepted: summary, individual, hybrid.` |

The first refusal is deliberate and not a convenience check. `$auditExclude`, `$auditRedact`,
`$auditEncrypt` and `$auditHash` are what decide which values from the query may be written into the
entry — see [What a model declares](../02-getting-started/03-what-a-model-declares.md). A model that
declared none would be audited with nothing protecting it, so it is refused instead.

> ⚠️ **Warning.** The second message names `sentinel.auditing` as if it were a configuration key.
> There is no such key. The exception type is reused; what actually happened is a bad argument to
> `auditing()`. Do not go looking in `config/sentinel.php`.

While auditing is paused or switched off, `auditing()` still returns the wrapper, but the wrapper
runs the statement and nothing else: no read, no transaction, no entry. `Sentinel::withoutAuditing()`
around a mass update leaves the statement exactly as it found it.

## The three modes

The mode is `ElPandaPe\Sentinel\Enums\MassMode` and is accepted as the enum case or as its string
value. Without an argument, `auditing()` reads `mass_operations.mode`, which ships as `summary`.

```php
use ElPandaPe\Sentinel\Enums\MassMode;

Invoice::query()->where(...)->auditing()->update([...]);                    // config: summary
Invoice::query()->where(...)->auditing('hybrid')->update([...]);            // by value
Invoice::query()->where(...)->auditing(MassMode::Individual)->update([...]); // by case
```

| Mode | What it writes | Extra reads | Cost shape |
|---|---|---|---|
| `summary` | One entry: criteria, the columns written, `affected_rows` | None | Constant for a set of any size |
| `individual` | The summary **and** one entry per row, each with its real `before` | One, unbounded — the whole matched set | Linear in rows |
| `hybrid` | The summary always; the per-row entries while the set fits under `threshold` | One, bounded to `threshold + 1` rows | Constant above the threshold |

`hybrid` decides which side of the line a set falls on by reading one row past the threshold, not by
issuing a `count(*)`. A count would be a second statement over the same predicate; reading
`threshold + 1` rows answers the same question and bounds the price of asking by the threshold
itself. If the extra row comes back, the mode degrades to the summary alone and the rows it read are
thrown away.

### The configuration keys

| Key | Default | What it does | When to change it |
|---|---|---|---|
| `mass_operations.mode` | `summary` | The mode `auditing()` uses when the call names none. A missing key falls back to `summary` in code, so a config file published before the section existed still boots. | Set it to `hybrid` only if nearly every audited mass statement in the application should describe rows. `individual` is not a value to put here. |
| `mass_operations.threshold` | `100` | How many rows `hybrid` will describe one by one. Floored at 1 — a threshold of zero is `summary` under another name. | Raise it to the largest set genuinely worth describing row by row. Remember the bounded read is paid on every hybrid operation, degraded or not. |
| `mass_operations.sample` | `20` | How many values of a long `whereIn` / `whereNotIn` set the criteria keeps. Floored at 1. | Lower it where the identifiers are themselves sensitive. |

A non-integer `threshold` or `sample` throws `ConfigurationException` (`must be an integer or null`);
an unknown `mode` throws with `mass_operations.mode` in the message.

### What each one costs

Measured on the package's own write-path benchmark, over a set of five hundred rows, on SQLite:

| Mode | Per row | Against the same update unaudited |
|---|---|---|
| not audited | 0.7 µs | — |
| `summary` | 4.6 µs | +579 % |
| `hybrid`, over its threshold | 19.6 µs | +2,791 % |
| `individual` | 889.7 µs | +131,194 % |

Read the middle column. `summary` is about 2.3 ms for the whole operation and stays there for a set
of any size, because it reads nothing and writes one entry. `individual` is roughly nine hundred
microseconds per **row**: five hundred rows means five hundred and one entries, and an update over
3,500 rows means 3,501. `hybrid` over its threshold costs about four times `summary` — the
difference is the bounded read, and that figure is its worst case, a threshold one row short of the
set.

> 📌 **Note.** These are SQLite figures from one machine. Treat them as ratios between the modes,
> not as absolutes for your hardware or your engine.

### Telling a deliberate summary from a degraded hybrid

The summary entry records the mode that settled it, under `metadata.mass.mode`:

```php
$entry->metadata;   // ['mass' => ['mode' => 'hybrid']]
```

Without it, a deliberate `summary` and a `hybrid` that degraded past its threshold write the same
entry byte for byte — same type, same `affected_rows`, same criteria — and nothing distinguishes a
choice from a description that was lost. The key is written even when hybrid did describe every row.
Per-row entries carry no `metadata` at all; the mode lives on the summary only. Entries written
before the key existed have no `metadata.mass` and verify exactly as they did.

## What a summary entry says

```php
use ElPandaPe\Sentinel\Models\Audit;
use App\Models\Invoice;

$affected = Invoice::query()
    ->where('status', 'pending')
    ->whereIn('plan_id', [1, 2, 3])
    ->auditing()
    ->update(['status' => 'overdue']);

$entry = Audit::query()->where('audit_type', 'mass')->latest('id')->firstOrFail();

$entry->audit_type;     // 'mass'
$entry->event;          // 'updated'
$entry->subject_type;   // 'App\Models\Invoice'
$entry->subject_id;     // null  — there is no one row, there is a set
$entry->affected_rows;  // what the engine reported
$entry->metadata;       // ['mass' => ['mode' => 'summary']]

$entry->toArray()['changes'];
// [['path' => '/status', 'op' => 'replace', 'new' => 'overdue']]
```

Three facts about that entry are worth stating outright, because consumers assume otherwise:

- **`subject_id` is null.** Any code that reads a `mass` entry and dereferences `subject_id` breaks
  on the summary. It is populated only on per-row entries.
- **`changes` carries `new` and never `old`.** Nothing was read, so no earlier value exists and none
  is invented. The internal `Change` records `oldKnown: false` rather than writing a `null` that
  would read as "it used to be null". This is the structural cost of `summary`, and it is exactly
  why `individual` exists.
- **The written columns are sorted.** `Mass\Writes` `ksort`s the values array, so two operations
  that wrote the same columns produce the same `changes` however the caller ordered the array — and
  therefore the same hash.

A mass `delete()` names no column, so its summary has `changes` set to `null` rather than an empty
list.

The presenter reads a summary as a set rather than as a record:

```php
// 'Someone changed 500 Invoice records'
```

> 🔐 **Integrity.** `criteria` and `affected_rows` are both columns of the canonical payload, so they
> are inside what `hash` seals — see [Canonicalization](../07-integrity/03-canonicalization.md).
> A change to what either column holds is a change to the payload, and that costs a
> `payload_version` bump plus a backwards-compatibility test, exactly like `sequence`, `hash` and
> `previous_hash`. Nothing in this page's behaviour changes them at runtime.

## The criteria

`criteria` is what the operation was aimed at, written down as a structure. It is never SQL with its
values interpolated back into it — a rendered `where email = 'ada@example.com'` would answer "which
rows" by leaking the value that selected them.

```json
{
  "wheres": [
    {"type": "basic", "boolean": "and", "column": "status", "operator": "=", "value": "pending"},
    {"type": "in", "boolean": "and", "column": "plan_id", "count": 3, "values": [1, 2, 3]}
  ]
}
```

`ElPandaPe\Sentinel\Mass\Criteria` names the clauses it understands one at a time; everything else is
recorded as its shape alone. That direction is deliberate — a `whereRaw` can carry literals no
declaration of your model reaches, and a clause a future framework release invents would otherwise be
written out whole by a serialiser that had never seen it.

| Query clause | Recorded as |
|---|---|
| `where('status', 'pending')` | `{type: basic, boolean, column, operator, value}` |
| `whereNull('deleted_at')` | `{type: null, boolean, column}` — no value |
| `whereIn('id', range(1, 5000))` | `{type: in, boolean, column, count: 5000, values: [...]}`, `values` bounded by `mass_operations.sample` |
| `whereNotBetween('price', [10, 20])` | `{type: between, boolean, column, not: true, values: [10, 20]}` |
| `whereLike('name', '%ada%')` | `{type: like, boolean, column, not: false, value}` |
| `whereColumn('name', '!=', 'email')` | `{type: column, boolean, first, operator, second}` — both sides are column names |
| A nested `where(fn ($q) => …)` group | `{type: nested, boolean, wheres: [...]}` — descended into, not flattened |
| `whereRaw("email like 'x%'")` | `{type: raw, boolean: and}` — nothing else |
| `whereExists(fn ($q) => …)` | `{type: exists, boolean: and}` — nothing else |
| `whereJsonContains('options', [...])` | `{type: json_contains, boolean: and}` — a clause the serialiser does not know |
| `join('authors', …)` | `criteria.joins`: `[{type: 'inner', table: 'authors'}]`. A `joinSub` whose table is a query is left out entirely |
| `orderBy('id')` / `orderByDesc('price')` | `criteria.order`: `[{column, direction}]` |
| `orderByRaw('field(status, ?, ?)', [...])` | `criteria.order`: `[{type: 'raw'}]` |
| `limit(10)` / `offset(20)` | `criteria.limit` / `criteria.offset`, present only when set |

`limit`, `offset` and `order` are recorded because MySQL accepts `update … limit`. Without them the
criteria would describe the whole matched set while `affected_rows` counted a slice of it — two
numbers in one entry that cannot both be right.

### A value the entry will not write down

Only a scalar, `null`, a backed enum or a `DateTimeInterface` is written as a criteria value. An
enum lands as its backing value, a date in the same format the snapshots use. Anything else — a
`DB::raw()`, a query object, a value object of your own — is dropped, and the `value` key is
**absent** rather than set to `null`:

```json
{"type": "basic", "boolean": "and", "column": "options", "operator": "="}
```

A `"?"` placeholder would invite a reader to wonder whether the caller really searched for a
question mark. For a set, one unwritable value drops the whole sample and keeps only `count`.

### Criteria values are protected like snapshot values

A binding is the caller's data, so it goes through the same masking, hashing and encryption as
`before` and `after`, matched on the clause's `column` key at any nesting depth:

```php
// email is in $auditRedact on this model
Patient::query()->where('email', 'ada@example.com')->auditing()->update(['status' => 'discharged']);
// criteria records the mask, never the address
```

See [Protecting sensitive data](../05-pipeline-and-security/02-protecting-sensitive-data.md) for how
the declarations are read.

> ⚠️ **Warning.** Only the `wheres` are walked, and only clauses that carry a `column` key. A
> `whereColumn` clause serialises as `first`/`operator`/`second` and is never matched — in practice
> it holds no caller value, since both sides are column names, but do not tell yourself that "every
> clause is redacted". `criteria.writes`, `criteria.joins`, `criteria.limit`/`offset`/`order` and an
> upsert's `columns`/`unique_by`/`update` are the query's own vocabulary and pass through untouched.

## An entry per row

Under `individual` and `hybrid`, the sequence is fixed and the order is the point:

1. Open a business transaction named `mass.updated` or `mass.deleted` — unless one is already open,
   in which case the operation inherits it and opens none of its own.
2. Open a database transaction on the subject model's connection.
3. **Read the rows** — before the statement, because after an update the earlier state is gone and
   after a delete the row is. The read and the statement share one database transaction, so no row
   can arrive between the `select` and the `update` that no entry would describe.
4. Run the statement.
5. Write the summary.
6. Write one entry per row, in a single batch.

```php
use ElPandaPe\Sentinel\Models\Audit;

Invoice::query()->where('due_at', '<', now())->auditing('individual')->update(['status' => 'overdue']);

$entries = Audit::query()->where('audit_type', 'mass')->orderBy('id')->get();

$summary = $entries->first();
$summary->subject_id;      // null
$summary->affected_rows;   // the count for the whole set

$row = $entries[1];
$row->subject_id;          // '1'
$row->before['status'];    // 'pending' — the state the row was really in
$row->after['status'];     // 'overdue'
$row->criteria;            // null — the summary carries it
$row->affected_rows;       // null — same reason
$row->metadata;            // null — the mode is on the summary

$entries->pluck('transaction_id')->unique()->count();   // 1
```

The `before` of a per-row entry comes from the row that was read in step 3, snapshotted through
`Snapshot\SnapshotBuilder` exactly as an ordinary model update would be. The `after` is that snapshot
with the written literals applied on top — Sentinel does not re-read the rows after the statement.

> 📌 **Note.** If snapshots are disabled — globally or by the model's own declaration — the per-row
> entry carries `before: null` and `after: null` but still carries its `changes`. The diff is
> computed either way; only the retained state is dropped.

### Correlation, and the header a standalone operation leaves

Every entry of the operation, summary and rows alike, shares one `transaction_id`. Standing alone,
the operation opens a header row in `sentinel_transactions` named `mass.updated` or `mass.deleted`
— that string appears nowhere else and is explained nowhere else. Inside a
[business transaction](06-business-transactions.md) it keeps the outer identifier and opens no
header:

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

Sentinel::transaction('close-the-month', function (): void {
    Invoice::query()->where('due_at', '<', now())->auditing('individual')->update(['status' => 'overdue']);
});
// one header, named 'close-the-month'; every entry carries its id
```

`summary` mode opens no scope at all, so it never leaves a header.

### The batch, the chain and the other performance modes

The per-row entries reach the ledger as one batch, so the chain is extended once rather than once per
row: sequences, hashes and links are computed beforehand and in the same order, and the whole batch
lands inside one transaction. A 3,500-row `individual` update produces 3,501 entries with sequences
`1..3501` and a `previous_hash` chain with no gap — verified on SQLite, MySQL 9 and PostgreSQL 16.

`Ledger\DatabaseLedger` splits that batch across several `insert` statements, at a ceiling of 32 766
placeholders taken from the narrowest engine. An audit entry is thirty-odd columns, so the crossing
sits around nine hundred entries — well inside what a mass operation writes. The arithmetic is on
[SQLite](../10-database-engines/04-sqlite.md#the-placeholder-ceiling).

Under the other [performance modes](../09-operations/01-performance-modes.md) the batch behaves as
follows:

| Mode | What happens to an `individual` operation over 3,500 rows |
|---|---|
| `sync` | 3,501 entries written in one batch, inside the operation's transaction |
| `queue` | 3,501 `SettleAudit` jobs dispatched — one per entry |
| `buffered` | 3,501 items enter the [buffer](../09-operations/02-the-buffered-mode.md) as a batch and settle on the next flush. `buffer.size` bounds the flush, not the arrival |

> ⚠️ **Warning.** `buffered` does not amortise a mass operation. The saving it buys elsewhere is the
> batching, and a mass operation already arrives batched. Choose it to move the cost out of the
> request, not to reduce it.

## `delete()` and `upsert()`

### `delete()`

Under `individual` or `hybrid`, a mass delete captures the full `before` of every row, because after
the statement there is nowhere left to read it from. It is the more expensive of the two statements
for that reason.

```php
$deleted = Invoice::query()->where('status', 'void')->auditing('individual')->delete();

$row = Audit::query()->where('audit_type', 'mass')->whereNotNull('subject_id')->firstOrFail();
$row->event;             // 'deleted'
$row->before['number'];  // the value the row held
$row->after;             // null — a delete leaves no later state
```

The summary's `changes` is `null`: a delete names no column. If the builder has an `onDelete`
callback that answers with something other than an `int`, the operation counts as zero rows and no
entry is written.

### `upsert()`

An `upsert()` is **always** recorded as a summary, whatever mode the query asked for. It names its
own rows, so there is no criteria to read them back by, and a composite `uniqueBy` is not a `where`.
There is no way to get a per-row upsert entry.

```php
Invoice::query()->auditing('individual')->upsert(
    [['id' => 1, 'number' => 'INV-1'], ['id' => 2, 'number' => 'INV-2']],
    ['id'],
    ['number'],
);

$entry = Audit::query()->where('audit_type', 'mass')->latest('id')->firstOrFail();
$entry->event;      // 'upserted'
$entry->metadata;   // ['mass' => ['mode' => 'summary']] — even though 'individual' was asked for
$entry->criteria;
// ['columns' => ['id', 'number'], 'unique_by' => ['id'], 'update' => ['number'], 'rows' => 2]
```

The `columns` are read off the first row sent. A single associative row and a bare string
`uniqueBy` are wrapped exactly as a batch is, so `upsert(['id' => 1, …], 'id', ['name'])` records
`rows: 1`.

## `affected_rows` is stored unnormalised

The entry stores what the driver reported. It is not converted, clamped or reconciled — and it is
not the same question on all three engines.

| Engine | On `update()` | On `upsert()` |
|---|---|---|
| SQLite | Rows matched | Rows inserted or updated |
| MySQL 9 | Rows **changed** — a row written with the value it already held does not count | Counts **two** for a row that was updated rather than inserted |
| PostgreSQL 16 | Rows matched | Rows inserted or updated |

> 🐘 **Engine.** The consequence is concrete: the same `update` over the same data reports 500 on
> SQLite and PostgreSQL and something smaller on MySQL whenever some rows already held the target
> value. Normalising that in silence would mean the entry no longer said what the database said. If
> you compare `affected_rows` across engines, compare it knowing this — and if you build an alert on
> it, build it per engine.

An operation that reported **zero** writes no entry at all. `Pipeline\Stages\FilterUnchanged` reads
`affected_rows === 0` as "this did nothing" and discards the capture before the ledger assigns a
sequence, so an update that matched no row leaves no gap in the chain.

## A column written from an expression

```php
use Illuminate\Support\Facades\DB;

Invoice::query()->auditing('individual')->update([
    'status'  => 'overdue',
    'penalty' => DB::raw('penalty + 1'),
]);
```

The formula is the database's to evaluate. Neither it nor a value for that column is recorded; the
column is named in `criteria.writes` and nothing more — the same trade a raw `where` fragment gets:

```json
{"wheres": [], "writes": ["penalty"]}
```

> ⚠️ **Warning.** Under `individual` or `hybrid`, one opaque column suppresses the `after` of the
> **whole row**, not just that column — and with it the `changes`. A per-row entry for the update
> above carries `before`, `after: null` and `changes: null`, even for `status`, which could have been
> described. `Mass\MassCapture` returns no `after` the moment any written column is opaque, because
> an `after` carrying `penalty`'s earlier value would state that it did not move. One side or the
> other, never a mixture that lies.

The summary is unaffected: its `changes` still holds `/status`, and `criteria.writes` holds
`penalty`. If you need the per-row `after`, split the statement — one audited call for the literal
columns and one unaudited call for the expression, or the reverse, depending on which fact matters.

## `auditing` is an un-namespaced global macro

`auditing` is registered with `Builder::macro()` on `Illuminate\Database\Eloquent\Builder`. That is
what keeps this package off the execution path of every query you make — nothing is overridden, and a
builder that never calls the macro never touches Sentinel.

A macro has no namespace. A second package registering `auditing` on the Eloquent builder would win
or lose by boot order, silently, with no error from either side. Nothing in this ecosystem has
claimed the name so far. This paragraph exists so that it is a thing you know rather than a thing you
find out.

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| A mass `update` in your application writes no entry at all | `auditing()` was not called. There is no global interception and no config flag for one | Add `->auditing()` to the query that should be recorded |
| `ConfigurationException: … does not use the Auditable trait` from a query that reads fine | The builder's model has no `Concerns\Auditable`, so nothing declares which values may be written down | Add the trait to the model, or drop the `auditing()` call |
| `Sentinel configuration key [sentinel.auditing] has unknown value [thorough]` | A bad argument to `auditing()`. There is no such config key — the exception type is reused | Pass `'summary'`, `'individual'`, `'hybrid'` or a `MassMode` case |
| A `mass` entry has a null `subject_id` and your consumer throws | That is the summary. A set has no one row | Branch on `subject_id === null`, or filter to per-row entries with `whereNotNull('subject_id')` |
| A summary's `changes` has `new` but no `old`, and code reading the old value gets nothing | Nothing was read under `summary`, so no earlier value exists and none is invented | Use `individual` or `hybrid` if you need the real `before` |
| A `summary` entry and a degraded `hybrid` look identical | They are, apart from `metadata.mass.mode` | Read `metadata.mass.mode` before concluding anything about how an operation was recorded |
| `individual` over a large set exhausts memory | It reads the entire matched set with no ceiling — that is what distinguishes it from `hybrid` | Use `hybrid` with a threshold, or narrow the query |
| Per-row entries describe rows the statement never touched, and `affected_rows` disagrees with their number | `hybrid` clones the query and calls `limit(threshold + 1)` on it, which **replaces** the query's own `limit` rather than intersecting it. On MySQL, which accepts `update … limit`, the two sets can differ | Do not combine `->limit()` with `auditing('hybrid')`. `individual` applies no limit of its own and keeps the query's |
| A per-row entry has `before` but `after: null` and `changes: null` | Some column of the update was written from an expression, which suppresses the `after` for the whole row | Split the statement, or accept the `before`-only entry |
| `->auditing('individual')->upsert(...)` writes one entry with `mode: 'summary'` | An upsert is always a summary; it names its own rows and there is nothing to read them back by | Do not promise per-row upsert entries — there is no mode that produces them |
| A row appears in `sentinel_transactions` named `mass.updated` with `audits_count` 0 | `individual` and `hybrid` open the header before the statement runs. If the statement matched nothing, the summary is discarded and no rows are described, but the header stays | Expect it, or use `summary`, which opens no scope |
| `affected_rows` is smaller on MySQL than on PostgreSQL for the same data | MySQL counts rows changed; the other two count rows matched. The value is stored exactly as the driver reported it | Compare per engine, never across |
| A `whereJsonContains` clause records only `{type, boolean}` | The serialiser names the clauses it knows and treats everything else as shape-only, by design | Put the values you need to keep in a clause the serialiser understands, or accept the shape |
| A `whereColumn` value was expected to be redacted and was not | Redaction matches on a clause's `column` key; a `Column` clause has `first`/`second` instead | Nothing to fix — both sides are column names, not caller data. Do not rely on it being masked |
| A test asserting on the raw `criteria` column passes on SQLite and fails on MySQL | MySQL and PostgreSQL both reorder the keys of a JSON object on storage | Compare by content, or read through `toArray()` / `diff()->toArray()`, which return the package's own order |

## ✅ Best practices

✅ **Do** — decide the mode on the query, from what the operation is worth.

```php
// A month-end sweep nobody will audit row by row:
Invoice::query()->where('due_at', '<', now())->auditing()->update(['status' => 'overdue']);

// A correction someone will have to answer for:
Invoice::query()->whereIn('id', $disputed)->auditing('individual')->update(['status' => 'void']);
```

❌ **Don't** — set `mass_operations.mode` to `individual` and forget about it. Every audited mass
statement in the application then reads its whole matched set into memory and writes an entry per
row, including the ones where nobody wanted that.

```php
// config/sentinel.php
'mass_operations' => ['mode' => 'individual'],   // a decision made once, paid on every query
```

✅ **Do** — prefer `hybrid` when you want row detail but cannot bound the set. The summary is written
either way, so the count and the criteria are never what gets lost when a set degrades.

```php
config()->set('sentinel.mass_operations.threshold', 250);

Order::query()->where('state', 'pending')->auditing('hybrid')->update(['state' => 'void']);
// ≤ 250 rows: summary + one entry per row. More: the summary alone, and no set held in memory.
```

❌ **Don't** — combine a query `limit` with `hybrid`. The read replaces the limit with
`threshold + 1`, so the per-row entries can describe rows the statement never touched.

```php
Order::query()->orderBy('id')->limit(10)->auditing('hybrid')->update(['state' => 'void']);
```

✅ **Do** — read `metadata.mass.mode` before drawing a conclusion from a `mass` entry.

```php
$mode = $entry->metadata['mass']['mode'] ?? null;

if ($mode === 'hybrid' && $entry->subject_id === null) {
    // Either it degraded, or its rows are the entries sharing its transaction_id. Go and look.
}
```

❌ **Don't** — infer the mode from the absence of per-row entries. A deliberate `summary` and a
`hybrid` that degraded are byte-identical apart from that key.

```php
$mode = $entry->affected_rows > 100 ? 'summary' : 'individual';   // guesswork
```

✅ **Do** — keep a per-row entry with its summary when you export, archive or prune a slice of the
trail. The row entry carries no `criteria` and no `affected_rows`; the summary it shares a
`transaction_id` with carries both.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

Sentinel::audits()->inTransaction($summary->transaction_id)->take(500)->get();
```

❌ **Don't** — filter a `mass` export by `subject_id` and ship only the rows. What is left cannot say
what the operation was aimed at.

```php
Audit::query()->where('audit_type', 'mass')->whereNotNull('subject_id')->get();   // context dropped
```

✅ **Do** — declare the sensitive columns on the model before you audit a query that filters on them.
The criteria is the same territory as `before` and `after`, not an exception to it.

```php
use ElPandaPe\Sentinel\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class Patient extends Model
{
    use Auditable;

    protected array $auditRedact = ['email', 'national_id'];
}

Patient::query()->where('email', $address)->auditing()->update(['status' => 'discharged']);
// the mask reaches the criteria, never the address
```

❌ **Don't** — reach for `whereRaw` to express a filter you want recorded. A raw fragment is stored as
`{"type": "raw"}` and nothing else, so the entry cannot say what it was aimed at.

```php
Patient::query()->whereRaw("email = '{$address}'")->auditing()->update(['status' => 'discharged']);
// criteria: {"wheres":[{"type":"raw","boolean":"and"}]} — and the address is not in the entry either
```

✅ **Do** — split a statement that mixes literals with an expression, when the per-row `after`
matters.

```php
Invoice::query()->whereIn('id', $ids)->auditing('individual')->update(['status' => 'overdue']);
Invoice::query()->whereIn('id', $ids)->update(['penalty' => DB::raw('penalty + 1')]);
```

❌ **Don't** — expect a per-row `after` from a single call that includes a `DB::raw()` column. One
opaque column suppresses the whole row's `after` and its `changes`.

```php
Invoice::query()->auditing('individual')->update([
    'status'  => 'overdue',
    'penalty' => DB::raw('penalty + 1'),
]);
// every per-row entry: before yes, after null, changes null
```

✅ **Do** — chain `auditing()` inline, as one expression.

```php
Order::query()->where('state', 'pending')->auditing(MassMode::Hybrid)->update(['state' => 'void']);
```

❌ **Don't** — hold the wrapper in a variable or type-hint it. `Mass\AuditedQuery` and everything else
under `src/Mass/` is `@internal` and outside the [API stability](../99-reference/09-api-stability.md)
guarantee.

```php
$audited = Order::query()->auditing();   // an internal type, in your code, forever
```

---

**See also:** [What gets audited](01-what-gets-audited.md) · [Snapshots](02-snapshots.md) · [Diffs](03-diffs.md) · [Business transactions](06-business-transactions.md) · [Protecting sensitive data](../05-pipeline-and-security/02-protecting-sensitive-data.md) · [Performance modes](../09-operations/01-performance-modes.md) · [Configuration](../99-reference/02-configuration.md) · [Enums](../99-reference/04-enums.md) · [Schema](../99-reference/03-schema.md)
