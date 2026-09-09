# 🔎 Order, paging and walking the trail

> How the trail is ordered and why, what `get()` refuses to do, how a page is asked for, and how to
> cross a whole trail — or a whole chain — without reading anything twice.

**On this page:** [The order](#the-order) · [Ordering by the clock of the fact](#ordering-by-the-clock-of-the-fact) · [How much comes back](#how-much-comes-back) · [Paging](#paging) · [Walking with a cursor](#walking-with-a-cursor) · [Sequence is a different axis](#sequence-is-a-different-axis) · [The compliance feedback loop](#the-compliance-feedback-loop) · [⚠️ Pitfalls](#️-pitfalls) · [✅ Best practices](#-best-practices)

---

## The order

Every read through [the Query API](01-the-query-api.md) comes back in one order, and it is the same
order on every driver: **a clock, then the entry's identifier**.

```sql
order by created_at asc, id asc
```

Two knobs move it, and nothing else does:

| Call | Clock in front | Direction | Filter case? |
|---|---|---|---|
| *(default)* | `created_at` — when the ledger sealed the entry | oldest first | — |
| `latest()` | unchanged | newest first | no — a driver can never refuse it |
| `byOccurrence()` | `occurred_at` — when the fact happened | unchanged | no — a driver can never refuse it |
| `byOccurrence()->latest()` | `occurred_at` | newest first | no |

`ElPandaPe\Sentinel\Ledger\DatabaseLedger::query()` picks the column and the direction and applies
both to the clock **and** to `id`, so the two never disagree. A ledger over arrays reaches
`Ledger\ArrayQuery::chronologically()` instead and lands on the same pair.

### Why the identifier is the tie-break

`id` is a ULID. Three properties make it the right second axis, and no other column has all three:

- **It is total.** `created_at` is `datetime(6)`; two entries written in the same microsecond do not
  order against each other at all. Two entries sharing an `id` cannot exist — it is the primary key.
- **It sorts by the instant it was minted.** So the tie-break is still chronological, not arbitrary.
- **It is the tail of the composite indexes the table carries**, which is what lets the engine walk
  the order instead of sorting it.

`tests/Query/TimelineTest.php` pins this with two entries stamped at the identical `occurred_at`:
they come back in identifier order, on all three engines.

> 📌 **Note.** The order is a property of the *ledger contract*, not of SQL. A third-party driver
> that returns entries in any other order is a broken driver — `Testing\LedgerContractTestCase`
> is what says so. See [The contract test suite](../11-extending/04-the-contract-test-suite.md).

### What the order rides

`Support\AuditSchema::indexes()` plus the occurrence migration give the table these, and they are
what decides whether the order is walked or sorted:

| Index | Serves |
|---|---|
| `(subject_type, subject_id, id)` | `for()` |
| `(actor_type, actor_id, id)` | `by()` |
| `(tenant_id, created_at)` | `forTenant()` with the default clock |
| `(audit_type, created_at)` | `whereType()` with the default clock |
| `(severity, created_at)` | `whereSeverity()` with the default clock |
| `(occurred_at, id)` | the unnarrowed timeline |
| `(subject_type, subject_id, occurred_at, id)` | the timeline of one subject |

> 🐘 **Engine.** `tests/Query/QueryPlanTest.php` runs each engine's own `EXPLAIN` over the SQL the
> driver actually issues. Three results worth carrying: an **unnarrowed** read pays a full pass *and*
> a sort on all three engines; the **unnarrowed timeline** commits to `(occurred_at, id)` on SQLite
> while MySQL and PostgreSQL prefer to read and top-N sort at suite size; and the **timeline of one
> subject** rides its index everywhere. The test's own note records the large-table measurement for
> the timeline index at two hundred thousand entries: MySQL 100 ms → 0.39 ms, PostgreSQL
> 12.9 ms → 0.23 ms.

---

## Ordering by the clock of the fact

`occurred_at` is stamped when the event is captured, inside the request. `created_at` is stamped when
the entry settles in the ledger. They agree while writing is synchronous and come apart the moment it
is not — a queued or buffered write settles later than it happened, and possibly out of order.

`Sentinel::timeline()` is exactly `Sentinel::audits()->byOccurrence()` and nothing else. It takes
every other filter, pages like any other query, and is one read of one table — not a merge of sources
in PHP. See [The timeline](05-the-timeline.md).

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

Sentinel::audits()->get();     // the order they were recorded
Sentinel::timeline()->get();   // the order they happened
```

> ⚠️ **Warning.** `between()` **always** bounds `created_at`, including under `byOccurrence()` and
> `timeline()`. Narrowing and ordering deliberately follow different clocks. `Query\Period` says why:
> `created_at` is the partition key of both published range plans and the clock retention counts
> from, so a window on the clock of the fact would not line up with the window a prune works in.
> `Sentinel::timeline()->between($from, $to)` therefore means *the entries the ledger sealed in that
> window, handed back in the order they happened*.

Both ends of a period are inclusive (`Query\Period::covers()`), and `$to < $from` is refused up front
with `QueryException::backwardsPeriod()` rather than answered with nothing.

> 📌 **Note.** `Sentinel::transitions()` forces `byOccurrence()` on the way to `get()` and gives you
> no way to opt out: the interval a lifeline reports is a distance in time, and measuring it on the
> settlement clock would be measuring the wrong thing. `Transitions\TransitionQuery` also has **no
> `paginate()`** — the interval of a page's first row is the distance to an entry the page does not
> contain. `->entries()` drops back to the `AuditQuery` underneath, which pages like any other. See
> [State transitions](../03-capture/08-state-transitions.md).

---

## How much comes back

`AuditQuery` has three terminals and they make three different promises.

| Terminal | What it asks the ledger for | What it hands back | Refuses when |
|---|---|---|---|
| `get()` *(no `take()`)* | `AuditQuery::DEFAULT_LIMIT + 1` = **501** rows | up to 500 entries | 501 rows came back → `QueryException::unbounded()` |
| `take($n)->get()` | exactly `$n` rows | up to `$n` entries | `$n < 1` → `QueryException::unreachableLimit()` |
| `paginate($perPage, $page)` | `$perPage + 1` rows at offset `($page - 1) * $perPage` | an `AuditPage` | `$perPage < 1` or `$page < 1` → `QueryException::unreachablePage()` |

### get() refuses past 500 rather than truncating

An uncapped `get()` clones itself with a bound of 501, issues that one statement, and throws only if
the 501st row came back. A filter matching exactly 500 is answered whole; 501 is where it refuses:

```
This filter matches at least 500 entries, and handing back the first 500 would look exactly
like handing back all of them. Narrow it, take() a prefix on purpose, or paginate() through
the whole thing.
```

A trail has no natural end. Truncating silently would hand back a collection whose shape, type and
iteration are **indistinguishable** from a complete answer — and the reader who counts it, sums it,
exports it or shows it to an auditor has no way to find out. Every other API in this package has the
same rule behind it: a wrong answer about history is worse than a refusal, because a refusal is
visible at the call site and a wrong answer is visible nowhere.

`take($n)` is the escape hatch, and it is deliberately a different verb. It issues exactly `$n` with
no probe and **never refuses**, because asking for a prefix on purpose is a different act from
discovering you were given one.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Query\AuditQuery;

Sentinel::audits()->for($invoice)->get();              // throws once this invoice has 500 entries
Sentinel::audits()->for($invoice)->take(20)->get();    // the 20 oldest, on purpose
Sentinel::audits()->for($invoice)->latest()->take(1)->get();

AuditQuery::DEFAULT_LIMIT;   // 500 — a public constant, so a caller can size a batch by it
```

> 🧪 **Verify it.** The probe is observable. Listen for `Illuminate\Database\Events\QueryExecuted`
> around a bare `Sentinel::audits()->get()` and the statement carries `limit 501` — that is
> `tests/Query/BoundedReadTest.php` asserting exactly this.

> 📌 **Note.** The query is immutable: `$query->take(10)` returns a **new** bounded query and leaves
> `$query` uncapped. Assign the result.

---

## Paging

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

$page = Sentinel::audits()
    ->for($invoice)
    ->latest()
    ->paginate(perPage: 50, page: 2);

$page->entries;    // Support\AuditCollection
$page->page;       // 2
$page->perPage;    // 50
$page->hasMore;    // bool
count($page);      // the length of THIS page

foreach ($page as $audit) {
    // AuditPage is IteratorAggregate over its entries
}
```

`Query\AuditPage` is a `final readonly` value object with those four properties and two interfaces
(`Countable`, `IteratorAggregate`). That is the whole shape.

### There is no total, on purpose

`hasMore` costs one row: the page asks the ledger for `perPage + 1` and hands back the page without
the extra one. A `total` would cost a `COUNT` over everything the filter matches, on a table that
only ever grows, and no index answers that question. It is the one cost in this API that is
unbounded, so it is the one number the API does not offer.

Consequences worth knowing before you build a screen on it:

- You cannot render "page 3 of 47". You can render "next" and "previous".
- `AuditPage` is **not** a Laravel paginator. It does not extend `AbstractPaginator`, so
  `links()`, `withQueryString()` and the `meta`/`links` envelope of `AuditResource::collection()` are
  all absent. See [Presenting and serializing](07-presenting-and-serializing.md).
- The offset is resolved by the engine, so a deep page costs more than a shallow one. Page 2 000 of
  50 makes the engine count past 99 950 rows before it returns anything. For a background pass that
  crosses the whole trail, use a cursor instead.

> 💡 **Tip.** Call `$page->entries->loadReferences()` before rendering a page whose entries point at
> mixed subject types. It resolves labels, subject and actor in a query per morph type instead of a
> query per line, and leaves a recorded type that names no class unresolved rather than fatal.

---

## Walking with a cursor

`after($id)` is not a filter over what an entry *is*. It is a filter over **where it sits**, and it
compiles to one predicate:

```sql
where id > ?
```

By identifier, never by clock. The identifier is the axis because it is total, it is the tail of
every composite index the table carries, and a ULID sorts by the instant it was minted — where two
entries sharing a clock reading do not order against each other at all.

And by identifier is how the walk is **ordered**: behind a cursor the read is sorted on `id` alone,
not on `created_at, id`. It is the only axis a cursor cut from `id` is exact on. The two agree while
one process writes; two workers writing in the same millisecond order one way by `created_at`,
which carries microseconds, and the other way by ULID, which does not — and a walk ordered by the
clock would resume behind the wrong neighbour and skip one for good.

Because the predicate is a *place*, a cursor pass costs the same at any depth. An offset pass does
not.

### A complete pass that resumes

```php
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Query\AuditQuery;

final class ShipTheTrail
{
    private const int BATCH = 1_000;

    public function handle(): void
    {
        $cursor = cache()->get('sentinel.shipper.cursor');

        do {
            $batch = $this->batch($cursor)->get();

            foreach ($batch as $audit) {
                $this->ship($audit);
            }

            $last = $batch->last();

            if ($last instanceof Audit) {
                $cursor = $last->id;
                cache()->forever('sentinel.shipper.cursor', $cursor);
            }
        } while ($batch->count() === self::BATCH);
    }

    private function batch(?string $cursor): AuditQuery
    {
        $query = Sentinel::audits()->whereType('model')->take(self::BATCH);

        return $cursor === null ? $query : $query->after($cursor);
    }
}
```

Three things this loop is doing deliberately:

1. **`take()` is not optional.** `after()` narrows; it does not bound. An uncapped `get()` behind a
   cursor still probes for 501 and still refuses once more than 500 come back.
2. **The cursor is persisted after the batch is shipped**, not before. Crashing mid-batch re-ships a
   batch; crashing after the write skips one.
3. **The loop stops on a short batch**, not on an empty one — one fewer round trip, and the ledger
   contract guarantees nothing comes back after the last identifier it holds.

`sentinel:rekey` is the same pattern on the command line: `--limit` bounds the pass, `--after`
resumes it, and the command prints the identifier to resume behind when it finishes. See
[Artisan commands](../09-operations/06-artisan-commands.md).

### What a cursor cannot do

> ⚠️ **Warning.** There is no `before()`, and `after()` refuses `latest()`: a cursor is cut from
> the identifier and walks along it, forwards. Combining the two throws
> `QueryException::cursorOffItsAxis()`, whichever was asked for first. To walk backwards, either
> page with `paginate()->latest()` and accept the offset cost, or walk forwards and reverse in your
> own code.

`after()` and `paginate()` also compose without complaining, and the result is almost never what was
meant: the cursor narrows first and the offset is then applied *inside* what is left, so
`after($id)->paginate(50, 2)` skips fifty entries beyond the cursor. Pick one.

`byOccurrence()` is refused for the same reason, and it is the combination that mattered most:
ordered by the clock of the fact, a resumed walk skipped whatever was minted before the cursor and
happened after it — under `queue`, `buffered`, an import or a backdated capture, exactly where that
order is wanted. `Sentinel::timeline()` carries that clock by default and refuses a cursor too. To
walk in occurrence order, walk by the cursor and sort each batch on `occurred_at` in your own code,
or page a window fixed with `between()`.

```php
Sentinel::timeline()->after($id);                                        // QueryException::cursorOffItsAxis()
Sentinel::audits()->after($id)->take(500)->get()->sortBy('occurred_at'); // walk by id, order the batch by the fact
```

Before `v1.0.0-rc.2` both combinations were accepted and walked the wrong axis, and the ordinary
walk was ordered by `created_at, id`, which skipped an entry at a page boundary whenever two workers
had written in the same millisecond.

Finally, `Filter::After` is a declared filter like any other, and it is **not** in
`Filter::assumed()` — the nine filters a driver is credited with when it does not implement
`Contracts\DeclaresFilters`. A driver written against the original contract refuses `after()` as you
call it, with `LedgerException::cannotFilterBy()`. See
[Writing a ledger driver](../11-extending/03-writing-a-ledger-driver.md).

---

## Sequence is a different axis

Nothing above involves `sequence`, and that is not an omission. `sequence` is dense and monotonic
**within one stream** — `(stream, sequence)` is the table's unique key — and the same number means a
different entry in every other chain. The Query API has no filter for it and no way to order by it,
because a cross-stream ordering by sequence would be an ordering over nothing.

Reading a chain *in chain order* is a different door: `Contracts\Ledger::stream($name)` hands back a
`Contracts\LedgerStream`, which is `name()`, `range(int $from, ?int $to = null)` and iteration.

| Implementation | How it walks |
|---|---|
| `Ledger\DatabaseStream` | A keyset walk — `where sequence > $cursor order by sequence limit 500`, repeated until a short page. It never holds the chain in memory. |
| `Ledger\ArrayStream` | Sorts by `sequence` in its constructor. A driver that took entries through `append()` holds them in *arrival* order, and a walk that yielded them in arrival order would read as a chain nobody wrote. |

The write side owns the other end of this axis. `Ledger\StreamGate::tail()` reads
`Ledger\StreamTail` — a `(sequence, hash)` pair, `StreamTail::empty()` being `(0, null)` — under a
lock, because the hash covers the sequence and the previous hash and so no `INSERT` can compute its
own link. That is why sequences start at 1 and why they are per chain. See
[Streams](../07-integrity/02-streams.md) and [The hash chain](../07-integrity/01-the-hash-chain.md).

### Why `verifyEverything()` takes no range

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

Sentinel::verifyIntegrity('global', from: 1, to: 10_000);   // one stream, a range
Sentinel::verifyEverything();                                // every stream, whole
```

`Integrity\Verifier::verifyEverything()` asks the ledger for its list of streams and calls
`verify($stream)` on each, with no bounds. It takes no `$from` and no `$to` because a range of
sequence numbers is a question about *one* chain: `1–1000` names a thousand different entries in each
stream it is applied to, so a range across all of them is a range across nothing.

The same rule is enforced at the command line. `sentinel:verify --from` or `--to` without `--stream`
returns `Command::INVALID` and prints:

```
A range of sequences is a question about one stream: the same numbers mean different entries
in each of them. Pass --stream with --from and --to.
```

A ledger that cannot list its chains is refused rather than reported clean. `verifyEverything()`
requires `Contracts\EnumeratesStreams` and throws `QueryException::cannotEnumerateStreams()`
otherwise — "nothing is broken" about a list nobody could produce is the one answer that reads as
reassurance and means nothing. The three commands that walk every chain
(`sentinel:verify`, `sentinel:checkpoint`, `sentinel:prune`) ask for the list in one place,
`Console\Concerns\WalksStreams`, so there is one rule and not three. `DatabaseLedger::streams()` is a
distinct scan of the leading column of the chain's unique index, **ordered by name** so two runs of
the same report can be diffed. See [Verification](../07-integrity/06-verification.md).

---

## The compliance feedback loop

With `compliance => true`, every read that goes through `AuditQuery` writes two things: an ordinary
audit entry with `audit_type = 'access'` and `event = 'read'` — chained, hashed, signed, consuming a
sequence like any other — plus a projection row in `sentinel_access_log`.

That entry lands **in `sentinel_audits`**. Which means an unfiltered walk of the trail eventually
reads its own footprints.

### The arithmetic

One read that goes through `AuditQuery` produces exactly one access entry. `paginate()` calls the
recorder once per page, so:

- Start with **N** entries and page with `perPage = P`.
- Each page consumes `P` entries and appends `1`.
- The walk ends after roughly **N / (P − 1)** pages, having read that many of its own access entries
  at the tail.
- At `P = 1` the tail grows exactly as fast as the walk advances, and **the loop never terminates.**

The default oldest-first order is what keeps this from being worse: new entries land at the tail, not
in the page in front of you. A cursor walk does not escape it either — `after()` is `id > cursor`,
and a ULID minted during the walk is always ahead of the cursor.

These are legitimate entries of legitimate reads and nothing hides them. What you do instead is
**bound the walk before you start it.**

### Bounding it

```php
use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Facades\Sentinel;

$upTo = CarbonImmutable::now();
$from = $upTo->subYear();
$page = 1;

do {
    $slice = Sentinel::audits()
        ->between($from, $upTo)   // created_at, both ends inclusive
        ->paginate(500, $page++);

    foreach ($slice as $audit) {
        // ...
    }
} while ($slice->hasMore);
```

`between()` bounds `created_at`, and an access entry written *during* the walk settles after `$upTo`.
The window is closed before the first page is asked for, so the walk cannot grow its own input.

Two alternatives, both verified against the code:

| Approach | Why access entries fall out |
|---|---|
| `between($from, $upTo)` fixed before the walk | Their `created_at` is later than `$upTo`. |
| `whereType('model')`, `whereType('relation')`, … | `Compliance\AccessLog` writes `audit_type = 'access'`, which no other type filter matches. |
| `for($subject)` | An access entry carries **no subject** — `AccessLog` builds its `AuditData` without one. |

> 🔒 **Security.** Do not "solve" this by excluding access entries from a report an auditor reads.
> They are evidence that the trail was read, and `sentinel_access_log` is only the searchable
> projection — the row is editable by anyone who can write the table, and the chained entry is what
> makes a read provable. Bound the walk; do not filter the evidence out of the output.

Two more facts about the same mechanism:

- **A refused read records nothing.** `get()` throws `QueryException::unbounded()` *before* the
  access log is reached, deliberately: nothing was handed over, so there is nothing to record.
- **What is recorded is the page, not the probe.** An uncapped `get()` issues `limit 501` and records
  `limit: 500`; a paginated read records `perPage` and the offset, and a result count equal to the
  page that actually went back.
- **Not every read is recorded.** `$model->audits()`, `latestAudit()`, the `Audit::field()` scope,
  `Ledger::find()`, the primary-key lookup `sentinel:show <id>` does on `Models\Audit`, and the four
  `verify*` walks go nowhere near `AuditQuery` and leave nothing behind. `tests/Compliance/ReadPathsTest.php` pins
  that boundary. See [Compliance mode](../08-lifecycle/05-compliance-mode.md).

> 🐘 **Engine.** `sentinel_access_log` grows with *reads* rather than with writes, and nothing in it
> is hashed, so it is cheap to divide. It has its own partitioning path via
> `sentinel:partitions --table=access_log`. See [Partitioning](../10-database-engines/06-partitioning.md).

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `QueryException`: *"This filter matches at least 500 entries…"* | An uncapped `get()` filled its 501-row probe | Narrow the filter, `take($n)` a prefix on purpose, or `paginate()` |
| A backwards walk keeps handing back entries you already processed | You are on a release before `v1.0.0-rc.2`, where `after()` under `latest()` returned entries newer than the cursor, newest first | From that candidate on the combination throws. Walk forwards with `after()`; page with `paginate()` if you must go backwards |
| A `latest()` offset walk shows the same entry on two consecutive pages | Under `latest()` new entries land at the head, shifting every offset by one per write | Fix the window with `between()` before the walk, or walk oldest-first with a cursor |
| `after($id)->paginate(50, 2)` silently skips 50 entries beyond the cursor | `after` and `offset` are independent criteria and both apply | Use a cursor **or** an offset, never both |
| `$page->total` is undefined | `AuditPage` carries `entries`, `page`, `perPage`, `hasMore` and nothing else | Use `hasMore`; there is deliberately no count |
| `AuditResource::collection($page)` renders a bare JSON array with no `meta` / `links` | `AuditPage` is not a Laravel paginator | Wrap `$page->entries` and build the envelope yourself |
| `Sentinel::timeline()->between(...)` returns entries whose `occurred_at` is outside the window | `between()` bounds `created_at`; `byOccurrence()` changes only the ordering | Read it as *sealed in this window, ordered by when it happened* |
| `QueryException::cursorOffItsAxis()` from `after()`, `byOccurrence()` or `latest()` | A cursor is cut from `id` and walks along it; a clock order would skip entries at a page boundary | Walk in the default order and sort each batch in your own code, or page a window fixed with `between()` |
| `LedgerException` naming `after()` on a third-party driver | `Filter::After` is not in `Filter::assumed()` | Implement `Contracts\DeclaresFilters` and name `Filter::After` |
| A compliance-mode walk never finishes | Each page appends one `access` entry to the tail; at `perPage = 1` the tail grows as fast as the walk | Bound with `between()` fixed before the walk, and page in hundreds, not ones |
| `sentinel:verify --from=1 --to=100` exits `INVALID` | A sequence range is a question about one chain | Pass `--stream` alongside `--from` / `--to` |
| `QueryException`: *"cannot say which streams it holds"* | The configured ledger does not implement `Contracts\EnumeratesStreams` | Name the stream to verify, or implement the interface |
| `Sentinel::transitions()->paginate(...)` — undefined method | `TransitionQuery` publishes no `paginate()`: a paged interval would be wrong or missing | `Sentinel::transitions()->entries()->paginate(...)` and compute intervals yourself |

---

## ✅ Best practices

✅ **Do** — bound a full pass with a window you fix *before* the first page. It makes the walk
finite, keeps a compliance-mode walk from reading its own access entries, and makes the pass
restartable with the same arguments.

```php
use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Facades\Sentinel;

$upTo = CarbonImmutable::now();
$page = 1;

do {
    $slice = Sentinel::audits()->between($upTo->subMonth(), $upTo)->paginate(500, $page++);
} while ($slice->hasMore);
```

❌ **Don't** — page an unnarrowed trail until `hasMore` goes false. Under compliance every page you
read appends an entry to the tail you are walking towards, and even without compliance an unnarrowed
read pays a full pass and a sort on all three engines.

```php
while ($page->hasMore) {
    $page = Sentinel::audits()->paginate(50, ++$number);   // the tail keeps moving
}
```

---

✅ **Do** — resume a background pass with `after()` plus `take()`. The cursor costs the same at any
depth, `take()` is what keeps `get()` from refusing behind it, and the first pass — which has no
cursor yet — skips `after()` rather than passing `null` into a `string` parameter.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

$query = Sentinel::audits()->whereType('model')->take(1_000);

$batch = ($cursor === null ? $query : $query->after($cursor))->get();
$cursor = $batch->last()?->id ?? $cursor;
```

❌ **Don't** — combine `after()` with `latest()` and expect the walk to continue backwards. The
predicate stays `id > ?`, so you get the entries *newer* than the cursor in newest-first order — a
loop that re-reads the head of the trail forever and never reaches the tail.

```php
Sentinel::audits()->latest()->after($cursor)->take(100)->get();   // not a backwards page
```

---

✅ **Do** — say `take($n)` when a prefix is genuinely what you want. It is the only way to read fewer
entries than the filter matches, and it names the intent at the call site.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

$recent = Sentinel::audits()->for($invoice)->latest()->take(10)->get();
```

❌ **Don't** — wrap `get()` in a `try`/`catch` to fake a truncation. Catching
`QueryException::unbounded()` and carrying on with whatever you happen to hold reinstates exactly the
failure the refusal exists to prevent — a partial answer wearing the shape of a complete one.

```php
use ElPandaPe\Sentinel\Exceptions\QueryException;

try {
    $entries = Sentinel::audits()->get();
} catch (QueryException) {
    $entries = Sentinel::audits()->take(500)->get();   // now silently incomplete again
}
```

---

✅ **Do** — read a chain in chain order through `Ledger::stream()` when what you need is the
sequence: it walks by keyset in pages of 500 and never holds the whole chain in memory.

```php
use ElPandaPe\Sentinel\Contracts\Ledger;

foreach (app(Ledger::class)->stream('global')->range(1, 10_000) as $audit) {
    // $audit->sequence is dense and ascending within this stream
}
```

❌ **Don't** — try to reconstruct a chain by ordering a Query API read. There is no `sequence` filter
and no way to order by it, because the same number means a different entry in every other stream —
and `verifyEverything()` refuses a range for the same reason.

```php
Sentinel::audits()->get()->sortBy('sequence');   // meaningless across streams
```

---

✅ **Do** — `loadReferences()` a page before rendering it. It resolves labels, subject and actor in a
query per morph type rather than a query per line, and tolerates a recorded type that names no class.

```php
$page = Sentinel::timeline()->forTenant('acme')->paginate(50);
$page->entries->loadReferences();
```

❌ **Don't** — touch `$audit->subject` or `$audit->impersonator` inside a loop over a page.
`loadReferences()` resolves subject, actor and labels only; the impersonator is not among them, so
rendering it per line is a query per line — and a fatal one under
`Model::preventLazyLoading()`.

```php
foreach ($page as $audit) {
    echo $audit->impersonator?->name;   // one query per entry
}
```

---

✅ **Do** — size a batch off the published constant when you want to stay inside what one uncapped
read will answer.

```php
use ElPandaPe\Sentinel\Query\AuditQuery;

$batch = Sentinel::audits()->for($order)->take(AuditQuery::DEFAULT_LIMIT)->get();
```

❌ **Don't** — hard-code `500`, or assume `paginate()` inherits the same bound. `paginate()` has no
ceiling of its own: `paginate(100_000)` issues a statement for 100 001 rows and hands them all back.

```php
$page = Sentinel::audits()->paginate(100_000);   // no refusal, and no memory left
```

---

**See also:** [The Query API](01-the-query-api.md) · [Filters reference](02-filters-reference.md) · [The timeline](05-the-timeline.md) · [Presenting and serializing](07-presenting-and-serializing.md) · [Streams](../07-integrity/02-streams.md) · [Verification](../07-integrity/06-verification.md) · [Compliance mode](../08-lifecycle/05-compliance-mode.md) · [Indexes and JSON](../10-database-engines/05-indexes-and-json.md) · [Writing a ledger driver](../11-extending/03-writing-a-ledger-driver.md) · [Exceptions](../99-reference/06-exceptions.md)
