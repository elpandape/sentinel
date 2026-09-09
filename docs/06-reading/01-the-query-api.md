# 🔎 The Query API

> How to read the trail: one entry point, an immutable query object stated against the ledger
> contract, and three terminals that actually go to the store.

**On this page:** [One door in](#one-door-in) · [A description, not an execution](#a-description-not-an-execution) · [Filters are refused as you add them](#filters-are-refused-as-you-add-them) · [The terminals](#the-terminals) · [What comes back](#what-comes-back) · [`Sentinel::audits()` vs `$model->audits()`](#sentinelaudits-vs-modelaudits) · [Compliance mode records what goes through here](#compliance-mode-records-what-goes-through-here) · [A real query](#a-real-query) · [⚠️ Pitfalls](#️-pitfalls) · [✅ Best practices](#-best-practices)

---

## One door in

Every read of the trail starts at the facade:

```php
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Query\AuditQuery;

$query = Sentinel::audits();   // AuditQuery
```

`Sentinel::audits()` (`src/Sentinel.php`) hands back a fresh `Query\AuditQuery` bound to whichever
driver `ledger.default` resolved — `database`, `archive`, `memory`, `null`, `fanout`, or one of your
own. It is
unfiltered, ordered oldest-first by the ledger's clock, and it has no criteria set.

Two other entry points are the same object with one method already applied:

| Call | Equivalent to | Returns |
|---|---|---|
| `Sentinel::audits()` | — | `AuditQuery`, unfiltered |
| `Sentinel::timeline()` | `Sentinel::audits()->byOccurrence()` | `AuditQuery` ordered by the clock of the fact |
| `Sentinel::transitions()` | `Sentinel::audits()->whereType('transition')`, wrapped | `Transitions\TransitionQuery` |

`TransitionQuery` composes an `AuditQuery` rather than extending it, and publishes only the criteria
that mean something about a sequence of states; `->entries()` drops back to the query underneath.
See [State transitions](../03-capture/08-state-transitions.md).

### Why not Eloquent

`AuditQuery` is stated against `Contracts\Ledger`, not against a query builder. No method takes a
column name, no method takes an operator or a direction, and nothing on the surface returns an
Eloquent builder. Two things follow, and both are the reason the surface is shaped this way:

- **Driver portability.** Execution is exactly one call to `Ledger::query($query)`. `DatabaseLedger`
  compiles each criterion into a bound `where`; `MemoryLedger`, `ArchiveLedger`, `NullLedger` and any
  third-party driver over arrays hand the same object to `Ledger\ArrayQuery`, which walks what it
  holds. A query written for one answers on the other. See
  [The Ledger contract](../11-extending/01-the-ledger-contract.md).
- **One place a read can be recorded.** Because there is no builder to reach past this surface, the
  read that [compliance mode](../08-lifecycle/05-compliance-mode.md) has to record is the one that
  goes through here — a single private method, not a hook spread over Eloquent.

> 📌 **Note.** There is no `where(string $column, ...)`, no `orWhere()`, no `toSql()`, no
> `getQuery()`, no `->toBase()`. Calling one is a PHP `Error: Call to undefined method`, not a
> runtime surprise. If you need a criterion the surface does not publish, the answer is a criterion
> on the surface, not an escape hatch — see [Filters reference](02-filters-reference.md) for what is
> published.

---

## A description, not an execution

An `AuditQuery` describes a narrowing. It runs nothing until you call a terminal.

**Every narrowing method returns a new instance.** The properties are declared with PHP 8.4
asymmetric visibility (`public private(set)`), and each method `clone`s before it writes:

```php
$query = Sentinel::audits();

$query->take(10);        // the return value is bounded

$query->limit;           // null — $query itself was never touched
```

That is asserted in `tests/Query/BoundedReadTest.php` ("hands back a new query rather than bounding
the one it was given"). The practical consequences:

- A query you hand to another object cannot be narrowed behind your back.
- A partially-built query is safe to reuse as a base for several different reads.
- **`$query->whereTag('billing');` on its own line does nothing.** Nothing is mutated, the return
  value is discarded, and there is no warning. Always assign.

The public properties are readable — `subject`, `actor`, `event`, `severity`, `source`, `tenantId`,
`transactionId`, `traceId`, `period`, `tags`, `relations`, `changedField`, `type`, `ip`, `route`,
`versions`, `newestFirst`, `byOccurrence`, `limit`, `offset`, `after`. They are what a driver reads
to answer the query and what the compliance access log reads to describe it. They are not settable
from outside the class.

Most criteria overwrite on a repeated call. Three accumulate on purpose: `whereTag()` /
`whereAnyTag()` (into a `Query\TagCriteria`), the three relation methods (into one
`Query\RelationCriteria`), and `whereVersion()` (a deduplicated `list<int>`). A repeated call that
overwrote there would be a filter that quietly stopped narrowing.

---

## Filters are refused as you add them

The constructor asks `Enums\Filter::answeredBy($ledger)` which criteria the driver can translate, and
`AuditQuery::accepting()` checks each one **at the moment you call the method**:

```php
use ElPandaPe\Sentinel\Exceptions\LedgerException;

// Against a driver that declared only Filter::Subject:
$query->forTenant('acme');
// LedgerException: "…cannot filter by tenant, so forTenant() is not part of the query it answers."
```

The refusal lands on the line that added the filter, with the method name in the message — not at
execution, and never as a silently dropped criterion. A driver that dropped what it could not
translate would answer with entries nobody asked for, and a trail showing the wrong history is worse
than one that refuses.

A driver declares its set through the optional `Contracts\DeclaresFilters`. What the shipped drivers
declare:

| Driver | Declares | Effect on the query surface |
|---|---|---|
| `database` (`DatabaseLedger`) | `Filter::cases()` — all 19 | Every published filter is answerable |
| `memory` (`MemoryLedger`) | `Filter::cases()` | Answered by walking, through `Ledger\ArrayQuery` |
| `archive` (`ArchiveLedger`) | `Filter::cases()` | Answered by walking its batches — and only over what it has itself written |
| `null` (`NullLedger`) | `Filter::cases()` | Every filter accepted, every read answered with an empty collection |
| `fanout` (`FanoutLedger`) | Its primary's set | Reads come from the primary destination only |
| A driver that implements nothing | `Filter::assumed()` — **nine** | See below |

> ⚠️ **Warning.** A driver that does **not** implement `Contracts\DeclaresFilters` is assumed to
> answer only the nine filters published with the contract in v0.9.0 — `Subject`, `Actor`, `Event`,
> `Severity`, `Source`, `Tenant`, `Transaction`, `Trace`, `Period` — and that list never grows. Every
> filter published later (`Tag`, `FieldChanged`, `Version`, `Relation`, `Related`, `Operation`,
> `Type`, `Ip`, `Route`, `After`) is **refused**, not answered. The docblock on
> `Contracts\DeclaresFilters` still says the opposite; `Filter::answeredBy()` and
> `tests/Query/AuditQueryTest.php` are the behaviour. If you maintain a driver, declare the set.

`byOccurrence()`, `latest()` and `take()` are not `Filter` cases and are never refused by a driver.

---

## The terminals

Three methods go to the store. Everything else returns a new `AuditQuery`.

| Terminal | Returns | Bound | Refuses when |
|---|---|---|---|
| `get()` | `Support\AuditCollection` | `AuditQuery::DEFAULT_LIMIT` = 500 unless `take()` set one | `QueryException::unbounded` — more than 500 entries came back and no bound was asked for |
| `paginate(int $perPage, int $page = 1)` | `Query\AuditPage` | `$perPage` | `QueryException::unreachablePage` — either argument below 1 |
| `compare(int $from, int $to)` | `Query\Comparison` | one read | `ComparisonException::withoutSubject` (no `for()`), `ComparisonException::missingVersion` |

### `get()` refuses rather than truncates

With no `take()`, `get()` asks the ledger for `DEFAULT_LIMIT + 1` (501) entries. It throws instead of
answering only when more than 500 come back; a filter matching exactly 500 is answered whole:

```php
use ElPandaPe\Sentinel\Exceptions\QueryException;

try {
    $entries = Sentinel::audits()->forTenant('acme')->get();
} catch (QueryException $tooMany) {
    // "This filter matches at least 500 entries, and handing back the first 500 would look
    //  exactly like handing back all of them. Narrow it, take() a prefix on purpose, or
    //  paginate() through the whole thing."
}
```

A prefix shaped exactly like a complete answer is the one mistake a trail cannot afford, so the
surface declines to issue a read whose size it would only learn once the rows had arrived. The
statement it does issue carries `limit 501` — the extra row is how it knows the bound filled.

`take($limit)` opts out of that: it sets the bound explicitly, and `get()` then issues exactly that
read, with no probe and no refusal. `take(0)` or lower throws `QueryException::unreachableLimit`.

### `paginate()` walks past it

```php
$page = Sentinel::audits()->for($invoice)->latest()->paginate(50);
```

One call to the ledger. It asks for `perPage + 1` rows at offset `(page - 1) * perPage` and hands
back the page without the extra one — which answers `hasMore` for the price of a row instead of the
price of a count. Depth, cursors and the `after()` walk are covered in
[Order, paging and walking the trail](03-order-paging-and-walking.md).

### `compare()` is a terminal too

```php
$comparison = Sentinel::audits()->for($invoice)->compare(1, 7);
```

Two versions, not necessarily adjacent, in one read: internally `whereVersion($from, $to)->latest()
->get()`, then the entry carrying each number. It needs `for()` first — comparing versions without
knowing whose they are throws `ComparisonException::withoutSubject`. It also needs a driver that
declares both `Filter::Subject` and `Filter::Version`. See
[Field history and comparing versions](04-field-history.md).

### What is not here

There is no `first()`, no `count()`, no `exists()`, no `sum()`, no `chunk()`, no `cursor()`, no
`each()`, no `pluck()`, no `lazy()`. Nothing on `AuditQuery` streams, and nothing counts the match.

| You want | Write |
|---|---|
| One entry | `->take(1)->get()->first()` |
| Whether anything matched | `->take(1)->get()->isNotEmpty()` |
| How many are on this page | `count($page)` or `$page->count()` |
| How many the filter matches in total | Not answerable — see [Order, paging and walking](03-order-paging-and-walking.md) |
| To iterate a large range | `paginate()` in a loop, or `after()` as a cursor |

> 📌 **Note.** `count($page)` is the length of the page, never the size of the match. `AuditPage`
> carries no total, deliberately: counting the rows a filter matches on a table that only ever grows
> is the one question in this API whose cost is unbounded and that no index answers.

---

## What comes back

| Type | Shape | Notes |
|---|---|---|
| `Support\AuditCollection` | `Illuminate\Database\Eloquent\Collection<int, Models\Audit>` | Every Eloquent collection method applies. `DatabaseLedger` eager-loads `tags`, so `Audit::toArray()` is not an N+1 |
| `Query\AuditPage` | `final readonly`, `Countable`, `IteratorAggregate` | `->entries` (`AuditCollection`), `->page`, `->perPage`, `->hasMore`. `foreach ($page as $audit)` iterates the entries |
| `Query\Comparison` | `final readonly` | `->from` (`Audit`), `->to` (`Audit`), `->diff` (`Diff\Diff`) |

`AuditPage` is **not** a Laravel paginator. It does not extend `AbstractPaginator`, has no `links()`,
no `total()`, no `meta` when wrapped in a resource collection. Treat it as the value object it is.

`AuditCollection` adds one method of its own, `loadReferences()`, which resolves what a page of
entries points at — labels, subject, actor — in a query per morph type instead of a query per line.
It lives on the collection and not on the query because it changes how entries come back hydrated,
not which ones come back:

```php
$page = Sentinel::timeline()->forTenant('acme')->paginate(50);

$page->entries->loadReferences();
```

A recorded type naming no class is left unresolved rather than fatal — an entry outliving its subject
is the normal case. It does **not** load `impersonator`. See
[Presenting and serializing](07-presenting-and-serializing.md).

---

## `Sentinel::audits()` vs `$model->audits()`

Both read entries. They are not the same mechanism, and the difference matters more than the
ergonomics suggest.

| | `Sentinel::audits()` | `$model->audits()` |
|---|---|---|
| Type | `Query\AuditQuery` | `MorphMany<Audit>` — an Eloquent relation |
| Goes through | `Contracts\Ledger` | The `Audit` model, straight at the audits table |
| Answers on a non-database ledger | Yes | **No** — it queries the table regardless of `ledger.default` |
| Order | The ledger's clock, then the entry's ULID | `id` ascending |
| Labels | Eager-loaded by `DatabaseLedger` | Eager-loaded (`->with('tags')`) |
| Filters | The 19 published criteria | Eloquent's whole builder, plus the `field()` scope |
| Bounded | `get()` refuses past 500 | Unbounded — `->get()` reads everything |
| Recorded under compliance mode | Yes | **No** |

`Concerns\Auditable` also publishes:

- `latestAudit(): ?Audit` — the newest entry about this model, by identifier. Not recorded under
  compliance mode either.
- `relationHistory(string $relation): AuditQuery` — equivalent to
  `Sentinel::audits()->for($this)->whereRelation($relation)`. It returns a **query**, so it composes
  with every other filter and pages like any other read.
- Access to `Models\Audit`'s own `field()` scope through that relation:
  `$user->audits()->field('email')->get()` uses the same "touched this field"
  predicate as `whereFieldChanged()`, so the two cannot drift.

> 💡 **Tip.** Use `$model->audits()` for a small, bounded relation on a page you already have the
> model on. Use `Sentinel::audits()` for anything that has to be bounded, portable across drivers, or
> visible to compliance mode.

---

## Compliance mode records what goes through here

With `compliance => true`, every read that reaches a terminal on `AuditQuery` writes two things: an
ordinary chained entry with `audit_type = 'access'`, and a projection row in `sentinel_access_log`.
Nothing is written when compliance is off. The full behaviour, the boot-time requirements and the
cost live in [Compliance mode](../08-lifecycle/05-compliance-mode.md); what belongs on this page is
the boundary.

**What is recorded** is the shape of the question, read off the query's own properties — subject,
actor, event, severity, source, tenant, transaction, trace, `audit_type`, changed field, versions,
cursor, limit and offset — plus how many entries went back. Not a rendered SQL string: the ledger
that answered may not have been a database.

**What is not recorded:** the period, the labels, the relation criteria, the IP, the route,
`byOccurrence` and `latest`. An auditor reading `sentinel_access_log` cannot tell that a read was
narrowed to a date window, a label, a relation, an address or a route.

**What leaves no trace at all**, even with compliance on — each verified in
`tests/Compliance/ReadPathsTest.php`:

| Path | Why |
|---|---|
| `$model->audits()`, `$model->latestAudit()`, the `field()` scope | Eloquent straight at the model; recording it would mean a write per row hydrated |
| `sentinel:show <id>`, `sentinel:redact` | They find one entry by primary key on `Models\Audit`, because the query surface has no filter on an entry's own identifier |
| `verifyIntegrity()`, `verifyAnchors()`, `verifyRoots()`, `verifyEverything()` | They read to prove, not to disclose |

A **refused** read is not recorded either: the unbounded `get()` throws before the access log is
reached, because nothing was handed over.

> ⚠️ **Warning.** Under compliance mode an access entry lands in `sentinel_audits` like any other, so
> an unfiltered paginated walk of the whole table eventually reads its own footprints. The default
> oldest-first order pushes them to the tail rather than into the page in front of you, but a walk
> that keeps paginating reaches them. They are legitimate entries of legitimate reads and are not
> hidden.

---

## A real query

Built up over several lines, the way it looks in an application:

```php
use App\Models\Invoice;
use DateTimeImmutable;
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Exceptions\QueryException;
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Presentation\AuditPresenter;

$invoice = Invoice::query()->findOrFail($id);
$from = new DateTimeImmutable('-30 days');
$to = new DateTimeImmutable('now');

// A base every branch below narrows further. Nothing has run yet.
$trail = Sentinel::audits()
    ->for($invoice)
    ->forTenant($tenantId);

// One branch: what an operator sees on screen, newest first, inside a window.
$page = $trail->between($from, $to)->latest()->paginate(25, $pageNumber);

$page->entries->loadReferences();

echo app(AuditPresenter::class)->timeline($page->entries);

// Another branch, from the same base — the base is untouched by the one above.
$critical = $trail->whereSeverity(Severity::Critical)->take(20)->get();

// A third: what changed between two versions of this invoice, in one read.
$comparison = $trail->compare(1, 7);

$comparison->diff;   // Diff — list<{path, op, old?, new}> through toArray()
$comparison->from;   // the Audit at version 1
$comparison->to;     // the Audit at version 7
```

An unbounded variant of the same read, and the failure the caller actually sees:

```php
try {
    $everything = Sentinel::audits()->forTenant('acme')->get();
} catch (QueryException $tooMany) {
    // Reached as soon as the tenant has 501 entries. Narrow it, take() a prefix,
    // or paginate() through the whole thing.
}
```

And the same trail on a subject that no longer exists — the model is gone, its entries are not:

```php
$entries = Sentinel::audits()
    ->for('App\\Models\\Invoice', 500)   // the type and key the ENTRY recorded
    ->take(100)
    ->get();
```

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `QueryException: This filter matches at least 500 entries…` on a read that used to work | The trail grew past `AuditQuery::DEFAULT_LIMIT` and `get()` refuses rather than truncating | Narrow the filter, `take(n)` a prefix on purpose, or `paginate()` |
| A narrowing method appears to do nothing | `AuditQuery` is immutable — `$query->whereTag('x');` discards the new instance | Assign: `$query = $query->whereTag('x');` |
| `LedgerException: … cannot filter by tag, so whereTag() is not part of the query it answers` | The configured driver did not declare that filter; the refusal lands on the line that added it | Implement `Contracts\DeclaresFilters` on the driver and name the filter, or drop the criterion |
| A third-party driver that worked in v0.9.0 now refuses `whereType()`, `whereTag()`, `after()` | It does not implement `DeclaresFilters`, so it is assumed to answer only the nine filters of `Filter::assumed()` | Add `supportedFilters()` to the driver |
| `for(Invoice::class, 500)` finds nothing while `for($invoice)` works | With `Relation::enforceMorphMap` the column holds the alias; `Support\Reference::to()` normalises a **model** through `getMorphClass()` but passes a class-string through unchanged | Pass the model, or the alias the entry actually recorded |
| `ComparisonException: Comparing two versions needs to know whose versions they are` | `compare()` was called on a query that was never narrowed with `for()` | Call `->for($subject)` first |
| `Error: Call to undefined method …AuditQuery::where()` | There is no builder behind this surface, by design | Use a published filter — see [Filters reference](02-filters-reference.md) |
| A read is not in `sentinel_access_log` although compliance is on | It did not go through `AuditQuery`: `$model->audits()`, `latestAudit()`, the `field()` scope, `sentinel:show <id>`, or a `verify*` walk | Reach the trail through `Sentinel::audits()` when the read must be evidenced |
| `AuditResource::collection($page)` produces a bare array with no `meta`/`links` | `AuditPage` is not a Laravel paginator — it implements only `Countable` and `IteratorAggregate` | Wrap `$page->entries`, and build the envelope from `->page` / `->perPage` / `->hasMore` yourself |
| `$model->audits()` returns rows although `ledger.default` is `memory` or `null` | The relation queries the audits table through Eloquent; it never goes through the ledger | Read through `Sentinel::audits()` when the driver is meant to decide the answer |

---

## ✅ Best practices

✅ **Do** — reach the trail through `Sentinel::audits()`. It is the only path that is bounded,
driver-portable and visible to compliance mode.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

$entries = Sentinel::audits()->for($invoice)->take(50)->get();
```

❌ **Don't** — query the `Audit` model directly to dodge a missing filter. You lose the bound, the
driver indirection and the compliance record, and you tie the read to the database driver forever.

```php
use ElPandaPe\Sentinel\Models\Audit;

// No bound, no access-log row, and it answers nothing on a non-database ledger.
$entries = Audit::query()->where('subject_id', $invoice->id)->get();
```

---

✅ **Do** — assign the result of every narrowing method. The query is immutable and each method
returns a new instance.

```php
$query = Sentinel::audits()->for($invoice);
$query = $query->whereSeverity(Severity::Critical);
```

❌ **Don't** — call a narrowing method for its side effect. There is none, and nothing warns you.

```php
$query = Sentinel::audits()->for($invoice);
$query->whereSeverity(Severity::Critical);   // discarded — $query is still unnarrowed
```

---

✅ **Do** — bound any read whose size you cannot state. `take()` when you want a prefix, `paginate()`
when you want the whole thing.

```php
$recent = Sentinel::audits()->for($invoice)->latest()->take(20)->get();
$page   = Sentinel::audits()->for($invoice)->latest()->paginate(50);
```

❌ **Don't** — leave a bare `get()` on a filter that grows with the business. It works in
development and throws `QueryException::unbounded` the day the tenant reaches 501 entries.

```php
$all = Sentinel::audits()->forTenant('acme')->get();   // fine at 500, refused at 501
```

---

✅ **Do** — pass the model to `for()` / `by()` / `whereRelated()`, or the exact type and key the entry
recorded when the subject is gone. `Reference::to()` resolves a model through `getMorphClass()`.

```php
Sentinel::audits()->for($invoice)->take(10)->get();
Sentinel::audits()->for('invoice', 500)->take(10)->get();   // the recorded alias
```

❌ **Don't** — pass a class-string while a morph map is registered. It is not resolved into the
alias, so it matches nothing and reports no error.

```php
use App\Models\Invoice;

// Under Relation::enforceMorphMap the column holds 'invoice', never the class-string.
Sentinel::audits()->for(Invoice::class, 500)->get();   // silently empty
```

---

✅ **Do** — declare `Contracts\DeclaresFilters` on any driver you write, and name the set honestly.
Refusing a filter is a supported answer.

```php
use ElPandaPe\Sentinel\Contracts\DeclaresFilters;
use ElPandaPe\Sentinel\Enums\Filter;

public function supportedFilters(): array
{
    return [Filter::Subject, Filter::Actor, Filter::Tenant, Filter::Period];
}
```

❌ **Don't** — leave `DeclaresFilters` off and assume the driver answers everything. It is assumed to
answer nine filters, and every one published later is refused at the call site.

```php
// Without supportedFilters(), this throws LedgerException on the second line.
$query = Sentinel::audits()->for($invoice);
$query = $query->whereTag('billing');
```

---

✅ **Do** — call `for()` before `compare()`, and read the two entries it hands back, not only the
diff. An empty diff has several causes and only one of them is "nothing changed".

```php
$comparison = Sentinel::audits()->for($invoice)->compare(1, 7);

$comparison->from;   // the entry at version 1 — snapshots off? redacted fields?
$comparison->to;
```

❌ **Don't** — treat a comparison as a diff alone, or call it on an unnarrowed query.

```php
Sentinel::audits()->compare(1, 7);   // ComparisonException::withoutSubject
```

---

✅ **Do** — call `loadReferences()` on the entries of a page before rendering subjects and actors.

```php
$page = Sentinel::timeline()->paginate(50);

$page->entries->loadReferences();   // labels + subject + actor, a query per morph type
```

❌ **Don't** — render `$audit->subject` across a page without it. That is a query per line, and a
`LazyLoadingViolationException` in an application that forbids one.

```php
foreach (Sentinel::timeline()->paginate(50) as $audit) {
    echo $audit->subject?->name;   // one query per entry
}
```

---

**See also:** [Filters reference](02-filters-reference.md) · [Order, paging and walking the trail](03-order-paging-and-walking.md) · [Field history and comparing versions](04-field-history.md) · [The timeline](05-the-timeline.md) · [Presenting and serializing](07-presenting-and-serializing.md) · [The Ledger contract](../11-extending/01-the-ledger-contract.md) · [Compliance mode](../08-lifecycle/05-compliance-mode.md) · [The Sentinel facade](../99-reference/01-facade-api.md)
