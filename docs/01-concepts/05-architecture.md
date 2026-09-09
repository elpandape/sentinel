# 🧠 Architecture

> Where everything lives, what the container hands you and with what lifetime, which seams are meant to be replaced, and which lines the test suite refuses to let you cross.

**On this page:** [The module map](#the-module-map) · [Container wiring](#container-wiring) · [Extension points](#extension-points) · [Architectural invariants](#architectural-invariants) · [The `@internal` boundary](#the-internal-boundary) · [Pitfalls](#️-pitfalls) · [Best practices](#-best-practices)

---

## The module map

`src/` holds thirty-six namespaces and two files at the root. Nothing here is a layer in the
"controllers, services, repositories" sense — each namespace owns one part of the journey an entry
takes, and the two root files are the only ones a consumer names by hand.

| Namespace | What it owns |
|---|---|
| `Sentinel` (root file) | The manager. Nineteen public methods: every read, correlation, custom event and verification starts here, and it is what the facade points at. |
| `SentinelServiceProvider` (root file) | Merges the config, registers the bindings below, latches the framework signals the resolvers read, opens the `auditing()` macro, and hooks request/worker shutdown. |
| `Concerns` | One trait, `Auditable`. Adding it to a model is the entire model-side opt-in — no interface, no observer to register. |
| `Contracts` | Seventeen interfaces. Thirteen are published surface; four are how the package talks to itself. |
| `Capture` | The front door of the write path: the Eloquent observer, relation and parent capture, `PendingEvent`, the opt-in authentication subscriber, and the `Recorder` that hands a capture over. |
| `Snapshot` | Builds the `before`/`after` pair from a model's raw attributes, honouring the model's declarations. |
| `Diff` | Structured change: `Diff`, `Change`, RFC 6901 pointers. It depends on nothing else in the package and nothing in `Illuminate\Database`. |
| `Data` | `AuditData` — the entry as the capture knows it, named after its columns — and `RelationLine`. |
| `Context` | `Runtime` (what this process is doing), the ten resolvers, the `ContextEngine` that runs them, and `ExecutionContext`, the bag an application pushes into. |
| `Pipeline` | The stage runner, the seven shipped stages, and the discard bookkeeping that gives a dropped entry a reason. |
| `Security` | Masking, salted digests, the keyring, the field walker every protection shares, and `Rekeyer`. |
| `Dispatch` | How an approved entry reaches the ledger: one strategy per performance mode, plus the settlement that drops a repeated capture. |
| `Jobs` | One job, `SettleAudit` — the queue mode's carrier. |
| `Buffer` | Where entries wait under the buffered mode: the Redis store, the in-process store, and the flusher. |
| `Ledger` | The five shipped drivers and the parts they are built from — entry builder, stream gate, array query, JSON predicates. |
| `Integrity` | The chain: canonical payload, hasher, stream naming, the three signers, checkpoints and folds, and the verifier. |
| `Models` | Seven Eloquent models, one per table. Deliberately not `final`. |
| `Query` | `AuditQuery` and its criteria objects. States a query without knowing what answers it. |
| `Presentation` | `AuditPresenter` — an entry, a field history or a timeline rendered as sentences from `resources/lang`. |
| `Http` | Two things: the opt-in `AssignRequestId` middleware and `AuditResource`. No routes. |
| `Restore` | Planning and executing a restoration, and the `RestoreResult` that says what did and did not go back. |
| `Redaction` | `Redactor` and `Tombstone` — the one sanctioned write over an entry already sealed. |
| `Retention` | Policies, durations, frontiers, the pruner and the archiver behind `sentinel:prune`. |
| `Archive` | Cold batches: writing NDJSON to a `Storage` disk, reading one back, the manifest, and `Rehydrator`. |
| `Partitions` | The calendar, grammar and maintainer behind `sentinel:partitions`. |
| `Import` | Reading another package's rows and mapping them onto entries. |
| `Compliance` | The boot-time requirement check, the read access log, and export. |
| `Transactions` | `TransactionScope` — one business operation, one identifier, one header row. |
| `Transitions` | State transitions: the builder, the query, and the small machine that asks a model whether a move is legal. |
| `Mass` | `->auditing()` on the Eloquent builder, the criteria serialiser and the three capture strategies. |
| `Telemetry` | W3C Trace Context: the parser, the tracer, the envelope that crosses a queue, and the OpenTelemetry adapter. |
| `Console` | Eleven artisan commands plus the `about` section. |
| `Events` | Eleven lifecycle events. |
| `Enums` | Eighteen enums. |
| `Exceptions` | Fifteen exception types. Two more live beside what they describe: `Diff\DiffException` and `Transitions\IllegalTransition`. |
| `Support` | `Config`, the policy readers, the schema definition, migration bookkeeping, `AuditCollection`. |
| `Facades` | The `Sentinel` facade and its `Sentinel` alias. |
| `Testing` | `LedgerContractTestCase` — shipped as production code so a driver outside this package can be held to the same chain. |

> 📌 **Note.** `Diff` is the one namespace with a hard dependency rule of its own: an arch test in
> `tests/ArchTest.php` forbids it from using `Illuminate\Database` or any of fourteen namespaces in
> this package. `Diff::between()` therefore works on two plain arrays, outside a Laravel request.

---

## Container wiring

The provider registers three lifetimes, and the split is a design decision rather than an
optimisation. Read it as: **a decision of the application is a singleton; anything holding the
circumstances of one request or job is scoped.**

### Singletons — one per application

| Binding | Resolved to | Why it outlives the request |
|---|---|---|
| `Support\Config` | itself, over `Illuminate\Contracts\Config\Repository` | It caches nothing and re-reads on every access, so a shared instance costs nothing and a runtime `config()->set()` still takes effect. |
| `Contracts\Canonicalizer` | `Integrity\JsonCanonicalizer` | Stateless. The definition of canonical bytes does not belong to a request. |
| `Support\Policies` | itself | A policy registered with `Sentinel::filter()` is a decision of the application, not of the request that registered it. |

### Scoped — one per container scope

`Contracts\Buffer` · `Contracts\Ledger` · `Sentinel` · `Context\ExecutionContext` ·
`Context\Runtime` · `Context\ContextEngine` · `Transactions\TransactionScope` ·
`Support\PolicyRegistry` · `Pipeline\Discard` · `Security\Keyring` · `Security\Maskers` ·
`Integrity\Signers` · `Restore\Columns` · `Retention\Schedule` · `Ledger\ArchiveLedger` ·
`Telemetry\Envelope`

### Bound — a fresh instance per resolve

`Models\Audit` and `Models\AuditTransaction` (both read `sentinel.models.*` and build whatever
subclass is configured), and `Contracts\SpanContextProvider`.

### Why the split matters in a worker

Laravel's queue `Worker` runs a reset callback before each job that calls
`$app->forgetScopedInstances()` — see `Illuminate\Queue\QueueServiceProvider`. Every scoped binding
above is therefore rebuilt per job. That is what stops one job's actor, memoised hostname, open
business transaction or chain tail from leaking into the next.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

Sentinel::pause();          // state on the scoped manager
Sentinel::isRecording();    // false — for this job only
```

The next job gets a fresh `Sentinel` with `paused = false`. A filter registered with
`Sentinel::filter()` does **not** reset, because `Support\Policies` is a singleton.

### Why it matters in a long-lived server process

Anything that serves several requests from one booted application — Octane, or a worker of your own —
has to reset scoped instances between them, and `tests/Context/OctaneResetTest.php` fixes what that
reset must achieve: after `forgetScopedInstances()`, `Runtime`, `ExecutionContext` and
`ContextEngine` come back as different instances, an actor does not survive into the next request,
and neither does a memoised request or its `request_id`. If your own long-lived process does not
call that reset, those three carry over.

> ⚠️ **Warning.** A console process is **one scope for the whole run**. Nothing resets scoped
> instances between commands, so five resolvers memoised on `ExecutionContext` — source, host,
> request, session and command — answer once and keep that answer for the rest of the process. An
> in-process `Artisan::call()` therefore reports the outer command.

---

## Extension points

Every published seam is one interface with one job. The table says where each one is named; if a
row's "Named in" column says a config key, you never touch the container.

| Contract | Replacing it changes | Named in | Documented in |
|---|---|---|---|
| `Contracts\Auditable` | The ten declarations a model makes, when they have to be computed instead of written down | Implemented on the model (the `Auditable` trait is the usual route) | [What a model declares](../02-getting-started/03-what-a-model-declares.md) |
| `Contracts\DeclaresTransitions` | Whether a move between two states is allowed to become an entry | Implemented on the model | [State transitions](../03-capture/08-state-transitions.md) |
| `Contracts\Resolver` | One of the ten context resolvers | `resolvers.<name>.class` | [Writing your own resolver](../04-context/07-writing-your-own-resolver.md) |
| `Contracts\Transformer` | A pipeline stage — added, reordered or removed | `pipeline` (the whole list, taken verbatim) | [The write pipeline](../05-pipeline-and-security/01-the-write-pipeline.md) |
| `Contracts\Masker` | How one redacted field is rendered | `security.redaction.masker`, or `security.redaction.maskers.<field>` | [Writing a masker](../05-pipeline-and-security/05-writing-a-masker.md) |
| `Contracts\StreamResolver` | How the chain an entry belongs to is named | `integrity.stream` (a class-string; a closure works too but breaks `config:cache`) | [Streams](../07-integrity/02-streams.md) |
| `Contracts\Ledger` | Where entries are stored and what answers a read | `ledger.default` for a shipped driver; the container for one of your own | [Writing a ledger driver](../11-extending/03-writing-a-ledger-driver.md) |
| `Contracts\LedgerStream` | The walk `Ledger::stream()` hands back | Returned by your driver | [The Ledger contract](../11-extending/01-the-ledger-contract.md) |
| `Contracts\DeclaresFilters` | Which published filters your driver answers | Implemented on the driver | [Writing a ledger driver](../11-extending/03-writing-a-ledger-driver.md) |
| `Contracts\Deduplicates` | Whether the driver can be asked if a capture already settled | Implemented on the driver | [The Ledger contract](../11-extending/01-the-ledger-contract.md) |
| `Contracts\EnumeratesStreams` | Whether the driver can list its chains — `verifyEverything()` needs it | Implemented on the driver | [The Ledger contract](../11-extending/01-the-ledger-contract.md) |
| `Contracts\SpanContextProvider` | Where the current trace comes from | A container binding (the package binds an OpenTelemetry adapter, or a null one) | [Distributed tracing](../04-context/06-distributed-tracing.md) |
| `Contracts\Signer` | How a hash is signed and verified | **Nothing names a class.** `integrity.signature.signer` is a closed match over `hmac`, `openssl` and `null` | [Signing the chain](../07-integrity/04-signing.md) |

Two more swaps need no interface at all:

| Swap | Named in | Constraint |
|---|---|---|
| The entry model | `models.audit` | Must be a subclass of `Models\Audit`; `Support\Config::model()` enforces it and `$model->audits()` resolves it too. |
| The business-transaction header model | `models.transaction` | Must be a subclass of `Models\AuditTransaction`. |

### The ledger is the one seam with two doors

`sentinel.ledger.default` is a closed match, and anything outside it throws
`ConfigurationException::unknown()` naming `archive, database, fanout, memory, null`. Four of those
five are usable as the default — `database`, `fanout`, `memory` and `null`. `archive` is refused
there by name, with `ConfigurationException::coldLedgerAsDefault()`, because it keeps the tail of a
stream on the instance; it belongs as a fanout destination or as the target of a prune. The key
never accepts a class name. A driver of your own goes into the container instead, with the lifetime
the package chose:

```php
use ElPandaPe\Sentinel\Contracts\Ledger;
use Illuminate\Support\ServiceProvider;

final class SentinelBindingsProvider extends ServiceProvider
{
    public function register(): void
    {
        // Scoped, like the package's own binding: a driver with no store keeps its chain
        // on the instance, and a worker must not carry one job's tail into the next.
        $this->app->scoped(Ledger::class, fn (): Ledger => new DocumentLedger(/* … */));
    }
}
```

### Four contracts you may not implement

`Contracts\Buffer`, `Contracts\Canonicalizer`, `Contracts\DispatchStrategy` and
`Contracts\MassStrategy` are marked `@internal` — "the seams the package uses to talk to itself, not
the points somebody extends", in the words of `tests/SurfaceTest.php`, which classifies them. They
are outside the 1.0 freeze and may change in a minor release.

---

## Architectural invariants

These are the five rules a change to the package — or an integration on top of it — can break
silently. Each is enforced somewhere, and the enforcement is named.

**1 · The core carries no user interface.** The package loads no routes and no views: nothing in
`src/` calls `loadRoutesFrom()` or `loadViewsFrom()`. `Presentation\AuditPresenter` renders strings
from `resources/lang`, and `Http\Resources\AuditResource` shapes an entry for a JSON response — both
are things you mount, not things the package mounts. Which entries a request may see is an
authorisation question this package has no standing to answer, so it does not.

**2 · Optional backends are drivers, never dependencies.** `composer.json` requires PHP, eight
`illuminate/*` components, `ext-mbstring` and `ext-openssl`. Cloud storage, search backends and the
OpenTelemetry SDK appear only under `suggest`. `Ledger\ArchiveLedger` speaks the `Storage`
filesystem contract and nothing else, which is why S3, R2 or MinIO work without the package knowing
they exist; `Telemetry\OpenTelemetry` is a single namespace, and `tests/ConventionsTest.php` fails if
an `OpenTelemetry\` symbol is referenced anywhere outside it.

**3 · Identifiers are ULID, never auto-increment.** `Models\Audit` and `Models\AuditTransaction` use
`HasUlids`, and `Support\AuditSchema` declares `id`, `capture_id`, `transaction_id` and
`source_audit_id` as `char(26)`. The id half of every morph pair — `subject_id`, `actor_id`,
`impersonator_id` — is `string(64)`, so an integer, a UUID and a ULID key all fit. A record that has
to survive export and a distributed environment cannot have an identity a second database would
reassign.

**4 · History is append-only.** `Models\Audit::booted()` registers `updating` and `deleting`
listeners that throw `ImmutableAuditException`. A restoration writes a new entry of
`audit_type = 'restore'`; a key rotation writes a new entry of `audit_type = 'security'` and leaves
the original byte for byte. The single sanctioned write over a sealed entry is a redaction, and it
destroys content rather than changing it — see
[Redaction and tombstones](../08-lifecycle/04-redaction-and-tombstones.md).

> ⚠️ **Warning.** That guard runs on Eloquent model events, so a `Builder::update()` straight at
> `sentinel_audits` never passes through it. What catches that is the chain, not the model.

**5 · Engine-specific behaviour lives in a driver.** `Query\AuditQuery` states a criterion and never
a column; an arch test asserts the whole `Query` namespace does not use `Illuminate\Database`. The
per-engine SQL lives in `Ledger\ChangedFieldPredicate` and `Ledger\ContextPredicate`, which throw
`LedgerException::cannotTranslateOn()` on an engine they have no dialect for rather than guessing.
Anything touching the chain is verified on SQLite, MySQL 9 and PostgreSQL 16.

---

## The `@internal` boundary

156 declarations under `src/` carry `@internal`, out of 278 files — a little over half the package.
A marked declaration is outside the 1.0 semantic-versioning contract: its name, its signature and
its existence can change in a minor release, and the upgrade notes owe you nothing about it.

> 🧪 **Verify it.** `grep -rl --include='*.php' '@internal' src | wc -l`

The line is not maintained by hand. `tests/SurfaceTest.php` holds it as two data lists — namespaces
whose every declaration is internal, and individual declarations that are internal inside a
namespace that is not — each keyed by the *reason*, and it fails in **both** directions: an
unmarked internal is red, and so is a marked declaration the list says is public. A declaration
added later is classified deliberately or CI goes red.

| Namespace | Why every declaration in it is internal |
|---|---|
| `Buffer`, `Dispatch`, `Ledger`, `Mass` | The configuration picks the implementation by a fixed string, so no caller ever names the class. |
| `Compliance`, `Console`, `Import`, `Partitions`, `Retention` | A command drives it, and the published surface is the command — its name, its options and its exit codes. |
| `Jobs`, `Snapshot`, `Telemetry\OpenTelemetry` | Reached only from inside another internal, and from nowhere else at all. |

Individually marked classes cover the machinery of the chain (`Integrity\Hasher`,
`Integrity\Verifier`, `Integrity\Stream`, the three signers, the canonicaliser), the capture path
(`Capture\ModelObserver`, `Capture\Recorder`, …), the archive and restore internals, the security
walkers, `Support\AuditPolicy` / `PolicyRegistry` / `Policies` / `Reference`, and
`SentinelServiceProvider` itself.

### What that means for you

- **Resolve contracts, not classes.** `app(Contracts\Ledger::class)` is stable;
  `app(Ledger\DatabaseLedger::class)` is not, even though it works today.
- **A third-party ledger driver needs internal helpers.** `Ledger\EntryBuilder`,
  `Ledger\ArrayQuery`, `Ledger\ArrayStream` and `Integrity\Stream` are the pieces a real driver is
  built from, and all four are marked `@internal`. Reusing them is faster than reimplementing them;
  pin the package version if you do. [Writing a ledger driver](../11-extending/03-writing-a-ledger-driver.md)
  says what a driver must do without them.
- **The published surface is small on purpose.** The facade, `Concerns\Auditable`, the thirteen
  public contracts, `Data\AuditData`, `Models\Audit`, `Query\AuditQuery`, the result shapes, the
  events, the commands, the config keys and the serialised entry. That list, and what may break it,
  is [API stability](../99-reference/09-api-stability.md).

### The other arch tests worth knowing

| Rule | Where |
|---|---|
| Every file declares `strict_types`; no `dd`/`dump`/`var_dump`/`exit` survives | `tests/ArchTest.php` |
| Classes are `final`, except `Models` (config replaces them) and `Testing` (being extended is what it is for) | `tests/ArchTest.php` |
| Every shipped resolver implements `Contracts\Resolver` and is `final` | `tests/ArchTest.php` |
| `Testing\LedgerContractTestCase` depends on nothing under `tests/` | `tests/ArchTest.php` |
| No mutable static state anywhere in `src/` | `tests/ConventionsTest.php` |
| The two language catalogues carry the same keys and the same placeholders | `tests/ConventionsTest.php` |

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `Sentinel::pause()` in one job has no effect on the next | `Sentinel` is `scoped` and the queue worker calls `forgetScopedInstances()` between jobs, so the paused flag went with the old instance | Nothing to fix — that is the design. Scope the silence to the work itself with `Sentinel::withoutAuditing()` |
| A filter registered in a controller fires twice, then three times, in a long-lived worker | `Support\Policies` is a singleton and `add()` only appends; nothing public removes a policy | Register every `Sentinel::filter()` once, from a service provider's `boot()` |
| Every entry of a console run reports the same `context.command`, including after a nested `Artisan::call()` | Five resolvers are memoised on the scoped `ExecutionContext`, and a console process is a single scope | Read `source` and `command` as facts about the process, not the call |
| `ConfigurationException` naming `ledger.default` after pointing it at your own driver class | `ledger.default` is a closed match over the shipped driver names and never accepts a class-string | Leave the key at `database` and bind `Contracts\Ledger` with `scoped()` in your own provider |
| A pipeline stage shipped by a newer package version never runs | A published `pipeline` list is taken verbatim; only an empty or absent list falls back to the shipped order | Re-check the published list against the upgrade notes on every package bump |
| A class you resolved out of `ElPandaPe\Sentinel\Ledger\…` vanished in a minor release | The whole namespace is `@internal` and outside the 1.0 freeze | Resolve `Contracts\Ledger`, or pin the exact package version |
| Rows written straight at `sentinel_audits` with the query builder are not refused | The immutability guard is an Eloquent model event; a `Builder::update()` never fires one | Grant no write access to the table, and let `sentinel:verify` be what notices |

---

## ✅ Best practices

✅ **Do** — resolve the contract and let the container decide the implementation. The driver classes
are `@internal`; the config string and the interface are the published surface.

```php
use ElPandaPe\Sentinel\Contracts\Ledger;

$ledger = app(Ledger::class);
```

❌ **Don't** — name a driver class in application code. It compiles today and is outside the freeze
tomorrow.

```php
use ElPandaPe\Sentinel\Ledger\DatabaseLedger;

$ledger = app(DatabaseLedger::class); // @internal — no upgrade promise attached
```

✅ **Do** — keep the lifetime the package chose when you replace a binding. A ledger holds the tail
of every stream it wrote; scoped is what stops a worker carrying one job's tail into the next.

```php
$this->app->scoped(Ledger::class, fn (): Ledger => new DocumentLedger(/* … */));
```

❌ **Don't** — promote a scoped binding to a singleton to "save an allocation". A singleton ledger in
a worker keeps the chain state of the first job it ever handled.

```php
$this->app->singleton(Ledger::class, fn (): Ledger => new DocumentLedger(/* … */));
```

✅ **Do** — register global filters from a provider's `boot()`, exactly once. `Support\Policies` is a
singleton with no public removal.

```php
use App\Models\HealthCheck;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Facades\Sentinel;
use Illuminate\Support\ServiceProvider;

final class AuditPolicyProvider extends ServiceProvider
{
    public function boot(): void
    {
        Sentinel::filter(
            static fn (AuditData $audit): bool => $audit->subject_type !== HealthCheck::class,
        );
    }
}
```

❌ **Don't** — register one from a controller or a job handler. In a long-lived worker process the
list grows on every pass, and every closure on it runs again for every entry.

```php
public function store(Request $request): RedirectResponse
{
    // Appended again on every request this worker serves, and never removed.
    Sentinel::filter(static fn (AuditData $audit): bool => $audit->severity !== Severity::Info);

    // …
}
```

✅ **Do** — read configuration through `Support\Config` in your own resolver, stage or driver. Every
accessor validates the value and throws `ConfigurationException` naming the key.

```php
use ElPandaPe\Sentinel\Support\Config;

public function __construct(private readonly Config $config) {}

$table = $this->config->table('audits');
```

❌ **Don't** — read the repository by hand. A wrong type then degrades into a silent default in the
middle of a write path instead of failing in one place.

```php
$table = config('sentinel.tables.prefix').config('sentinel.tables.audits'); // no validation
```

✅ **Do** — scope a suspension so it restores itself. `withoutAuditing()` saves the previous flag and
puts it back in a `finally`, so it nests and survives an exception.

```php
Sentinel::withoutAuditing(fn () => $importer->run());
```

❌ **Don't** — split `pause()` and `resume()` across a boundary. A throw in between leaves auditing
off for the rest of the scope, with the entries that should have been written simply absent.

```php
Sentinel::pause();
$importer->run();      // throws — resume() never runs
Sentinel::resume();
```

---

**See also:** [What Sentinel is](01-what-sentinel-is.md) · [The write path](03-the-write-path.md) · [Glossary](06-glossary.md) · [Swapping components](../11-extending/06-swapping-components.md) · [API stability](../99-reference/09-api-stability.md) · [Configuration](../99-reference/02-configuration.md)
