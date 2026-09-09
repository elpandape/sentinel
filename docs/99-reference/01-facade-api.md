# 📚 The Sentinel facade

> Every method `ElPandaPe\Sentinel\Facades\Sentinel` publishes, what it returns, what it throws — and
> the four fluent objects it hands back, with their full method lists.

**On this page:** [How it resolves](#how-it-resolves) · [Reading](#reading) · [Stating](#stating) ·
[Correlating](#correlating) · [Verifying](#verifying) · [Introspecting](#introspecting) ·
[Controlling](#controlling) · [AuditQuery](#auditquery) · [TransitionQuery](#transitionquery) ·
[PendingEvent](#pendingevent) · [TransitionBuilder](#transitionbuilder) ·
[What the facade does not publish](#what-the-facade-does-not-publish) · [⚠️ Pitfalls](#️-pitfalls) ·
[✅ Best practices](#-best-practices)

---

## How it resolves

`ElPandaPe\Sentinel\Facades\Sentinel` is a Laravel facade whose accessor is the container entry
`ElPandaPe\Sentinel\Sentinel`. The package's `composer.json` registers the root alias `Sentinel`, so
`\Sentinel::audits()` works too; every example here imports the facade explicitly.

| Fact | Consequence |
|---|---|
| `Sentinel::class` is bound `scoped` in `SentinelServiceProvider` | The `$paused` flag set by `pause()` lives as long as the container scope — one request, one job — and does not leak into the next. |
| The manager is `final` and takes eight constructor collaborators | You cannot subclass it. Rebinding it means constructing `Config`, `ExecutionContext`, `Verifier`, `Policies`, `Ledger`, `TransactionScope`, `Recorder` and `Tracer` yourself. |
| The extension seams are elsewhere | Rebind `Contracts\Ledger` (scoped) or `Contracts\SpanContextProvider`, or change `config/sentinel.php`. See [Swapping components](../11-extending/06-swapping-components.md). |
| The facade docblock carries exactly nineteen `@method static` lines | That list is the frozen surface. It is also what static analysis reads — there is no `__call` fallthrough to anything else. |

The nineteen methods fall into six groups. Every one of them is listed below, alphabetically inside
its group.

---

## Reading

Three methods, all of which return a *description* of a read rather than entries. Nothing touches the
ledger until you call a terminal (`get()`, `paginate()`, `compare()`).

### `audits(): ElPandaPe\Sentinel\Query\AuditQuery`

A fresh, unnarrowed query bound to the scoped `Contracts\Ledger`. This is the one door into the
trail: it is the only read path that writes to the compliance access log, and the only one a
non-database driver is guaranteed to answer identically.

**Returns** an [`AuditQuery`](#auditquery), ordered oldest-first by `created_at`, with no criterion
set. **Throws** nothing.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

$entries = Sentinel::audits()->for($invoice)->take(50)->get();
```

### `timeline(): ElPandaPe\Sentinel\Query\AuditQuery`

Literally `audits()->byOccurrence()` — the same query with `occurred_at`, the clock of the fact, in
front of the ordering instead of `created_at`, the clock of the ledger. It composes with every other
filter. It does **not** change which column `between()` bounds; that stays `created_at`.

**Returns** an `AuditQuery` with `byOccurrence` set. **Throws** nothing.

```php
$page = Sentinel::timeline()->forTenant('acme')->paginate(50);
```

### `transitions(): ElPandaPe\Sentinel\Transitions\TransitionQuery`

The lifeline surface: `audits()->whereType('transition')` wrapped in a
[`TransitionQuery`](#transitionquery), which publishes only the criteria that mean something about a
sequence of states.

**Returns** a `TransitionQuery`. **Throws** `LedgerException::cannotFilterBy` *at this call* when the
resolved ledger does not declare `Filter::Type` — `Type` is not one of the nine filters a driver
without `Contracts\DeclaresFilters` is assumed to answer, so a third-party driver written against the
v0.9.0 contract makes `Sentinel::transitions()` throw before you have narrowed anything.

```php
$lifeline = Sentinel::transitions()->for($invoice)->take(200)->get();
```

---

## Stating

Two builders for facts Eloquent never announces. Both are **mutable** builders whose terminal is
`record()`, and both `record()` methods return `void` — with the write deferred to a commit the entry
does not exist yet when the call comes back, so there is nothing honest to hand back.

### `event(string $name): ElPandaPe\Sentinel\Capture\PendingEvent`

Starts a custom event — an approval, a dispatch, a decision — that no model change describes. It
settles through the same pipeline, ledger and chain as an update.

**Returns** a [`PendingEvent`](#pendingevent) that will write `audit_type = 'custom'` and
`event = $name`. **Throws** `ConfigurationException::eventTooLong` immediately, at *this* call, when
`$name` is longer than `PendingEvent::MAX_NAME_LENGTH` (64 characters) — the name is inside the
canonical payload, so an engine that truncated it would leave an entry that never verifies again.

```php
Sentinel::event('invoice.approved')->subject($invoice)->record();
```

### `transition(Model $subject, bool|float|int|string|UnitEnum|null $from, bool|float|int|string|UnitEnum|null $to): ElPandaPe\Sentinel\Transitions\TransitionBuilder`

States that a record moved between two states. Sentinel says it moved; it never performs the move.
`from:` and `to:` are the documented named arguments.

**Returns** a [`TransitionBuilder`](#transitionbuilder) that will write `audit_type = 'transition'`.
**Throws** `QueryException::unsavedModel` immediately from the builder's constructor when `$subject`
has no key yet. `ConfigurationException::ambiguousTransition` and `Transitions\IllegalTransition` come
later, from `record()`.

```php
Sentinel::transition($invoice, from: 'pending', to: 'approved')->reason('Budget confirmed')->record();
```

---

## Correlating

### `transaction(string $name, Closure $callback): mixed`

Opens a business-operation scope: writes an `AuditTransaction` header row, stamps every entry
captured inside with the same `transaction_id`, and closes the header with `finished_at`,
`audits_count` and `metadata`.

It **does not open a database transaction**. Correlating and atomising are separate decisions, and
combining them is the application's — wrap the call in `DB::transaction()` yourself if you want
atomicity. When `isRecording()` is false the callback simply runs: no header, no identifier.

**Returns** whatever `$callback` returns. **Throws** whatever the callback throws, after the header
has been closed with the failure's *class* (never its message) in `metadata`.

```php
$reference = Sentinel::transaction('invoice.approve', fn (): string => $service->approve($invoice));
```

### `withContext(array<string, mixed> $context, Closure $callback): mixed`

Merges keys into the scoped `ExecutionContext` for the duration of the callback and restores the
previous map in a `finally`, so it nests and survives an exception. It merges **over** the resolved
payload and can never reach a promoted column — to change who acted, replace the actor resolver.

**Returns** whatever `$callback` returns. **Throws** whatever the callback throws.

```php
Sentinel::withContext(['reason' => 'Approved by finance'], fn () => $invoice->update(['status' => 'approved']));
```

---

## Verifying

Four walks over the chain, three of which take a stream name. See
[Verification](../07-integrity/06-verification.md) for what each one proves and does not prove; the
signatures and return shapes are here.

| Method | Reads | Returns |
|---|---|---|
| `verifyAnchors(string $stream)` | The anchors, plus the unanchored tail | `Integrity\StreamVerification` |
| `verifyEverything()` | Every stream the ledger names, one at a time | `Integrity\IntegrityReport` |
| `verifyIntegrity(string $stream, ?int $from = null, ?int $to = null)` | Every entry of the range, rehashing each | `Integrity\VerificationResult` |
| `verifyRoots(string $stream)` | The same anchor walk, refolding every root from stored hashes | `Integrity\StreamVerification` |

`VerificationResult` is a readonly value object with `$stream`, `$checked`, `$reason`
(`Enums\IntegrityBreak` or null), `$sequence`, `$auditId`, `$archived`, plus `isIntact()` and
`message()`. `StreamVerification` carries a `VerificationResult` as `$chain` alongside `$signatures`,
`$anchors`, `$covered`, `$content` and `$anchorSignatures`, with `isIntact()`, `stream()`, `break()`,
`redacted()` and `archived()`. `IntegrityReport` holds `$streams` (a list of `StreamVerification`) and
answers `isIntact()`, `checked()`, `covered()`, `archived()`, `firstBreak()`, `anchors()` and
`signatures()`.

`verifyEverything()` takes no range on purpose: the same sequence numbers mean different entries in
different streams. **It throws** `QueryException::cannotEnumerateStreams` when the resolved ledger
does not implement `Contracts\EnumeratesStreams` — a driver that cannot list its chains is refused
rather than answered with an empty, reassuring report.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

Sentinel::verifyIntegrity('global')->isIntact();
Sentinel::verifyEverything()->firstBreak()?->message();
```

---

## Introspecting

### `config(): ElPandaPe\Sentinel\Support\Config`

The resolved configuration as a typed object — the singleton every part of the package reads instead
of calling `config('sentinel.*')`. It caches nothing: each accessor re-reads the repository and
re-validates, so a runtime `config()->set()` takes effect on the next call, and a bad value surfaces
as a `ConfigurationException` wherever it is read rather than at boot. Around seventy accessors;
see [Configuration](02-configuration.md) for the keys.

```php
Sentinel::config()->mode();            // Enums\Mode
Sentinel::config()->table('audits');   // 'sentinel_audits'
```

### `context(): ElPandaPe\Sentinel\Context\ExecutionContext`

The scoped key/value bag the resolvers fill and the pipeline reads.

| Method | Does |
|---|---|
| `all(): array<string, mixed>` | The whole map |
| `get(string $key, mixed $default = null): mixed` | One key |
| `has(string $key): bool` | Whether the key was set, `null` included |
| `set(string $key, mixed $value): void` | Writes one key |
| `merge(array<string, mixed> $data): void` | Writes many |
| `forget(string $key): void` | Removes one |
| `flush(): void` | Empties the map *and* the memo table |
| `memoize(string $key, Closure $resolve): array<string, mixed>` | Resolves once per container scope |
| `scope(array<string, mixed> $data, Closure $callback): mixed` | What `withContext()` calls |

### `isRecording(): bool`

`config()->enabled() && ! $paused`. Every capture path asks this first. **Throws**
`ConfigurationException` when `sentinel.enabled` is absent or is not a real boolean.

### `trace(): ?ElPandaPe\Sentinel\Telemetry\TraceContext`

The trace this process is inside of, for an application that wants to forward it. It reads only: it
never mutates the context and opens no span. `null` means no trace, which is what telemetry switched
off always answers. `TraceContext` publishes `traceId()`, `spanId()`, `traceparent()`, `tracestate()`
and `sampled()`.

```php
Http::withHeaders(array_filter(['traceparent' => Sentinel::trace()?->traceparent()]))->post($url);
```

---

## Controlling

### `filter(Closure $policy): void`

Registers a global veto. The closure receives the fully transformed `Data\AuditData` — masked,
hashed, encrypted, labelled — and returning `false` discards the entry in the last pipeline stage,
before the ledger assigns a sequence, so the chain gets no gap.

`Policies` is a **singleton** with no public removal, so a filter registered per-request accumulates
for the life of a worker process. Register from a service provider's `boot()`, once.

```php
use ElPandaPe\Sentinel\Data\AuditData;

Sentinel::filter(static fn (AuditData $audit): bool => $audit->subject_type !== HeartbeatPing::class);
```

### `pause(): void` · `resume(): void`

`pause()` sets the flag on the scoped manager. `resume()` clears it **unconditionally** — it does not
restore a previous value. Nothing else takes the flag back down, so a throw between the two leaves
auditing off for the rest of the scope, with the entries that were supposed to be written simply
absent. Prefer `withoutAuditing()`.

### `withoutAuditing(Closure $callback): mixed`

The scoped form: saves the current flag, pauses, and restores it in a `finally`. It nests correctly
and survives an exception. **Returns** whatever the callback returns; **rethrows** whatever it throws,
with the previous pause state restored.

```php
Sentinel::withoutAuditing(fn () => $importer->run());
```

> ⚠️ **Warning.** A restoration (`$audit->restore()`) is recorded even inside `withoutAuditing()`. A
> trail that can put a record back without saying so misleads by omission.

---

## AuditQuery

`ElPandaPe\Sentinel\Query\AuditQuery` is **immutable**: every narrowing method clones, so a query you
hand to another object cannot be narrowed behind your back. Nothing on it takes a column name and
nothing returns an Eloquent builder — that is what lets a driver over arrays answer the same query.

Every criterion is checked against the driver's declared filter set **as the method is called**
(`LedgerException::cannotFilterBy`), never silently dropped at execution.

### Narrowing

| Method | Narrows by | `Enums\Filter` case | Throws |
|---|---|---|---|
| `after(string $id)` | Position: `id > $id`, a resumable cursor ordered by `id` alone | `After` | `QueryException::noCursor` on `''`; `cursorOffItsAxis` beside `byOccurrence()` or `latest()` |
| `between(DateTimeInterface $from, DateTimeInterface $to)` | `created_at`, both ends inclusive — never `occurred_at` | `Period` | `QueryException::backwardsPeriod` |
| `by(object\|string $actor, int\|string\|null $id = null)` — alias `byActor()` | Who did it | `Actor` | `QueryException::unsavedModel` · `missingKey` · `unreferenceable` |
| `for(object\|string $subject, int\|string\|null $id = null)` — alias `forModel()` | Whom it was about | `Subject` | same as `by()` |
| `forTenant(string $tenant)` | `tenant_id` | `Tenant` | — |
| `inTransaction(AuditTransaction\|string $transaction)` | One business operation; takes the header or its id | `Transaction` | — |
| `whereAnyTag(array\|string $tag)` | At least one of the labels; accumulates | `Tag` | `QueryException::noLabels` |
| `whereEvent(AuditEvent\|string $event)` | The name of what happened | `Event` | — |
| `whereFieldChanged(string $path)` | A diff touching that JSON Pointer *or anything beneath it* | `FieldChanged` | `QueryException::noField`; `LedgerException::cannotTranslateOn` later, when the query runs on an engine with no predicate |
| `whereIp(string $ip)` | `context.ip`, exact and case-sensitive | `Ip` | `QueryException::noContextValue` |
| `whereOperation(RelationOperation\|string ...$operations)` | attach / detach / update; accumulates | `Operation` | `QueryException::unknownOperation` |
| `whereRelated(object\|string $related, int\|string\|null $id = null)` | The record touched through a relation | `Related` | same as `by()` |
| `whereRelation(string $relation)` | The relation method's name | `Relation` | — |
| `whereRoute(string $route)` | `context.route` — the route's name, or its uri when unnamed | `Route` | `QueryException::noContextValue` |
| `whereSeverity(Severity $severity)` | info / notice / warning / critical | `Severity` | — |
| `whereSource(Source $source)` | http, api, cli, queue, job, scheduler, console, system, import | `Source` | — |
| `whereTag(array\|string $tag)` | Every label named; accumulates | `Tag` | `QueryException::noLabels` |
| `whereType(string $type)` | The *kind* of entry — one of the nine `audit_type` values: `model`, `relation`, `mass`, `custom`, `auth`, `transition`, `restore`, `security`, `access` | `Type` | `QueryException::noType` on `''` |
| `whereVersion(int ...$versions)` | The subject's version numbers; accumulates and deduplicates | `Version` | — |
| `withTrace(string $trace)` | W3C trace id | `Trace` | — |

Only `Subject`, `Actor`, `Event`, `Severity`, `Source`, `Tenant`, `Transaction`, `Trace` and `Period`
are assumed of a driver that does not implement `Contracts\DeclaresFilters`. That list never grows;
everything else in the table is answered only by a driver that names it.

> ⚠️ **Warning.** `whereType('transaction')` is not an error and always comes back empty. A business
> transaction is not a kind of entry: `Sentinel::transaction()` writes its header into
> `sentinel_transactions` (no `sequence`, no `hash`) and stamps `transaction_id` onto the entries
> captured inside it. Read the header through `Models\AuditTransaction`, and its entries with
> `inTransaction()` — the filter above, which takes the header or its id. See
> [Business transactions](../03-capture/06-business-transactions.md).

### Ordering

`byOccurrence(): self` puts `occurred_at` in front of the ordering; `latest(): self` reverses
whichever clock is in front. Neither is a `Filter` case, so neither can be refused by a driver, and
they commute. The order is total on every driver: the chosen clock, then the entry's ULID.

### Terminals

| Method | Returns | Behaviour |
|---|---|---|
| `compare(int $from, int $to)` | `Query\Comparison` | One read. `Comparison` carries `$from`, `$to` (both `Audit`) and `$diff`, computed from the two `after` snapshots. Throws `ComparisonException::withoutSubject` when the query was not narrowed with `for()`, `::missingVersion` when no entry carries a number, `::acrossSubjects` from `Comparison::between()`. |
| `get()` | `Support\AuditCollection` | With no `take()`, probes for `DEFAULT_LIMIT + 1` (501) and throws `QueryException::unbounded` once more than 500 come back — exactly 500 is answered whole. It refuses rather than truncates. |
| `paginate(int $perPage, int $page = 1)` | `Query\AuditPage` | One ledger call, asking for `perPage + 1`. Throws `QueryException::unreachablePage` when either argument is below 1. |
| `take(int $limit)` | `self` | A prefix asked for on purpose; `get()` then issues exactly that read with no probe and no refusal. Throws `QueryException::unreachableLimit` below 1. |

`AuditQuery::DEFAULT_LIMIT` is `500`; why the refusal is a refusal is in
[Order, paging and walking the trail](../06-reading/03-order-paging-and-walking.md#how-much-comes-back).
`AuditPage` is a readonly object with `$entries`, `$page`,
`$perPage` and `$hasMore`; it implements `Countable` and `IteratorAggregate` and has **no total** —
counting the rows a filter matches on a table that only ever grows is the one question here whose
cost is unbounded and that no index answers.

### Readable properties

`AuditQuery` publishes every criterion through PHP 8.4 asymmetric visibility (`public private(set)`):
`$subject`, `$actor`, `$event`, `$severity`, `$source`, `$tenantId`, `$transactionId`, `$traceId`,
`$period`, `$tags`, `$relations`, `$changedField`, `$type`, `$ip`, `$route`, `$versions`,
`$newestFirst`, `$byOccurrence`, `$limit`, `$offset`, `$after`. This is how a driver reads the
criteria and how the compliance access log describes a read without rendering SQL.

---

## TransitionQuery

`ElPandaPe\Sentinel\Transitions\TransitionQuery` is a `final readonly` wrapper over one `AuditQuery`
with `whereType('transition')` pinned. It publishes six methods and one terminal; the rest
of the query surface is deliberately absent, because narrowing a lifeline by the relation that was
touched would be asking a different question.

| Method | Returns | Notes |
|---|---|---|
| `between(DateTimeInterface $from, DateTimeInterface $to)` | `self` | Still bounds `created_at`. Throws `QueryException::backwardsPeriod`. |
| `by(object\|string $actor, int\|string\|null $id = null)` | `self` | Who moved it. |
| `entries()` | `Query\AuditQuery` | Drops back to the query underneath for paging and labels. It is handed back **before** `get()` puts `byOccurrence()` in front, so it is ordered by the ledger clock, not the clock of the fact. |
| `for(object\|string $subject, int\|string\|null $id = null)` | `self` | One record's lifeline. |
| `get()` | `Illuminate\Support\Collection<int, Transitions\Transition>` | Always ordered by `occurred_at`. Throws `QueryException::unbounded(500)` past the default limit with no `take()`. |
| `latest()` | `self` | Reverses the reading, not the arithmetic: `since` still points backwards in time. |
| `take(int $limit)` | `self` | Throws `QueryException::unreachableLimit` below 1. |

There is **no `paginate()`**: the interval of a page's first row is the distance to an entry the page
does not hold.

`Transitions\Transition` is a readonly step with `$entry` (the `Audit`), `$attribute`, `$from`, `$to`,
`$reason`, `$actor` (a `Support\Reference` — `@internal`, but its `->type` and `->id` are readable),
`$occurredAt` (`CarbonImmutable`) and `$since` (`CarbonInterval` or null). `since` is computed in PHP
at read time and persisted nowhere, and `null` means "first in what was read", not "first that ever
happened".

---

## PendingEvent

`ElPandaPe\Sentinel\Capture\PendingEvent`, returned by `Sentinel::event()`. Unlike `AuditQuery` it is
**mutable**: each modifier returns `$this`, and each one *overwrites* rather than merges.

| Method | Does | Throws |
|---|---|---|
| `actor(object\|string $actor, int\|string\|null $id = null): self` | Attributes the fact to someone other than the resolved actor | `QueryException::unsavedModel` · `missingKey` · `unreferenceable` |
| `metadata(array<string, mixed> $metadata): self` | The entry's metadata, inside the hashed payload | — |
| `record(): void` | The terminal. Returns silently when `isRecording()` is false | — |
| `severity(Severity $severity): self` | Beats `severity.events` and `severity.default` | — |
| `subject(Model $subject): self` | The record it is about; omit it and the fact stays subjectless | `QueryException::unsavedModel` when the model has no key |
| `tags(array<int, string> $tags): self` | Labels; unioned with `tags.default` in the pipeline | `ConfigurationException::tagTooLong` later, in the tag stage, past 64 characters |

Constants: `PendingEvent::AUDIT_TYPE` is `'custom'`, `PendingEvent::MAX_NAME_LENGTH` is `64`.

> 📌 **Note.** A custom event with no model subject gets no *model-level* protection: `$auditRedact`,
> `$auditHash`, `$auditEncrypt` and `$auditTags` contribute nothing, because there is no model to read
> them off. Only the config lists under `security.*.fields` and `tags.default` reach it. See
> [Protecting sensitive data](../05-pipeline-and-security/02-protecting-sensitive-data.md).

---

## TransitionBuilder

`ElPandaPe\Sentinel\Transitions\TransitionBuilder`, returned by `Sentinel::transition()`. Mutable,
same shape as `PendingEvent`; `record()` is the terminal and no other method closes the call.

| Method | Does | Throws |
|---|---|---|
| `actor(object\|string $actor, int\|string\|null $id = null): self` | Who moved it | `QueryException::unsavedModel` · `missingKey` · `unreferenceable` |
| `metadata(array<string, mixed> $metadata): self` | Your own metadata; the column and reason are added on top under a `transition` key, so a `['reason' => …]` of yours stays yours | — |
| `on(string $attribute): self` | Names the column that moved; it becomes the JSON Pointer of the change line | — |
| `reason(string $reason): self` | Lands at `metadata.transition.reason` | — |
| `record(): void` | The terminal. Returns silently when `isRecording()` is false — governance is skipped with it | `ConfigurationException::ambiguousTransition` · `Transitions\IllegalTransition` |
| `severity(Severity $severity): self` | Beats `severity.events.transition` and `severity.default` | — |
| `tags(array<int, string> $tags): self` | Labels | `ConfigurationException::tagTooLong` in the label stage |

The column is resolved at `record()` in this order: `->on()`, then a **single** entry in the model's
`$auditTransitions`, then `config('sentinel.transitions.attribute')` (ships as `status`). Two or more
declared columns with no `->on()` throws `ConfigurationException::ambiguousTransition`. If the subject
implements `Contracts\DeclaresTransitions` and answers `false`, `IllegalTransition` is thrown before
the entry reaches the pipeline, so nothing is written and no sequence is spent.

`TransitionBuilder::AUDIT_TYPE` is `'transition'`.

---

## What the facade does not publish

- **No global mass-operation switch.** Mass statements are audited per query, through the
  `auditing()` macro on the Eloquent builder — see [Mass operations](../03-capture/05-mass-operations.md).
- **No `Sentinel::restore()`.** Restoring is a method on the entry: `$audit->restore()`. See
  [Restoring state](../06-reading/08-restoring-state.md).
- **No way to remove a registered `filter()`.** `Support\Policies` is `@internal` and its `forget()`
  is used only by the package's own test suite.
- **No transaction control.** `transaction()` never opens a `DB::transaction()`, and no setting makes
  it. Whether the write itself waits for a commit is `transactions.after_commit`, a different
  decision in a different class.
- **No entry returned from `record()`.** Both builders' terminals return `void`.
- **No routes, no HTTP surface.** `Http\Resources\AuditResource` exists; the package mounts nothing
  and authorising a read is the application's question.

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| Auditing silently stops for the rest of a request or job, no error | `pause()` was called and something threw before `resume()`; nothing else lowers the flag | Use `Sentinel::withoutAuditing(fn () => …)`, which restores the previous state in a `finally` |
| `Sentinel::transitions()` throws `LedgerException` before you have narrowed anything | The resolved ledger does not declare `Filter::Type`, and `Type` is not in the assumed nine | Implement `Contracts\DeclaresFilters` on the driver and name `Filter::Type` |
| `QueryException: This filter matches at least 500 entries…` from a lifeline you did not think was long | `TransitionQuery::get()` delegates to `AuditQuery::get()`, which refuses rather than truncates | Add `->take(n)`, or use `->entries()->paginate(n)` and lose the interval arithmetic |
| A filter you added never narrowed anything | `whereVersion()` and `whereOperation()` are variadic with no emptiness guard — called with no arguments they are silent no-ops | Pass at least one value; `whereTag([])` throws, these two do not |
| `Sentinel::audits()->for(Invoice::class, 500)` returns nothing while `for($invoice)` works | With a morph map registered the column holds the alias; a class-string is passed through unresolved | Pass the model, or the alias the entry actually recorded |
| `transaction()` ran, the callback threw, and the rollback took your business rows but the header row is still there | The header is not a chained entry and is written outside any transaction you opened; it is closed with `metadata.failed` naming the exception class | Read `finished_at` and `metadata` on the header; that is what it is for |
| A `filter()` closure fires more and more times per entry in a long-lived worker | It was registered from a controller or job handler; `Policies` is a singleton and `add()` only appends | Register once, from a service provider's `boot()` |
| An entry was written but `$audit->before` is empty and the diff says nothing changed | `compare()` diffs the two `after` snapshots, and the model declares `$auditSnapshots = false` | Read `Comparison::$from` and `$to`, not only `$diff` — an empty diff has several causes |
| `Sentinel::event('…')` throws before you have called `record()` | The name is longer than 64 characters, and it is refused at construction because it is inside the hashed payload | Shorten the name; put the detail in `metadata()` |
| `verifyEverything()` throws `QueryException::cannotEnumerateStreams` | The ledger cannot list its chains | Name the stream with `verifyIntegrity()`, or implement `Contracts\EnumeratesStreams` |

---

## ✅ Best practices

✅ **Do** — suspend auditing with `withoutAuditing()`. It is the only form that restores the previous
state in a `finally`, so it nests and cannot leak a permanently-off engine into the rest of the scope.

```php
Sentinel::withoutAuditing(fn () => $importer->run());
```

❌ **Don't** — call `pause()` and hope for a `resume()`. A throw in between leaves auditing off with
no error and no entries, and silent absence is the worst failure mode an audit engine has.

```php
Sentinel::pause();
$importer->run();      // throws — and nothing is audited for the rest of the request
Sentinel::resume();
```

✅ **Do** — register `filter()` policies once, from a service provider's `boot()`. `Policies` is a
singleton with no public removal, so this is the only place a filter is registered exactly once.

```php
public function boot(): void
{
    Sentinel::filter(static fn (AuditData $audit): bool => $audit->changes !== []);
}
```

❌ **Don't** — register one per request or per job. In a long-lived worker or under Octane they
accumulate, and every entry pays for every copy.

```php
public function handle(): void
{
    Sentinel::filter(static fn (AuditData $audit): bool => true);   // one more, every job
}
```

✅ **Do** — spell out `DB::transaction()` when you want atomicity, beside `Sentinel::transaction()`
when you want correlation. They are two decisions and the package keeps them in two classes.

```php
DB::transaction(fn () => Sentinel::transaction('invoice.approve', fn () => $service->approve($invoice)));
```

❌ **Don't** — expect `Sentinel::transaction()` to roll anything back. It opens no database
transaction, and there is no setting that makes it.

```php
Sentinel::transaction('invoice.approve', function () use ($invoice): void {
    $invoice->update(['status' => 'approved']);
    throw new PaymentDeclined;      // the update stays; only the header records the failure
});
```

✅ **Do** — bound every read. `take()` when you want a prefix, `paginate()` for a screen, `after()`
for a background walk that resumes where it stopped — guarded on the first pass, because
`after(string $id)` is not nullable and `''` throws `QueryException::noCursor()`.

```php
$query = Sentinel::audits()->whereType('model')->take(1000);
$batch = ($cursor === null ? $query : $query->after($cursor))->get();
```

❌ **Don't** — combine `after()` with `latest()` or `byOccurrence()`. A cursor is cut from the
identifier and walks along it, forwards; beside a clock order it throws
`QueryException::cursorOffItsAxis()`, whichever was asked for first.

```php
Sentinel::audits()->latest()->after($cursor)->get();   // throws — a cursor is not a backwards walk
```

✅ **Do** — read `Sentinel::config()` when you need a setting, so a wrong type fails loudly in one
place.

```php
$mode = Sentinel::config()->mode();
```

❌ **Don't** — hand-roll `config('sentinel.…')`. That reintroduces the silent-default problem
`Support\Config` exists to remove, and a published config file that predates a nested key will win
with nothing configured.

```php
$mode = config('sentinel.mode', 'sync');   // no validation, no exception, wrong answer
```

✅ **Do** — go through `Sentinel::audits()` when a read has to be evidenced. It is the only read path
that writes to the compliance access log.

```php
$entries = Sentinel::audits()->for($patient)->take(100)->get();
```

❌ **Don't** — assume every read leaves a trail because compliance mode is on. `$model->audits()`,
`$model->latestAudit()`, the `Audit::field()` scope, `Ledger::find()` and the four `verify*` walks
leave nothing behind.

```php
$entries = $patient->audits()->get();      // ergonomic, and invisible to the access log
```

---

**See also:** [The Query API](../06-reading/01-the-query-api.md) ·
[Filters reference](../06-reading/02-filters-reference.md) ·
[State transitions](../03-capture/08-state-transitions.md) ·
[Business transactions](../03-capture/06-business-transactions.md) ·
[Custom and authentication events](../03-capture/07-custom-and-authentication-events.md) ·
[Verification](../07-integrity/06-verification.md) · [Configuration](02-configuration.md) ·
[Exceptions](06-exceptions.md) · [API stability](09-api-stability.md)
