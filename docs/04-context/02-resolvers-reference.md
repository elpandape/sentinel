# 🧭 The ten resolvers

> One page per question the entry answers about its circumstances: what each shipped resolver reads,
> what it returns when it finds nothing, which of its keys becomes a column, and how it behaves in a
> worker, a command or the scheduler.

**On this page:** [The contract](#the-contract) · [Where each value lands](#where-each-value-lands) · [The ten resolvers](#the-ten-resolvers) · [The source matrix](#the-source-matrix) · [Outside an HTTP request](#outside-an-http-request) · [Configuration keys](#configuration-keys) · [⚠️ Pitfalls](#️-pitfalls) · [✅ Best practices](#-best-practices)

---

## The contract

`ElPandaPe\Sentinel\Contracts\Resolver` has one method and no constructor requirement:

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

Three rules follow, and every one of the ten shipped resolvers obeys them:

- **An empty array means "I could work nothing out."** It is not an error and nothing is logged.
- **A returned key whose name is one of the nine promoted names fills that column**; every other key
  lands inside the `context` JSON. The promotion list is in `Context\ContextEngine::PROMOTED` and
  there is no per-resolver mapping — see
  [Execution context](01-execution-context.md#columns-and-the-json-bag).
- **A promoted value must already be a string.** `ContextEngine::column()` writes the value only
  when `is_string()` is true and writes `null` otherwise. `source` is the one exception in the
  opposite direction: it is taken only when it is an `Enums\Source` case, and anything else becomes
  `Source::System`.

Each resolver is built through the container (`$container->make(...)`), so a replacement may take
constructor dependencies. The class name is read from `resolvers.<name>.class` and validated **on
first use, not at boot** — a typo surfaces as a `ConfigurationException` at the first audited write.
All ten names are substitutable; `tests/Context/SubstitutionTest.php` swaps each one in turn.

---

## Where each value lands

| # | Resolver | Class in `src/Context/Resolvers/` | Memoised per scope | Columns it fills | Keys it puts in `context` |
|---|---|---|---|---|---|
| 1 | `source` | `SourceResolver` | yes | `source` | — |
| 2 | `host` | `HostResolver` | yes | — | `hostname`, `environment` |
| 3 | `request` | `RequestResolver` | yes | `request_id` | `ip`, `user_agent`, `url`, `route`, `method` |
| 4 | `session` | `SessionResolver` | yes | — | `session_id` |
| 5 | `command` | `CommandResolver` | yes | — | `command`, `arguments` |
| 6 | `trace` | `TraceResolver` | no | `trace_id`, `span_id` | `service_name`, `tracestate` |
| 7 | `actor` | `ActorResolver` | no | `actor_type`, `actor_id` | — |
| 8 | `impersonator` | `ImpersonatorResolver` | no | `impersonator_type`, `impersonator_id` | — |
| 9 | `tenant` | `TenantResolver` | no | `tenant_id` | — |
| 10 | `job` | `JobResolver` | no | — | `job`, `queue`, `attempts`, `batch_id` |

The number is the **run order**, and results are folded over one accumulator in that order — so on a
key collision the **later** resolver wins. A replacement `job` resolver returning `actor_id`
overrides `ActorResolver`.

The five memoised names are the ones whose answer cannot change inside one scope. The other five run
on every capture, because an actor can log in, a tenant can be switched, a trace can start and one
worker hands a job over to the next.

> 📌 **Note.** Only `context` keys are queryable through the JSON filters, and only `ip` and `route`
> have published filters. `request_id` has an index but no filter; `impersonator_type`,
> `impersonator_id` and `span_id` have neither. The tiers are laid out in
> [Execution context](01-execution-context.md#querying-what-was-written) and the filter list in
> [Filters reference](../06-reading/02-filters-reference.md).

---

## The ten resolvers

### actor

Fills the `actor_type` and `actor_id` **columns** from the authenticated user.

It calls `Auth::guard($name)->user()` where `$name` comes from `resolvers.actor.guard` — `null` by
default, which means the application's own default guard. The user is then turned into two strings
by `Context\Identity`: `actor_type` is `getMorphClass()` for an Eloquent model and `::class` for any
other `Authenticatable`; `actor_id` is `getAuthIdentifier()` cast to a string.

| Situation | Result |
|---|---|
| Nobody authenticated on that guard | `[]` — both columns null |
| `getAuthIdentifier()` is neither a string nor an int | `[]` — both columns null, no partial entry |
| The configured guard does not exist | Throws `ConfigurationException::unknown('resolvers.actor.guard', …)` at the first capture |

**Outside an HTTP request** it usually answers nothing. A queue worker, an artisan command and the
scheduler have no session and no authenticated user, so both columns come out null. There are two
supported ways round that, and manual context is neither of them: name the actor on the capture with
`Sentinel::event(...)->actor(...)`, or replace this resolver with one that reads a value the job
carried. See [Actor and impersonation](03-actor-and-impersonation.md) and
[Queues, commands and schedulers](05-queues-commands-and-schedulers.md).

### impersonator

Fills the `impersonator_type` and `impersonator_id` **columns** when somebody is acting on somebody
else's behalf.

It reads `Runtime::request()`, requires `$request->hasSession()`, and takes the value stored under
the session key named by `resolvers.impersonator.session_key` — `impersonated_by` by default.

Two invariants are fixed by tests and a replacement must keep them: **no impersonation leaves both
columns null**, never a copy of the actor; and **an id equal to the actor's own is the same session,
not a delegation** — it also yields nothing.

| Situation | Result |
|---|---|
| No request latched, or the request has no session | `[]` |
| The session key is absent, or holds anything but a string or an int | `[]` |
| Nobody is authenticated on the actor guard | `[]` |
| The session id equals the actor's own id | `[]` |
| Otherwise | `impersonator_type` = the morph alias — the class name for a non-Eloquent `Authenticatable` — of the **currently authenticated** user; `impersonator_id` = the session value as a string |

The last row is the surprising one. `Illuminate\Contracts\Auth\Guard` exposes no
`getProvider()`, so the resolver cannot hydrate a session id into a model — it assumes a person
impersonates a person and reports the class the guard authenticates. An impersonation package that
lets an `Admin` stand in for a `User` needs a resolver of its own.

**Outside an HTTP request** it always returns `[]`: no request, no session, nothing to read.

> ⚠️ **Warning.** This resolver calls `$auth->guard($this->config->actorGuard())` without the
> try/catch `ActorResolver` has, so an undefined guard name surfaces here as a raw
> `InvalidArgumentException` rather than a `ConfigurationException`. In the shipped run order
> `ActorResolver` runs first and converts the error, so you only meet this after replacing
> `ActorResolver` with one that never touches the guard.

### tenant

Fills the `tenant_id` **column** by asking the application, and stays ignorant of every tenancy
package.

`resolvers.tenant.using` holds a closure returning `string|int|null`. It is `null` by default, which
means no tenancy and no column.

```php
// config/sentinel.php
'resolvers' => [
    'tenant' => ['using' => fn (): ?string => Tenant::current()?->getKey()],
],
```

| Return of the closure | Result |
|---|---|
| `null` | `[]` — `tenant_id` null |
| `string` or `int` | `['tenant_id' => (string) $value]` |
| Anything else | `ConfigurationException::expected('resolvers.tenant.using', 'a closure returning a string, an integer or null', …)` |
| The config value is not a `Closure` | `ConfigurationException::expected('resolvers.tenant.using', 'a closure or null', …)` |

It is **not** memoised, so a process that switches tenant mid-run files each entry under the tenant
that was current when it was captured.

> ⚠️ **Warning.** `integrity.stream` ships as `tenant`, which behaves exactly like `global` until a
> tenant actually resolves. The first entry that resolves one moves to a `tenant:<id>` stream with
> `sequence` restarting at 1 and `previous_hash` null. Existing chains keep verifying — nothing is
> rewritten — but they stop growing. Pin `integrity.stream` to `global` first if you do not want the
> partition. See [Streams](../07-integrity/02-streams.md) and
> [Multi-tenancy](04-multi-tenancy.md).

**Outside an HTTP request** it works exactly the same — it only needs the closure to be able to
answer, which in a worker means the tenancy package must have made the tenant current first.

### request

Fills the `request_id` **column** and five `context` keys describing the request.

| Key | Where it comes from | Lands in |
|---|---|---|
| `request_id` | `Runtime::requestId()` — the value `AssignRequestId` latched — or a freshly minted ULID | column |
| `ip` | `$request->ip()`, nullable | `context` |
| `user_agent` | `$request->userAgent()`, nullable | `context` |
| `url` | `$request->fullUrl()` | `context` |
| `route` | the matched route's `getName()`, its `uri()` when it has no name, `null` when nothing matched | `context` |
| `method` | `$request->method()` | `context` |

Outside a request it returns `[]` — `request_id` null and none of the five keys present. A 404 still
resolves everything except `route`.

**The header.** `Http\Middleware\AssignRequestId` is opt-in and registered in no group. It reads the
header named by `resolvers.request.header` (`X-Request-Id` by default), accepts the incoming value
only when it is **at most 64 characters and matches `/^[\x21-\x7e]+$/`** (printable ASCII, no
spaces), mints a ULID otherwise, latches it on the `Runtime`, and writes it back on the response
under the same header name.

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->append(ElPandaPe\Sentinel\Http\Middleware\AssignRequestId::class);
})

// config/sentinel.php — when your gateway uses a different name
'resolvers' => [
    'request' => ['header' => 'X-Correlation-Id'],
],
```

The 64-character bound is the column width: `request_id` is `string('request_id', 64)` in
`Support\AuditSchema`. A longer or non-printable incoming id is discarded silently and the response
carries the generated one instead, so a client that assumes its own id was honoured will correlate
against the wrong key.

Without the middleware `request_id` is still filled — with a ULID minted at the first capture and
memoised for the rest of the scope. It is stable inside the process, it is shared by every entry
that request writes, and it matches nothing outside it.

> 📌 **Note.** The two other keys under `resolvers.request` are not read by this resolver.
> `resolvers.request.header` is read by the middleware, and `resolvers.request.api` by
> `SourceResolver`. They live together because they describe one boundary, not because one class
> owns them.

**The api pattern.** `resolvers.request.api` decides whether a request is `Source::Api` or
`Source::Http`. It defaults to the route pattern `api/*`, matched with `Request::is()`, and it also
accepts a closure:

```php
use Illuminate\Http\Request;

// config/sentinel.php
'resolvers' => [
    'request' => ['api' => fn (Request $request): bool => $request->hasHeader('X-Api-Key')],
],
```

The closure must return a boolean; anything else throws
`ConfigurationException::expected('resolvers.request.api', 'a closure returning a boolean', …)`. An
empty string or any other type in the config throws
`ConfigurationException::expected('resolvers.request.api', 'a route pattern or a closure', …)`.

### session

Puts one key in `context`: `session_id`, taken from `$request->session()->getId()`.

It needs a latched request that returns true from `hasSession()`. Otherwise it returns `[]` — which
is every console run, every worker and every stateless API request configured without session
middleware. It has no configuration beyond `resolvers.session.class`.

> 🔒 **Security.** A session id is a credential-shaped value sitting in a column the hash covers and
> a tombstone is the only way to remove. Name `session_id` in `security.redaction.fields` or
> `security.hashing.fields` if your trail is read by people who should not have it — the masking walk
> reaches `context` by key at any depth. See
> [Protecting sensitive data](../05-pipeline-and-security/02-protecting-sensitive-data.md).

### trace

Fills the `trace_id` and `span_id` **columns** and puts `service_name` and (opt-in) `tracestate` in
`context`.

**With `telemetry.enabled` false — the default — it returns `[]` and does nothing at all.** No header
is parsed, no provider is asked, no service name is recorded. That is what keeps the write path at
its non-tracing cost.

With telemetry on it resolves `service_name` from `telemetry.service_name`, falling back to
`app.name` and then to nothing, and asks `Telemetry\Tracer::current()` for the trace. The precedence
is the Tracer's, not this class's:

1. the active span of a registered OpenTelemetry SDK, through `Contracts\SpanContextProvider`;
2. the incoming `traceparent` header — only while `telemetry.trust_incoming_header` is true;
3. the envelope a queued job carried;
4. a root trace this process opened for itself, when `telemetry.root_context` is on.

`tracestate` is stored only when `telemetry.store_tracestate` is true and the trace carries one, and
only up to `Telemetry\TraceContext::TRACESTATE_LIMIT` (512 characters). It is **not** memoised: it
runs on every capture.

> 🔒 **Security.** `traceparent` is a value the caller chooses and `trace_id` is an indexed column.
> At a public edge, turn `telemetry.trust_incoming_header` off — otherwise a third party decides
> which trace your entries are filed under. Full detail in
> [Distributed tracing](06-distributed-tracing.md).

### source

Fills the `source` **column** and nothing else. It never returns `[]`:
`SourceResolver::resolve()` always produces a `Source` case. The decision table is
[the next section](#the-source-matrix).

Its only configuration is `resolvers.request.api` (the http/api boundary, described under
[request](#request)) and `resolvers.source.class`.

> ⚠️ **Warning.** A replacement must return an `Enums\Source` **case**, not its string value.
> `source` is on the promoted list, so a returned string is rejected by the column check *and*
> stripped out of the `context` JSON: it disappears twice and every entry reads `system`.

### host

Puts two keys in `context` and never returns an empty array:

| Key | Value |
|---|---|
| `hostname` | `gethostname()`, or the literal string `unknown` when the call fails |
| `environment` | `Application::environment()` cast to a string |

It has no configuration beyond `resolvers.host.class`. Replace it when you want a container id, a pod
name or a region instead of the machine's hostname. Because it is memoised, it runs once per scope —
once per request, once per job, once per artisan run.

### job

Puts up to four keys in `context`, read off the `Illuminate\Contracts\Queue\Job` the `Runtime`
latched from `JobProcessing`:

| Key | Value | Present when |
|---|---|---|
| `job` | `Job::resolveName()` | inside a queued job |
| `queue` | `Job::getQueue()` | inside a queued job |
| `attempts` | `Job::attempts()` — an int | inside a queued job |
| `batch_id` | `payload()['batchId']` | that payload key exists and is a string |

Outside a job it returns `[]`. It is not memoised, because one long-lived worker process hands one
job to the next — although the framework also forgets scoped instances between jobs, so the memo
would be cleared anyway.

None of these four is a column. A job's entries are correlated through `trace_id` (with telemetry on)
or through the transaction id, not through `context.job`.

### command

Puts `command` and `arguments` in `context`, read off the `Runtime`'s command stack.

The `Runtime` is fed by the `CommandStarting` listener in `SentinelServiceProvider`, which merges
`$event->input->getArguments()` and `$event->input->getOptions()`. Symfony reports **option names
without their leading dashes**, so `--api-token=…` arrives under the key `api-token`, and the
arguments map carries Symfony's own `command` entry — which this resolver drops.

**Redaction happens here, before the general masking stage.** The needles come from
`resolvers.command.redact`, default `['password', 'token', 'secret']`, and each is matched with
`stripos()` — a **case-insensitive substring of the argument name**, never of the value. A hit
replaces the value with `str_repeat(security.redaction.mask, 8)`, which with the default mask is
`********`.

```php
// config/sentinel.php
'resolvers' => [
    'command' => ['redact' => ['password', 'token', 'secret', 'signing-key', 'dsn']],
],
```

| Argument name | Needle matched | Stored value |
|---|---|---|
| `password` | `password` | `********` |
| `api-token` | `token` | `********` |
| `SECRET_KEY` | `secret` | `********` |
| `month` | none | `2026-08` |

Three behaviours worth knowing before you rely on it:

- **Setting the key replaces the default list.** Repeat `password`, `token` and `secret` if you still
  want them.
- **A masked value is masked whatever its type.** An array under a matching name becomes the mask
  string, not a masked array.
- **A value that is not scalar, null or an array is dropped without trace.** An object argument
  leaves no key at all — not a placeholder, not a class name.

Outside a command it returns `[]`. Because it is memoised and a console process is one scope for its
whole life, the first capture in the run fixes `context.command` for every capture after it — an
in-process `Artisan::call()` from inside another command will report the outer one.

> ⚠️ **Warning.** The match is on the **name**. A secret passed as a positional argument called
> `value`, or under a flag whose name contains none of the needles, reaches the ledger in the clear.
> Add the name to the list, or name it in `security.redaction.fields`.

---

## The source matrix

`SourceResolver` reads a single `match (true)` top to bottom. The first row that holds wins.

| Order | `Enums\Source` | Produced when | Runtime that produces it |
|---|---|---|---|
| 1 | `Source::Queue` | `Runtime::writingAudit()` is true | Sentinel settling one of its own entries — the only caller of `Runtime::whileWritingAudit()` is `Jobs\SettleAudit` |
| 2 | `Source::Job` | `Runtime::job()` holds a `Job` | any other capture inside a queued job |
| 3 | `Source::Scheduler` | `Runtime::scheduled()` is true, **or** the running command is `schedule:run`, `schedule:work` or `schedule:finish` | the scheduler process, and any task started in-process |
| 4 | `Source::Api` | a request is latched **and** it matches `resolvers.request.api` | HTTP under the api boundary |
| 5 | `Source::Http` | a request is latched and it does not match | ordinary web traffic |
| 6 | `Source::Cli` | no request, and a command is latched | `php artisan …` |
| 7 | `Source::System` | `Application::runningUnitTests()` | the test runner |
| 8 | `Source::Console` | `Application::runningInConsole()` | a console process with no `CommandStarting` behind it — `tinker`, a bootstrapped script |
| 9 | `Source::System` | nothing above holds | anything else |
| — | `Source::Import` | **never resolved** | written only by `sentinel:import`, through `Import\Origins\OwenIt` and `Import\Origins\Altek` |

Four consequences of that order, each of which surprises somebody:

- **A request beats a command running at the same time.** Row 4 sits above row 6, so a request
  dispatched from inside an artisan process (a test, an embedded server) reports `api`/`http`.
- **`Source::Queue` does not mean "the queue performance mode".** It means Sentinel was settling a
  deferred entry when the capture happened. An ordinary entry captured in a request and settled by a
  worker keeps `source = http`.
- **The unit-test runner reports `system`, never `console`.** `runningUnitTests()` is checked first,
  and its signal is the framework's own `$app['env'] === 'testing'`.
- **A task defined with `Schedule::command()` reports `cli`, not `scheduler`.** Laravel spawns a
  fresh artisan subprocess with no origin marker, and the child sees only a command. The parent
  process is covered by `ScheduledTaskStarting`, and the three `schedule:*` names are covered by
  name; a subprocess task is not. A task defined with `Schedule::call()` runs in-process and is
  latched correctly. See [Queues, commands and schedulers](05-queues-commands-and-schedulers.md).

`whereSource()` reads this column, and no index covers it — it is a refiner. Put an indexed filter in
front of it.

---

## Outside an HTTP request

What each resolver answers in the four runtimes that are not a web request. `—` means it returns an
empty array and its columns stay null.

| Resolver | HTTP request | Artisan command | Queue worker | Scheduler |
|---|---|---|---|---|
| `source` | `http` / `api` | `cli` | `job`, or `queue` while settling | `scheduler` in-process, `cli` in a subprocess |
| `host` | `hostname`, `environment` | same | same | same |
| `request` | all six keys | — | — | — |
| `session` | `session_id` when the request has a session | — | — | — |
| `command` | — | `command`, `arguments` | — | `command` (`schedule:run`) in the parent |
| `trace` | with telemetry on, from the SDK span or `traceparent` | with telemetry on, only from a root trace | with telemetry on, from the envelope the job carried | with telemetry on, only from a root trace |
| `actor` | the authenticated user | — | — | — |
| `impersonator` | when the session names one | — | — | — |
| `tenant` | whatever the closure returns | whatever the closure returns | whatever the closure returns | whatever the closure returns |
| `job` | — | — | `job`, `queue`, `attempts`, `batch_id` | — |

The short version: **`request`, `session`, `actor` and `impersonator` are the four that go quiet
outside a web request.** `host` and `source` always answer, `tenant` answers wherever your closure
can, `command` and `job` answer in exactly one runtime each, and `trace` answers only when telemetry
is on.

`telemetry.root_context` exists for the two runtimes in the `trace` row where nothing upstream
started a trace: it opens one trace per console or scheduled run, memoised on the execution scope,
so every entry of that run shares a `trace_id` instead of being an island.

---

## Configuration keys

Every key lives under `sentinel.resolvers`. Every default is also declared in code — the ten resolver
classes in `Context\ContextEngine::RESOLVERS`, the rest in `Support\Config` — and not only in the
published file, because Laravel's `mergeConfigFrom` is one level deep: an installation that published
`config/sentinel.php` before a key existed would otherwise run with the whole subtree overridden.

| Key | Default | What it does |
|---|---|---|
| `resolvers.actor.class` | `null` | Replaces `ActorResolver`. Must implement `Contracts\Resolver`. |
| `resolvers.actor.guard` | `null` (the app default guard) | Which guard the actor is read from. An undefined name throws `ConfigurationException::unknown` at the first capture. |
| `resolvers.impersonator.class` | `null` | Replaces `ImpersonatorResolver`. |
| `resolvers.impersonator.session_key` | `'impersonated_by'` | Session key holding the original user's id. An empty string throws `ConfigurationException::expected`. |
| `resolvers.tenant.class` | `null` | Replaces `TenantResolver`. |
| `resolvers.tenant.using` | `null` | `Closure(): string\|int\|null` giving the current tenant key. Turning this on partitions the chain while `integrity.stream` is `tenant`. |
| `resolvers.request.class` | `null` | Replaces `RequestResolver` — `request_id`, `ip`, `user_agent`, `url`, `route`, `method`. |
| `resolvers.request.header` | `'X-Request-Id'` | Header `AssignRequestId` reads the incoming correlation id from and echoes back. |
| `resolvers.request.api` | `'api/*'` | The http/api boundary: a route pattern for `Request::is()`, or a `Closure(Request): bool`. |
| `resolvers.session.class` | `null` | Replaces `SessionResolver` — `context.session_id`. |
| `resolvers.trace.class` | `null` | Replaces `TraceResolver` — `trace_id`, `span_id`, `service_name`, `tracestate`. |
| `resolvers.source.class` | `null` | Replaces `SourceResolver`. Must return a `Source` case. |
| `resolvers.host.class` | `null` | Replaces `HostResolver` — `context.hostname`, `context.environment`. |
| `resolvers.job.class` | `null` | Replaces `JobResolver` — `context.job`, `queue`, `attempts`, `batch_id`. |
| `resolvers.command.class` | `null` | Replaces `CommandResolver` — `context.command`, `context.arguments`. |
| `resolvers.command.redact` | `['password', 'token', 'secret']` | Case-insensitive substring needles matched against console argument **names**. Setting it replaces the list. |

Two keys outside the subtree change what these resolvers produce:

| Key | Default | Effect on a resolver |
|---|---|---|
| `security.redaction.mask` | `'*'` | Repeated eight times, this is the mask `CommandResolver` writes over a matched argument. A multi-character mask is repeated whole. |
| `telemetry.enabled` | `false` | While false, `TraceResolver` returns an empty array — not even `service_name`. |

> 🧪 **Verify it.** Read back what the chain wrote for the run you are looking at:
> ```shell
> php artisan tinker
> >>> $a = ElPandaPe\Sentinel\Facades\Sentinel::audits()->latest()->take(1)->get()->first();
> >>> [$a->source, $a->actor_type, $a->tenant_id, $a->request_id, $a->context];
> ```

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `actor_type` and `actor_id` are null on every entry a worker or a command writes | `ActorResolver` reads a guard, and there is no authenticated user in either runtime. | Name the actor on the capture with `Sentinel::event(...)->actor(...)`, or replace the resolver with one that reads a value the job carried. |
| `ConfigurationException` naming `resolvers.actor.guard` at the first audited write, not at boot | The guard name is not defined in `config/auth.php`. Resolver configuration is validated on use. | Define the guard, or set the key back to `null`. |
| `impersonator_type` names the impersonated user's class, not the impersonator's | `Guard` exposes no `getProvider()`, so the resolver reports the class the actor guard authenticates. | Accept it when both are the same model; replace the resolver when an `Admin` impersonates a `User`. |
| An impersonation session leaves both impersonator columns null | The session id equals the actor's own id, or it holds something that is neither a string nor an int. | Check what your impersonation package stores under `resolvers.impersonator.session_key`. |
| `InvalidArgumentException` about an auth guard instead of a `ConfigurationException` | `ImpersonatorResolver` calls the guard without a try/catch; you replaced `ActorResolver`, which normally converts the error first. | Fix the guard name in `resolvers.actor.guard`. |
| `tenant_id` is null although the tenancy package knows the tenant | `resolvers.tenant.using` was never set, or the closure returns `null` at capture time — in a worker, before the package made the tenant current. | Set the closure; make the tenant current before the audited write happens. |
| New entries suddenly start at `sequence` 1 under a stream named `tenant:<id>` | A tenant resolved for the first time while `integrity.stream` was `tenant`. | Expected. Pin `integrity.stream` to `global` **before** wiring tenancy if you do not want the partition. |
| `request_id` in the trail matches nothing in the gateway's access log | `AssignRequestId` is not installed, so `RequestResolver` minted a ULID that never left the process. | Register the middleware, and point `resolvers.request.header` at the header your gateway sends. |
| The client sent `X-Request-Id` and got a different one back | The incoming value was longer than 64 characters, empty, or contained something outside printable ASCII, so it was discarded. | Send an id that fits the column: ≤64 printable ASCII characters, no spaces. |
| `context.route` is null on entries written behind a real route | Nothing matched — a 404, or a capture that happened before routing. Everything else in the request still resolves. | Nothing to fix; read `context.url` instead. |
| `source` is `cli` on scheduled tasks | `Schedule::command()` runs the task in a fresh artisan subprocess with no origin marker. | Use `Schedule::call()` where in-process execution is acceptable, or filter on `context.command` instead. |
| `source` is `system` in the test suite where you expected `console` | `runningUnitTests()` is checked before `runningInConsole()`. | Drive the `Runtime` directly in the test rather than changing the resolver. |
| Every entry reads `source = system` after replacing `SourceResolver` | The replacement returned a string such as `'http'`. Only a `Source` case is accepted, and the key is stripped from the JSON too. | Return `['source' => Source::Http]`. |
| A console secret is readable in `context.arguments` | The argument name contains none of the redaction needles; the match is on the name, never the value. | Add the name to `resolvers.command.redact`, or to `security.redaction.fields`. |
| An object passed as a console argument leaves no key at all in `context.arguments` | Only scalars, null and arrays survive the filter. | Pass an identifier instead of an object, or record the fact with a custom event. |
| `trace_id`, `span_id` **and** `service_name` are all null | `telemetry.enabled` is false, so `TraceResolver` returns before reading anything. | Turn telemetry on. Note that correlation is not retroactive — older entries keep a null `trace_id` forever. |
| Every entry of a long artisan run names the same command | `command` and `source` are memoised, and a console process is one scope from start to finish. | Expected. Push the step with `Sentinel::withContext()` when you need to tell phases apart. |

---

## ✅ Best practices

✅ **Do** — use the closure at `resolvers.tenant.using` for the ordinary tenancy case. It is the same
result as a class with nothing to maintain, and the cast to string is the only detail that matters.

```php
// config/sentinel.php
'resolvers' => [
    'tenant' => ['using' => fn (): ?string => Tenant::current()?->getKey()],
],
```

❌ **Don't** — return a model, a UUID object or anything else the resolver cannot cast. The closure
takes a string, an integer or `null`, and anything else throws at the first capture.

```php
'tenant' => ['using' => fn (): ?Tenant => Tenant::current()],   // ConfigurationException::expected
```

✅ **Do** — add your own secret names to `resolvers.command.redact`, repeating the defaults you still
want. The needle is a substring, so `key` covers `--signing-key` and `--api-key` at once.

```php
'resolvers' => [
    'command' => ['redact' => ['password', 'token', 'secret', 'key', 'dsn']],
],
```

❌ **Don't** — assume the shipped list covers a flag you invented. `--credentials`, `--pin` and
`--webhook-url` match none of `password`, `token`, `secret`, and land in the trail verbatim.

```php
// artisan billing:sync --credentials='user:hunter2'
$audit->context['arguments']['credentials'];   // 'user:hunter2', hashed into the chain
```

✅ **Do** — use the closure form of `resolvers.request.api` when the split is not a path prefix, and
return a real boolean.

```php
use Illuminate\Http\Request;

'resolvers' => [
    'request' => ['api' => fn (Request $request): bool => $request->hasHeader('X-Api-Key')],
],
```

❌ **Don't** — return a truthy value and expect it to be coerced. Anything that is not a boolean
throws `ConfigurationException::expected('resolvers.request.api', 'a closure returning a boolean', …)`
at the first capture behind a route.

```php
'request' => ['api' => fn (Request $request): ?string => $request->header('X-Api-Key')],
```

✅ **Do** — name the actor on the capture when the fact is stated from a command, a worker or the
scheduler. `ResolveContext` applies it inside the pipeline and clears both impersonator columns at
the same time, so every policy and listener sees the actor you named.

```php
use App\Models\User;
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Facades\Sentinel;

Sentinel::event('invoices.closed')
    ->actor(User::class, 1)
    ->subject($invoice)
    ->severity(Severity::Notice)
    ->metadata(['month' => '2026-08'])
    ->record();
```

❌ **Don't** — push attribution through the context bag. Those nine names are promoted, so the value
lands in the `context` JSON, the column stays null, and no filter will ever find it.

```php
Sentinel::withContext(['actor_id' => '1'], fn () => $invoice->update(['status' => 'closed']));
// actor_id column: still null.
```

✅ **Do** — register `AssignRequestId` when something in front of your application already assigns a
correlation id, and point the config at that header.

```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware): void {
    $middleware->append(ElPandaPe\Sentinel\Http\Middleware\AssignRequestId::class);
})
```

❌ **Don't** — read `request_id` as proof that the middleware is installed. One is minted per scope
either way; the difference is whether anything else in your stack knows the value.

✅ **Do** — protect a `context` key by name when it must not be readable. The masking walk reaches
`context` at any depth, including inside `context.arguments`, and it is the only lever for a key no
model owns.

```php
'security' => [
    'redaction' => ['fields' => ['session_id', 'user_agent']],
],
```

❌ **Don't** — mask `ip` or `route` and then build reporting on `whereIp()` / `whereRoute()`. Those
filters compare against the stored value, which is now the mask, and they will quietly match nothing.

```php
'security' => ['redaction' => ['fields' => ['route']]],
Sentinel::audits()->whereRoute('invoices.approve')->take(50)->get();   // always empty
```

✅ **Do** — keep a replacement resolver to reading latched facts off `Context\Runtime`, the auth
guard or the config. Ten of these stand on every audited write, five of them on every capture, in the
process the user is waiting on.

```php
use ElPandaPe\Sentinel\Context\Runtime;
use ElPandaPe\Sentinel\Contracts\Resolver;

final readonly class RegionResolver implements Resolver
{
    public function __construct(private Runtime $runtime) {}

    /**
     * @return array<string, mixed>
     */
    public function resolve(): array
    {
        $request = $this->runtime->request();

        return $request === null ? [] : ['region' => (string) $request->header('X-Region', 'eu')];
    }
}
```

❌ **Don't** — query the database or call a service from a resolver. One lookup here is one lookup per
audited write, and it is on the write path in front of the ledger.

```php
public function resolve(): array
{
    return ['plan' => Subscription::where('tenant', $this->tenant())->value('plan')];
}
```

---

**See also:** [Execution context](01-execution-context.md) · [Actor and impersonation](03-actor-and-impersonation.md) · [Multi-tenancy](04-multi-tenancy.md) · [Queues, commands and schedulers](05-queues-commands-and-schedulers.md) · [Distributed tracing](06-distributed-tracing.md) · [Writing your own resolver](07-writing-your-own-resolver.md) · [Configuration](../99-reference/02-configuration.md) · [Enums](../99-reference/04-enums.md) · [Exceptions](../99-reference/06-exceptions.md)
