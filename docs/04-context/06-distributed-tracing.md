# 🧭 Distributed tracing

> How an audit entry becomes a locatable point inside a distributed trace: where `trace_id` and
> `span_id` come from, what Sentinel refuses to believe, and why the caller's `traceparent` is an
> input you have to decide whether to trust.

**On this page:** [Sentinel is not a tracer](#sentinel-is-not-a-tracer) · [Turning it on](#turning-it-on) · [Where the trace comes from](#where-the-trace-comes-from) · [What lands in a column and what lands in `context`](#what-lands-in-a-column-and-what-lands-in-context) · [Reading `traceparent`](#reading-traceparent) · [`trust_incoming_header` at a public edge](#trust_incoming_header-at-a-public-edge) · [Across the queue](#across-the-queue) · [A run nobody traced](#a-run-nobody-traced) · [With an OpenTelemetry SDK](#with-an-opentelemetry-sdk) · [`tracestate`](#tracestate) · [Reading it back and forwarding it on](#reading-it-back-and-forwarding-it-on) · [Finding a trace in the trail](#finding-a-trace-in-the-trail) · [⚠️ Pitfalls](#️-pitfalls) · [✅ Best practices](#-best-practices)

---

## Sentinel is not a tracer

Everything on this page rests on one boundary, so it is worth stating before anything else.

Sentinel **reads** the W3C Trace Context that already exists around it and **forwards** it where the
package itself crosses a process boundary. It does not create spans, does not export anything to a
collector, does not install middleware that stamps `traceparent` onto responses, and does not inject
headers into your HTTP client. There is no `Sentinel::startSpan()` and there never will be.

What it does is fill two columns of every entry — `trace_id` (32 chars) and `span_id` (16 chars) —
plus a `service_name` key inside the entry's `context`, so that an audit entry can be lined up
against the request, the job and the downstream call that produced it.

Three consequences follow, and each one bites somebody eventually:

- **Without an OpenTelemetry SDK, `span_id` is the caller's span, not yours.** The value written is
  the `parent-id` field of the header you were sent — the span of whoever called you. Sentinel opens
  no span of its own, so there is nothing local to name. Do not read `span_id` as the identity of an
  operation in your process.
- **`trace_id` is correlation, never identity and never proof.** By default it comes from a header
  the caller chose. Never use it for authorization, tenancy, deduplication, or as a key that grants
  access. What proves a record is the hash chain — see
  [The hash chain](../07-integrity/01-the-hash-chain.md).
- **Adoption is not retroactive.** `trace_id` and `span_id` are inside the canonical payload the
  chain hashes (`Integrity\CanonicalPayload::COLUMNS`). Entries written before you switched telemetry
  on keep a null `trace_id` forever, because rewriting the column would break every hash that covers
  it. A chain that mixes traced and untraced entries still verifies; only the correlation has a seam.

> 🔒 **Security.** `trace_id` is indexed (`AuditSchema::indexes()` declares a single-column index on
> it). An unauthenticated caller who can reach a public endpoint and is believed can therefore both
> choose the bucket your entries are filed under and inflate the cardinality of that index. The
> control for this is `telemetry.trust_incoming_header`, described [below](#trust_incoming_header-at-a-public-edge).

---

## Turning it on

The whole feature is off by default. Every key lives under `sentinel.telemetry`:

| Key | Default | What it does | When you change it |
|---|---|---|---|
| `enabled` | `false` | Master switch. Off: no header read, no span provider asked, no envelope sealed or opened, no `trace_id`/`span_id`/`service_name`/`tracestate` written, and `Sentinel::trace()` returns `null`. | Turn on when you want entries correlated to a distributed trace. Off, `TraceResolver::resolve()` returns on its first line, so an installation that does not trace pays nothing. |
| `service_name` | `env('APP_NAME', 'laravel')` | What this service calls itself inside the trace. Written into the entry's `context`. Falls back to `config('app.name')` when null; omitted entirely when both are null. | Set it when the deployed service name differs from `APP_NAME` — several apps sharing one code base, for instance. |
| `trust_incoming_header` | `true` | Whether the request's `traceparent` (and with it `tracestate`) is believed at all. | **The security control of this page.** Turn it off at any public edge. |
| `propagate_context` | `true` | Whether the trace, the `tracestate` and the open business transaction are sealed into the payload of jobs this process queues. | Turn off to stop Sentinel putting anything into job payloads — at the cost of workers writing untraced entries. |
| `store_tracestate` | `false` | Whether an inbound `tracestate` is stored in the entry's `context`. Propagation onto queued jobs happens regardless; this key governs storage only. | Turn on only if you consume the vendor list from stored entries. |
| `root_context` | `false` | Whether a run nobody traced opens a trace of its own, memoised for the execution scope. | Turn on for console commands, the scheduler, and public edges where you distrust the header but still want per-request correlation. |

```php
// config/sentinel.php
'telemetry' => [
    'enabled' => true,
    'service_name' => 'billing',
    'trust_incoming_header' => true,
    'propagate_context' => true,
    'store_tracestate' => false,
    'root_context' => false,
],
```

Anything that is neither a boolean nor `null` in one of the five flag keys throws
`Exceptions\ConfigurationException` from `Support\Config::flag()` the first time that key is read —
at a capture or a dispatch, not at boot — rather than being silently ignored. `service_name` accepts
a string or `null`, and throws for anything else.

> 🧪 **Verify it.** `php artisan about` prints a `Sentinel` section with a `Telemetry` row
> (`Console\About::__invoke()`). If it says the feature is off, nothing on this page is running.

---

## Where the trace comes from

The precedence order lives in exactly one place — `Telemetry\Tracer::current()` — because three
callers need the same answer: the resolver that fills the columns, the envelope that crosses the
queue, and `Sentinel::trace()` which your application reads. Three implementations of an order would
be three chances for them to disagree about which trace an entry belongs to.

The chain, in order, stopping at the first one that answers:

1. **The active span of a registered `Contracts\SpanContextProvider`.** With no OpenTelemetry SDK
   installed this is `Telemetry\NullSpanContextProvider`, which always answers `null`. With the SDK
   installed it is the adapter that reads `Span::getCurrent()`.
2. **The `traceparent` header of the current request** — but only if `trust_incoming_header` is
   `true`, and only if the header parses under the rules in the [next section](#reading-traceparent).
3. **The envelope a queued job carried**, when this process is a worker running a job that was
   dispatched by a process which had a trace.
4. **A root trace this run opens for itself**, if `root_context` is `true`.
5. **Nothing.** `trace_id` and `span_id` stay null. This is an ordinary outcome, not an error.

Two things about step 2 that are easy to miss. The request is read from `Context\Runtime::request()`,
which is latched by a listener on Laravel's `Illuminate\Routing\Events\Routing` event — so anything
capturing an entry *before* routing has run (early middleware, a request short-circuited before
dispatch) sees no header and falls through to step 3, 4 or 5. And a header that does not parse is
treated as **absent**: no exception, no log line, no warning. A misconfigured upstream shows up as
missing correlation and nothing else.

Step 4 is not console-only. `root()` is simply the fourth branch of the chain; it has no idea whether
the runtime is a request, a command or the scheduler. An HTTP request whose header was absent,
malformed, or not trusted will open a root trace if `root_context` is on.

---

## What lands in a column and what lands in `context`

`Context\Resolvers\TraceResolver` returns up to four keys. `ContextEngine` promotes two of them to
columns — `trace_id` and `span_id` are on its `PROMOTED` list — and merges everything else into the
entry's `context` JSON.

| Key | Where it goes | Type | Filled when |
|---|---|---|---|
| `trace_id` | column `trace_id` | `string(32)`, nullable, indexed | the precedence chain produced a trace |
| `span_id` | column `span_id` | `string(16)`, nullable, **not** indexed and with no published filter | the precedence chain produced a trace |
| `service_name` | inside `context` | string | telemetry is on and a name resolves, **even when nothing traced the run** |
| `tracestate` | inside `context` | string, ≤ 512 chars | telemetry is on, `store_tracestate` is true, and a `tracestate` survived |

`service_name` is written by `TraceResolver`, not by `HostResolver` — so switching telemetry off
removes it from `context` along with everything else on this page. It is not a column; the schema was
closed before this feature existed and no column was added. Read it out of `context`.

> ⚠️ **Warning.** `context` is one of the 27 columns in the canonical payload the chain hashes. Both
> `service_name` and a stored `tracestate` are therefore inside the hashed payload, which is exactly
> why the 512-character cap on `tracestate` exists. See
> [Canonicalization](../07-integrity/03-canonicalization.md).

In `queue` and `buffered` modes the trace is resolved **at capture**, by the `ResolveContext`
pipeline stage, and travels inside the `Data\AuditData` object. A flush or a settle never re-resolves
it — otherwise the entry would belong to the worker that emptied the buffer rather than to the fact
that happened. See [Performance modes](../09-operations/01-performance-modes.md).

---

## Reading `traceparent`

`Telemetry\TraceParent::parse()` implements the spec's rules, not a regular expression that happens
to fit the examples. Two of those rules are routinely got backwards: version `ff` is forbidden
however well formed the rest is, and a version *above* the one we know is not garbage — the spec
requires reading its first three fields and ignoring whatever it appended.

| Header | Result | Why |
|---|---|---|
| `00-4bf92f3577b34da6a3ce929d0e0e4736-00f067aa0ba902b7-01` | ✅ accepted | The canonical shape: 55 chars, version `00`, lowercase hex, dashes at 2/35/52. |
| `01-4bf9…-00f0…-01-whatever` | ✅ accepted | Higher version: the spec's versioning rule has the first three fields read and the rest ignored. |
| `01-4bf9…-00f0…-01` | ✅ accepted | A higher version that appended nothing is still a valid three-field header. |
| `ff-4bf9…-00f0…-01` | ❌ absent | Version `ff` is invalid by the spec, whatever follows it. |
| `00-4BF92F3577B34DA6A3CE929D0E0E4736-00f0…-01` | ❌ absent | Uppercase hex. The spec mandates lowercase; Sentinel does not normalise. |
| `00-4bf9…-00F067AA0BA902B7-01` | ❌ absent | Same rule, applied to the span id. |
| `00-4bf9…-00f0…-0A` | ❌ absent | Same rule, applied to the flags. |
| `00-00000000000000000000000000000000-00f0…-01` | ❌ absent | An all-zero trace id is invalid. |
| `00-4bf9…-0000000000000000-01` | ❌ absent | An all-zero parent id is invalid. |
| `00-4bf9…-00f0…-0` | ❌ absent | Version `00` must be exactly 55 characters. |
| `00-4bf9…-00f0…-01 ` | ❌ absent | Trailing space: exact-length rule again. |
| `01-4bf9…-00f0…-01x` | ❌ absent | A higher version's extra data must start with a dash. |
| `00x4bf9…` / `00-4bf9…x00f0…` | ❌ absent | Separators must be at offsets 2, 35 and 52. |
| `zz-4bf9…-00f0…-01` | ❌ absent | The version field must itself be hex. |
| `not-a-traceparent` | ❌ absent | Shorter than 55 characters. |

"Absent" is the whole failure mode. `parse()` returns `null`, `Tracer::current()` moves on to the
next branch of the chain, `TraceResolver` simply omits the two keys, and the entry is written with
`trace_id = null`. The request completes normally. Nothing is thrown and nothing is logged.

> 📌 **Note.** Re-emission is always version `00`. `TraceParent::value()` serialises with the version
> constant, so a future-version header you were sent is read for its three fields and then forwarded
> — and sealed into job payloads — as `00-…`, dropping whatever it had appended. If you need the
> exact bytes of an exotic header to survive a hop, Sentinel is not the component that will carry
> them.

---

## `trust_incoming_header` at a public edge

`traceparent` is a request header. A request header is a value the client chooses. `trace_id` is an
indexed column inside a hashed audit record. Put those three facts together and the default —
`trust_incoming_header => true` — is only correct between services you control.

With trust on and an endpoint reachable by third parties, anyone who can send a request can:

- **choose the trace their entries are filed under**, grouping their own activity with someone else's
  by reusing a `trace_id` they observed elsewhere, or scattering their activity across as many traces
  as they like so that no `withTrace()` query gathers it;
- **inflate the cardinality of an index** — one distinct 32-character value per request, on a column
  that is indexed on every engine and, on a partitioned table, indexed locally on every partition;
- **write up to 512 bytes of chosen text into `context`** on every entry, if you have also enabled
  `store_tracestate`.

None of this can forge an entry, alter one, or break the chain: the hash covers `trace_id`, so a
value the client picked is *sealed in* as the value the client picked, not smuggled past anything.
The damage is to correlation and to index health, not to integrity.

The switch is coarse on purpose. There is no "read but do not believe" middle ground: the trust check
happens in `Tracer::incoming()` before the header is read at all, so with it off, nothing of the
caller's context survives — `tracestate` included.

```php
// config/sentinel.php — an application with endpoints third parties can reach
'telemetry' => [
    'enabled' => true,

    // Never believe the caller's traceparent...
    'trust_incoming_header' => false,

    // ...but still file every entry of one request under one trace we opened.
    'root_context' => true,

    'service_name' => 'billing',
],
```

That pairing is the point. On its own, `trust_incoming_header => false` leaves public-edge entries
with a null `trace_id`. Adding `root_context => true` restores per-request correlation with no client
input reaching the column at all.

> 🔒 **Security.** Turning trust off does **not** protect a worker. `Telemetry\Envelope::receive()`
> never consults `trust_incoming_header` — trust was decided on the producing side, and the envelope
> is data your own process wrote. Anyone who can write to your jobs table can therefore hand a worker
> a `traceparent` it will believe. Treat the queue backend as the trusted store it already is.

---

## Across the queue

The trace and the open business transaction ride across the queue inside **Laravel's own context**
(`Illuminate\Support\Facades\Context`), under the hidden key `sentinel:trace`, written with
`addHidden()`. The framework already dehydrates its context into every job payload and hydrates it
back in the worker, so Sentinel registers no queue payload hook of its own — a hook of ours would be
a key another package's hook can overwrite, on a callback list the framework never clears between
boots.

What is sealed, by `Telemetry\Envelope::seal()`:

| Field | Sealed when |
|---|---|
| `traceparent` | a trace resolved at dispatch time |
| `tracestate` | that trace carried one — **regardless of `store_tracestate`** |
| `transaction_id` | a business transaction was open (see [Business transactions](../03-capture/06-business-transactions.md)) |

Sealing happens on `Context::dehydrating` and requires **both** `telemetry.enabled` and
`telemetry.propagate_context`. Opening happens on `Context::hydrated` and requires
`telemetry.enabled` only. Both callbacks read configuration when they fire, not when the provider
booted, so changing a setting at runtime takes effect immediately.

```php
use App\Jobs\CloseInvoice;
use App\Models\Invoice;
use Illuminate\Support\Facades\Route;

Route::post('/invoices/{invoice}/close', function (Invoice $invoice) {
    CloseInvoice::dispatch($invoice);   // the request carried a traceparent

    return response()->noContent();
});
```

The entry the worker writes shares the `trace_id` of the request that queued the job, so the HTTP
capture and the worker capture come back in one `withTrace()` result. Propagation is queue-driver
agnostic — it rides in the framework's context, not in a driver-specific field — and on the `sync`
driver the dispatching request is still latched anyway, so the trace resolves from the header rather
than from the envelope.

An absent envelope is the ordinary case, not a failure. Every job queued before you switched the
feature on reads as "no trace"; the worker writes an entry with a null `trace_id` and moves on.

> ⚠️ **Warning.** `transaction_id` rides in the same envelope. Setting `telemetry.enabled => false`
> to "save the tracing cost" also stops the open business transaction from crossing the queue,
> because both hooks return early on `! telemetryEnabled()`. That is a behaviour with nothing to do
> with tracing, and it is the default state of a fresh installation.

---

## A run nobody traced

A console command, a scheduled task, or a worker doing work nobody traced has no inbound header to
read. With `root_context => true`, `Tracer::root()` mints one trace — `TraceParent::root()`, 16
random bytes for the trace id and 8 for the span id — and memoises it on the `ExecutionContext` under
a single key. Every entry that run writes shares that `trace_id`, and each gets the same 16-character
`span_id`.

```bash
php artisan invoices:close --month=2026-08
```

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

// Every entry the command wrote is one query away.
$run = Sentinel::audits()->withTrace($traceId)->get();
```

The memo lives on the execution scope, which Laravel resets per request and per job, so a worker gets
a fresh root per job and one job's root never leaks into the next.

> 📌 **Note.** A root trace Sentinel opens is **never marked sampled**: `TraceParent::root()` fixes
> the flags at `00`. The package records audit entries, not spans, so claiming the trace was sampled
> would advertise telemetry that nobody is going to find. A downstream collector that honours the
> sampled bit will drop anything correlated to a root trace forwarded from here.

---

## With an OpenTelemetry SDK

`open-telemetry/api` is a **suggestion**, never a requirement — it appears in `require-dev` and in
`suggest`, and nothing in the core references the vendor namespace. A convention test
(`tests/ConventionsTest.php`) fails the build if any file outside `src/Telemetry/OpenTelemetry/`
mentions `OpenTelemetry\`.

Install it and one thing changes: `Telemetry\OpenTelemetry\SdkSpanContextProvider` is bound instead
of `NullSpanContextProvider`, and the **active span wins over the incoming header**. The adapter reads
`Span::getCurrent()->getContext()`, checks `isValid()`, and builds a `TraceContext` from the span's
trace id, span id and flags. It reads; it never writes a span, never registers a tracer, and
implements no SDK interface, so an SDK upgrade cannot break it with an abstract-method fatal.

With a real SDK active, `span_id` finally means what people expect it to mean: the span of the local
operation, not the caller's parent id.

If you run a tracer that is not OpenTelemetry, bind your own provider. It is the one seam through
which the package asks "what are you tracing right now", and it sits at the top of the precedence
chain:

```php
use ElPandaPe\Sentinel\Contracts\SpanContextProvider;
use ElPandaPe\Sentinel\Telemetry\TraceContext;
use ElPandaPe\Sentinel\Telemetry\TraceParent;

final readonly class VendorSpanContext implements SpanContextProvider
{
    public function __construct(private VendorTracer $tracer) {}

    public function current(): ?TraceContext
    {
        $span = $this->tracer->activeSpan();

        if ($span === null) {
            return null;   // the ordinary answer, not a failure
        }

        $parent = TraceParent::of($span->traceId(), $span->spanId(), '01');

        return $parent instanceof TraceParent ? new TraceContext($parent) : null;
    }
}
```

```php
// A service provider
$this->app->bind(SpanContextProvider::class, VendorSpanContext::class);
```

`TraceParent::of()` validates exactly as hard as the header parser — lowercase hex, 32/16/2
characters, no all-zero ids — and returns `null` rather than throwing. Return `null` from
`current()` freely: it means "no tracer, or a tracer outside a span", which is the common case, not
an error. Implementations must not throw; `Tracer` does not catch.

> ⚠️ **Warning.** A custom provider owns its own `tracestate` bounds. The 512-character cap is
> applied to the inbound header and, via `TraceState::toString(512)`, to the SDK reader — never
> inside `TraceContext`, the resolver, or the envelope. An unbounded string returned from
> `current()` will be stored verbatim in `context` under `store_tracestate` and sealed into every
> job payload.

`Telemetry\TraceContext` is published surface. `Telemetry\TraceParent` is marked `@internal`, even
though it is currently the only way to build the `TraceContext` a public interface requires you to
return — a rough edge worth knowing about before you build on it. See
[API stability](../99-reference/09-api-stability.md).

---

## `tracestate`

`tracestate` is the vendor list that travels beside `traceparent`. Sentinel treats it as opaque: it
parses no vendor entries, adds none of its own, and modifies nothing. That is not laziness — §3.4 of
the spec says a vendor that does not change `traceparent` must not change `tracestate` either, and
this package opens no spans.

Three different rules apply depending on where the value came from, and they do not agree:

| Source | Over 512 characters | Empty string |
|---|---|---|
| Inbound `traceparent` request header | **dropped entirely** by `Tracer::tracestate()` — the package will not vouch for a client-controlled list growing inside a hashed column | treated as absent |
| An OpenTelemetry span | **truncated** by the SDK, per its own rules, via `toString(TraceContext::TRACESTATE_LIMIT)` | n/a |
| A job envelope | **no cap applied**; any string is accepted verbatim by `Envelope::receive()` | stored as an empty string |

Storage is off by default. `store_tracestate => true` puts the value into the entry's `context`, and
therefore into the hashed canonical payload. Propagation onto queued jobs is independent of this key:
the envelope seals whatever `tracestate` the trace carries whether or not you store it.

---

## Reading it back and forwarding it on

`Sentinel::trace()` returns the `TraceContext` this process is inside of, or `null`. It reads: it
mutates nothing, opens no span, and touches no header.

| Method | Returns |
|---|---|
| `traceId()` | the 32 lowercase hex characters written to the `trace_id` column |
| `spanId()` | the 16 lowercase hex characters written to `span_id` |
| `traceparent()` | a header string, always re-emitted at version `00` |
| `tracestate()` | the vendor list verbatim, or `null` |
| `sampled()` | the low bit of the flags byte; always `false` for a root trace Sentinel opened |

Forwarding is your job, deliberately:

```php
use ElPandaPe\Sentinel\Facades\Sentinel;
use Illuminate\Support\Facades\Http;

$trace = Sentinel::trace();   // ?TraceContext — null when telemetry is off

Http::withHeaders(array_filter([
    'traceparent' => $trace?->traceparent(),
    'tracestate' => $trace?->tracestate(),
]))->post('https://billing.internal/invoices');
```

`array_filter` matters: passing a `null` header value is not the same as omitting the header, and a
downstream service that receives `traceparent: ` will refuse it as unparseable — correctly.

---

## Finding a trace in the trail

`AuditQuery::withTrace()` is the read side of the whole feature: one ordered list containing the HTTP
capture, the service capture and the worker capture of a single operation.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

Sentinel::audits()
    ->withTrace('4bf92f3577b34da6a3ce929d0e0e4736')
    ->get();
```

It compiles to a plain equality on `trace_id`, and it neither validates nor normalises its argument:
an uppercase id, a partial id or a whole `traceparent` header silently matches nothing. Entries
written before adoption are equally invisible to it — a gap in correlation, not a gap in the chain.

There is **no** `withSpan()`. `span_id` is stored but has no published filter and no index; combine
`withTrace()` with the other filters in [Filters reference](../06-reading/02-filters-reference.md).

> 🐘 **Engine.** `trace_id` is `string(32)` and `span_id` `string(16)` on SQLite, MySQL and
> PostgreSQL alike, both nullable, with an index on `trace_id` only. The index is declared in
> `AuditSchema::indexes()` — the set that does not depend on how the table is divided — so on a
> partitioned table it becomes a local index on every partition, and a `withTrace()` query naming no
> partitioning column fans out across all of them. See
> [Partitioning](../10-database-engines/06-partitioning.md).

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| Every entry has a null `trace_id` although callers send `traceparent` | `telemetry.enabled` is `false`, which is the default. `TraceResolver::resolve()` returns on its first line. | Set `sentinel.telemetry.enabled` to `true` and confirm with `php artisan about`. |
| One upstream never correlates; the others do | Its header is refused: uppercase hex, version `ff`, an all-zero id, a trailing space, or a wrong length. Nothing is logged. | Fix the producer. Compare its header against the [accept/reject table](#reading-traceparent) — the spec mandates lowercase hex. |
| `span_id` does not match any span in your tracing backend | With no OpenTelemetry SDK installed, `span_id` is the caller's `parent-id`. Sentinel opens no spans. | Install `open-telemetry/api`, or read `span_id` as "which call produced this", not "which of my operations". |
| Entries written by a worker carry no trace, though the request had one | `telemetry.propagate_context` is `false`, or the job was queued before the feature was switched on and its payload carries no envelope. | Turn propagation on. Jobs already in the queue table will never gain an envelope. |
| `transaction_id` stopped crossing the queue after telemetry was switched off | The same envelope carries the transaction; both context hooks return early on `! telemetryEnabled()`. | Set `telemetry.enabled` to `true` — it ships `false`, so on a fresh install the transaction never crossed the queue in the first place. The coupling has nothing to do with tracing, and there is no separate switch for it. |
| An inbound `tracestate` never reaches `context` | `store_tracestate` is `false` by default; or the value exceeded 512 characters, in which case it is dropped whole rather than truncated. | Enable `store_tracestate` and keep producers under `TraceContext::TRACESTATE_LIMIT`. |
| A console run's entries each carry a different trace, or none | `root_context` is `false`, so nothing opened a trace for a run nobody traced. | Set `telemetry.root_context` to `true`; the root is memoised per execution scope. |
| Entries captured very early in a request carry no trace | The header is read off `Runtime::request()`, latched by the framework's `Routing` event. Anything before routing sees no request. | Nothing to fix in the resolver. Use `root_context` if you need those entries correlated. |
| `withTrace()` returns nothing for a trace you can see in the column | The argument is compiled as an exact equality. An uppercase, truncated or full-header value matches no row. | Pass exactly the 32 lowercase hex characters stored in `trace_id`. |
| A downstream service rejects the header you forwarded | The context was `null` (telemetry off, or nothing traced the run) and an empty header was sent. | Build the header array with `array_filter`, or skip the header when `Sentinel::trace()` is `null`. |
| The forwarded header is not byte-identical to the one received | Re-emission is always version `00`, and anything a higher-version header appended after the flags is dropped. | Expected. Do not build anything that requires exotic `traceparent` bytes to round-trip through this package. |
| An SDK is installed but the span still loses to the header | The current span context is invalid (no tracer registered), or its trace flags do not fit in one byte — `sprintf('%02x', …)` then yields three characters and `TraceParent::of()` refuses it. | Check that a tracer is registered and that the flags are a byte. |

---

## ✅ Best practices

✅ **Do** — turn `trust_incoming_header` off at any edge third parties can reach, and pair it with
`root_context` so correlation survives without client input reaching an indexed, hashed column.

```php
'telemetry' => [
    'enabled' => true,
    'trust_incoming_header' => false,
    'root_context' => true,
],
```

❌ **Don't** — leave the `true` default on a public endpoint because "it's only a correlation id".
Anyone who can reach the endpoint then picks the bucket your entries are filed under and mints one
distinct value per request on an indexed column.

```php
'telemetry' => ['enabled' => true],   // trust_incoming_header defaults to true
```

---

✅ **Do** — treat `trace_id` as correlation and nothing else. Integrity comes from the chain; use
`verifyIntegrity()` when you need to assert that a record is intact.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

$related = Sentinel::audits()->withTrace($traceId)->get();   // grouping
$result = Sentinel::verifyIntegrity('global');               // proof
```

❌ **Don't** — use `trace_id` as a lookup key that grants access, a tenancy discriminator, or a
deduplication key. By default it is a value the caller chose, and two unrelated callers may send the
same one.

```php
Invoice::query()->where('trace_id', $request->header('traceparent'))->firstOrFail();
```

---

✅ **Do** — enable `root_context` for anything that runs without an inbound request, so a command's
entries are one trace instead of a pile of islands.

```php
'telemetry' => ['enabled' => true, 'root_context' => true],
```

❌ **Don't** — assume `root_context` is console-only and safe to leave on everywhere without
thinking. It is the fourth branch of the same chain, so an HTTP request whose header was absent,
malformed or distrusted opens a root trace too — one per request, each a fresh 32-character value in
an indexed column.

---

✅ **Do** — forward the context yourself on outgoing calls, filtering out nulls.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;
use Illuminate\Support\Facades\Http;

$trace = Sentinel::trace();

Http::withHeaders(array_filter([
    'traceparent' => $trace?->traceparent(),
    'tracestate' => $trace?->tracestate(),
]))->get('https://ledger.internal/entries');
```

❌ **Don't** — wait for Sentinel to inject the header into your HTTP client. It publishes the
context and does nothing else; there is no middleware, no client macro, and no exporter.

```php
Http::get('https://ledger.internal/entries');   // no traceparent leaves this call
```

---

✅ **Do** — bind your own `Contracts\SpanContextProvider` when you run a tracer that is not
OpenTelemetry. It is the top of the precedence chain and the intended seam.

```php
use ElPandaPe\Sentinel\Contracts\SpanContextProvider;

$this->app->bind(SpanContextProvider::class, VendorSpanContext::class);
```

❌ **Don't** — replace `resolvers.trace.class` to change where the trace comes from. The resolver
decides which keys are filled; swapping it means you now own the precedence order that `Tracer`
holds, and the envelope and `Sentinel::trace()` will keep using the original one.

```php
'resolvers' => ['trace' => ['class' => App\Audit\MyTraceResolver::class]],
```

---

✅ **Do** — keep the trace resolved at capture if you write a custom buffer or dispatch driver, and
carry it inside the `AuditData` you already serialise.

```php
$audit->trace_id;   // already filled by the ResolveContext stage
$audit->span_id;
```

❌ **Don't** — re-resolve the trace at flush or settle time. The entry would then belong to the
process that emptied the buffer rather than to the fact that happened.

```php
// the worker's trace, not the fact's
$audit->trace_id = app(ElPandaPe\Sentinel\Telemetry\Tracer::class)->current()?->traceId();
```

---

✅ **Do** — expect and explain the seam. A trail that predates telemetry keeps null `trace_id`s
forever, and the chain verifies straight across the mix.

```php
Sentinel::verifyIntegrity('global')->isIntact();   // true, traced and untraced entries alike
```

❌ **Don't** — back-fill `trace_id` on historical rows to "complete" the correlation. Those columns
are inside the canonical payload; rewriting one invalidates the hash that covers it and every hash
after it in the stream.

```sql
UPDATE audits SET trace_id = '4bf92f3577b34da6a3ce929d0e0e4736' WHERE trace_id IS NULL;
```

---

**See also:** [Execution context](01-execution-context.md) · [The ten resolvers](02-resolvers-reference.md) · [Queues, commands and schedulers](05-queues-commands-and-schedulers.md) · [Writing your own resolver](07-writing-your-own-resolver.md) · [Business transactions](../03-capture/06-business-transactions.md) · [Filters reference](../06-reading/02-filters-reference.md) · [Canonicalization](../07-integrity/03-canonicalization.md) · [Performance modes](../09-operations/01-performance-modes.md) · [Configuration](../99-reference/02-configuration.md) · [Schema](../99-reference/03-schema.md) · [Security checklist](../13-best-practices/03-security-checklist.md)
