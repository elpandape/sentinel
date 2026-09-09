# 🧭 Execution context

> What an entry knows about the circumstances it was written in: which nine facts become columns,
> which land inside the `context` JSON, when they are resolved, and how far that answer travels.

**On this page:** [The four nouns](#the-four-nouns) · [When context is resolved](#when-context-is-resolved) · [Columns and the JSON bag](#columns-and-the-json-bag) · [Querying what was written](#querying-what-was-written) · [What "scoped" means](#what-scoped-means) · [Pushing your own context](#pushing-your-own-context) · [Cost and the performance modes](#cost-and-the-performance-modes) · [⚠️ Pitfalls](#️-pitfalls) · [✅ Best practices](#-best-practices)

---

## The four nouns

Context is not one object. It is four, and knowing which one you are looking at answers most
questions about why a column came out empty.

| Noun | File | What it is |
|---|---|---|
| `Runtime` | `src/Context/Runtime.php` | A latch of what this process is doing right now: the request the router dispatched, the artisan command stack, the queued job stack, whether the scheduler started the task, the request id the middleware assigned, and whether Sentinel is settling one of its own entries. |
| `Contracts\Resolver` | `src/Contracts/Resolver.php` | One method — `resolve(): array<string, mixed>` — returning whatever it could work out, and an empty array when it could work out nothing. |
| `ContextEngine` | `src/Context/ContextEngine.php` | The invokable that runs the ten resolvers in a fixed order over an `AuditData`, promotes nine keys to columns and merges the manual context over the rest. |
| `ExecutionContext` | `src/Context/ExecutionContext.php` | The bag the application pushes into with `Sentinel::withContext()` — and the memo store the engine caches half the resolvers in. |

The `Runtime` inspects nothing. Every field on it is set from an event Laravel already fires, and
`SentinelServiceProvider` registers one listener per signal:

| Signal | Laravel event | Read by |
|---|---|---|
| The current request | `Illuminate\Routing\Events\Routing` | `RequestResolver`, `SessionResolver`, `SourceResolver` |
| The running command and its arguments | `CommandStarting` / `CommandFinished` | `CommandResolver`, `SourceResolver` |
| A scheduled task started | `ScheduledTaskStarting` | `SourceResolver` |
| The job being processed | `JobProcessing` / `JobProcessed` | `JobResolver`, `SourceResolver` |
| The correlation id for this request | `Http\Middleware\AssignRequestId` (opt-in) | `RequestResolver` |
| Sentinel is settling its own entry | `Runtime::whileWritingAudit()`, called only by `Jobs\SettleAudit` | `SourceResolver` |

Commands and jobs **nest**: entering pushes the current one onto a stack and leaving pops it, so an
`Artisan::call()` from inside another command does not convince the outer one that it ended.

> 📌 **Note.** `Context\Identity` — the two lines that turn an authenticated user into
> `actor_type` (the morph alias for a model, the class name for anything else) and `actor_id`
> (a string, or null when the key is neither a string nor an int) — is marked `@internal` and is
> outside the 1.0 freeze. Everything else in `src/Context/` and `Contracts\Resolver` is public
> surface you may build on.

The ten resolvers, their configuration keys and their individual failure modes are
[the next page](02-resolvers-reference.md). This one is about the machinery around them.

---

## When context is resolved

Context is resolved **at capture, inside the pipeline**, by the stage
`Pipeline\Stages\ResolveContext` — which does nothing but call the engine. It is second in
`Pipeline::DEFAULT_STAGES`, after `FilterUnchanged` and before `ResolveTags`, `NormalizeData`,
`MaskSensitiveData`, `EncryptSensitiveData` and `EnforcePolicies`.

Four consequences follow from that position, and each one is a question people ask:

- **The context is masked and encrypted like everything else.** `Security\Fields` walks `context`
  by key name at any depth, so `ip`, `user_agent`, `session_id` or a console argument can be
  protected by name even though no model declares them. See
  [Protecting sensitive data](../05-pipeline-and-security/02-protecting-sensitive-data.md).
- **A policy sees the actor the entry will carry**, because `EnforcePolicies` runs after
  `ResolveContext`, and what you named by hand is applied there.
- **An actor you name by hand is applied by the context stage itself.** `ResolveContext` resolves
  `actor_type`/`actor_id` and then applies the name you gave over them, clearing
  `impersonator_type`/`impersonator_id` at the same time; `Capture\Recorder` applies it once more
  after the pipeline, for a published stage list that dropped that stage. See
  [Actor and impersonation](03-actor-and-impersonation.md).
- **Nothing re-resolves later.** A worker, a buffer flush and a `sentinel:flush` run never ask the
  engine again; they write an entry whose context was already sealed.

The engine is idempotent by construction. It assigns *every* promoted column on *every* pass,
absent value included — so running it twice produces the same entry, and a second pass clears a
column whose signal is gone rather than leaving the first pass's leftovers standing. The cost of
that design is one thing it cannot express: a resolver that answers *nothing* and one that answers
*null* are indistinguishable to it.

> ⚠️ **Warning.** A `pipeline` list declared in `config/sentinel.php` is taken verbatim. Drop
> `ResolveContext` from it and every context column comes out null, `source` falls back to
> `Source::System`, and nothing warns you. If you add a stage of your own, keep the shipped ones.

---

## Columns and the JSON bag

There is exactly one promotion rule and no per-resolver table. Nine key names become columns on the
entry; **every other key any resolver returns lands inside the `context` JSON**.

### The nine promoted columns

| Column | Filled by | Notes |
|---|---|---|
| `actor_type` | `ActorResolver` | The morph alias for an Eloquent user, the class name otherwise. |
| `actor_id` | `ActorResolver` | `varchar(64)`. |
| `impersonator_type` | `ImpersonatorResolver` | Null unless somebody is genuinely impersonating — never a copy of the actor. |
| `impersonator_id` | `ImpersonatorResolver` | `varchar(64)`. |
| `tenant_id` | `TenantResolver` | Also decides the stream while `integrity.stream` is `tenant`. |
| `request_id` | `RequestResolver` | `varchar(64)`; shared by every entry of one request. |
| `trace_id` | `TraceResolver` | `varchar(32)`; empty unless `telemetry.enabled` is true. |
| `span_id` | `TraceResolver` | `varchar(16)`. |
| `source` | `SourceResolver` | The only non-string: a `Enums\Source` case, cast on the model. |

A promoted value must already **be a string**. `ContextEngine::column()` writes the value when
`is_string()` is true and writes `null` otherwise, so a resolver returning `['actor_id' => 123]`
produces a null column and no error. `source` is the exception in the other direction: it is taken
only when it is a `Source` instance, and anything else falls back to `Source::System`.

### The `context` JSON

Everything else a resolver returns is merged into one array and stored in the `context` column
(`jsonb`, cast to `array` on `Models\Audit`):

| Key | Resolver | Present when |
|---|---|---|
| `hostname`, `environment` | `HostResolver` | Always. `hostname` is `gethostname()`, or the literal `unknown` when that fails. |
| `ip`, `user_agent`, `url`, `route`, `method` | `RequestResolver` | Inside a request. `route` is the matched route's name, its uri when it has none, and null when nothing matched. |
| `session_id` | `SessionResolver` | The request has a session. |
| `command`, `arguments` | `CommandResolver` | Inside an artisan command. Arguments whose *name* matches `resolvers.command.redact` are masked; a value that is not scalar, null or array is dropped without trace. |
| `job`, `queue`, `attempts`, `batch_id` | `JobResolver` | Inside a queued job. `batch_id` only when the payload carries one. |
| `service_name`, `tracestate` | `TraceResolver` | `telemetry.enabled` is true; `tracestate` additionally needs `telemetry.store_tracestate`. |
| anything you push | `Sentinel::withContext()` | For the duration of the callback. |

Resolvers run in the order `source, host, request, session, command, trace, actor, impersonator,
tenant, job`, and each result is folded over the accumulator — so on a key collision **the later
resolver wins**. A replacement `job` resolver returning `actor_id` overrides `ActorResolver`.

> 🔒 **Security.** `context` is one of the twenty-seven columns in
> `Integrity\CanonicalPayload::COLUMNS`, so it is inside the hash and covered by the chain. Anything
> you push with `withContext()` is written down, hashed and kept — and a
> [tombstone](../08-lifecycle/04-redaction-and-tombstones.md) is the only way to take it back out.
> Do not put there what you would not want in a permanent record.

---

## Querying what was written

Where a fact lands decides how cheaply you can ask about it later. Five tiers:

| Tier | Fields | How you read them |
|---|---|---|
| Indexed **and** filterable | `actor_type`/`actor_id`, `tenant_id`, `trace_id` | `by()`, `forTenant()`, `withTrace()` |
| Indexed, no published filter | `request_id` | Read the column off the entry, or query the table directly |
| Filterable, no index | `source` | `whereSource()` — a refiner; put an indexed filter in front of it |
| Inside the JSON | `ip`, `route` | `whereIp()`, `whereRoute()` — scan unless you publish the JSON index |
| Neither | `impersonator_type`/`impersonator_id`, `span_id`, and every other `context` key | `$audit->impersonator` (a `MorphTo`), or read the array |

```php
use App\Models\Invoice;
use ElPandaPe\Sentinel\Enums\Source;
use ElPandaPe\Sentinel\Facades\Sentinel;

$audit = Sentinel::audits()->for($invoice)->latest()->take(1)->get()->first();

$audit->source;                  // Source::Http — cast to the enum
$audit->request_id;              // the id every entry of that request shares
$audit->context['route'];        // 'invoices.approve'
$audit->context['hostname'];     // 'web-3'

// Indexed reads
Sentinel::audits()->forTenant('acme')->take(50)->get();

// A refiner: give it something indexed to stand behind
Sentinel::audits()->forTenant('acme')->whereSource(Source::Job)->take(50)->get();
```

`whereIp()` and `whereRoute()` read inside `context`. They answer correctly with no extra schema —
by scanning. If you filter on them routinely, publish the optional index:

```shell
php artisan vendor:publish --tag=sentinel-json-indexes
php artisan migrate
```

> 🐘 **Engine.** The published migration builds an expression index on PostgreSQL and SQLite, and on
> MySQL a `VIRTUAL INVISIBLE` generated column plus an index over it — virtual so it does not
> rewrite and widen the table, invisible so it never rides along in `select *` and get handed back
> to MySQL by a fanout or a rehydration, which it refuses. The expression comes from
> `Ledger\ContextPredicate`, the same object the driver compiles the filter with, so the two cannot
> drift apart. The migration's own docblock states the measured cost of having it: **+15 % per write
> on PostgreSQL 16 and +21 % on MySQL 9**, over the table these migrations create. An installation
> that never asks where an entry came from should not pay it. More in
> [Indexes and JSON](../10-database-engines/05-indexes-and-json.md).

> 🐘 **Engine.** MySQL and PostgreSQL do not preserve **key order** inside the stored `context`
> JSON. Every value comes back intact; the order does not. Compare a context by value, never as
> text. The hash is unaffected — canonicalisation sorts before hashing, see
> [Canonicalization](../07-integrity/03-canonicalization.md).

---

## What "scoped" means

`Runtime`, `ExecutionContext` and `ContextEngine` are registered with `$app->scoped()`. So are the
buffer, the ledger and the transaction scope. A "scope" is whatever the framework decides it is, and
that decision is what keeps one unit of work's circumstances out of the next one's entries.

| Process | When the scope is torn down | Consequence |
|---|---|---|
| HTTP request (php-fpm) | End of the process | One request, one scope. |
| Octane worker | At the request boundary, when the runtime forgets scoped instances | The actor, the memoised request and the `request_id` do not leak into the next request. Sentinel registers no Octane listener of its own — the `scoped()` binding is the whole mechanism, and `tests/Context/OctaneResetTest.php` pins all three. |
| Queue worker | Before each job — the framework's own reset in `Illuminate\Queue\QueueServiceProvider` calls `forgetScopedInstances()` | One job's memoised host, source or root trace never reaches the next job. |
| Console command | **Never, until the process exits** | One artisan run is one scope from the first line to the last. |

Five resolvers are memoised for the life of the scope — `source`, `host`, `request`, `session`,
`command` — because their answer cannot change inside one request. Five run on every capture —
`trace`, `actor`, `impersonator`, `tenant`, `job` — because an actor can log in, a tenant can be
switched and one worker hands a job over to the next.

That combination has one sharp edge, and it is the console. A console process is a single scope, so
the first capture in the run fixes `context.command` and `source` for every capture after it. An
in-process `Artisan::call('invoices:close')` from inside `reports:nightly` will report the *outer*
command, even though the `Runtime` stack tracked the nesting correctly.

> ⚠️ **Warning.** `Sentinel::context()->flush()` clears the memo as well as the data. Call it
> mid-request and `host`, `request`, `session`, `command` and `source` all re-resolve — and without
> the `AssignRequestId` middleware installed a *second* `request_id` is minted for the same request,
> silently splitting that request's entries into two correlation groups.

---

## Pushing your own context

`Sentinel::withContext()` merges an array into the bag for the duration of a callback and restores
the previous state afterwards — including when the callback throws, because the restore is in a
`finally`.

```php
use App\Models\Invoice;
use ElPandaPe\Sentinel\Facades\Sentinel;

$invoice = Sentinel::withContext(
    ['reason' => 'Approved by finance', 'ticket' => 'FIN-4821'],
    function () use ($invoice): Invoice {
        $invoice->update(['status' => 'paid']);

        return $invoice;
    },
);

// On the entry:
$audit->context['reason'];    // 'Approved by finance'
$audit->context['hostname'];  // still there — manual context merges OVER the resolved payload
```

Outside a callback, the bag is reachable directly and behaves like an array:

```php
Sentinel::context()->set('batch', 'nightly-close');
Sentinel::context()->merge(['run' => 17, 'operator' => 'ops-team']);
Sentinel::context()->has('batch');    // true
Sentinel::context()->get('batch');    // 'nightly-close'
Sentinel::context()->forget('batch');
```

### Nesting, and what a scope restores

Calls nest, and the inner one sees the outer one's keys:

```php
Sentinel::withContext(['run' => 'nightly-close'], function (): void {
    Sentinel::withContext(['step' => 'invoices'], function (): void {
        // context here: ['run' => 'nightly-close', 'step' => 'invoices']
    });

    // and here: ['run' => 'nightly-close'] — 'step' is gone
});
```

`ExecutionContext::scope()` snapshots the **whole** data array on entry and restores that snapshot
on exit. It is not a diff of the keys it merged. Anything you `set()` from inside the callback is
discarded when the callback returns, along with everything else the callback changed.

### Three things it cannot do

- **It cannot write a promoted column.** `Sentinel::withContext(['actor_id' => '999'], …)` puts
  `actor_id` in the `context` JSON and leaves the column null; `['source' => 'http']` leaves
  `Source::System`. The merge builds the payload only. Changing who acted means replacing
  `ActorResolver` ([writing your own resolver](07-writing-your-own-resolver.md)) or naming the actor
  on the capture ([actor and impersonation](03-actor-and-impersonation.md)).
- **It does not cross a queue boundary.** The bag is a scoped in-process object; dispatching a job
  from inside a `withContext()` callback carries none of it. What *does* ride inside Laravel's own
  `Context` — and only with `telemetry.enabled` and `telemetry.propagate_context` on — is the trace
  envelope and the open transaction id. See
  [Queues, commands and schedulers](05-queues-commands-and-schedulers.md).
- **It does not reach an entry captured outside the callback.** The merge happens at capture, not at
  settlement. Under `after_commit` the write is deferred past the end of the callback, but the
  context was already sealed into the entry inside it — which is exactly why deferring is safe.

---

## Cost and the performance modes

The whole design exists to keep resolution cheap: ten small objects reading latched facts, five of
them answered once per scope. Nothing in the shipped chain queries the database or calls out over
the network, and a resolver of your own should hold to the same rule — it runs on the write path,
on every capture.

`make bench` measures the chain in isolation and prints it as `context only (no ledger, no write)`,
separated from the write it normally lands in. Run it against your own schema rather than trusting a
figure from someone else's machine.

**The mode does not change when context is resolved.** The pipeline always runs in the process that
captured the fact, in all three modes:

| Mode | Where the pipeline runs | Where the entry settles | What the entry's context describes |
|---|---|---|---|
| `sync` | The request | The request | The request |
| `queue` | The request | A worker, from `Jobs\SettleAudit` | The request |
| `buffered` | The request | A later flush, or `sentinel:flush` | The request |

`SettleAudit` carries the finished payload as an array — filtered, masked, encrypted, its context
resolved — and never a model, because a worker re-reading the record would photograph it as it is
*now* and resolve a context describing *itself*. The same holds for the buffer: an entry waits in it
already transformed.

So the answer an async mode changes is not *what* was recorded but *when it exists*:

- The call that captured the fact returns before the entry is written, so nothing comes back from it
  to read. See [Performance modes](../09-operations/01-performance-modes.md).
- `created_at` and `sequence` become the order things **settled**, while `occurred_at` stays the
  order things happened.
- `buffered` can lose what a dying process was holding, and the chain cannot detect the loss — it
  walks a shorter chain and correctly reports it intact. See
  [The buffered mode](../09-operations/02-the-buffered-mode.md).
- Switching to `queue` or `buffered` does **not** move resolver cost off the request. It moves the
  ledger write. If the resolver chain is what you want to shrink, replace a resolver, not the mode.

> 📌 **Note.** `Source::Queue` does not mean "written under the `queue` mode". It means Sentinel was
> settling one of its own entries when this capture happened — the only caller of
> `Runtime::whileWritingAudit()` is `Jobs\SettleAudit`. An ordinary entry captured in an HTTP request
> and settled by a worker keeps `source = http`.

> 🧪 **Verify it.** Capture one entry and read its context back:
> ```shell
> php artisan tinker
> >>> ElPandaPe\Sentinel\Facades\Sentinel::audits()->latest()->take(1)->get()->first()->context;
> ```

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `actor_id` / `tenant_id` is null and nothing was logged | A custom resolver returned an int, a `Stringable` or a UUID object. `ContextEngine::column()` keeps only strings. | Cast in the resolver: `(string) $model->getKey()`. |
| `source` is `system` on every entry after replacing `SourceResolver` | A string like `'http'` was returned. Only a `Enums\Source` case is accepted, and `source` is promoted so the string is stripped from the JSON too — it vanishes twice. | Return `['source' => Source::Http]`. |
| `Sentinel::withContext(['tenant_id' => 'acme'], …)` leaves `tenant_id` null | Manual context can never reach a promoted column; the key lands in the `context` JSON instead. | Configure `resolvers.tenant.using`, or replace the resolver. See [Multi-tenancy](04-multi-tenancy.md). |
| Every entry of a long artisan run names the same command | `source` and `command` are memoised for the life of the scope, and a console process is one scope. | Expect it. Where a nested command must be distinguishable, record a custom event naming it, or push the step with `withContext()`. |
| Two different `request_id` values inside one request | `Sentinel::context()->flush()` was called mid-request and cleared the memo; `RequestResolver` minted a second ULID. | Do not call `flush()` in a live request. Install `Http\Middleware\AssignRequestId` so the id comes from the latch. |
| A key set inside a `withContext()` callback is missing from later entries | `scope()` restores the entire previous array on exit, not a diff. | Pass the key in the `withContext()` array, or `set()` it outside the callback. |
| Entries written from a queued job carry none of the context the dispatching request pushed | The bag is scoped to the process; only the trace envelope crosses the queue, and only with telemetry on. | Put the value in Laravel's own `Context` before dispatching, and read it in a custom resolver. See [Queues, commands and schedulers](05-queues-commands-and-schedulers.md). |
| `whereIp()` / `whereRoute()` return nothing for a value you can see in the entry | `ip` or `route` was named in `security.redaction.fields` or `security.hashing.fields`; the stored value is the mask or the digest, and the filter compares against it. | Choose one: protect the key, or keep the filter. You cannot have both. |
| A `whereSource()` query got slow as the table grew | No index covers `source`; alone it walks the table. | Put `by()`, `forTenant()`, `withTrace()`, `for()` or `inTransaction()` in front of it. |
| Every context column is empty after customising `pipeline` | An explicitly declared stage list is used verbatim, and `ResolveContext` was left out. | Put `ResolveContext` back — second, before the masking and encryption stages. |
| `ConfigurationException` about `resolvers.<name>.class` at the first capture | The configured class does not exist or does not implement `Contracts\Resolver`. Resolver classes are validated when they are used, not at boot. | Fix the class name; confirm it implements `ElPandaPe\Sentinel\Contracts\Resolver`. |
| An assertion comparing a stored `context` as a JSON string fails on MySQL or PostgreSQL, passes on SQLite | Key order inside the JSON is not preserved by those engines. | Compare by value — decode and compare arrays, sorting keys if order matters to the assertion. |

---

## ✅ Best practices

✅ **Do** — push the *reason* for a change, not a copy of the data. The context is the circumstances;
the values already live in `before`, `after` and `changes`.

```php
Sentinel::withContext(
    ['reason' => 'Dispute resolved in customer favour', 'ticket' => 'SUP-9912'],
    fn (): bool => $invoice->update(['status' => 'refunded']),
);
```

❌ **Don't** — use the context bag to smuggle attribution. Those keys are ignored for the columns and
end up as decorative JSON that no filter reads.

```php
Sentinel::withContext(
    ['actor_id' => (string) $user->id, 'tenant_id' => 'acme'],
    fn (): bool => $invoice->update(['status' => 'refunded']),
);
// actor_id and tenant_id columns: still null.
```

✅ **Do** — cast every promoted value to a string in a resolver of your own. The engine silently
drops anything else.

```php
use App\Models\Tenant;
use ElPandaPe\Sentinel\Contracts\Resolver;

final class TenantFromRequest implements Resolver
{
    /**
     * @return array<string, mixed>
     */
    public function resolve(): array
    {
        $tenant = Tenant::current();

        return $tenant === null ? [] : ['tenant_id' => (string) $tenant->getKey()];
    }
}
```

❌ **Don't** — return the raw key and assume the cast happens downstream.

```php
return ['tenant_id' => $tenant->getKey()];   // int → the column is written as null
```

✅ **Do** — return an empty array when a resolver has nothing to say. The engine already writes null
into every column nobody filled, and an empty array keys nothing into the JSON either.

```php
$job = $this->runtime->job();

return $job === null ? [] : ['job' => $job->resolveName()];
```

❌ **Don't** — return an array of nulls to "clear" something. It fills the `context` JSON with dead
keys that are indistinguishable from a fact that was genuinely null.

```php
return ['job' => null, 'queue' => null, 'attempts' => null];
```

✅ **Do** — keep a resolver to reading latched facts. Ten of them stand on the write path, and the
five that are not memoised run on every capture.

```php
public function resolve(): array
{
    $request = $this->runtime->request();

    return $request === null ? [] : ['region' => $request->header('X-Region')];
}
```

❌ **Don't** — query or call out from a resolver. A single lookup here becomes a lookup per audited
write, in the request that is waiting for the response.

```php
public function resolve(): array
{
    return ['plan' => Subscription::where('tenant', $this->tenant())->value('plan')];
}
```

✅ **Do** — protect what no model owns by naming the context key. The masking walk reaches `context`
at any depth, including inside `context.arguments`.

```php
// config/sentinel.php
'security' => [
    'redaction' => ['fields' => ['ip', 'user_agent']],
],
```

❌ **Don't** — mask `route` and then build reporting on `whereRoute()`. The filter compares against
the stored value, which is now the mask, and it will quietly match nothing.

```php
'security' => ['redaction' => ['fields' => ['route']]],
Sentinel::audits()->whereRoute('invoices.approve')->take(50)->get();   // always empty
```

✅ **Do** — install `AssignRequestId` when a gateway or load balancer already assigns a correlation
id, so the trail and the access log name the same request.

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->append(ElPandaPe\Sentinel\Http\Middleware\AssignRequestId::class);
})
```

❌ **Don't** — assume the absence of the middleware means an absent `request_id`. One is minted per
scope regardless, it is stable inside the process, and it matches nothing your gateway recorded.

✅ **Do** — pin `integrity.stream` before switching a tenant resolver on in an installation that
already has history. The first tenant that resolves moves new entries onto a `tenant:<id>` chain
with `sequence` restarting at 1.

```php
'integrity' => ['stream' => 'global'],
```

❌ **Don't** — turn tenancy on and discover the partition from a verification report. Existing chains
keep verifying, but they stop growing. See [Streams](../07-integrity/02-streams.md).

---

**See also:** [The ten resolvers](02-resolvers-reference.md) · [Actor and impersonation](03-actor-and-impersonation.md) · [Writing your own resolver](07-writing-your-own-resolver.md) · [The write pipeline](../05-pipeline-and-security/01-the-write-pipeline.md) · [Performance modes](../09-operations/01-performance-modes.md) · [The audit record](../01-concepts/02-the-audit-record.md) · [Schema](../99-reference/03-schema.md)
