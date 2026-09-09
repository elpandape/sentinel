# 🔎 Presenting and serializing

> The two ways an entry leaves the package: as a sentence a person reads, and as a frozen array a
> machine consumes. What each contains, what neither contains, and why `verified` is `null`.

**On this page:** [Two surfaces](#two-surfaces) · [The presenter](#the-presenter) · [Localising it](#localising-it) · [The serialized entry](#the-serialized-entry) · [verified has three states](#verified-has-three-states-and-null-is-not-failure) · [What is deliberately not there](#what-is-deliberately-not-there) · [The two orders it fixes](#the-two-orders-it-fixes-and-the-ones-it-does-not) · [Serving it over HTTP](#serving-it-over-http) · [Correlating a request](#correlating-a-request-end-to-end) · [⚠️ Pitfalls](#️-pitfalls) · [✅ Best practices](#-best-practices)

---

## Two surfaces

Reading the trail gives you `Models\Audit` instances. Turning one into output is a separate step,
and there are exactly two:

| | `Presentation\AuditPresenter` | `Audit::toArray()` |
|---|---|---|
| Audience | a person | a program |
| Output | a translated sentence, possibly multi-line | `array<string, mixed>`, 26 top-level keys |
| Stability | the wording is a translation file you may replace | frozen since v0.15.0; keys only ever added |
| Localised | yes, `en` and `es` shipped | no — values are raw, never translated |
| Decrypts | no | no |
| Verifies the chain | no | no |
| Over HTTP | you render it | `Http\Resources\AuditResource` |

Neither one queries. Both operate on an entry you already hold, so both are as cheap or as expensive
as the hydration behind them — see [The Query API](01-the-query-api.md) for `loadReferences()`.

---

## The presenter

`Presentation\AuditPresenter` is a `final readonly` class with one dependency, Laravel's
`Translator`, so the container builds it with no registration:

```php
use ElPandaPe\Sentinel\Presentation\AuditPresenter;

$presenter = app(AuditPresenter::class);
```

It has three public methods and nothing else.

| Method | Takes | Returns | Shape of the output |
|---|---|---|---|
| `entry(Audit $audit)` | one entry | `string` | one sentence; several lines for a relation entry |
| `fieldHistory(AuditCollection $entries, string $path)` | a collection and a field | `string` | one line per entry, joined with `PHP_EOL` |
| `timeline(AuditCollection $entries)` | a collection | `string` | one line per entry, each stamped `H:i` |

```php
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Presentation\AuditPresenter;

$presenter = app(AuditPresenter::class);

echo $presenter->entry($invoice->latestAudit());
// User #100 changed Invoice #500

echo $presenter->timeline(Sentinel::timeline()->for($invoice)->take(20)->get());
// 10:02  Someone created Invoice #500
// 11:30  Administrator #1 acting as User #100 changed Invoice #500

echo $presenter->fieldHistory(
    Sentinel::audits()->for($patient)->whereFieldChanged('email')->get(),
    'email',
);
// 1. v1  ada@example.com
// 2. v4  ada@work.example
// 3. v7  ada@home.example
```

The left number in a field history counts that field's own changes; `v4` is the subject's real
`version`, which is the number that leads back to the whole entry. They differ whenever another
field changed in between. An entry the ledger never numbered renders as `v0`. See
[Field history and comparing versions](04-field-history.md).

### What it renders, per kind of entry

`entry()` branches on `audit_type`, not on the event name:

| `audit_type` | Rendered as | Example |
|---|---|---|
| anything else | one sentence | `User #100 changed Invoice #500` |
| entry with an impersonator | its own sentence template, not an appended clause | `Administrator #1 acting as User #100 changed Invoice #500` |
| `relation` | the sentence, then one indented line per record | `Someone synced Team #1 · members` then `  - Member #1` / `  + Member #2` |
| `transition` | the sentence with both states on the same line | `Someone moved Invoice #500 · draft → paid` |
| `mass` (summary row) | a count and a class instead of a record | `Someone changed 500 Invoice records` |

The relation markers are `+` attached, `-` detached, `~` pivot changed. The lines come out in the
order the entry canonicalised them at capture — by which record was related, not by what became of
it — so two runs of the same `sync()` read the same way round.

Impersonation gets its own translation key rather than a clause glued onto the plain sentence,
because languages do not agree on where "on behalf of" goes; concatenation would freeze English word
order into every later translation. Compare the two shipped lines: `:actor :event :subject` against
`:impersonator acting as :actor :event :subject`, and in Spanish `:impersonator en nombre de :actor
:event :subject`.

### What the presenter does not do

- **It does not render severity.** Neither catalogue carries a group for it. You have
  `Enums\Severity` and can name the four levels yourself.
- **It does not render labels, `tenant_id`, `request_id`, `trace_id` or the diff of a model entry.**
  `entry()` is actor + event + subject, plus the per-type suffix above.
- **`timeline()` prints `H:i` only** — no date, no timezone marker. A timeline spanning two days
  repeats the same hours with nothing to tell them apart. Group by day yourself before rendering.
- **It never queries.** `entry()` reads `subject_type`/`subject_id` off the row and calls
  `class_basename()`; it does not load the subject model, so a deleted record still renders.
- **A half-recorded reference renders as nobody.** `party()` needs both the type and the id; with
  only one it prints `Someone` / `something`.

---

## Localising it

Every word the presenter puts on screen comes out of `resources/lang`, event names included. Two
locales ship: `en` and `es`. Publish them to override or to add a third:

```bash
php artisan vendor:publish --tag=sentinel-lang
```

They land in `lang/vendor/sentinel/{locale}/sentinel.php`. Two key groups matter here:

| Group | What it holds | Fallback when a key is missing |
|---|---|---|
| `presenter.*` | the sentence templates, the markers, `Someone`/`something`, `nothing`/`yes`/`no`/`a structure` | the key name itself is printed (`entry`, `reference`, …) |
| `events.*` | the verb for each event — `updated` → `changed`, `attached` → `attached`, `transition` → `moved` | the raw event string is printed |

That second fallback is the useful one: a custom event named `invoice.approved` with no
`events.invoice.approved` line renders as `Someone invoice.approved Invoice #500`. Adding the line
is all that is needed; a dotted name nests two levels in the file.

> 📌 **Note.** The presenter renders in the locale `app()->getLocale()` returns at the moment of the
> call, not the locale the entry was written under. Nothing about the locale is stored on the row.

---

## The serialized entry

`Audit::toArray()` overrides Eloquent's, so it is also what `toJson()`, a `JsonResponse` and a
`return $audit;` from a controller produce. Its keys have been frozen since v0.15.0: none is renamed,
removed or reinterpreted, and a shape that has to change arrives *beside* the old one. New keys may
be added. `tests/Models/FrozenShapeTest.php` pins the exact list and order.

### The full key list

26 top-level keys, in this order:

| Key | Type | Notes |
|---|---|---|
| `id` | `string` | the entry's ULID, 26 characters |
| `audit_type` | `string` | one of nine: `model`, `relation`, `mass`, `custom`, `auth`, `transition`, `restore`, `security`, `access`. Never `transaction` — a business-transaction header lives in `sentinel_transactions`, not here |
| `event` | `string` | the event name, raw and untranslated |
| `severity` | `string` | `info` · `notice` · `warning` · `critical` |
| `source` | `string` | `http` · `api` · `cli` · `queue` · `job` · `scheduler` · `console` · `system` · `import` |
| `subject` | `{type, id}` \| `null` | `null` unless **both** halves were recorded; `type` is the recorded morph type or alias |
| `actor` | `{type, id}` \| `null` | same rule |
| `impersonator` | `{type, id}` \| `null` | same rule |
| `tenant_id` | `string` \| `null` | |
| `version` | `int` \| `null` | the subject's version counter; `null` when the ledger never numbered it |
| `changes` | `list` \| `null` | diff entries or relation lines — see below |
| `before` | `object` \| `null` | the snapshot, with any encrypted field left as ciphertext |
| `after` | `object` \| `null` | same |
| `metadata` | `object` \| `null` | whatever the caller attached |
| `tags` | `list<string>` | sorted alphabetically, always present, `[]` when there are none |
| `context` | `object` | never `null` — the column is `NOT NULL` |
| `transaction_id` | `string` \| `null` | ULID of the business transaction |
| `request_id` | `string` \| `null` | see [Correlating a request](#correlating-a-request-end-to-end) |
| `trace_id` | `string` \| `null` | W3C trace id, 32 hex characters |
| `span_id` | `string` \| `null` | 16 hex characters |
| `source_audit_id` | `string` \| `null` | the entry a restoration came from |
| `criteria` | `list` \| `null` | the `where` of a mass operation, as structure; `null` off a mass entry |
| `affected_rows` | `int` \| `null` | `null` off a mass entry |
| `integrity` | `object` | ten keys, below |
| `occurred_at` | `string` | `Y-m-d\TH:i:s.uP` — microseconds and offset |
| `created_at` | `string` | same format |

`changes` carries one of two shapes, decided by looking at the **first element** for `relation` and
`operation` keys:

```php
// a model entry — RFC 6901 pointers, `old` may be ABSENT rather than null
[['path' => '/email', 'op' => 'replace', 'old' => 'a@x.test', 'new' => 'b@x.test']]

// a relation entry — always the six keys, extras sorted behind them
[['relation' => 'members', 'operation' => 'attach', 'related_type' => 'App\\Models\\User',
  'related_id' => '7', 'pivot_before' => null, 'pivot_after' => ['role' => 'lead']]]
```

`old` is omitted entirely — not set to `null` — when the old value is genuinely unknown, which
happens for a `replace` or `remove` reconstructed from a JSON Patch with no guarding `test`
(`Diff\Change::toArray()`). Treat the published shape as `{path, op, old?, new}` and use
`array_key_exists()`, not `?? null`. See [Diffs](../03-capture/03-diffs.md).

> ⚠️ **Warning.** A `changes` column this package did not write and cannot parse comes back
> **exactly as found** rather than throwing. That is deliberate: one hand-written row must not stop a
> whole page of the trail from serialising. It also means a consumer cannot assume every `changes`
> value matches one of the two shapes above.

### The integrity block

Ten keys, in this order:

| Key | Type | Notes |
|---|---|---|
| `stream` | `string` | which chain this entry belongs to |
| `sequence` | `int` | its dense position in that chain |
| `algorithm` | `string` | read from the row, not from config — `sha256` by default |
| `payload_version` | `int` | which canonical payload format the hash covers |
| `previous_hash` | `string` \| `null` | `null` for the first entry of a stream |
| `hash` | `string` | 64 hex characters for `sha256` |
| `signature` | `string` \| `null` | published so a third party can verify an export |
| `signature_key_id` | `string` \| `null` | which key signed it |
| `verified` | `null` | **always** `null` — see below |
| `redacted` | `{at, reason, hash}` \| `null` | `null` unless the entry is a tombstone |

`stream`, `sequence`, `hash`, `previous_hash` and `payload_version` are the chain. They are published
here because an exported entry that lacks them cannot be verified by anyone. Changing what any of
them means is a `payload_version` bump and a backwards-compatibility test — see
[The hash chain](../07-integrity/01-the-hash-chain.md).

> 📌 **Note.** `toArray()` is *not* the canonical payload. The hash covers the 27 columns frozen in
> `Integrity\CanonicalPayload::COLUMNS`, which include `encryption` (absent here) and exclude
> `created_at` (present here), and which stamp `occurred_at` as `Y-m-d H:i:s.u` with no offset.
> Never rehash a `toArray()` result and compare it to `integrity.hash`; it will not match. See
> [Canonicalization](../07-integrity/03-canonicalization.md).

### `verified` has three states, and `null` is not failure

`toArray()` does not walk the chain. Verifying an entry means rebuilding its canonical payload and
rehashing it, and a serialiser that did that would turn rendering a page of 50 entries into 50
rehashes. So it reports `null` — *not checked* — rather than guessing.

| Value | Means | Reached by |
|---|---|---|
| `null` | not checked | every `toArray()`, always |
| `true` | this row still reproduces its own hash | your own call to `verifyIntegrity()`, written into the key |
| `false` | it does not | the same call returning `false` |

```php
$data = $audit->toArray();

$state = match ($data['integrity']['verified']) {
    true  => 'verified',
    false => 'TAMPERED',
    null  => 'not checked',
};
```

> ⚠️ **Warning.** Never write `! $data['integrity']['verified']`. `null` is falsy in PHP and in
> JavaScript alike, so that expression renders *not checked* as **TAMPERED** on every entry you ever
> serialise. This is the most misread field in the package.

To actually ask, call the three verifiers on the model. Each answers a different question:

| Call | Returns | Answers |
|---|---|---|
| `$audit->verifyIntegrity()` | `bool` | does this row still reproduce its own hash? (a tombstone answers `false`) |
| `$audit->verifyContent()` | `Enums\ContentState` | `sealed` · `redacted` · `altered` |
| `$audit->verifySignature()` | `Enums\SignatureState` | `signed` · `unsigned` · `invalid` · `unknown_key` |

They are three questions and not one boolean because an unsigned entry is not a failure and a
redaction is not tampering. See [Verification](../07-integrity/06-verification.md).

### What is deliberately not there

| Absent key | Why |
|---|---|
| `encryption` | publishing the block would tell every API consumer which fields are protected and which key is current. The ciphertext stays inline in `before`/`after`, in the same key it replaced. |
| `capture_id` | correlation and idempotency metadata, deliberately outside the canonical payload the hash covers. It is not going to appear. |
| a top-level `relation` | a relation entry's lines live inside `changes`, which the chain seals. `sentinel_audit_relations` is their queryable projection, not the fact. |
| top-level `signature` | it is published, but inside `integrity`, where the rest of the proof is. |
| `redacted_at` / `redaction_reason` / `redacted_hash` | published as `integrity.redacted`, and `null` for an entry nobody redacted. |
| the `subject` / `actor` **models** | `subject` and `actor` are `{type, id}` references, never a serialised model. An entry outlives the record it describes; resolving one is `AuditCollection::loadReferences()`, and the result is not in this shape. |

> 🔒 **Security.** `toArray()` never decrypts. An encrypted field appears in `before`/`after` as the
> ciphertext string that replaced it. There is no public reader-side helper that reverses it —
> `Security\Keyring` is `@internal`, and the only paths that decrypt are a restore and a rekey. If a
> UI must show plaintext, that is an explicit decision you implement and authorise yourself. See
> [Encryption and the keyring](../05-pipeline-and-security/03-encryption-and-the-keyring.md).

### The two orders it fixes, and the ones it does not

A JSON column hands its objects back in whatever order the engine kept them, and MySQL and
PostgreSQL both reorder an object's keys on the way in. So the serialiser puts the package's own key
order back on the two things the package wrote:

| Fixed on the way out | How |
|---|---|
| every shape inside `changes` | a diff entry goes out as `path, op, old?, new`; a relation line as `relation, operation, related_type, related_id, pivot_before, pivot_after`, pivot maps `ksort`ed, and any extra key sorted by name behind those six |
| `tags` | sorted alphabetically, as a plain `list<string>` |

Everything else is your data, and it goes back exactly as the engine handed it:

| Not ordered | Why |
|---|---|
| keys inside `before`, `after`, `metadata`, `context`, `criteria` | those are your columns and your resolvers' keys; reshaping them would be inventing a shape |
| the value of a change's `old` / `new` | same reason |
| the *list* order of `changes` | it is the order capture wrote, fixed at capture time so two runs of one `sync()` hash alike; the serialiser does not re-sort it |

None of this touches the hash. Canonicalisation (RFC 8785) sorts object members before hashing, so
key order in the stored column has never affected `integrity.hash` in either direction.

---

## Serving it over HTTP

`Http\Resources\AuditResource` is a `JsonResource` whose `toArray(Request)` returns
`$audit->toArray()` verbatim. It adds no key, renames none and hides none. It exists for the
envelope Laravel gives you — `collection()`, the response wrapper — not for a shape of its own,
because a second shape would be a second contract to keep in step.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Http\Resources\AuditResource;

Route::get('/invoices/{invoice}/audits', function (Invoice $invoice) {
    Gate::authorize('viewAudits', $invoice);

    return AuditResource::collection(
        Sentinel::audits()->for($invoice)->latest()->take(50)->get(),
    );
})->middleware('auth');
```

**The package mounts no routes.** Which entries a request may see is an authorisation question, and
Sentinel has no standing to answer it for your application. Nothing here is authorised for you.

> ⚠️ **Warning.** `AuditResource::collection()` given a `Query\AuditPage` produces a bare JSON array
> with **no** `meta` or `links` block. `AuditPage` implements `Countable` and `IteratorAggregate`,
> not Laravel's `AbstractPaginator`, so `ResourceCollection` falls through to `collect($resource)`.
> Wrap `$page->entries` and put `$page->page`, `$page->perPage` and `$page->hasMore` in your own
> envelope. See [Order, paging and walking the trail](03-order-paging-and-walking.md).

Before you expose any of this, decide what the reader is allowed to see. The serialized entry is a
complete record of a change, which means:

- `before` and `after` are the record's state. An endpoint that returns them returns every audited
  column, including ones the viewer cannot see on the record itself.
- `context` carries what the resolvers wrote — IP, user agent, full URL, route, HTTP method, and
  anything a custom resolver added. It is unpromoted resolver output, not a curated field.
- `criteria` on a mass entry carries the `where` clause with its bindings.
- `integrity.hash` and `integrity.signature` are safe to publish. That is the point of publishing
  them: a recipient with the verifying half can prove the entry untouched.

Under compliance mode, every read that goes through `Sentinel::audits()` writes an `access` entry
plus a row in the access log — so an HTTP endpoint over the Query API is a write path too. See
[Compliance mode](../08-lifecycle/05-compliance-mode.md).

---

## Correlating a request end to end

`request_id` is the column that ties every entry one request produced to that request. It is a
promoted context key: the resolver returns it, and it becomes a `string(64)` indexed column rather
than living inside `context`.

Without help, `Context\Resolvers\RequestResolver` mints a fresh ULID **per resolution**:

```php
'request_id' => $this->runtime->requestId() ?? Str::ulid()->toString(),
```

Read that carefully. With nothing assigning a request id, three entries written by one request get
three different values, and the column correlates nothing. `Http\Middleware\AssignRequestId` is what
makes it one value:

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->append(ElPandaPe\Sentinel\Http\Middleware\AssignRequestId::class);
})
```

It is opt-in and registered in no group. What it does, in order: read the configured header; keep an
incoming value if it is acceptable, otherwise mint a ULID; assign it to `Context\Runtime` (bound
`scoped`, so it lasts the request); call the rest of the stack; set the same header on the response.

| Incoming header value | Result |
|---|---|
| 1–64 characters, all in `\x21`–`\x7e` | honoured as-is |
| empty | replaced with a ULID |
| 65 characters or more | replaced with a ULID — the column is `string(64)` |
| contains a space, a newline or any control character | replaced with a ULID |
| absent | a ULID is minted |

The header name is `resolvers.request.header`, default `X-Request-Id`:

```php
// config/sentinel.php
'resolvers' => [
    'request' => ['class' => null, 'header' => 'X-Correlation-Id', 'api' => 'api/*'],
],
```

The same name is read and written, so a gateway that already stamps correlation ids only needs this
key changed. Then:

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

Sentinel::audits()->take(200)->get()
    ->filter(fn ($audit) => $audit->request_id === '01JB…')   // or filter in SQL
```

> 📌 **Note.** There is no `whereRequestId()` on the Query API. The published filters are listed in
> [Filters reference](02-filters-reference.md); correlate on `trace_id` with `withTrace()` when you
> need a filter that crosses services, and see
> [Distributed tracing](../04-context/06-distributed-tracing.md).

The id survives a queued write. Context is resolved in the request, before the entry is handed to
the queue, and the whole `AuditData` payload — `request_id` included — travels in the job. An entry
settled by a worker still names the request that caused it, not the worker.

What it does **not** do: nothing outside an HTTP request has a request id at all. In a command, a
scheduled task or a queue worker with no inbound request, `Runtime::request()` returns nothing, the
resolver contributes nothing, and `request_id` is `null`. See
[Queues, commands and schedulers](../04-context/05-queues-commands-and-schedulers.md).

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| Every entry in your UI reads **TAMPERED** | `! $data['integrity']['verified']` — `null` is falsy | Write the three-way `match` on `true` / `false` / `null` |
| `verified` is `null` even right after a successful `verifyIntegrity()` | `toArray()` never walks the chain; the key is a hard-coded `null` | Call the verifier yourself and merge the result into your own payload |
| Rehashing the serialized entry never matches `integrity.hash` | `toArray()` is not the canonical payload — different columns, different date format | Verify with `$audit->verifyIntegrity()`, never by rehashing `toArray()` |
| `$data['changes'][0]['old']` raises an undefined-key notice | `old` is omitted when the old value is genuinely unknown | Use `array_key_exists('old', $change)` |
| A JSON response has no `meta`/`links` after `AuditResource::collection($page)` | `AuditPage` is not a Laravel paginator | Wrap `$page->entries` and build the envelope from `page`, `perPage`, `hasMore` |
| Three entries from one request carry three different `request_id`s | `AssignRequestId` is not in the stack; the resolver mints one per resolution | Append the middleware, early in the stack |
| A gateway's correlation id is ignored and replaced | It is longer than 64 characters, empty, or contains a space or control character | Shorten it, or stop sending a value the column cannot hold |
| An encrypted field shows as a long opaque string in `before`/`after` | `toArray()` never decrypts, by design | Decrypt in your own layer with your own authorisation, or do not display the field |
| The presenter prints `entry` or `reference` instead of a sentence | A published lang file is missing that `presenter.*` key | Re-publish `--tag=sentinel-lang` or add the key |
| The presenter prints the raw event name | No `events.<name>` line exists for a custom event | Add the line; a dotted name nests two levels |
| A timeline of several days shows the same times repeatedly | `timeline()` formats `H:i` only, with no date | Group by day before rendering |
| Rendering `$audit->subject?->name` over a page fires a query per line | `toArray()` and the presenter never load the subject | `$page->entries->loadReferences()` first — note it does **not** load the impersonator |

---

## ✅ Best practices

✅ **Do** — treat `verified` as three-state everywhere, including in JavaScript.

```php
$state = match ($audit->toArray()['integrity']['verified']) {
    true  => 'verified',
    false => 'tampered',
    null  => 'not checked',
};
```

❌ **Don't** — collapse it to a boolean. `null` is falsy in both languages, so every unchecked entry
is reported as tampered and a real break drowns in false alarms.

```php
$tampered = ! $audit->toArray()['integrity']['verified'];   // always true
```

---

✅ **Do** — verify explicitly when the answer matters, and put the result in your own key.

```php
$data = $audit->toArray();
$data['integrity']['verified'] = $audit->verifyIntegrity();
```

❌ **Don't** — verify a whole page on render. Each call rebuilds the canonical payload and rehashes,
so a 50-entry page becomes 50 rehashes on the request path. Verify one entry on demand, or run
`sentinel:verify` out of band.

```php
foreach ($page->entries as $audit) {
    $audit->verifyIntegrity();   // 50 rehashes to draw a list
}
```

---

✅ **Do** — mount your own routes for `AuditResource` and authorise them.

```php
Route::get('/patients/{patient}/audits', function (Patient $patient) {
    Gate::authorize('viewAudits', $patient);

    return AuditResource::collection(
        Sentinel::audits()->for($patient)->latest()->take(50)->get(),
    );
})->middleware(['auth', 'throttle:30,1']);
```

❌ **Don't** — return the entry straight out of a controller with nothing in front of it. `before`
and `after` are the record's full audited state, and `context` carries IP, user agent and URL.

```php
Route::get('/audits/{audit}', fn (Audit $audit) => $audit);   // no gate, whole snapshot
```

---

✅ **Do** — append `AssignRequestId` and let it own the header in both directions.

```php
$middleware->append(ElPandaPe\Sentinel\Http\Middleware\AssignRequestId::class);
```

❌ **Don't** — set `request_id` yourself per write, or assume the column correlates without the
middleware. `RequestResolver` mints a new ULID on every resolution when the runtime holds none, so
each entry of one request gets a different value.

```php
Sentinel::event('invoice.approved')
    ->metadata(['request_id' => request()->header('X-Request-Id')])
    ->record();
// lands in metadata, not in the indexed column, and only for this one entry
```

---

✅ **Do** — extend by adding keys *beside* the shape, in your own resource or payload.

```php
final class DecoratedAudit extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [...$this->resource->toArray(), 'verified' => $this->resource->verifyIntegrity()];
    }
}
```

❌ **Don't** — override `Audit::toArray()` in a replacement model, or subclass `AuditResource` to
rename or hide keys. The shape is frozen from v0.15.0 and consumers — including exports and any
importer reading them back — are entitled to every key being where it was.

```php
final class SlimAudit extends Audit
{
    public function toArray(): array
    {
        return ['id' => $this->id, 'event' => $this->event];   // breaks the contract
    }
}
```

---

✅ **Do** — call `loadReferences()` before rendering a page, and load the impersonator yourself if
you show it.

```php
$page = Sentinel::timeline()->forTenant('acme')->paginate(50);

$page->entries->loadReferences();          // tags + subject + actor
$page->entries->load('impersonator');      // loadReferences() does not do this one

echo app(AuditPresenter::class)->timeline($page->entries);
```

❌ **Don't** — reach for `$audit->impersonator` inside a loop. That is a query per line, and a
`LazyLoadingViolationException` in an application that forbids one.

```php
foreach ($page->entries as $audit) {
    echo $audit->impersonator?->name;
}
```

---

**See also:** [The Query API](01-the-query-api.md) · [Order, paging and walking the trail](03-order-paging-and-walking.md) · [Field history and comparing versions](04-field-history.md) · [The timeline](05-the-timeline.md) · [Labels](06-labels.md) · [Diffs](../03-capture/03-diffs.md) · [Encryption and the keyring](../05-pipeline-and-security/03-encryption-and-the-keyring.md) · [Canonicalization](../07-integrity/03-canonicalization.md) · [Verification](../07-integrity/06-verification.md) · [Compliance mode](../08-lifecycle/05-compliance-mode.md) · [Execution context](../04-context/01-execution-context.md) · [Serialization](../99-reference/08-serialization.md) · [API stability](../99-reference/09-api-stability.md)
