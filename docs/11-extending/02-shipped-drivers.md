# 🧩 The shipped drivers

> The five implementations of `Contracts\Ledger` that come in the box — what each one is for, what
> it can and cannot answer, and the one mistake people make with it.

**On this page:** [How a driver is chosen](#how-a-driver-is-chosen) · [`database`](#database--the-one-you-ship-with) ·
[`memory`](#memory--the-reference-implementation) · [`null`](#null--the-write-path-with-no-store) ·
[`archive`](#archive--a-destination-never-a-default) · [`fanout`](#fanout--one-entry-several-destinations) ·
[What each driver can answer](#what-each-driver-can-answer) · [Which driver where](#which-driver-where) ·
[⚠️ Pitfalls](#-pitfalls) · [✅ Best practices](#-best-practices)

---

## How a driver is chosen

One config key, one string:

```php
// config/sentinel.php
'ledger' => [
    'default' => env('SENTINEL_LEDGER', 'database'),
    'ledgers' => [
        'database' => [],
        'archive'  => ['disk' => env('SENTINEL_ARCHIVE_DISK', 'local'), 'path' => 'sentinel', 'codec' => 'gzip', 'batch' => 1000],
        'memory'   => [],
        'null'     => [],
        'fanout'   => ['destinations' => ['database'], 'on_failure' => 'strict'],
    ],
],
```

`SentinelServiceProvider::driver()` maps the string to a class and the container binds
`Contracts\Ledger` to it. An unknown string raises `Exceptions\ConfigurationException` naming the key
and the accepted list — `archive, database, fanout, memory, null` — and `archive` is refused
specifically when the key is `ledger.default`.

Three things follow from how that binding is made, and all three catch people out.

**The classes are `@internal`.** `Ledger\DatabaseLedger`, `MemoryLedger`, `NullLedger`,
`ArchiveLedger` and `FanoutLedger` all carry the annotation, and `tests/SurfaceTest.php` classifies
the whole `ElPandaPe\Sentinel\Ledger` namespace as internal with the reason that configuration picks
the implementation by a fixed string, so no caller ever names the class. Resolve
`Contracts\Ledger`, or go through the `Sentinel` facade. The published surface here is the config
keys, `Contracts\*`, `Enums\Filter`, `Enums\FanoutPolicy`, `Events\LedgerDestinationFailed`,
`Exceptions\LedgerException` and `Testing\LedgerContractTestCase`.

**The binding is `scoped`, not `singleton`.** A driver that keeps its chain on the instance keeps it
for one request or one queued job and no longer. That is deliberate for `memory` and `null`; for
`archive` it is why an open batch has to be sealed on the way out of the process.

**A driver subtree that ships empty takes no options.** `database`, `memory` and `null` get an empty
array each. Anything you put in one is ignored without a word.

**There is no driver registry.** `driver()` is a closed `match`, and the five names above are the
whole of it — a driver you write cannot be selected by putting its name in `ledger.default`. A
third-party driver goes in by rebinding `Contracts\Ledger` in your own service provider, after the
package's; the same is true of a fanout that names one as a destination, which the closed match will
refuse. See [Swapping components](06-swapping-components.md).

> 📌 **Note.** Selecting `null` is not the same as `sentinel.enabled => false`. `enabled` turns
> capture off — `Sentinel::isRecording()` answers false and no entry is ever built. The `null`
> driver still captures, still runs the pipeline, still seals and chains the entry, and then keeps
> nothing. See [Turning auditing off](../02-getting-started/04-turning-auditing-off.md).

---

## `database` — the one you ship with

`Ledger\DatabaseLedger`. The only driver with durability, indexes and a transaction, and the default
for that reason.

`write()` is `writeMany([$audit])->firstOrFail()`, so there is one write path and not two. Inside one
database transaction it groups the captures by stream, takes the stream's tail through
`Ledger\StreamGate` (a `lockForUpdate()` on the tail row, plus `pg_advisory_xact_lock` on
PostgreSQL, where no row lock covers a stream with no rows yet), builds each entry through
`Ledger\EntryBuilder` with `++$sequence` and the previous entry's hash, inserts the rows, writes the
label rows and the relation projection, and returns the entries in the order the captures were given
— `ksort` puts them back even when the batch mixed streams.

| It does | Detail |
|---|---|
| Wrap `writeMany()` in a transaction | Stronger than the contract, which promises no atomicity. A batch lands whole or not at all. |
| Split a batch across INSERT statements | At 32 766 placeholders — SQLite's ceiling, not PostgreSQL's or MySQL's. An entry is 35 columns, so 936 rows fit one statement and 937 open a second (`tests/Ledger/PlaceholderCeilingTest.php`). The chain is built before the split and every statement runs inside the one transaction. |
| Retry a unique violation, at most three times | The unique index is the final arbiter for `(stream, sequence)`. Each retry first drops what has settled by `capture_id`, because for a capture id a replay would seal the same chain three times over. A batch with nothing left is handed back its own `UniqueConstraintViolationException`. |
| Derive `version` from the rows | `max('version')` per `(subject_type, subject_id)`, so an appended entry heals the counter for free. |
| Eager-load `tags` on `find()` and `query()` | An unloaded subject reads as null, which is legible; an unloaded label list reads as an empty one, which is a false claim. |
| Chunk `settled()` | Capture-id lookups go out in `whereIn` slices of 32 766. |
| Anchor after the commit | Only when `integrity.checkpoints.enabled` is true. Never inside the sealing transaction: folding a window means reading it, and holding the stream's writer lock across that read would serialize every other writer. An emission failure is **not** swallowed. |

It declares all three capability interfaces and answers all nineteen `Enums\Filter` cases.

**What it cannot do.** The three JSON-backed filters — `whereFieldChanged()`, `whereIp()`,
`whereRoute()` — are compiled by `Ledger\ChangedFieldPredicate` and `Ledger\ContextPredicate`, which
know `mysql`, `pgsql` and `sqlite` and nothing else. On any other PDO driver they throw
`LedgerException::cannotTranslateOn()` naming the filter and the driver. `DatabaseLedger` still
declares `Filter::cases()`, so the refusal arrives when the query runs, not when the criterion is
added.

```php
// config/sentinel.php — audits in a database of their own
'database' => ['connection' => env('SENTINEL_DB_CONNECTION')],
'tables'   => ['prefix' => 'sentinel_', 'audits' => 'audits'],
```

Labels and relation lines are written through the connection the sealing transaction is open on,
not through the `AuditTag` model's own — precisely because `database.connection` exists.

> 🐘 **Engine.** The chain behaves differently underneath on each engine: InnoDB's gap lock covers
> the first write of a stream, PostgreSQL needs the advisory lock, SQLite serializes the whole
> database and ignores `lockForUpdate()`. See [Choosing an engine](../10-database-engines/01-choosing-an-engine.md).

**The one mistake.** Assuming every driver behaves like this one. `writeMany()` being atomic,
`find()` seeing a write that just returned, and `settled()` existing at all are `DatabaseLedger`
properties, not contract guarantees — see [The Ledger contract](01-the-ledger-contract.md).

---

## `memory` — the reference implementation

`Ledger\MemoryLedger`. The whole contract over plain arrays, chaining with the same
`EntryBuilder` and the same `Integrity\Stream` the database driver uses.

> ⚠️ **Warning.** `memory` is a reference implementation and a test double. **It is never a store.**
> Nothing survives the instance, and because the binding is `scoped`, "the instance" means one
> request or one queued job. A ledger with no durability that looks like it works is worse than one
> that fails.

It exists for two reasons. A contract with one implementation is an interface nobody has questioned,
so the package keeps a second one that must pass the same published suite; and a test that only
needs entries back should not have to pay for a database.

It declares `DeclaresFilters`, `Deduplicates` and `EnumeratesStreams`, answers all nineteen filters
through `Ledger\ArrayQuery`, and returns `streams()` sorted so two runs of a report can be diffed.

Two behaviours to know:

- **Entries come back with `exists = false`.** Nothing went through Eloquent's insert path
  (`tests/Ledger/MemoryLedgerTest.php`). Code branching on `$audit->exists` after a write behaves
  differently here than under `database`, which sets `exists` and `wasRecentlyCreated` to true.
- **It numbers the next entry as `count($entries) + 1`, not as `tail->sequence + 1`.** After
  `append()`ing an entry another ledger sealed at sequence 7 into an empty stream, the next `write()`
  gets sequence 2, while `null` and `archive` — which track the tail — give 8 for the same input.
  The contract suite does not distinguish them because its fixture entries are sealed at sequence 1.
  It is a hazard for anyone reading `MemoryLedger` as a template, not a production defect: nothing
  ships it as a store.

**The one mistake.** Treating a green integration suite run against `memory` as evidence that the
write path works. It never touched a transaction, a unique index, a lock or a JSON column.

---

## `null` — the write path with no store

`Ledger\NullLedger`. Turns writing off without taking the code path apart: the entry is still
captured, transformed, built, sealed and chained. Then it is dropped.

```bash
SENTINEL_LEDGER=null
```

```php
use ElPandaPe\Sentinel\Contracts\Ledger;

$written = app(Ledger::class)->write($capture);

$written->sequence;      // 1, then 2, then 3 — the chain still advances
$written->previous_hash; // linked to the entry before it
$written->exists;        // false: nothing went through Eloquent

app(Ledger::class)->find($written->id);   // null, always
```

It is **not stateless**. It keeps a `Ledger\StreamTail` per stream and a `Ledger\SubjectVersions`
counter per subject, because both are sealed into the next entry and a chain cannot be continued
without them. That is one entry per stream and per subject, never one per record written — turning
auditing off must not grow with the traffic it is refusing to record. `append()` moves both, so a
chain a real ledger started can be continued through this one
(`tests/Ledger/LedgerAgnosticChainTest.php`).

It declares `DeclaresFilters` and returns `Filter::cases()`. That is a deliberate choice: refusing a
filter would claim it cannot translate it, when in fact it translates all of them into the same empty
answer. It declares neither `Deduplicates` nor `EnumeratesStreams`.

**What it cannot answer.** `find()` returns null, `query()` returns an empty collection however
narrow the query was, `stream()` walks nothing. And because it cannot enumerate,
`Sentinel::verifyEverything()` and any of `sentinel:verify`, `sentinel:checkpoint`,
`sentinel:prune` run without `--stream` throw `QueryException::cannotEnumerateStreams()` rather than
reporting a reassuring empty result.

**The one mistake.** Reaching for `null` to silence audits in production. It costs everything except
the store, so it is a measurement baseline and a load-test fixture — for silencing, `sentinel.enabled
=> false` skips capture entirely.

---

## `archive` — a destination, never a default

`Ledger\ArchiveLedger`. Cold storage as NDJSON on any disk `Storage` can reach; S3, R2 and MinIO work
because the driver speaks only the Filesystem contract and knows none of them exist.

> ⚠️ **Warning.** `archive` **must never be `ledger.default`.** The service provider refuses it with
> `ConfigurationException::coldLedgerAsDefault()`: the tail of a stream lives on the instance,
> because `sentinel_archives` holds no hash and could never hand one back, so a second process would
> start a second chain under the same name. Name it as a fanout destination, or let
> `sentinel:prune --action=archive` write the cold copies.

| Key | Default | What it does |
|---|---|---|
| `ledger.ledgers.archive.disk` | `local` (env `SENTINEL_ARCHIVE_DISK`) | Which `Storage` disk batches go to. |
| `ledger.ledgers.archive.path` | `sentinel` | Root prefix, trimmed of surrounding slashes. |
| `ledger.ledgers.archive.codec` | `gzip` | `gzip` (needs ext-zlib) or `null` for plain NDJSON. A **name**, not a flag, because the manifest records it — a boolean could never say what to inflate a batch written two years ago with. |
| `ledger.ledgers.archive.batch` | `1000` | How many entries one open batch holds before the driver writes it out. Floored at 1. |

The full object key is `<path>/<slug>-<8 hex of sha256(stream)>/<from>-<to>.ndjson[.gz]`, with both
sequence ends zero-padded to 20 characters so a directory listing sorts the way the chain does. A
resulting path longer than 512 characters is refused at write time with
`ConfigurationException::archivePathTooLong()`, because the manifest column would truncate it and a
row pointing at a truncated path points at nothing.

A batch is sealed when it fills, when a read is asked of the driver, or when `seal()` is called. The
service provider registers `seal()` on `terminating()` and on `WorkerStopping`, and asks whether the
driver was ever resolved rather than resolving it, so a request that archived nothing does not build
one on its way out.

Four things it does not do:

- **It does not write to `sentinel_archives`.** The manifest has exactly one writer, the prune. A row
  there means a range *left* the hot table, and a cold copy of a range that is still hot would
  disarm both the prune's tamper guard and the verifier's gap-crossing.
- **It does not implement `Contracts\Deduplicates`.** A capture-id lookup would be a scan with no
  index behind it, and the contract says a driver that cannot answer reliably must not claim to.
- **It does not discover what other processes wrote.** `find()`, `query()`, `stream()` and
  `streams()` read only the batches *this instance* wrote — coherent with being a destination. The
  manifest-driven read path is `Archive\Rehydrator`.
- **It writes no operation lines.** The driver's batches carry entries only; the prune's `Retention\Archiver`
  additionally writes the header of every business transaction the window touched.

```php
// A read seals the open batch as a side effect — this writes a file to the disk.
$ledger->query(new AuditQuery($ledger));
```

That is exactly what the contract's `settle()` hook exists for, and it is what
`tests/Testing/ArchiveLedgerContractTest.php` implements.

**The one mistake.** Expecting `Archive\Rehydrator::restore()` to bring back a batch this driver
wrote. Rehydration walks `sentinel_archives`, which the driver never writes to — only ranges the
prune retired can be rehydrated. See [Cold archiving](../08-lifecycle/02-cold-archiving.md) and
[Rehydration](../08-lifecycle/03-rehydration.md).

---

## `fanout` — one entry, several destinations

`Ledger\FanoutLedger`. Hot plus cold, or hot plus a search satellite.

```php
'ledger' => [
    'default' => 'fanout',
    'ledgers' => [
        'fanout' => [
            // The FIRST destination is the primary: it seals, it numbers, it answers every read.
            'destinations' => ['database', 'archive'],
            'on_failure'   => 'primary',
        ],
    ],
],
```

The first destination is the primary and the only one that assigns a sequence and seals a hash. Every
other destination is handed the sealed entry through `append()`, because two ledgers each numbering
their own chain produce two truths about one fact. Every read — `find()`, `query()`, `stream()`,
`settled()`, `streams()`, `supportedFilters()` — goes to the primary, for the same reason.

`Support\Config::fanoutDestinations()` refuses `fanout` inside its own destination list: composing a
fanout into itself is a loop with no bottom, and a readable error beats a stack overflow.

| `on_failure` | A secondary refuses the entry | A primary refuses the entry |
|---|---|---|
| `strict` (default) | `Events\LedgerDestinationFailed` is dispatched, then the exception is rethrown — and the fanout **stops there**, so later destinations never see the entry. The primary has already sealed and stored it. | The write fails. |
| `primary` | `LedgerDestinationFailed` is dispatched and the fanout **carries on** to the remaining destinations. The write settles. | The write fails. |

The event is dispatched *before* the policy decides, and that ordering is the point: under `strict`
it is the only thing that names the entry which did land, in an exception that says a write did not
complete.

```php
use ElPandaPe\Sentinel\Events\LedgerDestinationFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

Event::listen(function (LedgerDestinationFailed $failure): void {
    Log::critical($failure->message(), [
        'destination' => $failure->destination,   // the secondary's class name
        'stream'      => $failure->stream,
        'sequence'    => $failure->sequence,
        'audit_id'    => $failure->auditId,
    ]);
});
```

The event carries where and which, and nothing of what the entry said.

Three limits worth knowing before you configure one:

- **`writeMany()` fans out entry by entry.** There is no `appendMany()` in the contract, so the
  primary's batch runs once and then one `append()` per entry. A destination that could write batches
  cannot exploit it.
- **There is no recovery path.** Every read goes to the primary. If the primary loses data a
  secondary still holds, nothing goes and gets it.
- **`settled()` and `streams()` answer `[]` when the primary lacks the capability** rather than
  throwing. An empty `settled()` is safe — the unique index is still the arbiter. An empty
  `streams()` is not: a fanout always implements `EnumeratesStreams`, so it bypasses the loud refusal
  a bare non-enumerating driver would get from `verifyEverything()` and reports an empty,
  reassuring whole-trail result instead.

**The one mistake.** Putting the durable, indexed driver anywhere but first. `['archive',
'database']` makes the archive the primary — which is refused only for `ledger.default`, not inside a
fanout — and every read then goes to a driver that scans its own files.

See [Fanout: writing to more than one place](05-fanout.md) for the full treatment.

---

## What each driver can answer

| | `database` | `memory` | `null` | `archive` | `fanout` |
|---|---|---|---|---|---|
| Durable past the process | ✅ | ❌ | ❌ | ✅ (files) | delegates |
| Legal as `ledger.default` | ✅ | ✅ (never do it) | ✅ | ❌ refused | ✅ |
| `write()` / `writeMany()` chain | ✅ | ✅ | ✅ | ✅ | primary only |
| `writeMany()` atomic | ✅ transaction | ❌ | ❌ | ❌ | primary's answer |
| `find()` returns the entry | ✅ | ✅ | ❌ always null | ✅ own batches, by scan | primary's answer |
| `query()` | ✅ SQL | ✅ `ArrayQuery` | ❌ always empty | ✅ scan of files | primary's answer |
| Reads see a write that just returned | ✅ | ✅ | n/a | ✅ every read seals first | primary's answer |
| `Contracts\DeclaresFilters` | ✅ all 19 | ✅ all 19 | ✅ all 19 | ✅ all 19 | primary's set |
| `Contracts\Deduplicates` | ✅ indexed | ✅ by scan | ❌ | ❌ deliberately | primary's, else `[]` |
| `Contracts\EnumeratesStreams` | ✅ | ✅ | ❌ | ✅ own batches | ✅, else `[]` |
| Entry comes back with `exists = true` | ✅ | ❌ | ❌ | ❌ | primary's answer |

All five run the same published `Testing\LedgerContractTestCase`, which is what keeps them from
drifting apart — see [The contract test suite](04-the-contract-test-suite.md).

---

## Which driver where

| Environment | Driver | Why |
|---|---|---|
| Unit tests that only need entries back | `memory` | No database, same chaining code, `EnumeratesStreams` so `verifyEverything()` works. |
| Feature tests of anything chain-bearing | `database` on SQLite | The lock, the unique index, the JSON columns and the transaction are what you are testing. |
| Local development | `database` | You want the trail to still be there tomorrow. |
| CI | `database`, on all three engines for chain-bearing changes | `make test-dbs` runs SQLite, MySQL 9 and PostgreSQL 16. Anything touching `sequence`, `hash`, `previous_hash` or the canonical payload is verified on all three. |
| Benchmarking the package's own cost | `null` | Everything except the store, so the delta is the store. |
| Load tests where audit volume is not the subject | `null` | The write path still runs; nothing accumulates. |
| Production, single store | `database` | |
| Production, hot plus cold | `fanout` with `['database', 'archive']` | The database seals and answers reads; the archive takes a copy. |
| Production, audits off | `sentinel.enabled => false` | Not `null` — `enabled` skips capture altogether. |

> 🧪 **Verify it.** `php artisan sentinel:verify --stream=global` walks a named chain on any driver.
> `php artisan sentinel:verify` with no `--stream` needs `Contracts\EnumeratesStreams`, so it is the
> quickest way to find out whether the driver you configured can list its chains.

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `ConfigurationException`: "cannot use the archive driver as [sentinel.ledger.default]" | `ledger.default => 'archive'`. | Name `archive` in `ledger.ledgers.fanout.destinations`, or let `sentinel:prune --action=archive` write the cold copies. |
| `QueryException`: "cannot say which streams it holds" from `sentinel:verify` / `sentinel:checkpoint` / `sentinel:prune` | The configured driver does not implement `Contracts\EnumeratesStreams` — `null`, or a third-party driver. | Pass `--stream=`, or implement the interface. |
| Code that worked under `database` breaks under `memory` or `null` | Those two return entries with `exists = false`; nothing went through Eloquent. | Do not branch on `$audit->exists` after a write. |
| A `find()` right after a `write()` wrote a file to the disk on the archive driver | Every read seals what is still open before it answers, and sealing is what writes the batch out. | Expected. Call `seal()` yourself if you would rather choose when the file appears. |
| A `find()` on the archive driver throws `ArchiveException` | A batch file this instance wrote is no longer readable on the disk. The driver reads out of the file, never out of what it remembers. | Check the disk and the credentials; the exception names the path. |
| A cold copy exists on the disk but `Archive\Rehydrator::restore()` finds nothing | `ArchiveLedger` never writes `sentinel_archives`, and rehydration walks the manifest. | Only ranges the prune retired are rehydratable. Use the fanout copy as a copy, not as a restore source. |
| Under `strict`, an exception propagates but the entry is in the primary anyway | The primary seals and stores before the entry is handed to any secondary. Nothing can unseal it. | Listen for `Events\LedgerDestinationFailed` — it names the entry that did land. |
| A secondary further down the list never received an entry | Under `strict` the fanout stops at the first refusal. | Use `on_failure => 'primary'` when a secondary is a convenience copy. |
| `whereFieldChanged()` / `whereIp()` / `whereRoute()` throw `LedgerException::cannotTranslateOn()` | `DatabaseLedger` compiles those three only for `mysql`, `pgsql` and `sqlite`. | Use one of the three engines, or drop those criteria. |
| A third-party driver silently answers a query nobody asked | It declares no `Contracts\DeclaresFilters`, so it is taken to answer only `Filter::assumed()` — nine of the nineteen, a set that never grows. | Declare `supportedFilters()` explicitly. `LedgerException` extends `BadMethodCallException`, so `catch (RuntimeException)` will not see the refusal. |
| Sequence jumps after `append()` on the memory driver | It numbers from `count($entries) + 1`, not from the tail. | It is a test double. Do not copy the pattern into a real driver: track the tail. |
| A batch open at the end of a request vanished | The `Ledger` binding is `scoped`; the seal hooks run on `terminating()` and `WorkerStopping` and are wrapped in `rescue()`, so a failing seal is reported, not thrown. | Watch the log channel for what `rescue()` reported. |

---

## ✅ Best practices

✅ **Do** — select the driver by its config string and resolve the contract.

```php
use ElPandaPe\Sentinel\Contracts\Ledger;

$ledger = app(Ledger::class);          // whatever sentinel.ledger.default names
```

❌ **Don't** — resolve a driver class by name. Every one of them is `@internal`; the class you name
today may not be the class the string resolves to tomorrow, and your code stops following the config.

```php
use ElPandaPe\Sentinel\Ledger\DatabaseLedger;

$ledger = app(DatabaseLedger::class);  // ignores ledger.default entirely
```

✅ **Do** — put the durable, indexed driver first in a fanout. It is the primary: the only one that
seals, and the one every read is answered by.

```php
'fanout' => ['destinations' => ['database', 'archive'], 'on_failure' => 'primary'],
```

❌ **Don't** — lead with the archive. It is legal inside a fanout, and it makes a file-scanning
driver with per-instance state the source of truth for every query, every verification and every
sequence.

```php
'fanout' => ['destinations' => ['archive', 'database']],   // reads now scan files
```

✅ **Do** — decide `on_failure` on what a missing copy actually means, and listen for
`LedgerDestinationFailed` under either policy.

```php
// The cold copy is a convenience: a disk outage must not fail business writes.
'fanout' => ['destinations' => ['database', 'archive'], 'on_failure' => 'primary'],
```

❌ **Don't** — leave `on_failure` unwritten and inherit `strict` without meaning to. The default
makes every destination critical, so an unreachable bucket fails every audited write in the
application.

```php
'fanout' => ['destinations' => ['database', 'archive']],   // on_failure defaults to strict
```

✅ **Do** — use `null` when you want to measure what the package costs minus the store, and turn
capture off when you want silence.

```bash
SENTINEL_LEDGER=null       # still builds, seals and chains; keeps nothing
SENTINEL_ENABLED=false     # captures nothing at all
```

❌ **Don't** — reach for `memory` as a way to "make audits fast" in any environment that outlives a
request. Everything it holds is gone at the end of the scope, and no exception says so.

```php
'ledger' => ['default' => 'memory'],   // accepted, and it loses everything
```

✅ **Do** — run the published contract suite against a driver you write, and let the hooks state what
it is.

```php
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Testing\LedgerContractTestCase;

final class DocumentLedgerContractTest extends LedgerContractTestCase
{
    private ?Ledger $ledger = null;

    protected function ledger(): Ledger
    {
        return $this->ledger ??= app(DocumentLedger::class);   // memoized: it is asked for repeatedly
    }

    protected function settle(Ledger $ledger): void
    {
        // make what was just written visible, if your reads are eventually consistent
    }
}
```

❌ **Don't** — copy `MemoryLedger` as a template for a durable driver. It numbers from the entry
count rather than from the tail, which is correct for a test double and wrong for anything that takes
an `append()` of a range sealed elsewhere.

```php
count($entries) + 1        // gives 2 after appending an entry sealed at sequence 7
$tail->sequence + 1        // gives 8, which is what a real chain needs
```

✅ **Do** — verify anything chain-bearing on all three engines before you trust it.

```bash
make test-dbs              # SQLite, MySQL 9, PostgreSQL 16
```

❌ **Don't** — conclude from a green suite on `memory` that the write path is sound. That run touched
no transaction, no lock, no unique index and no JSON column.

```bash
make test ARGS=tests/Feature   # with ledger.default = memory: proves the chaining, nothing else
```

---

**See also:** [The Ledger contract](01-the-ledger-contract.md) ·
[Writing a ledger driver](03-writing-a-ledger-driver.md) ·
[The contract test suite](04-the-contract-test-suite.md) ·
[Fanout: writing to more than one place](05-fanout.md) ·
[Swapping components](06-swapping-components.md) ·
[Cold archiving](../08-lifecycle/02-cold-archiving.md) ·
[Rehydration](../08-lifecycle/03-rehydration.md) ·
[Retention and pruning](../08-lifecycle/01-retention-and-pruning.md) ·
[Streams](../07-integrity/02-streams.md) ·
[Verification](../07-integrity/06-verification.md) ·
[Filters reference](../06-reading/02-filters-reference.md) ·
[Events and listeners](../09-operations/04-events-and-listeners.md) ·
[Choosing an engine](../10-database-engines/01-choosing-an-engine.md) ·
[Turning auditing off](../02-getting-started/04-turning-auditing-off.md) ·
[Configuration reference](../99-reference/02-configuration.md)
