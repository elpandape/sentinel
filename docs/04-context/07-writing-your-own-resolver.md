# 🧭 Writing your own resolver

> The one-method contract behind every context column, what a resolver is allowed to do on the write
> path, how to register one, and what breaks when it misbehaves.

**On this page:** [The contract](#the-contract) · [Ten slots, not a registry](#ten-slots-not-a-registry) · [Registering it](#registering-it) · [A tenant resolver over a tenancy package](#a-tenant-resolver-over-a-tenancy-package) · [Adding a key to the context JSON](#adding-a-key-to-the-context-json) · [When a resolver throws](#when-a-resolver-throws) · [The performance contract](#the-performance-contract) · [Testing a resolver](#testing-a-resolver) · [⚠️ Pitfalls](#️-pitfalls) · [✅ Best practices](#-best-practices)

---

## The contract

`ElPandaPe\Sentinel\Contracts\Resolver` has one method and no base class:

```php
namespace ElPandaPe\Sentinel\Contracts;

interface Resolver
{
    /**
     * @return array<string, mixed>
     */
    public function resolve(): array;
}
```

That is the whole extension point. `Context\ContextEngine` calls `resolve()`, folds the result into
an accumulator, promotes nine key names to columns and puts the rest into the `context` JSON. The
mechanics are on [Execution context](01-execution-context.md); this page is about the object you
write.

### What the return value means

| You return | The engine does |
|---|---|
| `[]` | Nothing. Every column it did not fill is written as `null` anyway. |
| A promoted key holding a **string** | Writes that column. |
| A promoted key holding anything else | Writes `null` into that column, silently. `ContextEngine::column()` keeps a value only when `is_string()` is true. |
| `'source'` holding an `Enums\Source` case | Writes that source. |
| `'source'` holding anything else | Writes `Source::System`, and the value is dropped from the JSON too, because `source` is promoted. |
| Any other key | Merged into the `context` JSON, at the top level, under exactly the name you gave it. |

The nine promoted names are `actor_type`, `actor_id`, `impersonator_type`, `impersonator_id`,
`tenant_id`, `request_id`, `trace_id`, `span_id` and `source`. Everything else is JSON.

### What a resolver may do

- **Take constructor dependencies.** The class is built with `$container->make()`, so type-hinted
  arguments are resolved normally. `Context\Runtime`, `Support\Config`,
  `Illuminate\Contracts\Auth\Factory`, `Illuminate\Contracts\Foundation\Application` and
  `Telemetry\Tracer` are the ones the shipped resolvers use.
- **Read the latch.** `Runtime` answers `request()`, `command()`, `arguments()`, `scheduled()`,
  `job()`, `requestId()` and `writingAudit()` from events Laravel already fired — property reads, no
  inspection of the world.
- **Cache on the execution scope.** `ExecutionContext::memoize(string $key, Closure $resolve): array`
  is public. See [The performance contract](#the-performance-contract).

### What a resolver must not do

- **Throw because a value is missing.** There is no `try`/`catch` between the Eloquent event and the
  engine. An exception here takes down the write that caused it — see
  [When a resolver throws](#when-a-resolver-throws). Absence is `[]`, not an exception.
- **Query the database or call out over the network.** Ten resolvers run per capture, on the write
  path, inside the request that is waiting for a response.
- **Keep state between calls.** For the five slots that are not memoised the engine calls
  `$container->make()` again on every capture, so unless you bind the class yourself an instance
  property is written and thrown away within one entry.
- **Return an array of nulls to "clear" something.** The engine already nulls every column nobody
  filled. `['job' => null]` writes a dead key into the JSON that nobody can tell apart from a fact
  that genuinely was null.

> 📌 **Note.** That last limitation is structural, not an oversight. The contract cannot express the
> difference between "I have nothing to say" and "the answer is null", which is why a redaction run
> writes a trail entry carrying the *run's* tenant rather than the redacted entry's. Fixing it would
> mean changing `Contracts\Resolver`, and that interface is frozen for 1.x —
> `tests/Contracts/ContractsTest.php` pins it to exactly one method. See
> [API stability](../99-reference/09-api-stability.md).

---

## Ten slots, not a registry

There is no way to add an eleventh resolver. `ContextEngine::RESOLVERS` is a private constant naming
ten slots, and a slot is the only thing configuration can point at. Adding
`'deployment' => ['class' => …]` to `config/sentinel.php` is read by nobody and reports nothing.

**To add a value, you replace the slot that is closest to it and return the shipped keys alongside
your own.** Here is what each slot owns, so you know what disappears if you do not return it:

| Slot | Fills | Memoised | Cost of replacing it badly |
|---|---|---|---|
| `source` | `source` column | Yes | Every entry reads `system`. |
| `host` | `hostname`, `environment` | Yes | You no longer know which machine or environment wrote an entry. |
| `request` | `request_id` column; `ip`, `user_agent`, `url`, `route`, `method` | Yes | `whereIp()` and `whereRoute()` match nothing, and entries of one request stop sharing a `request_id`. |
| `session` | `session_id` | Yes | — |
| `command` | `command`, `arguments` | Yes | Console runs stop naming themselves; you also drop the argument redaction that `resolvers.command.redact` performs. |
| `trace` | `trace_id`, `span_id` columns; `service_name`, `tracestate` | No | `withTrace()` finds nothing. See [Distributed tracing](06-distributed-tracing.md). |
| `actor` | `actor_type`, `actor_id` columns | No | `by()` finds nothing; the trail stops saying who did it. |
| `impersonator` | `impersonator_type`, `impersonator_id` columns | No | `Audit::impersonator()` resolves to nothing. |
| `tenant` | `tenant_id` column | No | With `integrity.stream` at its default the entry falls back to the global chain. |
| `job` | `job`, `queue`, `attempts`, `batch_id` | No | — |

The five memoised slots are resolved **once per container scope** and the result is cached on
`ExecutionContext`. The five that are not run on every capture. Pick the slot accordingly: a fact
that changes inside a request does not belong in `host`.

> ⚠️ **Warning.** Resolvers are folded in the fixed order `source, host, request, session, command,
> trace, actor, impersonator, tenant, job`, and a later result overwrites an earlier one on a key
> collision. A replacement `job` resolver that returns `actor_id` silently wins over `ActorResolver`.
> Nothing warns you, and no test in the package pins that precedence — treat a collision as a bug in
> your own code, not as a feature.

---

## Registering it

```php
// config/sentinel.php
'resolvers' => [
    'tenant' => ['class' => App\Sentinel\TenantResolver::class],
],
```

`Support\Config::resolverClass()` reads `sentinel.resolvers.<slot>.class`, and:

| Value | Result |
|---|---|
| `null` (or the key absent) | The package default for that slot. |
| A class-string implementing `Contracts\Resolver` | That class, built with `$container->make()`. |
| A class-string that does not implement it, or does not exist | `ConfigurationException` — *"Sentinel configuration key [sentinel.resolvers.tenant.class] must be ElPandaPe\Sentinel\Contracts\Resolver or a subclass of it, […] given."* |
| Anything that is not a string | `ConfigurationException` — *"… must be a class-string or null, array given."* |

> ⚠️ **Warning.** All three checks run **at the first capture, not at boot**. A typo in the class
> name boots cleanly, passes health checks, and throws the first time a model is saved.
> `ConfigurationException` extends `InvalidArgumentException`, so it is not a Sentinel-specific
> exception type from a `catch` block's point of view. See
> [Exceptions](../99-reference/06-exceptions.md).

Two slots also accept a closure instead of a class — `resolvers.tenant.using` and
`resolvers.request.api`. They are the short form for the two cases where a class would be one line,
and they have one cost:

> ⚠️ **Warning.** A closure in `config/sentinel.php` makes `php artisan config:cache` fail with
> *"Your configuration files could not be serialized because the value at
> `"sentinel.resolvers.tenant.using"` is non-serializable."* If your deployment caches config — and
> it should — use the class form.

---

## A tenant resolver over a tenancy package

`TenantResolver` ships deliberately ignorant of every tenancy package: it invokes the closure at
`resolvers.tenant.using` and casts what comes back. Replacing it with a class buys you two things —
a config file that still caches, and constructor injection.

```php
// app/Sentinel/CurrentTenantResolver.php
namespace App\Sentinel;

use ElPandaPe\Sentinel\Contracts\Resolver;
use Spatie\Multitenancy\Models\Tenant;

final readonly class CurrentTenantResolver implements Resolver
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

```php
// config/sentinel.php
'resolvers' => [
    'tenant' => ['class' => App\Sentinel\CurrentTenantResolver::class],
],
```

`Tenant::current()` is `spatie/laravel-multitenancy`'s accessor for the tenant that has been made
current; swap it for whatever your own package exposes. Three things about this eight-line class are
not decoration:

- **The cast to string.** `getKey()` returns an int for an auto-incrementing primary key. Return it
  uncast and `tenant_id` is written as `null`, with no exception and no log line.
- **`[]` when there is no tenant.** A console command, a worker before the tenant is made current,
  and a request to a central domain all reach this method. None of them is an error.
- **No query.** `Tenant::current()` reads what the package already put in memory. A resolver doing
  `Tenant::where('domain', $host)->first()` would add a select to every audited write.

> ⚠️ **Warning.** Turning a tenant resolver on partitions the hash chain. `integrity.stream` ships as
> `tenant`, which behaves exactly like `global` until a tenant actually resolves; the moment one
> does, new entries move to a `tenant:<id>` stream with `sequence` restarting at 1 and
> `previous_hash` null. Existing chains keep verifying — nothing is rewritten — but they stop
> growing. On an installation that already has history, pin `integrity.stream` to `'global'`
> **before** you register this class. See [Streams](../07-integrity/02-streams.md) and
> [Multi-tenancy](04-multi-tenancy.md).

---

## Adding a key to the context JSON

Say every entry should name the release and the pod it was written on. There is no `deployment`
slot, so the value goes into the slot that already answers "where did this run" — `host` — and the
replacement returns the two shipped keys plus its own.

```php
// app/Sentinel/DeploymentResolver.php
namespace App\Sentinel;

use ElPandaPe\Sentinel\Contracts\Resolver;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;

final readonly class DeploymentResolver implements Resolver
{
    public function __construct(
        private Application $app,
        private Repository $config,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function resolve(): array
    {
        $hostname = gethostname();

        return [
            'hostname' => $hostname === false ? 'unknown' : $hostname,
            'environment' => (string) $this->app->environment(),
            'release' => (string) $this->config->get('deploy.release', 'unknown'),
            'pod' => (string) $this->config->get('deploy.pod', 'unknown'),
        ];
    }
}
```

```php
// config/sentinel.php
'resolvers' => [
    'host' => ['class' => App\Sentinel\DeploymentResolver::class],
],
```

Reading `release` and `pod` through the config repository rather than `env()` is what keeps this
working under `config:cache`, where `env()` outside a config file returns null.

The entry now carries them where every other context key lives:

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

$audit = Sentinel::audits()->latest()->take(1)->get()->first();

$audit->context['release'];   // '2026.09.04-3'
$audit->context['pod'];       // 'web-7c9f4d-xk2ml'
$audit->context['hostname'];  // still there, because the resolver returned it
```

Two consequences worth knowing before you ship it:

> 🔒 **Security.** `context` is inside the canonical payload, so `release` and `pod` are hashed and
> covered by the chain. They are also permanent: the only way to take a context key back out of a
> settled entry is a [tombstone](../08-lifecycle/04-redaction-and-tombstones.md), which empties the
> whole column. Do not put a secret, a token or a personal identifier in a resolver's return value.
> If the key must exist but must not be readable, name it in `security.redaction.fields` or
> `security.hashing.fields` — the masking walk reaches `context` by key at any depth. See
> [Protecting sensitive data](../05-pipeline-and-security/02-protecting-sensitive-data.md).

> 📌 **Note.** No query filter reads an arbitrary context key. `whereIp()` and `whereRoute()` are the
> only two published filters that look inside the JSON, and they name `ip` and `route` specifically.
> A `release` you add is readable off the entry and searchable with raw SQL, and nothing more. See
> [Filters reference](../06-reading/02-filters-reference.md).

---

## When a resolver throws

There is no error handling around a resolver. Not in `ContextEngine`, not in
`Pipeline::process()`, not in `Capture\Recorder`, and not in the observer that called them.

```
Invoice::save()
  └─ Eloquent "updated" event
       └─ Capture\ModelObserver → ModelCapture → Recorder::prepared()
            └─ Pipeline::process()
                 └─ Stages\ResolveContext
                      └─ ContextEngine → your resolver   ← an exception here
```

The exception propagates back up that stack, unwrapped and unlogged, and comes out of the `save()`
call in your application code. **A broken resolver turns every audited write into a failed write.**

> ⚠️ **Warning.** `on_write_failure` does **not** cover this. That setting is applied by
> `Capture\WriteFailure`, which wraps the *settlement* — the ledger write — and settlement happens
> after the pipeline. Setting it to `log` will not make a throwing resolver survivable. See
> [Failure policy](../09-operations/05-failure-policy.md).

Neither does the mode. `queue` and `buffered` move the ledger write off the request; the pipeline,
and therefore the resolver, always runs in the process that captured the fact. There is no mode in
which a resolver exception reaches a worker instead of the user.

Which leaves one rule: **a resolver returns `[]` where a shipped one would have.** Every shipped
resolver models it — `ActorResolver` returns `[]` when nobody is authenticated, `JobResolver` when
there is no job, `RequestResolver` when there is no request.

```php
public function resolve(): array
{
    $tenant = $this->tenancy->current();          // may be null; that is not an error

    return $tenant === null ? [] : ['tenant_id' => (string) $tenant->id];
}
```

The exceptions the shipped resolvers *do* throw are all `ConfigurationException`, and every one of
them says the application misconfigured something rather than that a value was absent: an undefined
actor guard, an `resolvers.tenant.using` closure returning a type no column holds, a
`resolvers.request.api` closure returning a non-boolean, and a `resolvers.command.redact` that is not
a list of strings. Follow that line: throw for a broken configuration, return `[]` for a missing
fact.

---

## The performance contract

A resolver runs **on every capture**, in the request that caused it, before the entry reaches the
ledger. Ten run in sequence. Five are answered once per container scope; the other five — yours
included, if it sits in one of their slots — run per entry, and a single `update()` on a model with
three audited parents is four captures.

`make bench` measures the whole chain in isolation and prints it as
`context only (no ledger, no write)`, separated from the write it normally lands in. Run it before
and after adding your resolver, on your own machine and your own schema — a figure from someone
else's is worth nothing.

### How to cache legally

If your value genuinely cannot change inside one scope, memoise it on the execution scope rather
than in an instance property (which does not survive, because the engine calls `make()` again):

```php
namespace App\Sentinel;

use ElPandaPe\Sentinel\Context\ExecutionContext;
use ElPandaPe\Sentinel\Contracts\Resolver;

final readonly class PlanResolver implements Resolver
{
    public function __construct(private ExecutionContext $context) {}

    /**
     * @return array<string, mixed>
     */
    public function resolve(): array
    {
        return $this->context->memoize(
            'acme.plan',
            fn (): array => ['plan' => (string) $this->lookup()],
        );
    }

    private function lookup(): string
    {
        // Resolved once per request, per job, per console run.
        return 'enterprise';
    }
}
```

`ExecutionContext::memoize()` is public, returns `array<string, mixed>`, and the memo lives exactly
as long as the scope: one HTTP request, one Octane request, **one queued job** (the framework's
worker calls `forgetScopedInstances()` before each), and **one whole console run** — an artisan
process is never reset.

> ⚠️ **Warning.** The memo namespace is shared. `source`, `host`, `request`, `session`, `command`
> belong to the engine and `telemetry.root` to `Telemetry\Tracer`. Prefix your key. And note that
> `Sentinel::context()->flush()` clears the memo along with the pushed data, so a mid-request
> `flush()` re-runs everything memoised, yours included.

---

## Testing a resolver

Three levels, cheapest first. Nothing here needs a server, a worker or a scheduler — `Runtime` is
public, and driving it directly is how the package tests its own eight-value source matrix.

### The resolver alone

```php
use App\Sentinel\CurrentTenantResolver;

it('resolves nothing when no tenant is current', function (): void {
    expect(app(CurrentTenantResolver::class)->resolve())->toBeEmpty();
});

it('casts an integer tenant key to a string', function (): void {
    Tenant::create(['id' => 42])->makeCurrent();

    expect(app(CurrentTenantResolver::class)->resolve())->toBe(['tenant_id' => '42']);
});
```

The second assertion is the one that matters. `toBe()` is strict, so `42` fails against `'42'` —
which is exactly the failure the engine would otherwise swallow as a null column.

### The console case

```php
use ElPandaPe\Sentinel\Context\Runtime;
use ElPandaPe\Sentinel\Context\Resolvers\SourceResolver;
use ElPandaPe\Sentinel\Enums\Source;

it('reports cli while a command is running', function (): void {
    app(Runtime::class)->enteredCommand('invoices:close', ['month' => '2026-08']);

    expect(app(SourceResolver::class)->resolve()['source'])->toBe(Source::Cli);
});
```

Resolve the resolver **directly** here, not through `ContextEngine`. `source` and `command` are
memoised, so going through the engine fixes the answer at the first capture in the test's scope and
the second case in the same test would assert the first one's result.

### The queue case

```php
use ElPandaPe\Sentinel\Context\Resolvers\JobResolver;
use ElPandaPe\Sentinel\Context\Runtime;
use Illuminate\Container\Container;
use Illuminate\Queue\Jobs\SyncJob;

it('names the job the entry was written inside', function (): void {
    $payload = json_encode(['job' => 'App\Jobs\CloseInvoices', 'displayName' => 'App\Jobs\CloseInvoices']);

    app(Runtime::class)->enteredJob(new SyncJob(app(Container::class), $payload, 'sync', 'invoices'));

    expect(app(JobResolver::class)->resolve()['queue'])->toBe('invoices');
});
```

`Runtime::enteredJob()` is what the package's own `JobProcessing` listener calls, so this is the same
state a real worker produces. `enteredSchedule()` covers the scheduler, and
`whileWritingAudit(fn () => …)` covers `Source::Queue`.

### End to end

The one that proves the wiring, not just the class:

```php
use App\Models\Invoice;

it('stamps the tenant on an entry the application writes', function (): void {
    config()->set('sentinel.resolvers.tenant.class', App\Sentinel\CurrentTenantResolver::class);
    Tenant::create(['id' => 42])->makeCurrent();

    $invoice = Invoice::create(['total' => 100]);

    expect($invoice->audits()->get()->last()?->tenant_id)->toBe('42');
});
```

> 🧪 **Verify it.** Outside a test suite, one capture is enough to see the whole payload:
> ```shell
> php artisan tinker
> >>> ElPandaPe\Sentinel\Facades\Sentinel::audits()->latest()->take(1)->get()->first()->context;
> ```

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| The key you added never appears in `context` | It was registered under a name of its own — `resolvers.deployment.class`. Only the ten shipped slot names are read; an unknown one is ignored without error. | Replace one of the ten slots and return the shipped keys alongside yours. |
| Replacing `host` lost `hostname` and `environment` from every entry | A replacement is a replacement, not a decorator. Whatever it does not return is gone. | Return the shipped keys too, as `DeploymentResolver` above does. |
| The column is `null` and nothing was logged | A promoted key was returned as an int, a `Stringable`, a `BackedEnum` or a UUID object. `ContextEngine::column()` keeps strings only. | `(string) $model->getKey()`. Assert it in a test with `toBe('42')`, not `toBe(42)`. |
| `source` reads `system` on every entry after replacing `SourceResolver` | `'http'` was returned instead of `Source::Http`. It is rejected as a column *and* stripped from the JSON, because `source` is promoted — the value vanishes twice. | Return `['source' => Source::Http]`. |
| The resolver runs once and never again in a console command | It occupies one of the five memoised slots — `source`, `host`, `request`, `session`, `command` — and an artisan process is one scope from first line to last. | Put a per-entry fact in a non-memoised slot (`trace`, `actor`, `impersonator`, `tenant`, `job`). |
| Swapping the class in config mid-request has no effect | Same memo: the first capture in the scope already cached the answer. | Expect it, or use a non-memoised slot. |
| `php artisan config:cache` fails with *"could not be serialized"* | A closure at `resolvers.tenant.using` or `resolvers.request.api`. | Move it into a class and use `['class' => …]`. |
| Every `save()` in the application started throwing after registering the resolver | It threw. Nothing between the Eloquent event and the engine catches, and `on_write_failure` covers only the ledger write. | Return `[]` for an absent value; throw only for a genuinely broken configuration. |
| `ConfigurationException` at the first model save, not at boot | Resolver classes are validated when they are first used. | Fix the class name, and confirm it implements `ElPandaPe\Sentinel\Contracts\Resolver`. |
| An instance property set in one call is empty in the next | The engine calls `$container->make()` per capture for the five non-memoised slots. | Cache with `ExecutionContext::memoize()` under a prefixed key, or bind the class `scoped()` yourself. |
| Two resolvers fight over one key and the wrong one wins | Results are folded in the order `source, host, request, session, command, trace, actor, impersonator, tenant, job`; the later one overwrites. | Do not return a key that belongs to another slot. |
| Latency rose after adding the resolver, and only on writes | It queries or calls out. Ten resolvers run per capture, and one `update()` on a model with parents is several captures. | Move the lookup out of the write path, or memoise it on the execution scope. |
| `whereIp()` stopped matching after replacing `RequestResolver` | The replacement did not return the `ip` key, or returned it under another name. | Return `ip`, `user_agent`, `url`, `route` and `method` if you want those filters and that context to keep working. |

---

## ✅ Best practices

✅ **Do** — cast every promoted value to a string inside the resolver. The engine writes `null` for
anything else and reports nothing, so the mistake is invisible until an auditor asks who did it.

```php
return ['tenant_id' => (string) $tenant->getKey()];
```

❌ **Don't** — hand the engine the raw key and assume something downstream casts it.

```php
return ['tenant_id' => $tenant->getKey()];      // int → tenant_id is written as null
```

✅ **Do** — return `[]` when the fact is not there. That is the shipped behaviour of every
resolver in the package, and it is the only shape that cannot break a write.

```php
$job = $this->runtime->job();

return $job === null ? [] : ['job' => $job->resolveName(), 'queue' => $job->getQueue()];
```

❌ **Don't** — throw because a value is absent. There is no `catch` on this path: the exception comes
out of `Invoice::save()`, and `on_write_failure = log` does not soften it.

```php
$tenant = Tenant::current() ?? throw new RuntimeException('No tenant');   // breaks every write
```

✅ **Do** — return the keys the resolver you are replacing returned, plus your own. A slot is
replaced whole.

```php
return [
    'hostname' => $hostname,
    'environment' => (string) $this->app->environment(),
    'release' => $release,
];
```

❌ **Don't** — return only your addition and discover the loss from an incident three months later.

```php
return ['release' => $release];   // hostname and environment: gone from every entry
```

✅ **Do** — read facts that are already in memory: the `Runtime` latch, the auth guard, the config
repository, Laravel's own `Context`.

```php
public function resolve(): array
{
    $request = $this->runtime->request();

    return $request === null ? [] : ['region' => (string) $request->header('X-Region', 'unknown')];
}
```

❌ **Don't** — put a query or an HTTP call in `resolve()`. It becomes one per audited write, paid by
the user waiting for the response.

```php
public function resolve(): array
{
    return ['plan' => Subscription::where('tenant', $this->tenant)->value('plan')];
}
```

✅ **Do** — use the class form and register it in config, so `php artisan config:cache` keeps working
and the resolver can take constructor dependencies.

```php
'resolvers' => ['tenant' => ['class' => App\Sentinel\CurrentTenantResolver::class]],
```

❌ **Don't** — leave a closure in a production config file because it was quicker to write.

```php
'resolvers' => ['tenant' => ['using' => fn (): ?string => Tenant::current()?->id]],
// php artisan config:cache → "the value at "sentinel.resolvers.tenant.using" is non-serializable"
```

✅ **Do** — pin `integrity.stream` to `'global'` before registering a tenant resolver on a trail that
already has entries, if you do not want the chain to partition.

```php
'integrity' => ['stream' => 'global'],
```

❌ **Don't** — register one on an existing installation and find out from a verification report. The
old chain keeps verifying and stops growing, and the decision is not reversible for entries already
written.

✅ **Do** — test the cast and the empty case explicitly, with `toBe()` rather than `toEqual()`.

```php
expect(app(CurrentTenantResolver::class)->resolve())->toBe(['tenant_id' => '42']);
expect(app(CurrentTenantResolver::class)->resolve())->toBeEmpty();
```

❌ **Don't** — assert only through `ContextEngine` when the slot is memoised. The first call in the
scope fixes the answer and the second assertion tests nothing.

```php
expect(contextEngine()(auditData())->context['command'])->toBe('invoices:close');
expect(contextEngine()(auditData())->context['command'])->toBe('reports:nightly');  // never passes
```

---

**See also:** [Execution context](01-execution-context.md) · [The ten resolvers](02-resolvers-reference.md) · [Multi-tenancy](04-multi-tenancy.md) · [Queues, commands and schedulers](05-queues-commands-and-schedulers.md) · [The write pipeline](../05-pipeline-and-security/01-the-write-pipeline.md) · [Swapping components](../11-extending/06-swapping-components.md) · [Configuration](../99-reference/02-configuration.md) · [API stability](../99-reference/09-api-stability.md)
