# 🔎 The timeline

> Everything that happened, in the order it happened: what `Sentinel::timeline()` actually is, when
> its order and the chain's order disagree, which one answers which question, and how to render a feed
> a person will read.

**On this page:** [What a timeline is](#what-a-timeline-is) · [The clock of the fact](#the-clock-of-the-fact) · [When the timeline and the chain disagree](#when-the-timeline-and-the-chain-disagree) · [Narrowing a timeline](#narrowing-a-timeline) · [The indexes that serve it](#the-indexes-that-serve-it) · [Rendering](#rendering-a-timeline) · [An activity feed](#worked-example-an-activity-feed) · [Pitfalls](#️-pitfalls) · [Best practices](#-best-practices)

---

## What a timeline is

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

Sentinel::timeline();            // ===  Sentinel::audits()->byOccurrence()
```

That is the whole of it. `Sentinel::timeline()` returns the same `Query\AuditQuery` that
`Sentinel::audits()` returns, with one flag flipped: `byOccurrence = true`. It is not a different
surface, not a different table, and not a merge of sources performed in PHP.

It can be one query because every kind of entry lives in `sentinel_audits`. A model change, a pivot
attach, a state transition, a stated business fact, a sign-in, a mass update, a restoration, a
redaction trail and — under compliance mode — a read all share the table and are told apart by
`audit_type`. A timeline is therefore the *unnarrowed trail with a different clock in front*, and it
takes every filter the trail takes.

```php
Sentinel::timeline()->for($invoice)->whereTag('billing')->latest()->paginate(50);
```

### The order

`Ledger\DatabaseLedger::query()` compiles it as two `order by` clauses and nothing else:

```
order by occurred_at <dir>, id <dir>
```

The array-backed drivers (`MemoryLedger`, `ArchiveLedger`, `NullLedger`, any third-party driver over
`Ledger\ArrayQuery`) sort with the same rule in PHP. The identifier is the tie-break because a ULID
sorts by the instant it was minted, so two entries stamped in the same microsecond still come back in
the order they were written — on a table or not. The order is total on every driver.

`latest()` reverses both clauses at once. It commutes with `byOccurrence()`: `timeline()->latest()`
and `audits()->latest()->byOccurrence()` are the same query.

---

## The clock of the fact

Every entry carries two instants, and the timeline picks the first of them.

| | `occurred_at` | `created_at` |
|---|---|---|
| Means | when the fact happened | when the ledger settled the entry |
| Stamped by | the capture class, `CarbonImmutable::now()` | `Ledger\EntryBuilder`, with `sequence` and `hash` |
| Covered by the hash | **yes** — it is one of the twenty-seven columns in `Integrity\CanonicalPayload::COLUMNS` | no |
| Nullable | no. `Support\AuditSchema` declares `dateTime('occurred_at', 6)` with no `nullable()` | no |
| Used for ordering by | `timeline()`, `byOccurrence()`, every lifeline | `audits()` by default |
| Used for narrowing by | nothing | `between()`, retention, the published partition stubs |

`occurred_at` is a public property of `Data\AuditData`, so an importer or a pipeline transformer can
set it to the instant a fact really happened: `Import\Origins\OwenIt` and `Import\Origins\Altek` copy
the source row's own instant, and **refuse** a row that does not say when it happened rather than
inventing one. That is why an imported entry can carry an `occurred_at` years older than its
`created_at` — and why a timeline is the only order that puts imported history where it belongs.

For the full account of when the two clocks agree and what silently changes when they stop, see
[The audit record](../01-concepts/02-the-audit-record.md) and
[Performance modes](../09-operations/01-performance-modes.md).

> ⚠️ **Warning.** `between()` bounds `created_at`, always — including on a timeline. `Query\Period`
> says so in as many words and `DatabaseLedger` compiles `whereBetween('created_at', …)` even when the
> query is ordered `byOccurrence()`. Narrowing and ordering follow different clocks on purpose:
> `created_at` is the partition key of both published range plans and the clock retention counts from,
> so a window on the fact's clock would not line up with the one a prune works in.
>
> `Sentinel::timeline()->between($from, $to)` reads the entries the ledger **sealed** in that window
> and hands them back in the order they **happened**.

---

## When the timeline and the chain disagree

There are two orders in this package and they answer different questions.

- **The chain order** is `(stream, sequence)`. It is dense, monotonic, assigned inside the sealing
  transaction, unique per stream, and it is what `Sentinel::verifyIntegrity()` walks. It is also what
  `previous_hash` links, which is why nothing may reorder it.
- **The timeline order** is `occurred_at, id`. It is global — it crosses every stream — and it is what
  a person means by "what happened next".

They agree while writing is synchronous and come apart the moment it is not: a queued write, a
buffered flush, `transactions.after_commit`, or an import. When they disagree, pick by the question:

| The question | The order to trust | Why |
|---|---|---|
| What happened to this record, and in what order? | timeline (`occurred_at`) | It is the clock of the fact, stamped before anything was written. |
| How long was the record in this state? | timeline (`occurred_at`) | `Transitions\Transition::of()` computes the interval from `occurred_at`. |
| Has anything been inserted, removed or rewritten? | chain (`stream`, `sequence`) | `sequence` is dense per stream and each entry hashes the previous hash. |
| Which entry immediately follows this one in the ledger? | chain | `occurred_at` has no such guarantee: two entries can share it. |
| How far behind is the write path? | both — compare `created_at` with `occurred_at` | The gap *is* the answer. |
| Which entries belong to a retention window or a partition? | `created_at` | Retention and both range partition stubs count from it. |

> 📌 **Note.** The timeline order is a presentation order. It proves nothing about the trail. A
> tampered entry with a plausible `occurred_at` sits quietly in the right place on a feed; it is
> `sentinel:verify` walking `(stream, sequence)` that catches it. See
> [Verification](../07-integrity/06-verification.md).

> ⚠️ **Warning.** Never reconstruct chain order from `occurred_at`, and never sort a verification walk
> by it. `sequence`, `hash` and `previous_hash` are load-bearing: an ordering built on the wrong clock
> would report a healthy chain as broken, and any change to how they are assigned costs a
> `payload_version` bump and a backwards-compatibility test.

---

## Narrowing a timeline

A timeline takes every criterion the trail takes. Three of the four correlation axes are published
filters; one is not.

| Timeline of | Call | Filter | Notes |
|---|---|---|---|
| One subject | `Sentinel::timeline()->for($invoice)` | `Filter::Subject` | Also accepts the recorded type and key of a subject that no longer exists: `for('App\Models\Invoice', 500)`. |
| One actor | `Sentinel::timeline()->by($user)` | `Filter::Actor` | Entries the actor caused. It does **not** include entries where they were only the impersonator. |
| One business operation | `Sentinel::timeline()->inTransaction($operation)` | `Filter::Transaction` | Accepts the `Models\AuditTransaction` header as well as its id. |
| One distributed trace | `Sentinel::timeline()->withTrace($traceId)` | `Filter::Trace` | W3C trace id, 32 hex characters. See [Distributed tracing](../04-context/06-distributed-tracing.md). |
| **One HTTP request** | — | **none** | `request_id` is a column and is indexed, but the Query API publishes no filter for it. |

### There is no request filter

`Enums\Filter` has nineteen cases and none of them is the request. `Compliance\AccessLog::describe()`
does not record one either. Two ways round it, both with a cost:

```php
use ElPandaPe\Sentinel\Models\Audit;

// 1. The trace, when telemetry is on. A published filter, driver-agnostic, logged by compliance mode.
Sentinel::timeline()->withTrace($traceId)->take(200)->get();

// 2. Eloquent, straight at the table. Rides the request_id index.
Audit::query()->where('request_id', $requestId)->orderBy('occurred_at')->orderBy('id')->get();
```

Option 2 leaves the Query API, so it is answered only by the database driver, and it leaves **no
access entry** even with compliance mode on — `Compliance\AccessLog` is reached from
`Query\AuditQuery` and from nowhere else.

`request_id` gets onto entries either from the resolver or from
`Http\Middleware\AssignRequestId`, which is opt-in and registered in no middleware group. It reads the
configured header (`resolvers.request.header`, default `X-Request-Id`), honours an incoming value of
1–64 printable ASCII characters, mints a ULID otherwise, and echoes it back on the response. See
[Execution context](../04-context/01-execution-context.md).

### Paging a timeline

`paginate()` and `after()` behave exactly as they do on any other read — one call to the ledger, no
total, and a cursor that is `id > ?` in **both** directions. Do not combine `after()` with `latest()`
expecting a backwards walk. See [Order, paging and walking](03-order-paging-and-walking.md).

---

## The indexes that serve it

The base migration's indexes all end in `created_at` or in `id`. A migration added two more,
specifically so a timeline does not sort outside every index it touches:

| Index | Added by | Serves |
|---|---|---|
| `(occurred_at, id)` | `2026_08_28_000001_add_occurrence_indexes_to_sentinel_audits_table.php` | the whole trail's timeline |
| `(subject_type, subject_id, occurred_at, id)` | the same migration | the timeline of one subject — the shape that actually gets run |
| `(subject_type, subject_id, id)` | base migration | `for()` on the default clock |
| `(actor_type, actor_id, id)` | base migration | `by()` — ends in `id`, so a timeline still sorts |
| `(tenant_id, created_at)` | base migration | `forTenant()` — ends in the *other* clock |
| `(audit_type, created_at)` | base migration | `whereType()` |
| `transaction_id`, `request_id`, `trace_id` | base migration | single-column seeks |

Two of those rows are the interesting ones. A timeline narrowed by **subject** rides an index end to
end: `tests/Query/QueryPlanTest.php` asserts that `Sentinel::timeline()->for('invoice', 7)` both reads
an index and does **not** sort outside it. A timeline narrowed by **tenant** reaches the tenant index
and still sorts, because `(tenant_id, created_at)` ends in the clock the timeline is not using — the
same test asserts exactly that pair of facts.

> 🐘 **Engine.** For an *unnarrowed* timeline the three engines disagree. SQLite commits to
> `(occurred_at, id)` at any size. MySQL and PostgreSQL are cost-based: at suite size they prefer to
> read the table and top-N sort it, and only take the index once the table is large enough. Measured
> at two hundred thousand entries, taking the index moves MySQL from 100 ms to 0.39 ms and PostgreSQL
> from 12.9 ms to 0.23 ms. The suite asserts what each engine really does at fixture size rather than
> pinning a plan that stops being true when the fixture shrinks.

> 💡 **Tip.** The practical consequence: narrow by subject when you can. An unnarrowed timeline is the
> one read whose cost grows with the whole table.

> 🧪 **Verify it.** `make test ARGS=tests/Query/QueryPlanTest.php` runs the plan assertions on SQLite;
> `make test-dbs` runs them on MySQL 9 and PostgreSQL 16 as well.

---

## Rendering a timeline

### The presenter

`Presentation\AuditPresenter::timeline()` renders one line per entry: `occurred_at` as `H:i`, two
spaces, then the entry sentence.

```php
use ElPandaPe\Sentinel\Presentation\AuditPresenter;

echo app(AuditPresenter::class)->timeline($entries);
// 10:02  Someone created Role #3
// 11:30  Administrator #1 acting as User #100 changed Invoice #500
```

Every word comes from `resources/lang` — event names included — in English and Spanish. An empty
collection renders as an empty string, not as a placeholder. A relation entry gets its lines
underneath it; a transition entry gets its two states appended on the same line; a mass entry renders
as a count and a class rather than as a reference.

The format is `H:i`: **no date**. That is deliberate for a single day's feed and wrong for anything
longer, which is why grouping is the caller's job.

### Load what the page points at, once

```php
$page = Sentinel::timeline()->forTenant('acme')->paginate(50);

$page->entries->loadReferences();
```

`Support\AuditCollection::loadReferences()` resolves `tags`, `subject` and `actor` in a query per morph
type instead of a query per line — `tests/Query/TimelineTest.php` asserts three queries or fewer for
ten entries naming ten different subjects. Two things it does not do:

- **It does not resolve the impersonator.** Only `subject` and `actor`. Rendering
  `$entry->impersonator` across a page is a query per line, and a
  `LazyLoadingViolationException` under `Model::preventLazyLoading()`.
- **It does not fail on a type that names no class.** An entry outliving its subject is the normal
  case. Such an entry comes back with the relation simply not loaded, and `$entry->subject` is null.

### Grouping by day

There is no grouping in the package. Do it on the collection, and hand each group to the presenter as
its own `AuditCollection`:

```php
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Presentation\AuditPresenter;
use ElPandaPe\Sentinel\Support\AuditCollection;

$byDay = $page->entries->groupBy(
    static fn (Audit $entry): string => $entry->occurred_at->toDateString(),
);

foreach ($byDay as $date => $entriesOfTheDay) {
    echo $date, PHP_EOL;
    echo app(AuditPresenter::class)->timeline(new AuditCollection($entriesOfTheDay->all())), PHP_EOL;
}
```

Group on `occurred_at`, not on `created_at`: a feed grouped by settlement date puts a Monday afternoon
edit under Tuesday whenever the buffer flushed overnight.

### Collapsing runs

Twenty consecutive edits by one person to one record is one thing that happened, read out twenty
times. Collapse on the tuple that makes a run — actor, subject and event — and keep the count:

```php
$runs = [];

foreach ($page->entries as $entry) {
    $key = implode('|', [$entry->actor_type, $entry->actor_id, $entry->subject_type, $entry->subject_id, $entry->event]);
    $last = $runs === [] ? null : array_key_last($runs);

    if ($last !== null && $runs[$last]['key'] === $key) {
        $runs[$last]['count']++;
        $runs[$last]['until'] = $entry->occurred_at;

        continue;
    }

    $runs[] = ['key' => $key, 'count' => 1, 'from' => $entry->occurred_at, 'until' => $entry->occurred_at, 'entry' => $entry];
}
```

Collapse for display only. Keep the ids of what you folded away, and never present the count as though
it were one entry: the trail holds twenty rows and the chain covers twenty rows.

### There is no null `occurred_at` branch to write

`occurred_at` is `NOT NULL` in `Support\AuditSchema`, cast to `CarbonImmutable` on the model, required
by the `Data\AuditData` constructor, and inside the canonical payload. The importers refuse a source
row that does not say when it happened rather than inventing an instant. `AuditPresenter::timeline()`
calls `$audit->occurred_at->format('H:i')` with no guard, and that is safe.

What you can meet instead is an **implausible** instant — an imported entry dated years ago, or one a
transformer set. Those sort exactly where their clock says, which is the point. If a feed needs to
separate "recorded history" from "live activity", filter on `whereSource(Source::Import)` rather than
looking for nulls.

---

## Worked example: an activity feed

A page of a tenant's activity, grouped by day, rendered without a query per line, and safe against
subjects that no longer exist.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Presentation\AuditPresenter;
use ElPandaPe\Sentinel\Query\AuditPage;

final readonly class ActivityFeed
{
    public function __construct(private AuditPresenter $presenter) {}

    /** @return array{days: array<string, list<array{time: string, line: string, id: string, type: string}>>, hasMore: bool} */
    public function forTenant(string $tenant, int $page = 1): array
    {
        $result = Sentinel::timeline()
            ->forTenant($tenant)
            ->latest()
            ->paginate(50, $page);

        $result->entries->loadReferences();

        return ['days' => $this->group($result), 'hasMore' => $result->hasMore];
    }

    /** @return array<string, list<array{time: string, line: string, id: string, type: string}>> */
    private function group(AuditPage $result): array
    {
        $days = [];

        foreach ($result as $entry) {
            /** @var Audit $entry */
            $days[$entry->occurred_at->toDateString()][] = [
                'time' => $entry->occurred_at->format('H:i'),
                'line' => $this->presenter->entry($entry),
                'id' => $entry->id,
                'type' => $entry->audit_type,
            ];
        }

        return $days;
    }
}
```

Rendered, that gives:

```
2026-08-26
  11:30  Administrator #1 acting as User #100 changed Invoice #500
  10:02  Someone created Role #3
```

What the example is doing on purpose:

1. **`latest()` before `paginate()`.** Newest first is what a feed wants, and the order applies to the
   fact's clock because `timeline()` already put it in front.
2. **`paginate()`, not `get()`.** One call to the ledger, no `COUNT`, and `hasMore` for the price of
   one extra row. A bare `get()` refuses with `QueryException::unbounded` once more than 500 match.
3. **`loadReferences()` before the loop.** The presenter reads `actor_type`/`actor_id` from the entry
   itself, but anything richer — a link, an avatar, a display name — needs the relation, and this is
   the only cheap way to have it.
4. **`audit_type` carried through.** It is the column that tells a relation entry from a model change
   from a compliance read. An event name cannot: an application is free to call its own custom event
   `updated`.
5. **The id carried through.** A feed line that leads nowhere is a feed line nobody can act on, and
   `id` is the only identifier that is unique across the whole table.

> 📌 **Note.** With compliance mode on, an unfiltered paginated walk eventually reaches its own access
> entries: a read writes an `access` entry, and that entry lands in `sentinel_audits` like any other.
> Under the default oldest-first order they collect at the tail; under `latest()` they arrive first.
> They are legitimate entries of legitimate reads and are not hidden. Narrow them out with
> `whereType('model')` if a feed should not show them.

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| A feed shows edits in the wrong order after switching to `queue` or `buffered` mode | The screen is built on `Sentinel::audits()`, which orders by `created_at` — the settlement clock. | Use `Sentinel::timeline()` (or `->byOccurrence()`) anywhere a person reads the order of events. |
| `timeline()->between($from, $to)` returns entries whose `occurred_at` is outside the window | `between()` bounds `created_at`, always. Narrowing and ordering follow different clocks on purpose. | Accept it, or filter the collection on `occurred_at` after the read. There is no filter on the fact's clock. |
| A timeline of one tenant is slow and the plan shows a sort | `(tenant_id, created_at)` ends in the wrong clock, so the tenant filter finds by index and the order is sorted afterwards. | Narrow further, page with `paginate()`, or accept the sort over a bounded page. |
| An unnarrowed timeline is fast on SQLite in tests and slow on MySQL in production | SQLite commits to `(occurred_at, id)`; MySQL and PostgreSQL are cost-based and only take it once the table is large. | Do not benchmark plans on SQLite. Run `make test-dbs`, and narrow by subject where you can. |
| `after($cursor)` under `latest()` returns entries *newer* than the cursor | The cursor compiles to `id > ?` in both directions; only the `order by` is reversed. | Walk forwards with `after()`, or use `paginate()` for a backwards walk. |
| `$entry->subject` is null on a timeline line | The recorded type names no class — the model was dropped, or a morph alias was retired. `loadReferences()` leaves it unresolved rather than fatal. | Render from `subject_type` + `subject_id`, which the entry always has. That is what `AuditPresenter` does. |
| A `LazyLoadingViolationException` while rendering a feed | `loadReferences()` resolves `tags`, `subject` and `actor` only — not `impersonator`, not `transaction`, not `relations`. | Load them yourself: `$page->entries->load('transaction')`. |
| `AuditResource::collection($page)` produces a bare array with no `meta` or `links` | `Query\AuditPage` implements `Countable` and `IteratorAggregate`, not Laravel's paginator contract, so no pagination envelope is built. | Wrap `$page->entries`, and put `page`, `perPage` and `hasMore` in the envelope yourself. |
| A read of one HTTP request's entries is not in the compliance access log | There is no request filter on `AuditQuery`, so the read went through Eloquent, and `Compliance\AccessLog` is reached only from `AuditQuery`. | Correlate with `withTrace()` where telemetry is on, or accept the gap knowingly. |
| The feed fills up with `read` entries | Compliance mode writes an `access` entry per read through the Query API, and those are entries like any other. | `whereType('model')`, or exclude `access` at the rendering layer. |

---

## ✅ Best practices

✅ **Do** — use `timeline()` for anything a person reads as a sequence, and `audits()` for anything
about the ledger's own bookkeeping.

```php
$feed = Sentinel::timeline()->for($invoice)->latest()->paginate(25);
```

❌ **Don't** — build a feed on the default order and assume the two clocks agree. They agree only
while writing is synchronous; under `queue`, `buffered` or `after_commit` the default order is the
order things were *written*.

```php
$feed = Sentinel::audits()->for($invoice)->latest()->paginate(25);
```

---

✅ **Do** — narrow a timeline by subject when you can. `(subject_type, subject_id, occurred_at, id)`
exists precisely so that read rides an index end to end with no sort.

```php
Sentinel::timeline()->for($order)->take(100)->get();
```

❌ **Don't** — page an unnarrowed timeline deeply. `paginate()` resolves an offset the engine has to
count past, and the unnarrowed order is the one read whose cost grows with the whole table.

```php
Sentinel::timeline()->paginate(50, 4000);   // the engine skips 199,950 rows to build this page
```

---

✅ **Do** — call `loadReferences()` once per page, before rendering.

```php
$page = Sentinel::timeline()->forTenant($tenant)->paginate(50);
$page->entries->loadReferences();
```

❌ **Don't** — reach for a relation inside the render loop. Each line becomes its own query, and an
application that forbids lazy loading gets an exception instead of a feed.

```php
foreach ($page as $entry) {
    echo $entry->subject?->name, $entry->impersonator?->name;
}
```

---

✅ **Do** — group and label with `occurred_at`, and keep the entry id on every line so a reader can act
on it.

```php
$days[$entry->occurred_at->toDateString()][] = ['id' => $entry->id, 'time' => $entry->occurred_at->format('H:i')];
```

❌ **Don't** — group by `created_at`. A buffered flush at 02:00 files Monday's work under Tuesday, and
nothing about the screen says so.

```php
$days[$entry->created_at->toDateString()][] = /* … */;
```

---

✅ **Do** — answer "is this trail intact?" with the chain, not with the feed.

```php
Sentinel::verifyIntegrity($stream);   // walks (stream, sequence) and rehashes
```

❌ **Don't** — infer tampering from a gap or a jump in a timeline. `occurred_at` has no density
guarantee, entries from every stream are interleaved in it, and an imported entry legitimately sits
years back. `sequence` is the column that is dense and monotonic.

```php
$suspicious = $entries->first()->occurred_at->diffInMinutes($entries->last()->occurred_at) > 60;
```

---

✅ **Do** — slice a feed by `whereType()` when you want one kind of entry.

```php
Sentinel::timeline()->for($order)->whereType('transition')->get();
```

❌ **Don't** — slice by event name. `audit_type` is the kind of entry; `event` is its name, and an
application is free to call its own custom event `updated`.

```php
Sentinel::timeline()->whereEvent('updated')->get();   // matches model changes and custom events alike
```

---

**See also:** [The Query API](01-the-query-api.md) · [Filters reference](02-filters-reference.md) · [Order, paging and walking](03-order-paging-and-walking.md) · [Field history](04-field-history.md) · [Labels](06-labels.md) · [Presenting and serializing](07-presenting-and-serializing.md) · [The audit record](../01-concepts/02-the-audit-record.md) · [State transitions](../03-capture/08-state-transitions.md) · [Business transactions](../03-capture/06-business-transactions.md) · [Execution context](../04-context/01-execution-context.md) · [Distributed tracing](../04-context/06-distributed-tracing.md) · [Performance modes](../09-operations/01-performance-modes.md) · [Verification](../07-integrity/06-verification.md) · [Indexes and JSON](../10-database-engines/05-indexes-and-json.md)
