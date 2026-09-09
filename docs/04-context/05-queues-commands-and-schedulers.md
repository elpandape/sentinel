# 🧭 Queues, commands and schedulers

> Attribution where nobody is logged in. What a worker, a console command and the scheduler each
> work out on their own, what none of them can, and the standard answer — the actor travels with the
> job.

**On this page:** [Three runtimes, three signals](#three-runtimes-three-signals) · [What a background run resolves on its own](#what-a-background-run-resolves-on-its-own) · [The hole: nobody is authenticated](#the-hole-nobody-is-authenticated) · [Carrying the actor into a job](#carrying-the-actor-into-a-job) · [The same for a command](#the-same-for-a-command) · [The scheduler](#the-scheduler) · [Querying what the system did to itself](#querying-what-the-system-did-to-itself) · [Catching unattributed entries early](#catching-unattributed-entries-early) · [⚠️ Pitfalls](#️-pitfalls) · [✅ Best practices](#-best-practices)

---

## Three runtimes, three signals

`Context\Runtime` inspects nothing. It is latched from events the framework already fires, and
`SentinelServiceProvider::latchRuntimeSignals()` registers one listener per signal:

| Runtime | Laravel event | Latched as | Read by |
|---|---|---|---|
| A console command | `Illuminate\Console\Events\CommandStarting` | `enteredCommand($name, [...arguments, ...options])` | `CommandResolver`, `SourceResolver` |
| — its end | `CommandFinished` | `leftCommand()` — pops the stack | |
| A queued job | `Illuminate\Queue\Events\JobProcessing` | `enteredJob($job)` | `JobResolver`, `SourceResolver` |
| — its end | `JobProcessed` | `leftJob()` — pops the stack | |
| A scheduled task | `Illuminate\Console\Events\ScheduledTaskStarting` | `enteredSchedule()` — a one-way latch | `SourceResolver` |
| Sentinel settling its own entry | `Runtime::whileWritingAudit()`, called only by `Jobs\SettleAudit` | a scope, restored in a `finally` | `SourceResolver` |

Commands and jobs **nest**: entering pushes the current one onto a stack, leaving pops it. An
`Artisan::call()` from inside another command does not convince the outer one that it ended.
`enteredSchedule()` is different — it is a flag with no counterpart, because once a process has
started running scheduled work everything it does afterwards is scheduled work.

`SourceResolver` reads those latches top to bottom, and the order is the contract:

| Order | Condition | `source` |
|---|---|---|
| 1 | `Runtime::writingAudit()` | `Source::Queue` |
| 2 | `Runtime::job()` holds a job | `Source::Job` |
| 3 | Scheduled — the latch, or the command is `schedule:run` / `schedule:work` / `schedule:finish` | `Source::Scheduler` |
| 4 | `Runtime::request()` holds a request | `Source::Api` or `Source::Http`, by `resolvers.request.api` |
| 5 | A command is running | `Source::Cli` |
| 6 | The unit-test runner | `Source::System` |
| 7 | Running in console | `Source::Console` |
| 8 | Nothing else | `Source::System` |

> 📌 **Note.** `Source::Queue` does **not** mean "written under the `queue` performance mode". It
> means Sentinel was settling one of its own entries at the moment of capture. An `$invoice->save()`
> inside your own job is `Source::Job`; the entry it produces keeps `Source::Job` even if a worker
> settles it later, because context is resolved at capture. See
> [Execution context](01-execution-context.md) and
> [Performance modes](../09-operations/01-performance-modes.md).

---

## What a background run resolves on its own

Read this table before deciding what to carry. Most of what a request gives you for free is simply
not there.

| Field | HTTP request | Queue worker | Console command | Scheduled task (in-process) |
|---|---|---|---|---|
| `source` | `http` / `api` | `job` | `cli` | `scheduler` |
| `actor_type`, `actor_id` | from the guard | **null** | **null** | **null** |
| `impersonator_type`, `impersonator_id` | from the session | **null** | **null** | **null** |
| `tenant_id` | from `resolvers.tenant.using` | only if the closure can answer without a request | same | same |
| `request_id` | latched or a fresh ULID | **null** | **null** | **null** |
| `trace_id`, `span_id` | with `telemetry.enabled` | with `telemetry.enabled` and a carried envelope | with `telemetry.enabled` and `telemetry.root_context` | same |
| `context.hostname`, `context.environment` | yes | yes | yes | yes |
| `context.ip`, `user_agent`, `url`, `route`, `method` | yes | **absent** | **absent** | **absent** |
| `context.session_id` | if the route has a session | **absent** | **absent** | **absent** |
| `context.command`, `context.arguments` | absent | absent (see the note below) | yes | `schedule:run` |
| `context.job`, `queue`, `attempts`, `batch_id` | absent | yes | absent | absent |

Everything marked **null** or **absent** is a resolver honestly answering "I could work nothing out",
not a bug. `ActorResolver` asks a guard and the guard says nobody; `RequestResolver`,
`SessionResolver` and `ImpersonatorResolver` all return an empty array on their first gate because
`Runtime::request()` is null.

> 📌 **Note.** A daemon worker (`php artisan queue:work`) calls the framework's own
> `forgetScopedInstances()` **before every job**, which throws away the `Runtime` that was holding
> `queue:work` — so entries written inside a job carry no `context.command`. Under
> `queue:work --once` there is no such reset, and those entries carry `command = 'queue:work'` with
> the worker's own options in `context.arguments`. `source` is `job` either way, because the job
> signal is checked before the command signal.

### What `JobResolver` writes

```php
[
    'job'      => $job->resolveName(),   // 'App\Jobs\CloseInvoices'
    'queue'    => $job->getQueue(),      // 'invoices'
    'attempts' => $job->attempts(),      // 3
    'batch_id' => '9b1…',                // only when the payload carries a string batchId
]
```

All four land inside the `context` JSON — none is a column, none has a query filter.
`context.attempts` is the one worth knowing about: an entry written on a retry is distinguishable
from the entry written on the first try, which is how you tell a genuine second change from the same
change recorded twice.

### What `CommandResolver` writes

```php
[
    'command'   => 'invoices:close',
    'arguments' => ['month' => '2026-08', 'force' => true, 'env' => null, 'quiet' => false, …],
]
```

Four things about `arguments` that surprise people. The first and the last are the resolver's own
doing and are pinned in `tests/Context/CommandResolverTest.php`; the middle two come from the way
Symfony binds console input before the event fires:

| Behaviour | Detail |
|---|---|
| The Symfony `command` key is dropped | `context.command` already names it; keeping both would say it twice |
| Option names arrive **without** the leading dashes | Symfony stores an option as `password`, not `--password` |
| The framework's global options ride along | Console input is bound to the merged definition before `CommandStarting` fires, so `help`, `silent`, `quiet`, `verbose`, `version`, `ansi`, `no-interaction` and Laravel's `env` appear with their defaults |
| A value that is not scalar, null or array is **dropped without trace** | No placeholder, no class name — the key simply is not there |

And the redaction, which happens in the resolver rather than waiting for the
[masking stage](../05-pipeline-and-security/02-protecting-sensitive-data.md):

```php
// config/sentinel.php
'resolvers' => [
    'command' => ['redact' => ['password', 'token', 'secret']],
],
```

Each needle is matched **case-insensitively as a substring of the argument name**, so `token`
catches `api-token` and `refresh_token`. A hit replaces the value — whatever its type, an array
included — with `security.redaction.mask` repeated eight times: `********` with the default mask.
Setting `redact` **replaces** the default list; repeat the three defaults if you still want them.

> 🔒 **Security.** The match is on the argument **name**, never the value. A secret passed as
> `php artisan invoices:close --key=…` is masked only if `key` contains one of the needles — it does
> not contain any of the three defaults, so it lands in the trail in the clear. Add your own names, or pass secrets
> through the environment rather than the command line.

---

## The hole: nobody is authenticated

There is no authenticated user in a worker, a command or the scheduler, so `ActorResolver` returns an
empty array and the entry says nobody did it.

**Sentinel does not invent one.** It does not fall back to the operating-system user, to
`config('app.name')`, or to the last actor it saw. There is no `resolvers.actor.default`. The
package would rather write null than write a name it made up, because an audit trail that guesses is
worse than one that admits it does not know.

That leaves exactly three doors, and this page is about all three:

| Door | Works for | Section |
|---|---|---|
| Carry the actor in Laravel's `Context` and read it in a replacement `ActorResolver` | **model changes** — the only door that reaches them | [below](#carrying-the-actor-into-a-job) |
| `->actor(...)` on a custom event or a state transition | facts you state outright | [below](#the-same-for-a-command) |
| Accept that there is no person, and name the system | scheduled and automated work | [Actor and impersonation](03-actor-and-impersonation.md#when-the-actor-is-a-system) |

> ⚠️ **Warning.** `Sentinel::withContext(['actor_id' => '91'], …)` is **not** a fourth door. Manual
> context can never write a promoted column — the key lands in the `context` JSON and `actor_id`
> stays null — and the bag is a scoped in-process object that does not cross a queue boundary at all.

---

## Carrying the actor into a job

The mechanism is Laravel's own, and Sentinel adds nothing to it.
`Illuminate\Log\Context\ContextServiceProvider` registers a `Queue::createPayloadUsing()` hook that
dehydrates the `Context` repository into every job payload under `illuminate:log:context`, and a
`JobProcessing` listener that hydrates it back in the worker. Anything you put in `Context` before
dispatching is there when the job runs.

Sentinel uses the same road for the trace envelope, which is why it registers no payload hook of its
own — see [Distributed tracing](06-distributed-tracing.md).

### 1. Put the actor in the context where you still know it

```php
use App\Jobs\CloseInvoices;
use Illuminate\Support\Facades\Context;

public function store(Request $request): RedirectResponse
{
    $user = $request->user();

    Context::add('audit.actor_type', $user->getMorphClass());
    Context::add('audit.actor_id', (string) $user->getKey());

    CloseInvoices::dispatch($this->period);

    return back();
}
```

Two strings, not a model. A model put into `Context` is serialised by queueable identity and
**re-queried** on hydration — a database read per job, and a `ModelNotFoundException` path if the
record is gone by then. The two columns want strings anyway.

### 2. Read it in a resolver that still prefers a live guard

```php
namespace App\Sentinel;

use ElPandaPe\Sentinel\Contracts\Resolver;
use Illuminate\Contracts\Auth\Factory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Context;

final readonly class CarriedActorResolver implements Resolver
{
    public function __construct(private Factory $auth) {}

    /**
     * @return array<string, mixed>
     */
    public function resolve(): array
    {
        $user = $this->auth->guard()->user();

        if ($user instanceof Model) {
            return [
                'actor_type' => $user->getMorphClass(),
                'actor_id' => (string) $user->getKey(),
            ];
        }

        $type = Context::get('audit.actor_type');
        $id = Context::get('audit.actor_id');

        return is_string($type) && is_string($id)
            ? ['actor_type' => $type, 'actor_id' => $id]
            : [];
    }
}
```

```php
// config/sentinel.php
'resolvers' => [
    'actor' => ['class' => App\Sentinel\CarriedActorResolver::class],
],
```

The live guard comes first on purpose: in a request the carried value would be stale the moment
somebody else's job payload was replayed, and a resolver that trusts the payload over the session is
a resolver that can be lied to. The `is_string()` checks are not decoration either —
`ContextEngine::column()` drops any promoted value that is not already a string, so an int would
produce a null column and no error.

> 📌 **Note.** The `Context` repository is bound with `scoped()`, and the queue worker forgets scoped
> instances before every job, so one job's carried actor cannot leak into the next. The same reset is
> what stops a memoised host, source or root trace from crossing jobs. See
> [Execution context](01-execution-context.md#what-scoped-means).

> 🧪 **Verify it.** Dispatch one audited job from a request and read the entry back:
> ```shell
> php artisan tinker
> >>> $a = ElPandaPe\Sentinel\Facades\Sentinel::audits()->latest()->take(1)->get()->first();
> >>> [$a->source->value, $a->actor_type, $a->actor_id, $a->context['job'] ?? null];
> ```
> `source` should be `job`, and the actor should be the user who dispatched it.

---

## The same for a command

A command has two doors, and they answer different questions.

### A fact the command states outright

For an event or a transition, name the actor on the builder. This is per-entry and needs no
configuration:

```php
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Facades\Sentinel;
use Illuminate\Console\Command;

final class CloseInvoices extends Command
{
    protected $signature = 'invoices:close {month} {--actor=}';

    public function handle(): int
    {
        Sentinel::event('invoices.closed')
            ->actor('system', $this->option('actor') ?? 'invoices:close')
            ->severity(Severity::Notice)
            ->metadata(['month' => $this->argument('month')])
            ->record();

        return self::SUCCESS;
    }
}
```

`->actor()` is re-applied **after** the pipeline and clears the impersonator columns. It does not
exist on a model change — see
[Actor and impersonation](03-actor-and-impersonation.md#naming-the-actor-yourself).

### An actor for everything the command touches

For the model changes the command makes, the resolver from the previous section is the only door.
Put the actor into `Context` at the top of `handle()` and every entry the run produces — model
changes included — is attributed:

```php
use Illuminate\Support\Facades\Context;

public function handle(): int
{
    $operator = $this->option('actor');

    if ($operator !== null) {
        Context::add('audit.actor_type', 'operator');
        Context::add('audit.actor_id', $operator);
    }

    Invoice::query()->where('period', $this->argument('month'))->each(
        fn (Invoice $invoice) => $invoice->update(['status' => 'closed']),
    );

    return self::SUCCESS;
}
```

> 💡 **Tip.** A console process is **one container scope from the first line to the last** — nothing
> resets it between commands. `source` and `command` are memoised for the life of that scope, so an
> in-process `Artisan::call()` from inside another command reports the **outer** command on every
> entry after the first capture. If a nested step must be distinguishable, record a custom event
> naming it, or push it with `Sentinel::withContext(['step' => …], …)`.

---

## The scheduler

`Source::Scheduler` is narrower than most people expect, and the reason is in Laravel, not in
Sentinel.

| How the task is defined | Where it runs | `source` | `context.command` |
|---|---|---|---|
| `Schedule::call(fn)` | in-process, inside `schedule:run` | `scheduler` | `schedule:run` |
| `Schedule::command('invoices:close')` | a **fresh artisan subprocess** | `cli` | `invoices:close` |
| `Schedule::command(…)->runInBackground()` | a fresh detached subprocess | `cli` | `invoices:close` |
| `Schedule::job(new CloseInvoices)` | a queue worker, later | `job` | absent |

`Illuminate\Console\Scheduling\Event::execute()` runs a scheduled command through
`Process::fromShellCommandline()` — a new PHP process, with no marker saying who started it. In that
child, `ScheduledTaskStarting` never fires and the `Runtime` sees only a command, so gate 3 of the
source table is skipped and gate 5 answers `cli`. What the resolver *does* recognise is the parent
process: the scheduler latch, plus the three command names `schedule:run`, `schedule:work` and
`schedule:finish`.

> ⚠️ **Warning.** Do not build alerting on `whereSource(Source::Scheduler)` if your schedule is made
> of `Schedule::command()` calls. Those entries are `cli` and are indistinguishable from somebody
> running the same command by hand. Distinguish them yourself — an `->actor('system', 'scheduler')`
> on a custom event, or a `Context` value set in an `->before()` callback.

### What the scheduler *does* carry into the child

The one thing that crosses is Laravel's `Context`. `Event::execute()` passes it to the subprocess in
the `__LARAVEL_CONTEXT` environment variable, and `ContextServiceProvider` hydrates it back when a
`Repository` is resolved in a console process. So the resolver from
[Carrying the actor into a job](#carrying-the-actor-into-a-job) works for a scheduled command too —
set the value in the parent, read it in the child:

```php
// routes/console.php
Schedule::command('invoices:close', ['2026-08'])
    ->dailyAt('02:00')
    ->before(fn () => Context::add('audit.actor_id', 'scheduler'));
```

### Grouping one run's entries

With `telemetry.root_context` on, a run nobody traced opens one trace of its own, memoised on the
execution scope, so every entry of that console run or scheduled task shares a `trace_id` — which
*is* indexed and *does* have a filter. It is the cheapest way to ask "everything the 02:00 run
touched". See [Distributed tracing](06-distributed-tracing.md).

---

## Querying what the system did to itself

```php
use ElPandaPe\Sentinel\Enums\Source;
use ElPandaPe\Sentinel\Facades\Sentinel;

// Everything a worker wrote for one customer
Sentinel::audits()->for($customer)->whereSource(Source::Job)->take(50)->get();

// Everything the nightly close attributed to itself
Sentinel::audits()->by('system', 'invoices:close')->take(50)->get();

// One scheduled run, if telemetry.root_context is on
Sentinel::audits()->withTrace($traceId)->take(200)->get();
```

Three things govern how you write these:

| Filter | Index | Use it |
|---|---|---|
| `by()` | `(actor_type, actor_id, id)` | as the leading filter — this is why naming a system actor pays off |
| `withTrace()` | `trace_id` | as the leading filter |
| `whereSource()` | **none** | only behind one of the above, or `for()`, `forTenant()`, `inTransaction()` |

`whereSource()` is a **refiner**: `Support\AuditSchema::indexes()` creates nine indexes and `source`
is not among them, so on MySQL or PostgreSQL it walks the table when it stands alone. The
`context.job`, `context.queue` and `context.command` keys have no filter at all — reach for them on
the model, or narrow with an indexed filter first and inspect the results in PHP. See
[Filters reference](../06-reading/02-filters-reference.md).

> 📌 **Note.** `Source::Import` is the one case no resolver ever produces. It is written by
> `sentinel:import` and by nothing else, and it means the entry did not happen in this application at
> all — another package recorded it and this one copied it in. See [Enums](../99-reference/04-enums.md).

---

## Catching unattributed entries early

The failure mode is quiet: everything works, nothing throws, and six months later every background
entry in the trail says nobody did it. Find it on day one.

### Count them

There is no published filter for "the actor is null", so query the model directly:

```php
use ElPandaPe\Sentinel\Models\Audit;

Audit::query()
    ->whereNull('actor_id')
    ->selectRaw('source, count(*) as entries')
    ->groupBy('source')
    ->pluck('entries', 'source');

// ['job' => 4821, 'cli' => 96, 'http' => 3]
```

A few `http` rows are ordinary — an unauthenticated request, a failed login that named nobody. A
large `job` or `cli` count is the symptom of this page.

### Pin it with a test

```php
use App\Jobs\CloseInvoices;
use App\Models\User;
use ElPandaPe\Sentinel\Enums\Source;
use ElPandaPe\Sentinel\Models\Audit;
use Illuminate\Support\Facades\Context;

it('attributes an entry written inside a job to whoever dispatched it', function (): void {
    $user = User::factory()->create();

    Context::add('audit.actor_type', $user->getMorphClass());
    Context::add('audit.actor_id', (string) $user->getKey());

    (new CloseInvoices('2026-08'))->handle();

    $audit = Audit::query()->latest('created_at')->firstOrFail();

    expect($audit->actor_id)->toBe((string) $user->getKey())
        ->and($audit->source)->toBe(Source::System);
});
```

> 📌 **Note.** Calling `handle()` directly does not fire `JobProcessing`, so the `Runtime` holds no
> job and `source` is whatever the test runner resolves — `Source::System` under the unit-test
> runner, because `runningUnitTests()` is checked before `runningInConsole()`. To assert on the
> source itself, drive the latch: `app(Runtime::class)->enteredJob($job)` before the capture.

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| Every entry written by a worker has a null actor | No authenticated user in a worker, and Sentinel invents none | Carry the actor in `Context` and read it in a replacement `ActorResolver` |
| The carried actor works for custom events but not for `$model->update()` | `->actor()` exists only on `PendingEvent` and `TransitionBuilder`; a model capture takes no actor argument | The resolver is the only door for model changes |
| `Sentinel::withContext(['actor_id' => …])` before `dispatch()` changes nothing | Manual context cannot reach a promoted column, and the bag does not cross a queue boundary | Use Laravel's `Context` plus a resolver |
| A scheduled task reports `source = cli` | `Schedule::command()` runs in a fresh artisan subprocess where `ScheduledTaskStarting` never fires | Expected. Mark the run yourself with an actor or a `Context` value in `->before()` |
| Entries from a worker carry no `context.command`, but `queue:work --once` entries do | A daemon worker forgets scoped instances before each job and discards the `Runtime` holding the worker's own command; `--once` does not | Cosmetic. `source` is `job` in both cases |
| Every entry of a long artisan run names the same command, even after `Artisan::call()` | `source` and `command` are memoised for the life of a scope, and a console process is one scope | Record a custom event naming the nested step, or push it with `withContext()` |
| `context.arguments` is full of keys nobody passed (`quiet`, `ansi`, `env`, …) | Console input is bound to the merged definition before `CommandStarting` fires, so global options arrive with their defaults | Expected. Name what you want protected in `resolvers.command.redact` |
| A secret passed as `--key=…` is in the trail in plain text | The redaction needles are `password`, `token`, `secret` and match the argument **name**; `key` contains none of them | Add `key` to `resolvers.command.redact`, or pass secrets through the environment |
| Setting `resolvers.command.redact` stopped masking `--password` | The configured list **replaces** the default list, it does not extend it | Repeat the three defaults alongside your own needles |
| An object passed as a command argument is missing from `context.arguments` | Only scalar, null and array values survive; anything else is dropped with no placeholder | Pass a scalar, or record what you need in `metadata` |
| `whereSource(Source::Job)` got slow as the table grew | No index covers `source` | Put `by()`, `for()`, `forTenant()`, `withTrace()` or `inTransaction()` in front of it |
| Entries from a job have no `tenant_id` although the site is multi-tenant | `resolvers.tenant.using` reads a tenant context that a worker does not have | Carry the tenant key in `Context` too, and read it in the closure — and see [Multi-tenancy](04-multi-tenancy.md) for what that does to the chain |
| A model put in `Context` throws `ModelNotFoundException` in the worker | Context serialises models by queueable identity and re-queries on hydration | Carry the morph alias and the key as strings |

---

## ✅ Best practices

✅ **Do** — carry the actor as two strings in Laravel's own `Context`, at the point where you still
know who it is.

```php
Context::add('audit.actor_type', $user->getMorphClass());
Context::add('audit.actor_id', (string) $user->getKey());

CloseInvoices::dispatch($period);
```

❌ **Don't** — carry the model. Context serialises it by queueable identity, so every job hydration
is an extra query and a missing record becomes an exception on a path you were not thinking about.

```php
Context::add('audit.actor', $user);   // re-queried in the worker, and it may be gone
```

✅ **Do** — prefer a live guard over the carried value inside the resolver. The payload is data that
arrived from elsewhere; the session is the process you are in.

```php
$user = $this->auth->guard()->user();

if ($user instanceof Model) {
    return ['actor_type' => $user->getMorphClass(), 'actor_id' => (string) $user->getKey()];
}
```

❌ **Don't** — read the carried value first. A replayed or hand-crafted payload then decides who a
request's entries are attributed to.

```php
return ['actor_type' => Context::get('audit.actor_type'), 'actor_id' => Context::get('audit.actor_id')];
```

✅ **Do** — give automated work a system identity rather than leaving it blank, and pick a value a
query can use.

```php
Sentinel::event('invoices.closed')
    ->actor('system', 'invoices:close')
    ->metadata(['month' => '2026-08'])
    ->record();

Sentinel::audits()->by('system', 'invoices:close')->take(50)->get();   // rides the actor index
```

❌ **Don't** — reconstruct "what the nightly run did" from `whereSource()` alone. It is a refiner
with no index behind it, and `cli` cannot tell a scheduled run from a developer at a terminal.

```php
Sentinel::audits()->whereSource(Source::Cli)->take(500)->get();   // walks the table, answers vaguely
```

✅ **Do** — add your own secret argument names to `resolvers.command.redact`, and repeat the defaults
you still want.

```php
'resolvers' => [
    'command' => ['redact' => ['password', 'token', 'secret', 'key', 'pin', 'dsn']],
],
```

❌ **Don't** — assume the default three cover your commands. The match is a substring of the
**name**, so a value passed under any other name reaches the ledger unmasked — and the ledger is
append-only, so getting it out again means a
[redaction](../08-lifecycle/04-redaction-and-tombstones.md).

```php
// artisan billing:sync --api-credential=live_sk_…      → stored verbatim
```

✅ **Do** — count your null-actor entries per source as soon as background auditing goes live, and
keep the check in CI.

```php
Audit::query()->whereNull('actor_id')->selectRaw('source, count(*) as entries')->groupBy('source')->get();
```

❌ **Don't** — wait for an auditor to ask who ran the job. The columns are inside the canonical
payload, so backfilling them later breaks the hash of every row touched and everything chained after
it.

```sql
UPDATE sentinel_audits SET actor_id = 'system' WHERE source = 'job';   -- breaks the chain
```

✅ **Do** — turn on `telemetry.root_context` when you want one console run's entries grouped. The
`trace_id` column is indexed and has a published filter, unlike anything in `context`.

```php
'telemetry' => ['enabled' => true, 'root_context' => true],
```

❌ **Don't** — reach for `context.job` or `context.command` as a grouping key. Neither is indexed and
neither has a filter; you would be scanning JSON to answer a question the trace id answers on an
index.

---

**See also:** [Execution context](01-execution-context.md) · [The ten resolvers](02-resolvers-reference.md) · [Actor and impersonation](03-actor-and-impersonation.md) · [Distributed tracing](06-distributed-tracing.md) · [Writing your own resolver](07-writing-your-own-resolver.md) · [Running audits on a queue](../09-operations/03-queues.md) · [Scheduling](../09-operations/07-scheduling.md) · [Filters reference](../06-reading/02-filters-reference.md)
