# Sentinel

> Ledger-first audit & integrity engine for Laravel.
> **Know what happened. Know who did it. Prove the record.**

[![Version](https://img.shields.io/badge/version-v0.20.0-blue)](https://github.com/elpandape/sentinel/releases)
[![PHP](https://img.shields.io/badge/php-8.4%2B-777bb4)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/laravel-13-ff2d20)](https://laravel.com/)
[![Coverage](https://img.shields.io/badge/coverage-100%25-brightgreen)](#development)
[![PHPStan](https://img.shields.io/badge/phpstan-max-brightgreen)](#development)

Sentinel is not an activity log. Its unit is the **audit record**: an append-only entry that answers
what changed, who changed it, on whose behalf, from where, inside which business transaction, what
the state was before, what it is now — and whether the record itself can be proven untampered.

> **Status: alpha (0.x).** The public API may change between minor versions until `v1.0.0`.
> Not yet on Packagist — install from the repository while the 0.x cycle runs.

## Installation

```json
"repositories": [{ "type": "vcs", "url": "https://github.com/elpandape/sentinel" }],
"require": { "elpandape/sentinel": "v0.20.0" }
```

```bash
php artisan vendor:publish --tag=sentinel-config
```

## What's available

| Version | Feature |
|---|---|
| `v0.1.0` | Configuration, execution context, facade, enums, quality toolchain |
| `v0.2.0` | `sentinel_audits` schema, `Audit` model, `AuditData`, package contracts, factory |
| `v0.3.0` | `DatabaseLedger`, `NullLedger`, the hash chain, verification, immutability guards |
| `v0.4.0` | Model auditing, full snapshots, the `Auditable` trait, the write-path baseline |
| `v0.5.0` | Structured diffs, `$audit->diff()`, JSON Patch import and export |
| `v0.6.0` | Resolved context: actor, impersonator, tenant, request, trace, source, host, job, command |
| `v0.7.0` | The write pipeline, field-level redaction, hashing and encryption, key rotation, discards |
| `v0.8.0` | `MemoryLedger`, `FanoutLedger`, the published contract suite, `append()` |
| `v0.9.0` | The Query API: `Sentinel::audits()`, nine filters, ordering and paging over the ledger contract |
| `v0.10.0` | Labels, field history, the timeline, comparing two versions, and a readable presenter |
| `v0.11.0` | Relationship auditing: the six pivot operations, the relation projection, three filters and the `+ / -` render |
| `v0.11.1` | The parent side of a `belongsTo`: a child that changes hands leaves an entry on the parent it left and the parent it joined |
| `v0.12.0` | Business transactions: `Sentinel::transaction()`, the `sentinel_transactions` header, and entries that wait for the commit |
| `v0.12.1` | Custom events and authentication events: `Sentinel::event()` and an opt-in subscriber over the five auth events |
| `v0.13.0` | State transitions: `Sentinel::transition()`, `$auditTransitions`, an optional state machine, `whereType()` and the lifeline with time spent in each state |
| `v0.14.0` | The Restore Engine: `$audit->restore()`, granular and relation restores, `RestoreResult`, and two cancellable lifecycle events |
| `v0.15.0` | The lifecycle events, a write-failure policy, and `toArray()` frozen as a public contract |
| `v0.16.0` | Performance modes: the dispatcher, the `queue` mode with a job of its own, `capture_id` and idempotent settlement, and `Audited` |
| `v0.16.1` | The `buffered` mode: a Redis buffer with its own contract, batched settlement, four flush triggers and `sentinel:flush` |
| `v0.17.0` | Mass operations: `auditing()` on the query builder, three modes, the criteria recorded without its values, and `upserted` |
| `v0.18.0` | Signatures over the hash, a key ring that rotates and retires, verification of the whole trail, and `sentinel:verify` with its exit codes |
| `v0.18.1` | Checkpoints: a signed root over a fixed window, a chain of anchors, two shallower verification depths and `sentinel:checkpoint` |
| `v0.19.0` | Retention by logical type, `sentinel:prune` with a dry run, and a verification that tells a range you retired from an entry that went missing |
| `v0.19.1` | Cold archiving: NDJSON batches on any `Storage` disk, read back and rehashed before a row is removed, and `--action=archive` as the default |
| `v0.19.2` | Rehydration: a batch goes back into the table exactly as it left, headers included, and `append()` keeps the counter it was silently leaving behind |
| `v0.19.3` | Tombstones: an entry whose contents are destroyed while its position, its hash and its link stay, a third content state, and `sentinel:redact` |
| `v0.19.4` | Redaction that reaches the archive: a batch is rewritten with the entry emptied, and a range that was archived refuses to be redacted in place only |
| `v0.19.5` | Compliance mode with teeth: it refuses to boot without signatures and anchors, a redaction has to name who ordered it, every read leaves an entry and a row, plus `sentinel:export` and `sentinel:rekey` |
| `v0.20.0` | Scale: `whereIp()` and `whereRoute()` with the index that serves them, three partitioned alternatives to the base migration, and `sentinel:partitions` |

Everything else is on the roadmap: distributed tracing, and a way in from other packages.

`v0.4.0` is the version that starts auditing: a model with the trait writes its own chained entries.
`v0.5.0` is the one that answers what changed, instead of leaving you two states to compare.
`v0.6.0` is the one that answers who did it and from where, instead of every entry claiming it came
from nowhere. `v0.7.0` is the one that stops the answer from being a leak: no declared value reaches
the ledger in the clear, and nothing writes an entry that says nothing happened. `v0.8.0` is the one
that makes "extensible by drivers" a thing you can run rather than a thing this file claims. `v0.9.0`
is the one that lets you ask, which a trail you cannot read back is not. `v0.10.0` is the one that
answers the three questions a trail gets asked most — what happened to this field, what is this
entry about, and what happened at all — and says them in a sentence a person can read. `v0.11.0`
is the one that records what Eloquent never announces: a pivot table changing under you. `v0.11.1`
finishes that thought for the relations that have no pivot at all. `v0.12.0` is the one that stops
the trail from being a pile of entries that happen to share a request: an operation gets a name, and
its entries stop existing when the transaction that produced them does not. `v0.12.1` is the one
that stops "auditable" from meaning "a model changed" — a fact you state and a login you did not
are entries like any other. `v0.13.0` is the one that stops a document's lifeline from being
something you reconstruct: `draft → pending → approved → paid` is a read, with how long it spent
in each. `v0.14.0` is the one that makes the trail something you can act on and not only read: a
row of it puts the record back the way it found it, and says what it could not. `v0.15.0` is the one
that lets something else react to all of it without reaching inside, and that stops the serialised
entry from being a shape that can move under you. `v0.16.0` is the one that stops the trail from
costing the request the whole write: where an entry settles becomes a setting, and a fact captured
here and settled somewhere else is still settled exactly once. `v0.16.1` finishes that thought with
the mode that batches, and writes down exactly what it can lose and what it cannot. `v0.17.0` closes
the blind spot every auditing package in this ecosystem documents as a limitation: a mass update, a
mass delete and an upsert can be audited by asking for it on the query, and a query that does not
ask goes on costing nothing. `v0.18.0` is the one that makes the trail provable to someone who does
not trust the database it lives in. `v0.18.1` is the one that stops proving it from costing a read
of everything — and is careful to say which of its three walks proves what, because an anchor
covering a range is not the same claim as having read it. `v0.19.0` through `v0.19.5` are the ones
that stop the trail from growing without a ceiling and make what leaves it provable on the way out.
`v0.20.0` is the one that stops the answer to "will this still work at ten million entries" from
being a shrug: the two filters that had no index get one, the table can be divided, and what each of
those costs is a number in this file rather than a promise.

## Quick start

```php
use ElPandaPe\Sentinel\Concerns\Auditable;

class Invoice extends Model
{
    use Auditable;
}
```

That is the whole setup — no interface to implement, no observer to register. Every `created`,
`updated`, `deleted`, `restored` and `forceDeleted` on that model writes a chained entry carrying
the full state before and after:

```php
$invoice->update(['status' => 'paid']);

$invoice->latestAudit()->before['status'];  // 'pending'
$invoice->latestAudit()->after['status'];   // 'paid'
$invoice->audits();                          // the whole trail, oldest first
```

Auditing pauses on demand, and what happens while it is paused leaves no entry:

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

Sentinel::withoutAuditing(fn () => $importer->run());

Sentinel::withContext(['reason' => 'Approved by finance'], function () {
    $invoice->approve();
});
```

## What gets audited

Five kinds of entry, from four eloquent events — a force delete fires `deleted` on its way to
`forceDeleted`, and a restore is the update that clears the deletion mark:

| Entry | Written when | `before` | `after` |
|---|---|---|---|
| `created` | The record is inserted | `null` | The full state |
| `updated` | Any attribute changed | The state before | The state after |
| `deleted` | `delete()`, soft or hard | The state leaving | `null` |
| `restored` | `restore()` on a soft deleting model | The state in the bin | The state restored |
| `force_deleted` | `forceDelete()` | The last known state | `null` |

`restored` and `force_deleted` only exist if the model uses `SoftDeletes`; the trait neither
requires it nor adds it. A soft delete reads like a hard one from the trail's point of view: the
subject leaves the visible set, and the instant is already in `occurred_at`.

Every attribute is audited unless the model says otherwise:

```php
class Invoice extends Model
{
    use Auditable;

    protected array $auditInclude = ['status', 'total'];   // a whitelist; wins outright
    protected array $auditExclude = ['internal_notes'];    // ignored when $auditInclude is set

    protected Severity $auditSeverity = Severity::Critical; // beats the configured default
    protected bool $auditSnapshots = false;                 // entry without payload, still chained
}
```

Attributes in `$hidden` **are audited** by default — auditing is what the package is for. One key
drops them everywhere:

```php
// config/sentinel.php
'snapshots' => [
    'enabled' => true,
    'include_hidden' => false,
],
```

> **There is no redaction and no encryption yet.** Until `v0.7.0`, `$auditExclude` is the only lever,
> and an entry is immutable once written: a password hash or a token that reaches `before`/`after`
> stays there. Exclude those columns now.

> **Context is not resolved yet.** Until `v0.6.0` every entry carries `source = system` and no actor,
> impersonator, tenant or trace.

## Snapshots & diffs

Each entry stores the **complete** state, not just the dirty attributes: an entry has to be readable
without the original row in front of you. The model's own casts apply — a date serializes with its
microseconds, a backed enum as its value, a value object through the `toArray()` or `__toString()`
it declares — so a new cast in your application never asks for a change in the package.

`null` and `{}` are different answers and are kept different: `null` means the state does not apply
to that event, an empty map means the state applied and was empty.

Key order is normalized and lists stay lists, which is what makes the column come back from MySQL,
PostgreSQL or SQLite with the shape it went in with. The chain hashes the structure, never the text
the engine happened to store.

For a wide table where two copies per write do not pay for themselves:

```php
protected bool $auditSnapshots = false;
```

The entry is still written, still chained and still verifies, and it carries the diff: the two states
are dropped, the change is not. The comparison runs either way, so the flag saves storage, not the
microseconds it takes to compare.

`version` is a counter per subject, assigned by the ledger in the same operation that assigns
`sequence`, so you can talk about *v3 of Invoice #500* without counting rows at query time.

### Diffs

An entry says what changed. You do not compare two JSON blobs by hand:

```php
$audit->diff();                      // ElPandaPe\Sentinel\Diff\Diff — countable and iterable
$audit->diffFor('profile.address');  // the same, narrowed to a subtree
```

```php
[
    ['path' => '/profile/address/city', 'op' => 'replace', 'old' => 'Lima', 'new' => 'Arequipa'],
    ['path' => '/roles/1',              'op' => 'remove',  'old' => 'editor', 'new' => null],
]
```

`path` is an RFC 6901 JSON Pointer, so a key holding a `.` or a `/` is still unambiguous.
`diffFor()` accepts dot notation as well, for the common case where no key contains a dot; when one
does, the literal pointer is the form that always means what it says.

`old` travels next to `new` because the previous value is the point of an audit, and RFC 6902 has no
place for it. Interoperability is offered as an export instead:

```php
$audit->diff()->toJsonPatch();   // strict RFC 6902, with a `test` guarding what it overwrites
Diff::fromJsonPatch($patch);     // and back — without the tests, `old` is absent, not null
```

Lists are matched by identity when every element carries a unique `id` or `uuid`, and by position
when they do not. Inserting in the middle of an identified list is one addition, not *everything
changed from here down*, and reordering one is no change at all.

`created` produces only additions, `deleted` only removals, and an update that changed nothing
writes `[]` — an empty list means the comparison ran and found nothing, `null` means the event had
nothing to compare. Entries written before this version keep `changes = null` and are never
rewritten: `$audit->diff()` computes theirs on read from the states they stored.

> **The diff duplicates sensitive data.** What lives in `before` and `after` now also lives in
> `changes`. Redaction and encryption land in `v0.7.0`; until then `$auditExclude` is the only lever.

The entries keep the order the comparison emitted them in. The keys *inside* an entry do not survive
a round trip through MySQL or PostgreSQL — both reorder the keys of a JSON object — and nothing
depends on them: entries are read by key, and the chain hashes the canonical form.

`Diff` knows nothing about eloquent or about the rest of the package — `Diff::between($a, $b)` works
on any two structures, inside an audit or outside one.

## Context & resolvers

Every entry resolves its own circumstances while it is being built — before it reaches the ledger, so
the context is the one of the moment audited. Ten resolvers do it, each replaceable one at a time:

| Resolver | Fills |
|---|---|
| `ActorResolver` | `actor_type`, `actor_id` from the configured guard |
| `ImpersonatorResolver` | `impersonator_type`, `impersonator_id` |
| `TenantResolver` | `tenant_id` |
| `RequestResolver` | `request_id`, and `ip`, `user_agent`, `url`, `route`, `method` in `context` |
| `SessionResolver` | `context.session_id` |
| `TraceResolver` | `trace_id`, `span_id` from an incoming `traceparent` |
| `SourceResolver` | `source` |
| `HostResolver` | `context.hostname`, `context.environment` |
| `JobResolver` | `context.job`, `queue`, `attempts`, `batch_id` |
| `CommandResolver` | `context.command` and its arguments, redacted |

Nine names are **promoted columns** — `actor_type`, `actor_id`, `impersonator_type`,
`impersonator_id`, `tenant_id`, `request_id`, `trace_id`, `span_id` and `source`. A resolver
returning one of them fills the column; every other key it returns lands inside the `context` JSON.
That is the whole mapping rule.

### Where an entry came from

`source` is not a default and not a guess by elimination. Each value is produced by a signal the
framework itself emits, and the table is read top to bottom:

| `source` | Signal |
|---|---|
| `queue` | Sentinel is writing the entry from its own job |
| `job` | A queued job of the application is in flight |
| `scheduler` | A scheduled task is running, or the command is `schedule:run`, `schedule:work` or `schedule:finish` |
| `api` | A request reached the router and the boundary recognises it as the api |
| `http` | A request reached the router and the boundary does not |
| `cli` | An artisan command is running |
| `console` | A console process with no command: a REPL, a boot script |
| `system` | No signal applies |

The `http`/`api` boundary is a route pattern by default, `api/*`, and takes a closure instead:

```php
// config/sentinel.php
'resolvers' => [
    'request' => ['api' => fn (Request $request): bool => $request->hasHeader('X-Api-Key')],
],
```

### Replacing a resolver

Every entry takes a `class`, and `null` means the one the package ships:

```php
'resolvers' => [
    'tenant' => ['class' => App\Sentinel\SpatieTenantResolver::class],
],
```

A resolver implements one method and returns whatever it could resolve, or an empty array:

```php
use ElPandaPe\Sentinel\Contracts\Resolver;

final class SpatieTenantResolver implements Resolver
{
    public function resolve(): array
    {
        return Tenant::current() === null ? [] : ['tenant_id' => (string) Tenant::current()->id];
    }
}
```

For the common case a closure is enough and needs no class at all:

```php
'resolvers' => [
    'tenant' => ['using' => fn (): ?string => Tenant::current()?->id],
],
```

> **Activating a tenant partitions the chain.** `integrity.stream` ships as `tenant`, which behaves
> exactly like `global` until a tenant actually resolves. The moment one does, entries move to a
> `tenant:<id>` stream of their own, with `sequence` restarting at `1`. Existing chains keep
> verifying with their own genealogy — nothing is rewritten — but they stop growing. Set
> `integrity.stream` explicitly before wiring a tenant if you do not want that partition.

### Pushing your own context

`Sentinel::withContext()` adds to the `context` payload for the duration of a callback:

```php
Sentinel::withContext(['reason' => 'Approved by finance'], function () use ($invoice) {
    $invoice->update(['status' => 'paid']);
});
```

Manual context merges **over** the resolved payload and can never reach a promoted column: pushing
an `actor_id` by hand puts it in the payload, not in the column. To change who acted, replace
`ActorResolver`.

### Correlating a request

`request_id` is generated per request and is the same on every entry that request writes. To honour
an identifier a gateway already assigned — and hand it back in the response — add the middleware. It
is opt-in and registered in no group:

```php
// bootstrap/app.php
$middleware->append(ElPandaPe\Sentinel\Http\Middleware\AssignRequestId::class);
```

The header is `X-Request-Id` by default and configurable at `resolvers.request.header`. An incoming
value is honoured when it is printable and fits the 64-character column; otherwise one is generated.

> **Context carries sensitive data**, and since `v0.7.0` there is a mechanism for it. Addresses, user
> agents, urls, session identifiers and command arguments sit in `context`; name the keys you do not
> want readable in `security.redaction.fields` or `security.hashing.fields`. `CommandResolver` keeps
> its own list, `resolvers.command.redact` — `password`, `token` and `secret` by default — for the
> arguments of a console command. See [Protecting sensitive data](#protecting-sensitive-data).

## The write pipeline

Every entry travels **capture → pipeline → ledger**. The pipeline is an ordered list of stages, and
the list is configuration — not a set of flags:

```php
// config/sentinel.php
'pipeline' => [
    ElPandaPe\Sentinel\Pipeline\Stages\FilterUnchanged::class,
    ElPandaPe\Sentinel\Pipeline\Stages\ResolveContext::class,
    ElPandaPe\Sentinel\Pipeline\Stages\NormalizeData::class,
    ElPandaPe\Sentinel\Pipeline\Stages\MaskSensitiveData::class,
    ElPandaPe\Sentinel\Pipeline\Stages\EncryptSensitiveData::class,
    ElPandaPe\Sentinel\Pipeline\Stages\EnforcePolicies::class,
],
```

| Stage | What it does |
|---|---|
| `FilterUnchanged` | Drops an update whose comparison found nothing |
| `ResolveContext` | Runs the context engine of `v0.6.0` |
| `NormalizeData` | Sorts the keys of every stored container, all the way down |
| `MaskSensitiveData` | Applies `$auditRedact` (a mask) and `$auditHash` (a digest) |
| `EncryptSensitiveData` | Applies `$auditEncrypt` and fills the `encryption` column |
| `EnforcePolicies` | Gives your own policies the last word on whether the entry settles |

Reorder them, replace one, or slot your own in between. A stage implements one method:

```php
use Closure;
use ElPandaPe\Sentinel\Contracts\Transformer;
use ElPandaPe\Sentinel\Data\AuditData;

final class TagWithRelease implements Transformer
{
    public function handle(AuditData $audit, Closure $next): ?AuditData
    {
        $audit->metadata = [...$audit->metadata ?? [], 'release' => config('app.release')];

        return $next($audit);
    }
}
```

**An empty list means the shipped order**, not an empty pipeline. The config published before this
version ships `'pipeline' => []` and the merge is shallow, so treating that as "no stages" would
leave those installations transforming nothing and saying nothing about it. To drop a stage, declare
the list without it.

The pipeline runs **during the capture**, inside the request — never behind the queue or the buffer.
That is deliberate and every [performance mode](#performance-modes) keeps it: whatever gets queued
has already been transformed.

### Discarding an entry

A stage returns `null` to discard, and gives a reason so the event can carry one:

```php
public function handle(AuditData $audit, Closure $next): ?AuditData
{
    if ($audit->severity === Severity::Info) {
        app(ElPandaPe\Sentinel\Pipeline\Discard::class)->because('info entries are not kept');

        return null;
    }

    return $next($audit);
}
```

`Events\AuditDiscarded` is dispatched with the subject, the event, the stage and the reason — and
**nothing else**. `FilterUnchanged` runs before redaction and encryption, so an event carrying the
payload would be the exact route by which the plaintext escaped the pipeline that exists to
transform it.

A discard costs no `sequence`, so the chain the entry never joined has no gap in it. Discarding
after the ledger has assigned one is a usage error and throws: the chain admits no holes, and
pretending otherwise would make `verifyIntegrity()` lie.

For a policy you do not want to write a stage for:

```php
Sentinel::filter(fn (AuditData $audit): bool => $audit->subject_type !== Session::class);
```

And for one you would rather put in a listener than in the config file, there is the `Auditing`
event at the end of the pass — see [Events](#events).

### Updates that changed nothing

An `updated` whose diff came back empty writes no entry. It happens more than you would think — a
`touch()`, or a column that moved but is excluded from the snapshot — and the ecosystem generally
leaves those rows in the table.

```php
$post->update(['view_count' => $post->view_count]);   // ✅ no entry, no sequence, no gap
$post->update(['internal_flag' => true]);             // ✅ no entry if internal_flag is excluded
$post->update(['title' => 'A new title']);            // ✅ entry written
```

If you want those entries, take `FilterUnchanged` out of the list. Only updates are filtered: a
creation with no comparable fields still happened, and a restore whose one moved column is not
audited is still a restore.

## Performance modes

**Where an entry settles is one setting, and nothing else in your application changes.**

```php
'mode' => env('SENTINEL_MODE', 'sync'),
```

| Mode | Route | Request pays | Durability | What you can lose | Order of `created_at` |
|---|---|---|---|---|---|
| `sync` | capture → pipeline → ledger | The whole write | Maximum: the entry exists before the call returns | Nothing the database kept | The order things happened |
| `queue` | capture → pipeline → job → ledger | The pipeline and one enqueue | The queue's: a job that fails is retried, and what never lands is in `failed_jobs` | Nothing the queue kept | The order entries **settled** |
| `buffered` | capture → pipeline → buffer → flush | The pipeline and one buffer write | Least: the buffer is not a durable store | Everything a process dies holding | The order entries settled |

**The pipeline always runs in the request.** Filters, redaction, hashing, encryption and your own
policies happen where the capture happened, in every mode. Nothing sensitive ever waits
untransformed, and the job carries an entry that has already been through all of it — never a model
the worker would have to re-read.

### What the modes actually cost

One create per iteration, a thousand iterations after two hundred warm-up writes, median of three
passes, SQLite on the same machine and in the same run:

| | Per write (µs) | vs. `sync` |
|---|---|---|
| Not audited | 179 | — |
| `sync`, in the request | 2068 | — |
| `queue`, what the request pays | 1077 | **−48 %** |
| `queue`, what the worker pays to settle one | 1161 | — |
| `buffered`, what the request pays | 1194 | **−42 %** |
| `buffered`, what the flush pays per entry | 655 | — |

**Both asynchronous modes roughly halve what the request pays**, and they differ in what happens
after. `queue` settles one entry per job, so the total comes to about eight per cent more than
`sync` — deferring moves work, it does not remove it. `buffered` settles in batches, and the batch
amortises what a single write cannot share: one tail read, one transaction and one sequence
assignment for five hundred entries. At about eleven per cent less than `sync` in total, it is the
only mode that is cheaper end to end — and the only one that can lose an entry.

Turning snapshots off changes little either way. The snapshot was never the dominant cost.

Run `make bench` to reproduce it against your own schema. The numbers above put `sync` first in the
pass, so the queued figure is measured against a larger table than the one it is compared with.

### The buffered mode, and what it can lose

```php
'mode' => env('SENTINEL_MODE', 'buffered'),

'buffer' => [
    'store' => 'redis',        // or memory — a reference implementation, never a store
    'connection' => null,      // null uses the application's default Redis connection
    'key' => 'sentinel:buffer',
    'size' => 500,             // flush when this many are waiting
    'flush_interval' => 60,    // or when the oldest has waited this long, in seconds
],
```

**What a process dies holding is gone.** Those entries never reached the ledger: they have no
sequence, no hash, and no place in any chain. That is the trade, and the two thresholds are what
bounds it — nothing else about the mode is negotiable.

Everything else is not a loss:

- A batch the ledger refused goes **back into the buffer**, at the head, in order. A database that
  was briefly unreachable costs you nothing but a retry on the next trigger.
- A flush that runs twice settles once. Taking from the buffer is atomic and every entry carries a
  `capture_id` the database will not accept twice, so a scheduled flush racing the one at the end of
  a request is safe rather than merely unlikely.

**Four things vacate the buffer**, and only the first two are thresholds:

| | When |
|---|---|
| `buffer.size` | An entry arrives and that many are waiting |
| `buffer.flush_interval` | An entry arrives and the oldest has waited longer than that |
| End of the request | `terminating`, after the response has gone out |
| Worker shutdown | `WorkerStopping` — a worker never passes through `terminating` between jobs |

```bash
php artisan sentinel:flush     # and the fifth, on demand
```

> **The thresholds are read when an entry arrives.** Nothing inside PHP is watching a clock between
> requests, so a buffer that stops receiving entries stops being evaluated. What bounds a quiet one
> is the flush at the end of the request, the one at worker shutdown, and the command — schedule it
> if you need a ceiling on how long anything waits.

**The chain cannot tell you what the buffer lost.** An entry that never reached the ledger consumed
no sequence, so it leaves no gap: `verifyIntegrity()` walks a shorter chain and reports it intact,
correctly. The chain proves that what settled was not tampered with, never that everything that
happened settled. If you need to detect loss, count what you handed over against what landed — the
exit code and the count from `sentinel:flush` are there for that. If you cannot accept that, use
`sync` or `queue`.

### `created_at` stops being the order things happened in

This is the one thing to check before switching. The trail records two clocks and they stop agreeing
the moment an entry settles somewhere other than where it was captured:

- **`occurred_at`** is when the fact happened, stamped at capture. It never moves.
- **`created_at`** and `sequence` are when the entry settled, stamped in the ledger.

```php
Sentinel::timeline();                      // ✅ ordered by occurred_at — the order things happened
Sentinel::audits()->get();                 // ordered by created_at — the order they settled
Sentinel::audits()->byOccurrence()->get(); // ✅ the same query, by the clock of the fact
```

A lifeline built on `created_at` keeps working and quietly starts answering a different question.
The chain is unaffected: `(stream, sequence)` is the order of the chain, it is dense and monotonic
in every mode, and it is what `verifyIntegrity()` walks.

### What else changes under an asynchronous mode

- **`Audited` arrives without an entry.** It says the process that captured is done; under `queue`
  and `buffered` the entry does not exist yet. `AuditCreated` is announced where the ledger assigns identity, which
  is the worker — a listener that needs the settled entry belongs there.
- **`$audit->restore()` returns a result with no entry.** `RestoreResult::$entry` is `null`, the way
  it already was inside a transaction of your own: the record moved, and the entry recording it has
  not been written yet.
- **An operation counts what it handed over.** `sentinel_transactions.audits_count` is what the
  request accepted for settlement rather than what landed, because the header closes before the
  worker runs.
- **The write-failure policy governs the request only.** In a worker the queue is the policy: it
  retries under the same `capture_id` — which settles at most once — and what still does not land
  goes to `failed_jobs`.

### A retry is not a second entry

Every capture is stamped with a `capture_id`, and the column has carried a unique index since the
schema was written. A job the queue hands back, or a flush that repeats, settles the same fact once:

```php
$entry->capture_id;   // 01JD3K…  the capture, not the entry
$entry->id;           // 01JD3K…  the entry
```

The database is what enforces it, not the memory of any process. A ledger that can look the
identifier up says so by implementing `Contracts\Deduplicates`, and then a retry costs one query
instead of a sealed chain thrown away; one that cannot is no less correct, because the unique index
is the arbiter either way.

### Choosing a queue

```php
'queue' => [
    'connection' => env('SENTINEL_QUEUE_CONNECTION'),   // null uses the application default
    'queue' => env('SENTINEL_QUEUE'),
],
```

Give audits a queue of their own if the default one is where slow work lives: an audit waiting
behind a video transcode is an audit that arrives long after the fact it describes.

> **Rolling deploys.** The job carries the entry as an array, not as a serialised object, so a
> worker on the previous release can read a payload the current one wrote — unknown keys are
> dropped, missing ones take their defaults. Drain the queue before deploying anything that changes
> what an entry means, rather than what it contains.

## Events

Ten classes, and between them the whole life of an entry. Listen to react to what Sentinel does
without reaching inside it.

| Event | When | Carries | Cancellable |
|---|---|---|---|
| `Auditing` | End of the pipeline, before the ledger | The `AuditData`, transformed | ✅ |
| `AuditDiscarded` | A stage returned `null`, or `Auditing` was refused | Identity, the stage and the reason | — |
| `AuditCreating` | At the ledger's door, before `sequence` and `hash` | The `AuditData` | ❌ |
| `AuditCreated` | The entry exists and is chained | The `Audit` | ❌ |
| `Audited` | The capturing process is done with it | The `AuditData`, and the `Audit` where it settled here | ❌ |
| `AuditWriteFailed` | A write did not complete | Identity and the exception | ❌ |
| `AuditRestoring` | Before a restoration touches the record | The entry, the record, the keys | ✅ |
| `AuditRestored` | After the commit that made it true | The entry, the record, the closed result | ❌ |
| `IntegrityVerificationFailed` | A stream or range failed verification | The stream, the reason, the sequence, the id | ❌ |
| `LedgerDestinationFailed` | A fanout destination refused a sealed entry | The destination, the coordinate, the exception | ❌ |

```php
Event::listen(AuditCreated::class, function (AuditCreated $event): void {
    Notification::route('slack', $channel)->notify(new Recorded($event->entry));
});
```

**Prefer queued listeners for anything slow.** They are dispatched inline, on the write path, so a
listener that calls an API charges it to the request that saved the model.

`AuditCreated` and `Audited` are not the same event, and the difference only shows once a mode
separates them. `AuditCreated` is announced wherever the ledger assigned identity — under `queue`
that is a worker. `Audited` is announced where the capture happened, and carries the entry only when
the two are the same place; a `null` there means "settled elsewhere", never "not settled". A write
that did not complete announces `AuditWriteFailed` instead, and the two never both go out for one
capture.

### Cancelling is only legal before the entry has identity

`Auditing` is the application's own say, at the end of the pipeline and before the ledger:

```php
Event::listen(Auditing::class, function (Auditing $event): bool|null {
    if ($event->audit->subject_type === Session::class) {
        app(Discard::class)->because('sessions are not kept');

        return false;
    }

    return null;
});
```

It comes **after** masking, hashing and encryption, for the reason the policy stage is last: before
them, the entry holds the plaintext of every declared field — and for an entry a stage is about to
drop, that payload is never transformed at all. A listener putting that on a queue is the one route
the pipeline exists to close.

The subject is not a listener's to rewrite. It says what the entry is about and which chain signs
it, and both were settled before the listener saw it; anything the listener does to
`subject_type` or `subject_id` is put back.

Refusing here costs no `sequence`, so the chain the entry never joined has no gap. Past the ledger
it is a usage error and throws — `AuditCreating` is announced, not consulted:

```php
Event::listen(AuditCreating::class, fn () => app(Discard::class)->because('too late'));  // ❌ throws
```

However an entry is stopped — a stage returning `null`, a policy, or a listener here — it leaves
through **one door**, `AuditDiscarded`, with the reason whoever stopped it gave.

### When a write fails

```php
'on_write_failure' => env('SENTINEL_ON_WRITE_FAILURE', 'throw'),
'log_channel' => env('SENTINEL_LOG_CHANNEL'),
```

`throw` propagates the failure to whoever caused the entry. `log` records it through the channel
above — identity and the exception, never the payload — and lets the request through. One default
for every environment, because a policy that differs between them is a policy nobody has tested.
Compliance overrules it: a ledger that can lose entries in silence proves nothing.

`AuditWriteFailed` is dispatched either way, so the policy decides what happens to the request and
not whether you are told.

**A write deferred to a commit never propagates**, whatever the policy says. By then the transaction
has committed: an exception would report the failure of something that succeeded, and would stop
every later entry of the same operation from even being attempted. It is announced and recorded
instead. If you need a failed audit to fail the operation, turn `transactions.after_commit` off —
and give up discarding on rollback in exchange.

One more ordering worth knowing, because two settings sound alike: `ledger.ledgers.fanout.on_failure`
decides whether a *secondary destination* refusing an entry counts as a failed write at all;
`on_write_failure` decides what a failed write does to the request.

## Protecting sensitive data

Four mechanisms, declared per model. They are not interchangeable — each gives up something
different:

| Mechanism | Declared as | What lands in the entry | Reversible |
|---|---|---|---|
| **Exclusion** | `$auditExclude` | Nothing. The field never reaches the pipeline | — |
| **Redaction** | `$auditRedact` | A mask: `c****s@e****e.c****m` | ❌ Never |
| **Hashing** | `$auditHash` | A digest, salted per installation | ❌ Never |
| **Encryption** | `$auditEncrypt` | Ciphertext, plus `{fields, key_id}` in `encryption` | ✅ With the key |

```php
final class User extends Model
{
    use Auditable;

    protected array $auditExclude = ['remember_token'];
    protected array $auditRedact = ['email'];
    protected array $auditHash = ['card_number'];
    protected array $auditEncrypt = ['national_id'];
}
```

Protection reaches **five containers**, not two — `before`, `after`, both sides of every entry in
`changes`, `metadata` and `context` — and matches by key name at any depth. Declare `email` once and
it is covered wherever it surfaces, including inside the arguments of a console command.

A protected field that changed **still shows its path**, so the entry proves something moved while
saying nothing about what it was:

```json
{ "path": "/national_id", "op": "replace", "old": "eyJpdiI6...", "new": "eyJpdiI6..." }
```

### What each one does and does not promise

```php
// ✅ Redaction keeps a value queryable and unreadable
'email' => 'c****s@e****e.c****m'

// ❌ Redaction is not anonymisation
// On a small domain a mask can still identify someone. The masker is replaceable per field,
// and the package promises a mask, not anonymity.

// ✅ Hashing answers "did it change" without keeping either state
'card_number' => '557a4675b2f1...'

// ❌ A digest cannot be restored, and neither can a mask
// v0.14.0 restores an entry into a model. It cannot restore what was never written down.
// If a field has to come back, encrypt it — do not redact or hash it.

// ✅ Encryption keeps the value recoverable by whoever holds the key
'national_id' => 'eyJpdiI6...'

// ❌ Losing the key loses the value
// The entry keeps verifying and stops being readable. The keyring belongs to your application
// and lives outside the database the entries do.

// ❌ A listener on a pre-pipeline hook sees the plaintext
// The guarantee covers the ledger and everything the pipeline dispatches after it.
```

### The hash covers the ciphertext

Not the plaintext. `verifyIntegrity()` has to run in an environment that holds **no key at all** — an
external auditor, an isolated verification job — and still prove the row was not touched.

```php
config(['sentinel.security.encryption.keys' => []]);

Sentinel::verifyIntegrity('tenant:acme')->isIntact();   // ✅ true
```

The trade is stated rather than hidden: **the chain proves the row is the one that was written, not
what the value said.** In exchange, `encryption` is part of the canonical payload, so altering the
`key_id` of a stored row breaks its hash. A forged rotation is not something that can be done
quietly.

### The keyring and rotation

```php
// config/sentinel.php
'security' => [
    'encryption' => [
        'cipher' => 'aes-256-gcm',
        'key_id' => 'default',                          // what new entries are written with
        'keys' => [
            'default' => env('SENTINEL_ENCRYPTION_KEY'), // falls back to APP_KEY
            '2025' => env('SENTINEL_ENCRYPTION_KEY_2025'),
        ],
    ],
],
```

There is **no on/off switch**. Declaring a field is what turns encryption on for it; a separate flag
exists only so someone can believe they are encrypting when they are not.

Rotation writes, it never rewrites:

```php
app(ElPandaPe\Sentinel\Security\Rekeyer::class)->rekey($audit, 'default');
```

The original stays byte for byte where it was — same `hash`, same `previous_hash`, same `sequence` —
and keeps verifying. A **new entry** carries the same values under the new key and points back at the
one it stands in for, so the rotation is part of the history rather than something that happened to
it. Keep the old key on the keyring for as long as the entries it wrote matter.

`php artisan sentinel:rekey` arrives in `v0.19.5`; for now the rotation is a service you call.

### Protecting what no model owns

`context` carries addresses, user agents, urls, session identifiers and console arguments, and no
model declares any of those. Name them in configuration instead — the lists there **add to** what
each model declared:

```php
'security' => [
    'redaction' => [
        'mask' => '*',
        'fields' => ['ip', 'user_agent'],
        'masker' => null,                                  // the package default for every field
        'maskers' => ['ip' => App\Sentinel\IpMasker::class],   // per field
    ],
    'hashing' => [
        'algorithm' => 'sha256',
        'salt' => env('SENTINEL_HASH_SALT'),               // derived from APP_KEY when null
        'fields' => ['session_id'],
    ],
],
```

The salt is **stable by definition**. Rotating it breaks no chain and destroys the comparability of
every digest written before it, which is the whole reason a digest is worth keeping.

> **Anything you push into the execution context is audited.** `Sentinel::withContext()` merges into
> the entry, which is what it is for — so do not put there what you would not want written down, or
> name the key in `security.redaction.fields`.

## Actor & impersonator

An entry tells **who acted** apart from **on whose behalf**:

```php
$audit->actor_type;         // App\Models\User
$audit->actor_id;           // 100
$audit->impersonator_type;  // App\Models\User
$audit->impersonator_id;    // 1
```

The actor comes from the configured guard, `null` meaning the application's default:

```php
'resolvers' => [
    'actor' => ['guard' => 'admin'],
],
```

The impersonator comes from a session key, `impersonated_by` by default:

```php
'resolvers' => [
    'impersonator' => ['session_key' => 'original_user'],
],
```

Two invariants hold, and tests fix them. Without impersonation both columns are `null` — never a
copy of the actor. And an identifier equal to the actor's own is not impersonation either: it is the
same session, and the columns stay `null`.

Because `Illuminate\Contracts\Auth\Guard` exposes no provider, the impersonator carries the class the
guard authenticates rather than a hydrated model. Replace `ImpersonatorResolver` if your
impersonation package stores something else.

## Querying the trail

`Sentinel::audits()` hands back an `AuditQuery`: a description of what you want, stated against
the ledger contract rather than against Eloquent. Nothing here returns a query builder and no
method takes a column name, so the same query a SQL driver compiles into a `where` clause a
driver over arrays — or over something that is not a table at all — answers by walking what it
holds. Every read goes through `Ledger::query()`, which is the one place a later version can
record who read what.

```php
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Facades\Sentinel;

$page = Sentinel::audits()
    ->for($invoice)
    ->by($admin)
    ->whereSeverity(Severity::Critical)
    ->between($from, $to)
    ->latest()
    ->paginate(50);
```

The query is immutable: every method returns a new one, so a query you hand to something else
cannot be narrowed behind your back.

### The filters

| Method | Narrows by | Index that finds it | Index that orders it |
|---|---|---|---|
| `for()` / `forModel()` | `subject_type`, `subject_id` | `(subject_type, subject_id, id)` | none — the subject's own entries are sorted |
| `by()` / `byActor()` | `actor_type`, `actor_id` | `(actor_type, actor_id, id)` | none — the actor's own entries are sorted |
| `whereEvent()` | `event` | `(event)` | none — see below |
| `whereType()` | `audit_type` | `(audit_type, created_at)` | the same index |
| `whereSeverity()` | `severity` | `(severity, created_at)` | the same index |
| `forTenant()` | `tenant_id` | `(tenant_id, created_at)` | the same index |
| `inTransaction()` | `transaction_id` | `(transaction_id)` | none — one transaction is sorted |
| `withTrace()` | `trace_id` | `(trace_id)` | none — one trace is sorted |
| `whereTag()` / `whereAnyTag()` | the labels an entry carries | `(tag, audit_id)` on the labels table | none |
| `whereIp()` | `ip`, inside `context` | the JSON index migration, **if you publish it** | none |
| `whereRoute()` | `route`, inside `context` | the JSON index migration, **if you publish it** | none |
| `whereSource()` | `source` | **none — a refiner** | — |
| `between()` | `created_at` | **none — a refiner** | — |
| `whereFieldChanged()` | a path inside `changes` | **none — a refiner** | — |
| `whereVersion()` | `version` | **none — a refiner** | — |

Every row of that table was measured, on SQLite, MySQL 9 and PostgreSQL 16, with the engine's own
`EXPLAIN` over the statement the driver actually issues — and the measurement is a test, so it
stays true. No published filter falls back to a full pass over the table without being called a
refiner here.

`for()` and `by()` take a model, or the type and key the entry recorded — a hard-deleted subject
has no model left to hand over, and its trail is exactly what outlives it:

```php
Sentinel::audits()->for($invoice)->get();
Sentinel::audits()->for(Invoice::class, 500)->get();
```

### The two that live inside the context

`whereIp()` and `whereRoute()` read `context`, which is a JSON column and not one the schema gives
them. They match exactly and case-sensitively on all three engines — MySQL's default collation is
accent- and case-insensitive, so the driver puts a binary recheck behind the comparison there, and
`whereRoute('invoices.show')` does not answer with an entry recorded from `Invoices.Show` on any
engine.

Whether they **find** or merely **refine** is your decision, because the index they need is a
migration you publish rather than one the package runs:

```bash
php artisan vendor:publish --tag=sentinel-json-indexes
php artisan migrate
```

It creates a B-tree index over the expression on PostgreSQL 16 and a `VIRTUAL` generated column with
an index on MySQL 9. Without it the two filters still answer, correctly, by scanning — so put an
indexed filter in front of them, the way you would with any other refiner.

The reason it is not shipped as a default is the number. Measured over 200 000 writes on a table with
the shape this package creates — thirty columns and the twelve indexes it already carries:

| | PostgreSQL 16 | MySQL 9 |
|---|---|---|
| The indexes today | 10 303 ms | — |
| With the JSON index | 11 880 ms (**+15 %**) | **+21 %** |

That is what an installation which never filters by address would be paying for nothing. `route` is
the name of the route, or its uri where it has no name — whichever the resolver recorded.

**`whereRoute()` names a category, and at volume that shows.** An application has a few hundred
routes and millions of entries, so the filter reaches its index and then sorts everything it matched
— measured at 5.1 seconds over ten million entries on MySQL 9, against 32 milliseconds for
`whereIp()` on the same table. It is the same shape as [`whereEvent()`](#order-and-how-much-comes-back)
and it wants the same treatment: put an indexed filter in front of it. `whereIp()` selects an entity
rather than a category and does not have the problem.

### Refiners

Four filters are **refiners**: they narrow a result, they do not find one. `whereSource()` reads a
column with eight possible values and no index of its own; `between()` reads `created_at`, which
lives in the tail of the composite indexes and not at the head of any; `whereFieldChanged()` reads
inside a JSON column, which no index this package ships covers; and `whereVersion()` reads a counter
that is not indexed either. On MySQL and PostgreSQL any of them, alone, walks the whole table. Put
one of the indexed filters in front and they ride its index.

`whereTag()` is not a refiner, but its index is **selectivity-dependent** and that is worth knowing:
both planners are cost-based, and a label carried by a large share of the table is walked rather than
sought. That is the right plan for a label that broad — it is a fact about how you label, not about
the query.

`between()` bounds `created_at`, the clock the ledger stamps the entry with, and both ends are
inclusive. It is not `occurred_at`: that is what the entry says about the world, it has no index,
and the two come apart the moment writing stops being synchronous.

### Order, and how much comes back

Entries come back oldest first. `latest()` turns that around. The order is total on every driver:
`created_at`, and the entry's own ULID behind it, which sorts by the instant it was minted — so two
entries stamped in the same microsecond still come back in the order they were written, and they come
back in that order whether the ledger is a table or not.

That is one order for nine filters, and no single one can be free for all of them: the schema has
indexes that end in `created_at` and indexes that end in `id`, and an order can only ride one shape.
It rides `created_at`, which is free exactly where it matters most — `forTenant()` and
`whereSeverity()`, the two filters whose match grows with the table. The rest sort what they matched,
which is bounded by what they selected: one subject's history, one actor's actions, one transaction,
one trace.

**`whereEvent()` is the exception worth knowing.** It names a category rather than a thing, so what it
matches is not bounded by anything — `whereEvent('updated')` on a busy trail is most of the table, and
its index finds those rows but cannot order them. Put a filter in front of it.

`get()` is bounded at `AuditQuery::DEFAULT_LIMIT` entries — 500 — and **refuses** rather than
truncates. A trail has no natural end, so a read with no bound is a read of the whole table; handing
back the first five hundred in the shape of a complete answer is the one mistake a trail cannot
afford.

```php
Sentinel::audits()->get();                  // throws if the filter matches 500 or more
Sentinel::audits()->take(500)->get();       // a prefix, asked for on purpose
Sentinel::audits()->paginate(100);          // all of it, a page at a time
```

```php
$page = Sentinel::audits()->for($invoice)->latest()->paginate(25);

$page->entries;   // the entries
$page->hasMore;   // whether there is another page behind this one
$page->page;
$page->perPage;

foreach ($page as $audit) { /* ... */ }
```

There is no total, and that is a decision rather than an omission: counting the rows a filter
matches on a table that only ever grows is the one question in this API whose cost is unbounded
and that no index answers. A page costs one call to the ledger, which asks for one entry more
than it hands back — which is how it knows there is another page.

### Drivers that cannot answer everything

A driver over a store that cannot translate one of the filters declares the ones it can, by
implementing `Contracts\DeclaresFilters`. The query then refuses that filter **as you add it**,
not when it runs:

```php
use ElPandaPe\Sentinel\Contracts\DeclaresFilters;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Enums\Filter;

final class RedisLedger implements DeclaresFilters, Ledger
{
    public function supportedFilters(): array
    {
        return [Filter::Subject, Filter::Tenant];
    }
}
```

A driver that does not implement it answers all of them. Silently dropping a filter it could not
translate would answer with entries nobody asked for, and a trail that shows the wrong history is
worse than one that refuses to answer.

## Labels

An entry can carry labels, and a label is how you ask for a slice of the trail that no column
describes: everything billing touched, everything a compliance review cares about.

A model says what its entries are born with, the same way it says their severity:

```php
final class Invoice extends Model
{
    use Auditable;

    protected array $auditTags = ['billing'];
}
```

and the configuration gives every entry the ones an installation wants:

```php
// config/sentinel.php
'tags' => [
    'enabled' => true,
    'default' => ['environment:production'],
],
```

Both arrive together, in declaration order and without repeats, and they are written **inside the
transaction that seals the entry**: an entry is stored with its labels or it is not stored.

```php
Sentinel::audits()->whereTag('billing')->get();               // carries billing
Sentinel::audits()->whereTag(['billing', 'refund'])->get();   // carries both
Sentinel::audits()->whereAnyTag(['billing', 'payroll'])->get();  // carries either
```

Asking twice accumulates, so `whereTag('a')->whereTag('b')` and `whereTag(['a', 'b'])` are the same
question. An empty list is refused rather than answered: it asks nothing of an entry, so it would
hand back the whole trail.

### What a label is not

**Labels are outside the hash, deliberately, and it cuts both ways.** Classifying an old entry when
a new category appears is a legitimate operation, and making it break the chain would turn your
taxonomy into an integrity problem — so `verifyIntegrity()` is untouched by labelling.

The other half of that: labelling is **not tamper-evident**. Anyone with write access to the
database can relabel an entry and nothing will say so. Labels are operational classification, not
facts. What has to be provable goes in `metadata`, which is part of the entry, is covered by the
hash, and is redacted with it.

## Field history

"What did this field do" is one query, and it means the same thing everywhere in the package:

```php
Sentinel::audits()->for($user)->whereFieldChanged('email')->get();
$user->audits()->field('email')->get();       // the same reading, from the relation
```

A field is touched by a change **at that pointer or beneath it**, which is what `$audit->diffFor()`
already meant, so `whereFieldChanged('profile')` finds a change to `/profile/address/city`. Dot
notation is read as a JSON Pointer, and a longer neighbour is never a match: `email` does not find
`/email_verified_at`.

The predicate is a JSON function per engine, measured to return the same entries on SQLite, MySQL 9
and PostgreSQL 16. It reads only an element's own `path`, never one buried inside another change's
`old` or `new`.

### The two numberings

A field's history skips versions, and both numbers on screen are true:

```text
v1  ada@example.com     ← the subject's own version, which leads back to the entry
v4  ada@work.example
v7  ada@home.example
```

The subject changed seven times; this field changed in three of them. Sentinel keeps the **real**
version, because that is what takes you back to the whole entry, and the presenter counts the field's
own changes beside it.

### Comparing two versions

```php
$comparison = Sentinel::audits()->for($invoice)->compare(1, 7);

$comparison->diff;    // what changed between them
$comparison->from;    // the entry at v1
$comparison->to;      // the entry at v7

$entry->comparedTo($other);   // two entries you already hold
```

The two versions need not be adjacent — that is the point, and it costs one read. You get the two
entries and not only the diff, because an empty diff has several causes and only one of them is
"nothing changed". Comparing entries about different subjects is refused rather than answered with an
empty diff that would read as agreement.

A caveat worth carrying: **`version` is assigned without a lock**, so two concurrent writes to one
subject can reach the same number. A repeated number resolves to the newest entry carrying it.

## Timeline

Everything that happened, in the order it happened:

```php
$page = Sentinel::timeline()->paginate(50);

Sentinel::timeline()->for($invoice)->get();
Sentinel::timeline()->byActor($user)->between($from, $to)->get();
```

One query over one table. Every kind of entry lives in `sentinel_audits`, so a timeline is the
unnarrowed read with a different clock in front — not a merge of sources in PHP.

**The clock is `occurred_at`**, when it happened, with the entry's own identifier behind it. It is
not `created_at`, when the ledger sealed it: the two agree while writing is synchronous and come
apart the moment it is not. Two indexes ship with this version so that order rides an index instead
of sorting outside one — measured over two hundred thousand entries on all three engines, where
without them it sorts outside every index and an indexed filter in front only shrinks what it sorts.

Rendering a page of a timeline resolves what the entries point at in a query per morph type rather
than a query per line:

```php
$page->entries->loadReferences();
```

A recorded type that names no class is left unresolved rather than fatal. An entry outliving the
subject it describes is the normal case, not the edge one.

## Relationship auditing

Eloquent fires **no event** when a pivot table is touched. `attach()` inserts and `detach()` deletes
straight through the query builder, so a package listening for model events hears nothing — which is
why the rest of the ecosystem asks you to call `auditAttach()` instead of `attach()`.

Sentinel wraps the relation instead. You keep writing exactly what you already wrote:

```php
$team->members()->attach($ada, ['role' => 'lead']);
$team->members()->sync([$ada->id, $linus->id]);
$team->members()->updateExistingPivot($ada->id, ['role' => 'admin']);
$team->members()->detach($ada);
```

All six operations are covered — `attach`, `detach`, `sync`, `syncWithoutDetaching`, `toggle` and
`updateExistingPivot` — on `belongsToMany`, `morphToMany` and `morphedByMany`.

**One call is one entry.** A `sync()` that attaches two records and detaches one writes a single
entry with three lines, not three entries: the operation the application performed was one.
Internally `sync()` calls `attach()` and `detach()`, and none of those inner calls writes anything.

A `sync()` that attaches nothing and detaches nothing writes **no entry at all** — and takes no
sequence number, so the chain has no link claiming nothing happened.

```text
Someone synced Team #1 · members
  + Member #2
  - Member #7
  ~ Member #9
```

`+` was attached, `-` was detached, `~` kept its place and its pivot changed.

### What a line says

Each line records what happened to **one** related record:

| | |
|---|---|
| `relation` | The relation on the parent — `members` |
| `operation` | `attach`, `detach` or `update` — what became of that record |
| `related_type` / `related_id` | Which record |
| `pivot_before` / `pivot_after` | The pivot columns either side. `null` means the row did not exist; `{}` means it existed and carried nothing |

`operation` is what **happened**, not which method was called. That is deliberate: most attachments
in a real application are made by `sync()`, and a filter for attachments that could not find them
would be answering the wrong question. The method you called is not lost — it travels in the entry's
`metadata` as `{"api": "sync"}`, and metadata is inside the hashed payload.

### Asking

```php
$team->relationHistory('members')->get();
$team->relationHistory('members')->whereOperation('attach')->get();

Sentinel::audits()->whereRelated($ada)->get();
Sentinel::audits()->whereRelation('members')->whereRelated($ada)->whereOperation('detach')->get();
```

The three narrow the **same line**, so an entry answers only when one of its lines satisfies all of
them at once. Asked separately, "when was Ada detached" would also be answered by the entry that
attached Ada and detached somebody else.

They compose with every other filter, page like any other read, and reach the projection through an
index on all three engines.

```php
$audit->relations;     // the lines, as rows
$audit->diff();        // the same lines, read as a diff: /members/7 added, removed or replaced
```

### Protecting a pivot column

A pivot has no class of its own to declare anything on, so the **parent** declares for it, with the
same properties it already uses for its own columns:

```php
class Team extends Model
{
    use Auditable;

    protected array $auditRedact = ['role'];
    protected array $auditEncrypt = ['expires_at'];
}
```

Both sides of a changed pivot are protected, not just the new one, and the hash still covers the
ciphertext — so a relation entry with an encrypted pivot verifies where no key is reachable.

### What the chain covers, and what it does not

**The lines are inside the hashed payload.** Nobody can change what was attached, what was detached
or what the pivot was worth without breaking `verifyIntegrity()`.

`sentinel_audit_relations` is a **projection** of those lines: an index over the evidence, not the
evidence. It is written in the same transaction that seals the entry and is rebuildable from it, so
removing a row there leaves `verifyIntegrity()` untouched. That is the point — rebuilding an index
must not be indistinguishable from tampering. `sentinel:verify --projections` checks the index
against the entries and reports a divergence as its own kind of defect; rebuilding it lands in
`v0.22.0`.

One thing this version does not do: `whereFieldChanged('members')` finds **nothing**. A field is an
attribute, a relation is not one, and the field predicate reads a `path` that relation lines do not
carry.

### When there is no pivot at all

A `belongsTo` has no pivot table to wrap. When an article changes author, the only thing Eloquent
announces is the article's own update — and yet two parents lived a change of relation. Say which
relations that applies to, on the child:

```php
class Article extends Model
{
    use Auditable;

    protected array $auditParents = ['author' => 'articles'];
}
```

The key is the `belongsTo` on this model; the value is the name the **parent** gives that
collection, because the entry hangs off the parent and its line has to be findable as `articles`.

```php
$article->update(['author_id' => $grace->id]);
```

That writes **three** entries: the article's own `updated`, a `detached` on the author it left, and
an `attached` on the author it joined. Two and not one, because one entry holds one subject and a
hand-over has two. Both read exactly like a pivot entry — same lines, same projection, same filters,
same render — with the article as the related record and no pivot on either side, because there is
none:

```php
$grace->relationHistory('articles')->whereOperation('attach')->get();
Sentinel::audits()->whereRelated($article)->get();   // both ends of the hand-over
```

The three share a `request_id`, and a [business transaction](#business-transactions) correlates them as the one operation they are.

A parent that has since been deleted still gets its entry: the foreign key **is** the name, so
nothing has to be read to write it. That changes when the `belongsTo` points at a column other than
the parent's primary key — then the parent is read once per end, because `subject_id` is a primary
key wherever it appears, and an end that resolves to nobody is left unsaid.

Declaring nothing keeps the old behaviour exactly. Only `belongsTo` is covered: a `morphTo` moves
the type as well as the key, so it is refused by name rather than half-audited. And this is about
the hand-over — creating or deleting the child writes no relation entry, because the child's own
entry already carries the foreign key.

## Mass operations

Eloquent fires no model event for `Builder::update()`, `Builder::delete()` or `Builder::upsert()`.
The row changes and nothing announces it — the blind spot every auditing package in this ecosystem
documents as a limitation. From `v0.17.0` you can close it, per query, by asking:

```php
User::query()->where('active', false)->auditing()->update(['status' => 'archived']);
```

**Without `auditing()`, nothing happens.** No listener, no extra statement, no branch — a mass
update in an application that never uses the feature costs exactly what it cost before. That is the
decision, and it is worth stating plainly rather than defending later:

| | |
|---|---|
| ✅ | You ask, on the query that should be audited |
| ✅ | Every other query in your application is untouched, and pays nothing |
| ❌ | Sentinel does **not** intercept mass updates globally |
| ❌ | There is no config flag to turn that on |

Intercepting every mass update would turn a one-line statement into thousands of inserts nobody
asked for, and would put this package on the path of queries that have nothing to do with it.

The model has to use `Auditable`. Its `$auditExclude`, `$auditRedact`, `$auditEncrypt` and
`$auditHash` are what govern which columns of the criteria may be written down, so a model that
declared none is refused outright rather than audited with nothing protecting it.

### The three modes

```php
User::query()->where(...)->auditing('individual')->update([...]);   // per call
```

Without an argument it takes `mass_operations.mode` from the config, which ships as `summary`.

| Mode | What it writes | Extra reads | Cost |
|---|---|---|---|
| `summary` | One entry: the criteria, the columns written, `affected_rows` | None | Constant |
| `individual` | The summary **and** one entry per row, each with its real `before` | One: the rows, before the statement |  Linear in rows |
| `hybrid` | The summary always; the per-row entries while the set fits under `threshold` | One, bounded to `threshold + 1` | Constant above the threshold |

Measured on the write-path benchmark, over a set of five hundred rows on SQLite:

| Mode | Per row | Against the same update unaudited |
|---|---|---|
| not audited | 0.7 µs | — |
| `summary` | 4.6 µs | +579% |
| `hybrid`, over its threshold | 19.6 µs | +2,791% |
| `individual` | 889.7 µs | +131,194% |

Read the middle column, not the last one. `summary` is 2.3 ms for the whole operation and stays 2.3
ms for a set of any size — it reads nothing and writes one entry. `individual` is about nine hundred
microseconds a **row**, and five hundred rows means five hundred and one entries. It is never the
default and it never will be: an installation that wants that has asked for it.

`hybrid` over its threshold costs four times `summary` rather than anything like `individual`. The
difference is the bounded read, and the figure above is its worst case — a threshold one row short
of the set. At the shipped default of a hundred it reads a hundred and one rows and stops.

`hybrid` decides by reading one row past the threshold instead of counting. A `count(*)` is a second
statement over the same predicate; this way the price of asking is bounded by the threshold itself,
and the set is never materialised whole.

### What an entry says

`audit_type` is `mass`. The summary has `subject_type` and **no** `subject_id` — there is no one
row, there is a set — and it reads as one:

```text
Someone changed 3500 User records
```

`changes` holds the columns that were written, with **no old side**: nothing was read, so there is
no earlier value and none is invented. `Change::oldKnown` is what says so, rather than a `null`
pretending to be the old value. This is the structural cost of `summary`, and it is exactly why
`individual` exists.

`criteria` holds the `where` as a structure — column, operator and the value as a binding — and
never as SQL with its values interpolated back in. `affected_rows` holds what the engine reported.

```json
{
  "wheres": [
    {"type": "basic", "boolean": "and", "column": "active", "operator": "=", "value": false},
    {"type": "in", "boolean": "and", "column": "id", "count": 5000, "values": [1, 2, 3]}
  ]
}
```

A long set records its size and a sample of it, bounded by `mass_operations.sample`. Five thousand
identifiers written out in full would make the entry about the list rather than about the operation.

**A raw fragment or a subquery records its shape and nothing else** — `{"type": "raw", "boolean":
"and"}`. A `whereRaw` can carry literals no declaration of your model reaches, so its body is not
written down. Same for any clause a future framework release invents: the serialiser names the
clauses it understands one by one and everything else is opaque, rather than the other way round.

Bindings go through the same redaction, hashing and encryption as the snapshots. A `where('email',
$x)` on a model with `email` in `$auditRedact` records the mask, not the address — the criteria is
the same territory as `before` and `after`, not an exception to it.

A value that is not a scalar, a date or an enum is left out: the clause keeps saying which column it
compared, and says nothing about what it was compared to.

### An entry per row

```php
User::query()->where('active', false)->auditing('individual')->update(['status' => 'archived']);
```

The rows are read **before** the statement runs — after an update the earlier state is gone, and
after a delete the row is — and the read and the statement share one database transaction, because
between a select and an update a row can arrive that no entry would describe.

Every entry of the operation shares one `transaction_id`, so the summary and its rows read as the
one thing they were. Inside a [business transaction](#business-transactions) they take that one
instead of opening another.

A per-row entry carries no `criteria` and no `affected_rows`: it is about a row, and the summary it
shares a transaction with is about the set. Three thousand copies of one fact is not three thousand
facts.

The batch reaches the ledger in one assignment of the sequence, so the chain is extended once rather
than once per row. Under `queue` it is one job per entry; under `buffered` it goes into the buffer
whole and settles on the next flush.

### `delete()` and `upsert()`

A mass `delete()` under `individual` captures the full `before` of every row, because after the
statement there is nowhere left to read it from. It costs more than an update and this is where that
is written down.

An `upsert()` is **always** recorded as a summary, whatever the mode says. It names its own rows, so
there is no criteria to read them back by, and a composite `uniqueBy` is not a `where`:

```json
{"columns": ["id", "name"], "unique_by": ["id"], "update": ["name"], "rows": 2}
```

### `affected_rows` means what your engine says it means

The entry stores what the driver reported, without normalising it. That number is not the same
question on all three:

| Engine | On `update()` | On `upsert()` |
|---|---|---|
| SQLite | Rows matched | Rows inserted or updated |
| MySQL 9 | Rows **changed** — a row written with the value it already held does not count | Counts **two** for a row that was updated rather than inserted |
| PostgreSQL 16 | Rows matched | Rows inserted or updated |

Normalising it in silence would mean the entry no longer said what the database said. If you compare
`affected_rows` across engines, compare it knowing this.

An operation that reported zero writes **no entry at all**. It is discarded in the pipeline, before
the ledger assigns anything, so it leaves no gap in the chain.

### A column written from an expression

```php
User::query()->auditing()->update(['score' => DB::raw('score + 1')]);
```

The formula is the database's to evaluate, so it is not recorded and neither is a value for that
column. It is named in `criteria.writes` instead — its name, never its body, the same trade a raw
fragment gets:

```json
{"wheres": [], "writes": ["score"]}
```

Under `individual`, an operation like this gives its rows a `before` and **no** `after`. An after
carrying that column's earlier value would say it did not move, and one side of a comparison is
better than a mixture of the two that lies.

### The name `auditing` is global

It is a macro on `Illuminate\Database\Eloquent\Builder`, which is what makes it reachable without
this package sitting on the path of every query you make. A macro has no namespace: a second package
registering `auditing` on the Eloquent builder would win or lose by boot order. Nothing has claimed
it in this ecosystem so far, and this line exists so it is a thing you know rather than a thing you
find out.

## Business transactions

A payment is not four things that happened to share a request. `Sentinel::transaction()` gives the
operation a name and every entry inside it the same `transaction_id`:

```php
Sentinel::transaction('invoice-payment', function () use ($invoice, $payment) {
    $invoice->markPaid();
    $payment->save();
    $invoice->auditors()->sync([$reviewer->id]);
});

$operation = ElPandaPe\Sentinel\Models\AuditTransaction::query()->latest('started_at')->first();

$operation->name;           // 'invoice-payment'
$operation->audits_count;   // 3 — the update, the insert, and one relation entry for the sync
Sentinel::audits()->inTransaction($operation)->get();
$audit->transaction->name;  // from either end
```

The header lands in `sentinel_transactions` with the name, the actor and tenant resolved exactly as
an entry resolves them, the window it ran in, and how many entries it wrote — counted as they settle
into the ledger, so a capture the pipeline discarded or a rollback undid is not in the total. It is **opened before
the operation runs**, so one that died halfway is still findable, and closed after — including when
the operation threw, in which case the class of the failure is in `metadata`. The class and not the
message: a header does not go through the pipeline, so nothing would redact a domain value someone
interpolated into an exception.

**Nesting keeps the outer identifier.** An operation does not split because its implementation
reuses code that already wrapped itself; the inner name is kept in the header's `metadata` rather
than lost.

**It correlates; it does not atomise.** `Sentinel::transaction()` opens no database transaction.
Combining the two is your decision, and the next section is what happens when you do.

### Entries wait for the commit

With `transactions.after_commit` on — the default — an entry captured inside a `DB::transaction()`
is written when that transaction commits, and never if it rolls back:

```php
DB::transaction(function () use ($invoice) {
    $invoice->update(['status' => 'paid']);   // captured here
    throw new PaymentDeclined;                 // and never written
});
```

A rollback to a `SAVEPOINT` discards only that level. Both are the framework's own behaviour, so
they are what your engine already does with transactions, not a second mechanism layered on top.

**This is honesty, not speed.** Deferring does not make the request faster — measured, it is
indistinguishable from writing in place. What it does is stop the ledger from claiming a fact the
database does not keep.

Three things are worth knowing before you rely on it:

- **What waits is the write, not the pipeline.** Redaction, encryption and context resolution all
  run at capture, because the context is only true then — the actor can change before the commit,
  `Sentinel::withContext()` is restored the moment its callback returns, and the tenant decides
  which chain signs the entry. A rollback therefore costs you that work for nothing. That is cost,
  not correctness.
- **`occurred_at`, and only `occurred_at`, numbers the fact.** `created_at`, `sequence` and
  `version` number the settlement. Two changes to the same subject whose transactions commit out of
  order settle in commit order, and that order is sealed into the hash. Under an asynchronous
  [mode](#performance-modes) the two clocks part company for good.
- **Where the ledger shares the connection that rolled back, the database had already undone the
  entry.** What `after_commit` adds is the case where it does not: a dedicated
  `database.connection`, a ledger that is not this database, or a fanout to somewhere external. It
  also stops the chain's stream lock from being held for the whole business transaction.

Turning it off is supported and means what it says — a ledger that can assert what a rollback
undid. A deferred write that fails is announced with `AuditWriteFailed` rather than thrown: the
framework runs commit callbacks in a bare loop, so an exception there would stop every later entry
of the same transaction from even being attempted.

> **Testing note.** `RefreshDatabase` and `DatabaseTransactions` replace Laravel's transaction
> manager with one that skips the wrapping transaction the trait opened. Your audits still land: a
> capture with no `DB::transaction()` of its own is written immediately, and one inside a
> `DB::transaction()` your test opens is written when *that* commits. What you cannot do is assert
> on an entry from inside the transaction that produced it — which is exactly what the deferral is
> for, in tests as much as in production.

## Custom events

Not everything worth auditing is a model changing. An approval, a dispatch, a decision taken in a
meeting and typed in afterwards — `Sentinel::event()` states them outright:

```php
Sentinel::event('invoice.approved')
    ->actor($user)
    ->subject($invoice)
    ->severity(Severity::Notice)
    ->tags(['billing'])
    ->metadata(['reason' => 'Approved by finance'])
    ->record();
```

It settles through the **same pipeline and the same ledger** as an update: same redaction, same
encryption, same policies, same `sequence`, `stream`, `previous_hash` and `hash`. There is no
shortcut for entries that did not come from Eloquent, and no way to tell one apart in the chain.

`record()` is the terminal and **nothing is written until you call it**. It returns nothing on
purpose: with the write [waiting for a commit](#entries-wait-for-the-commit), the entry does not
exist yet when the call comes back, so returning it would have to mean two different things at once.

- **Leave out `subject()`** and the entry has none. Some facts are not about a record, and giving
  them one would be inventing it.
- **Leave out `actor()`** and the context engine names whoever is authenticated. Pass one and it
  wins — including `->actor('system', 'nightly-billing')` for an actor that is not a model. Naming
  one also clears the resolved impersonator: whoever the session had standing in was standing in
  for the actor that was resolved, not for the one you just named. And a `Sentinel::filter()`
  policy still sees the **resolved** actor, because policies run inside the pipeline and the swap
  happens after it — filter on the subject or the event, not on a declared actor.
- **The name is capped at 64 characters**, the width of the column, and refused at the call rather
  than at the write. The name is inside the hash, so an engine that truncated it instead of raising
  would leave an entry that can never reproduce its own.
- **Leave out `severity()`** and it comes from `severity.events` keyed by your event name, falling
  back to `severity.default`.
- **Labels go to the labels table**, so `whereTag()` still finds them.
- Inside a `Sentinel::transaction()` it takes the operation's id like any other entry.

The event name is yours, and the presenter prints it as you wrote it — `Someone invoice.approved
something`. The package cannot translate names it has never seen; publish `--tag=sentinel-lang` and
add your own line under `events` to change that. Note that a dotted name nests: `invoice.approved`
becomes `events.invoice.approved`, two levels deep.

## Authentication events

Who got in, who did not, and who was shut out — as entries, not as a log file. **Opt-in**: the
package ships the subscriber and you register it.

```php
// bootstrap/app.php, a service provider, wherever you register listeners
Event::subscribe(ElPandaPe\Sentinel\Capture\AuthenticationSubscriber::class);
```

```php
Sentinel::audits()->whereEvent('failed')->whereSeverity(Severity::Warning)->get();
Sentinel::audits()->for($user)->whereEvent('login')->get();
```

**Until you register it, nothing about authentication is written.** A package that started
recording who logs in the moment it was upgraded would be making that call on your behalf.

| Event | `event` | Default severity | Fired by |
|---|---|---|---|
| `Login` | `login` | `info` | the framework's session guard |
| `Logout` | `logout` | `info` | the framework's session guard |
| `Failed` | `failed` | `warning` | the framework's session guard |
| `Lockout` | `lockout` | `critical` | **your application** |
| `PasswordReset` | `password_reset` | `notice` | **your application** |

That last column is the part nobody documents. **`Lockout` and `PasswordReset` are not fired by the
framework at all** — there is no `new Lockout(` anywhere in `laravel/framework`. They come from your
application skeleton or a starter kit like Fortify or Breeze. Register the subscriber on a bare
install and you get three of the five, which is worth knowing before you go looking for the other
two.

The person is recorded as **both actor and subject**: an authentication event is something someone
did, and the thing it happened to is that same someone. So `->by($user)` and `->for($user)` both
find it. A `Failed` that named nobody the provider could find has neither — but it still has the IP,
the user agent and the request id the context engine resolved, which is the part worth keeping.

**The credentials are never captured.** `Failed` carries them, marked sensitive by the framework,
and the subscriber does not look at them. Not capturing is a stronger guarantee than capturing and
redacting afterwards.

`Lockout` arrives with no guard and no user — the framework hands it a request and nothing else —
so its entry has no actor and no `metadata`. Everything identifying about the attempt that is not a
credential is already in the context.

## State transitions

`draft → pending → approved → paid` is the question a trail gets asked about a document, and
answering it from a pile of `updated` entries means mining every diff for one column. A state
change is an entry of its own kind instead — `audit_type = transition` — with where it came from,
where it went, why, and how long the record had been where it was.

Two ways in. State it outright:

```php
Sentinel::transition($invoice, from: Status::Pending, to: Status::Approved)
    ->reason('Budget confirmed')
    ->actor($user)
    ->record();
```

Or let the model declare which column is its lifeline, and every `update` that moves it is written
as a transition instead of a generic change:

```php
class Invoice extends Model
{
    use Auditable;

    protected array $auditTransitions = ['status'];
}

$invoice->update(['status' => 'approved']);   // audit_type = transition
$invoice->update(['total' => 90_00]);         // audit_type = model, event = updated
```

`record()` is the terminal and nothing is written until you call it, for the same reason
[`Sentinel::event()`](#custom-events) works that way.

- **`from` and `to` take enums or strings**, and a backed enum, a pure enum and a plain string all
  reach the entry as the same scalar — read exactly the way [snapshots](#snapshots--diffs) read
  them, so a transition and the snapshot of the same column never disagree.
- **The two states are filed as a diff line** under the column that moved, so
  `whereFieldChanged('status')` finds a transition like any other change to that column, with no
  new index.
- **The column is named** by `->on('phase')`, or inferred from `$auditTransitions` when it declares
  exactly one, or taken from `transitions.attribute` in the config, which ships as `status`. A model
  that declares more than one and a call that names none is refused rather than guessed: falling
  through to the default would file the change under a column that did not move.
- **`reason` travels in `metadata`**, under a `transition` key alongside the column, so your own
  `->metadata(['reason' => …])` stays yours.
- **One save is one entry.** An `update` that moves the state *and* three other columns writes a
  single transition carrying the whole diff. Splitting it would invent a second fact where there
  was one.
- **Setting the column to the value it already had writes nothing at all**, the way any update that
  changed nothing does.
- **A record that had no state** and acquires one is a transition from nothing, not a non-event.

### The state column has to be readable

A column named in `$auditTransitions` cannot also be in `$auditExclude`, `$auditRedact`,
`$auditEncrypt` or `$auditHash`, nor left out of a declared `$auditInclude`. A lifeline the entry
cannot show is not a lifeline, and the combination raises a `ConfigurationException` the first time
the model is audited rather than leaving you to discover it months later in a row of asterisks.

### Refusing a move the model does not make

Optional, and off unless the model asks for it. Implement `DeclaresTransitions` and Sentinel will
refuse to record a jump that never should have happened:

```php
use ElPandaPe\Sentinel\Contracts\DeclaresTransitions;

class Invoice extends Model implements DeclaresTransitions
{
    use Auditable;

    protected array $auditTransitions = ['status'];

    public function allowsTransition(string $attribute, bool|float|int|string|null $from, bool|float|int|string|null $to): bool
    {
        return in_array([$from, $to], [['draft', 'pending'], ['pending', 'approved']], true);
    }
}

$invoice->update(['status' => 'paid']);   // IllegalTransition — and the row is not written either
```

The refusal happens **before the save**, not after it. By the time Eloquent announces an update the
row is already written, so refusing there would leave the record holding a state the trail says
never happened. Here the `save()` itself is abandoned, and nothing reaches the ledger.

Sentinel asks; it does not execute. It will not move your model between states, and a model that
declares no machine may move however it likes — this is an audit engine, not a workflow engine. If
you already use a state machine package, one method delegating to it is the whole adapter.

### Reading the lifeline

```php
$lifeline = Sentinel::transitions()->for($invoice)->get();

foreach ($lifeline as $step) {
    $step->from;        // 'pending'
    $step->to;          // 'approved'
    $step->reason;      // 'Budget confirmed', or null
    $step->actor;       // a Reference, or null
    $step->occurredAt;  // when it happened
    $step->since;       // how long it had been in the state it just left, or null for the first
    $step->entry;       // the Audit itself
}
```

It composes `for()`, `by()`, `between()`, `latest()` and `take()`, and it is **always ordered by the
clock of the fact**: the two clocks agree while writing is synchronous and come apart the moment it
is not, and only the first says how long something lasted. Asking for the newest first reverses the
reading, not the arithmetic — the interval still points backwards in time.

The elapsed time is computed on read and stored nowhere. It is a fact about two entries rather than
about either of them, and an entry carrying it would be wrong the moment an earlier one was
archived away.

There is no `paginate()` on a lifeline: the interval of a page's first row is the distance to an
entry the page does not hold. `->entries()` drops back to the query underneath, which pages like
any other read.

### Transitions only exist from the moment you declare them

Adopting `$auditTransitions` does not rewrite the `updated` entries that already described state
changes. `Sentinel::transitions()` sees the part of the history that came after the adoption, and
a lifeline that starts late is exactly that — not a gap in the chain.

The trail can also be narrowed by the kind of entry directly, which is what `Sentinel::transitions()`
does underneath:

```php
Sentinel::audits()->whereType('transition')->for($invoice)->paginate(20);
```

`whereType()` is not the same question as `whereEvent()`: an application is free to call its own
stated fact `updated`, and only the type tells that apart from a model change. It rides the
`(audit_type, created_at)` index the table has carried since it was created. Like every filter
published after `v0.9.0`, a driver that does not declare it refuses it rather than dropping it.

## Restoring state

An entry stops being read-only. Point at any row of the trail and put the record back the way that
row found it — all of it, some named fields, or one of its relations:

```php
$result = $audit->restore();                      // the whole recorded state
$result = $audit->restore(['email', 'role']);     // only these
$result = $audit->restoreRelationship('members'); // the pivot rows it recorded
```

Nothing is rewritten or deleted. A restoration is a **new** entry of its own kind —
`audit_type = restore` — pointing back at the one it came from, so the history stays what it was
plus one more link:

```text
v1 Created  →  v2 Email changed  →  v3 Role changed  →  v4 Restored from v1
```

**An entry is a photograph of the record at a moment, and restoring it is going back to that
moment.** You point at a row and say "back to this one" — not "back to whatever came before this
one", which for the first entry of a record would be nothing at all. That is the state the entry
holds in `after`, and in `before` only for a deletion, whose `after` is empty because there was no
record left to photograph.

### What comes back, and what does not

None of these three ever reach the record, because none of them can:

```php
class User extends Model
{
    use Auditable;

    protected array $auditRedact  = ['email'];   // stored masked; the original is gone
    protected array $auditHash    = ['token'];   // stored as a digest; digests do not reverse
    protected array $auditEncrypt = ['dni'];     // read back with the key the entry names
}
```

An encrypted field comes back **decrypted with the `key_id` the entry recorded**, so a value written
under yesterday's key still restores while that key is on the keyring. Once it leaves, the field is
skipped with its reason — never written back as ciphertext.

The primary key is never restored either: it identifies the record rather than describing its state.

### The result says what it did

```php
$result = $audit->restore();

$result->applied;                  // ['email', 'role']
$result->entry;                    // the Audit that recorded the restoration
$result->reason('token')->message('token');
// The token was stored as a digest, which cannot be reversed.

foreach ($result->skipped as $field => $omission) {
    // Omission::RedactedField, Omission::UnknownField, Omission::KeyUnavailable, …
}
```

There is no method on it that returns `bool`. A restoration that put back four fields out of six is
neither a success nor a failure, and `true` would hide the two a masked value and a dropped column
left behind.

**A field the schema no longer has is skipped, and the rest still goes back.** That is deliberate: a
column a later migration dropped is an accident of the schema, not a reason to abandon the five
fields that are still there.

Five conditions refuse the restoration whole, and `$result->refused` says which:

| `$result->refused` | When |
|---|---|
| `SubjectMissing` | The record was deleted for good, or its type no longer resolves to a model |
| `EntryRedacted` | The entry was redacted: its contents were destroyed on purpose |
| `EntryTampered` | The entry no longer reproduces its own hash |
| `EntryStateless` | The entry holds no state — a stated fact, or a model with `$auditSnapshots = false` |
| `Cancelled` | A listener stopped it |

**A tampered entry restores nothing.** Restoring is the only thing the package does that writes into
your business model out of what the ledger holds, so an entry that cannot answer for itself does not
get to. The hash is checked before anything is touched.

### It is a write, and it is audited like one

The whole restoration is **one database transaction**: either the applicable set goes back and the
ledger settles the entry, or the record is untouched. Auditing is paused for the save itself, so the
trail carries the restoration and not a restoration plus an `updated` describing the same movement
backwards.

Restoring the same entry twice writes nothing the second time. There is no movement to record, and
an entry for it would be a link in the chain describing no change:

```php
$audit->restore();
$audit->restore()->applied;   // []
```

A record in the recycle bin comes back out of it: `restore()` finds it past the soft-delete scope
and clears the deletion mark. A **granular** restore does not — you named the fields you wanted, and
reviving the record was not one of them.

### Restoring a relation

`restoreRelationship()` reads the lines [relationship auditing](#relationship-auditing) recorded and
leaves the relation the way that entry left it: what it attached stays attached with the pivot it
had, what it detached stays detached. A related record that has since been deleted is skipped with
`RelatedMissing` rather than breaking referential integrity halfway through.

One entry per call, however many pivot rows it takes — the same way a `sync()` that touched three of
them was one entry.

### Who may restore

Sentinel imposes no gate. Restoring writes into your business model, and which of your users may do
that is your decision, not an audit engine's. The hook is an event, and returning `false` stops it:

```php
Event::listen(function (AuditRestoring $event): bool {
    return Gate::forUser($actor)->allows('restore', $event->subject);
});
```

`AuditRestoring` carries the entry, the record and the keys about to move — keys, not values, so a
field the pipeline masked on the way in does not escape in an event payload on the way out.
`AuditRestored` follows **after** the commit, with the closed result — its `entry` is always there,
even where the call that returned it had none yet because your own transaction had not committed.
Announcing it any earlier would tell listeners about a change a rollback is still free to undo.

A restoration is recorded even inside `Sentinel::withoutAuditing()`. That switch says not to audit
what you are about to do, and a restoration is not that: it is the engine writing its own trail back
into your model, and a trail that can put a record back without saying so misleads by omission.

### A restoration is not a transition

An entry with `audit_type = restore` does not appear in `Sentinel::transitions()`, even when it
moves a column named in `$auditTransitions`, and the state machine of `DeclaresTransitions` does not
govern it. A lifeline answers which states the workflow moved through, and a correction made by an
operator is not one of them. What moved is still on the entry, in its own `changes`.

Ask for restorations the way you ask for anything else:

```php
Sentinel::audits()->whereType('restore')->for($invoice)->get();
```

And mind the vocabulary: `event = 'restored'` is Eloquent's — a soft-deleted record coming back out
of the bin — while `audit_type = 'restore'` is this engine. The two are different facts and the
filters tell them apart.

## Reading a trail out loud

```php
$presenter = app(ElPandaPe\Sentinel\Presentation\AuditPresenter::class);

$presenter->entry($audit);
// Administrator #1 acting as User #100 changed Invoice #500

$presenter->fieldHistory($history, 'email');
// v1  ada@example.com
// v4  ada@work.example

$presenter->timeline($entries);
// 10:02  Someone created Role #3
// 11:30  User #100 changed Invoice #500
```

Every word comes from `resources/lang`, event names included — "changed" is what a person reads, not
what the column holds. English and Spanish ship with the package; `--tag=sentinel-lang` publishes
them.

An impersonated entry has its **own** language key rather than a clause appended to the plain one:
the two languages do not put "on behalf of" in the same place, and a conditional concatenation would
freeze English word order into every translation that came after.

## Serialization

`$audit->toArray()` gives the entry as data, and from `v0.15.0` its shape is a **public contract**:

| Key | Shape |
|---|---|
| `id` | `string` (ULID) |
| `audit_type`, `event`, `severity`, `source` | `string` |
| `subject`, `actor`, `impersonator` | `{type, id}` \| `null` |
| `tenant_id`, `version` | `string` \| `null`, `int` \| `null` |
| `changes` | `list<{path, op, old, new}>` \| `list<relation line>` \| `null` |
| `before`, `after`, `metadata`, `context` | `object` \| `null` (`context` is never null) |
| `tags` | `list<string>` |
| `transaction_id`, `request_id`, `trace_id`, `span_id`, `source_audit_id` | `string` \| `null` |
| `criteria` | `object` \| `null` — the shape of a mass operation |
| `affected_rows` | `int` \| `null` |
| `integrity` | `{stream, sequence, algorithm, payload_version, previous_hash, hash, signature, signature_key_id, verified, redacted}` |
| `occurred_at`, `created_at` | `string`, ISO-8601 with microseconds |

**Keys are only ever added.** None is renamed, none is removed, and none is quietly reinterpreted:
a shape that has to change arrives beside the one it replaces, and the old one stays until `v2` with
its deprecation in `UPGRADE.md`. A snapshot test names every key above, so one arriving or leaving
is a decision somebody made rather than a detail that slipped out.

`AuditResource` is the same shape over HTTP and adds nothing to it:

```php
return AuditResource::collection(Sentinel::audits()->for($invoice)->paginate(50));
```

The package mounts no routes for it. Which entries a request may see is an authorisation question,
and Sentinel has no standing to answer it for your application.

### `verified` has three states, and `null` is not failure

```php
match ($entry['integrity']['verified']) {
    true => 'verified',
    false => 'TAMPERED',
    null => 'not checked',       // ← still the only value: toArray() does not walk the chain
};
```

`toArray()` does not walk the chain to verify, so it says `null` rather than guessing. Write the
three-way check: `!$entry['integrity']['verified']` renders "not checked" as "TAMPERED" in PHP and
in JavaScript alike. To actually verify, ask: `$audit->verifyIntegrity()`.

### What is deliberately not there

`encryption` and `signature` are absent for different reasons. `toArray()` never decrypts, so the
ciphertext and its `key_id` stay where they are — and publishing that block would tell every API
consumer which fields are protected and which key is current. `signature` is published, inside the
`integrity` block beside the hash it covers: without it an exported entry is not something a third
party can verify, which is the whole point of signing over the hash.

`capture_id` is absent because no capture writes one yet. The redaction block is `null` for every
entry nobody redacted, and carries `at`, `reason` and `hash` for one that was — see
[Redaction](#redaction). `criteria` and `affected_rows` arrived in `v0.17.0`, which is the version that produces
them; they are `null` on every entry that is not a mass operation.
There is **no top-level `relation` key**: a relation entry's lines live inside `changes`, which is
what the chain seals. `sentinel_audit_relations` is a queryable projection of exactly those lines —
an index, not the fact.

### Two orders the package fixes, and the ones it does not

MySQL and PostgreSQL both reorder the keys of a JSON object on the way in. So everything the package
writes, it also orders: the diff entries and the relation lines inside `changes`, the pivot maps
inside those lines, and the labels. The same entry serialises identically on SQLite, MySQL and
PostgreSQL.

Inside `before`, `after`, `context`, `metadata`, `criteria` and the `old`/`new` of a change, the key
order is your data's and the engine's — MySQL and PostgreSQL both reorder the keys of a JSON object
on the way in. That is not part of the contract; the keys are. The hash is unaffected either way:
canonicalisation sorts before it hashes, so the same entry hashes the same on all three engines.

`RestoreResult` and `Enums\Omission` are frozen under the same only-ever-added rule, and stay
outside `toArray()`: they answer a call, they are not part of an entry.

## Configuration

`config/sentinel.php` ships every section the package will use through 1.0, with future features
turned off. Read it once and you know what is coming.

Six sections are live today beyond the basics: `resolvers` decides who and where an entry came
from, `pipeline` is the ordered list of stages every entry travels through, `security` holds the
redaction mask and field lists, the encryption keyring and the hashing salt, `on_write_failure` with
`log_channel` decides what a write that did not complete does to the request, `mode` with `queue`
and `buffer` decides [where an entry settles](#performance-modes), and `retention` with `prune`
decides [what stops being kept](#retention--pruning).

Every one of those keys also has its default **in code**. Laravel merges a published config file one
level deep, so an installation that published `sentinel.php` before a subtree existed would
otherwise silently win over the package and end up with nothing configured at all.

## Schema & models

`sentinel_audits` ships complete: forty columns, created once and never altered by a later minor.
Thirteen indexes — eleven from `v0.2.0` and two added by `v0.10.0` for the clock a timeline orders
by. Most columns stay empty until the version that writes them lands — an empty column
in an empty table costs nothing, an `ALTER` on a table with ten million rows costs a maintenance
window.

| Group | Columns |
|---|---|
| Identity | `id`, `stream`, `sequence` |
| What happened | `audit_type`, `event`, `severity` |
| Who | `subject_type`/`subject_id`, `actor_type`/`actor_id`, `impersonator_type`/`impersonator_id`, `tenant_id` |
| Correlation | `transaction_id`, `request_id`, `trace_id`, `span_id`, `source`, `version` |
| Payload | `context`, `before`, `after`, `changes`, `metadata`, `criteria`, `affected_rows` |
| Integrity | `payload_version`, `encryption`, `algorithm`, `previous_hash`, `hash`, `signature`, `signature_key_id` |
| Lifecycle | `capture_id`, `source_audit_id`, `redacted_at`, `redaction_reason`, `redacted_hash`, `occurred_at`, `created_at` |

The JSON and date types come from the engine grammar: `jsonb` on PostgreSQL 16, `json` on MySQL 9,
text on SQLite; `datetime(6)` on MySQL and `timestamp(6)` on PostgreSQL.

Five tables travel beside it, each created by the version that first writes to it:
`sentinel_audit_tags`, `sentinel_audit_relations`, `sentinel_transactions`, `sentinel_checkpoints`
and `sentinel_archives`. The last two are worth telling apart. **The anchors can be thrown away** —
every root is derivable from the entries again, so losing them costs speed. **The archives cannot**:
that table accounts for entries that are no longer in `sentinel_audits`, so losing it loses the map
rather than a shortcut. And once a range has been [retired](#retention--pruning), the anchor covering
it stops being a shortcut too: it is the only thing left standing behind those entries.

### Engines

| Engine | Run on every push | What the SQL it emits needs |
|---|---|---|
| PostgreSQL | 16 | 9.4, where `jsonb` and `jsonb_array_elements` arrive |
| MySQL | 9 | 8.0.4, where `JSON_TABLE` arrives |
| SQLite | 3.45 on today's runner, 3.53 in the dev container | 3.38, where JSON stops being a compile-time option |

Only the middle column is a support claim: this package does not declare compatibility it does not
run. The right-hand column is not a promise — it is the floor the emitted SQL needs, and the first
place to look when an older engine misbehaves.

MariaDB is not in that table and is refused rather than guessed at. `whereFieldChanged()` has no
dialect for it, so it declines by name instead of answering with something that might not mean the
same thing.

Below the version a row is run on, the entries that come back are the same; what can differ is the
plan the engine picks to find them. SQLite is the worked example. From **3.51** it rewrites the
correlated `EXISTS` behind a label filter into a semi-join and seeks `(tag, audit_id)`; every
version before it evaluates that `EXISTS` once per row with the entry id already fixed, and seeks
`(audit_id, tag)`. Both are a seek and both answer the same, which is why the suite asserts the seek
and not the name of the index that served it.

`subject_id`, `actor_id` and `impersonator_id` are `string(64)`, so integer, UUID and ULID keys all
fit without a migration. There is no `updated_at`: the table is append-only.

`sentinel_audit_tags` sits beside it: `(audit_id, tag)` unique, with a `(tag, audit_id)` index the
other way round. No foreign key — date partitioning and batched purging both live badly with a
cascade — so cleaning up after a purged entry belongs to whoever purges it.

`sentinel_transactions` is the header of a [business transaction](#business-transactions), keyed by
the `transaction_id` its entries already carry, so the correlation needs no join table. It names the
actor with the same morph an entry uses, rather than a second shape for the same person. It is the
one table here that is **updated** rather than appended to: a header is opened when the operation
starts and completed when it ends, and nothing in it is hashed — what happened is in the entries,
where the chain covers it.

Two things the schema does **not** promise. Order comes from `(stream, sequence)`, never from the
clock — `occurred_at` records when something happened, it does not sort the chain. And neither MySQL
`json` nor PostgreSQL `jsonb` preserves the key order you wrote; values round trip intact, order does
not. That is precisely why the integrity chain canonicalises before hashing.

```bash
php artisan vendor:publish --tag=sentinel-migrations
php artisan migrate
```

Publishing is optional: the package loads its own migration unless it finds a published copy, so the
migration never runs twice.

`AuditFactory` is not publishable and does not need to be: it ships inside the package and your
application autoloads it, so `Audit::factory()` works the moment the package is installed. The
supported way to change what it builds is `models.audit`, below.

Replace the model with your own subclass:

```php
// config/sentinel.php
'models' => [
    'audit' => App\Models\Audit::class,
    'transaction' => App\Models\AuditTransaction::class,
],
```

## Integrity chain

Chaining is unconditional: every entry a `Ledger` writes links to the one before it in its stream,
regardless of configuration. `integrity.enabled` does not govern this — it is
[signatures](#signing-the-chain) and [anchors](#anchoring-ranges) that are optional, and both ship
off.

Each entry's `hash` covers a prefix and the canonical payload:

```
hash = algorithm(payload_version + SEP + stream + SEP + sequence + SEP + (previous_hash ?? '') + SEP + canonical)
```

`SEP` is the ASCII unit separator (`\x1f`), so the parts of the prefix cannot concatenate into one
another. `algorithm` is read from the row itself — `sha256` by default — never from the current
configuration, so a row keeps verifying under the algorithm it was written with even if the
configured default changes later. `canonical` is the RFC 8785 canonical JSON of the twenty-seven
columns frozen in `Integrity\CanonicalPayload::COLUMNS`. Writing and verifying both call
`Integrity\Hasher::hash()`, so they walk the exact same code.

**Labels are not covered by any of this**, and that is deliberate in both directions. Classifying an
old entry when a new category appears does not break its hash — making it would turn a taxonomy into
an integrity problem — and equally, relabelling leaves no trace that `verifyIntegrity()` can find.
Labels are operational classification; what has to be provable goes in `metadata`, which is inside
the payload the hash covers. See [Labels](#labels).

`stream` is part of that prefix, which is why a stream is never renamed in place: changing the
stream strategy while a chain already holds data does not rewrite the old rows under the new name,
it starts a second chain under it — the history ends up split across two independent chains instead
of continuing as one.

Verify a whole chain, or a bounded slice of it:

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

$result = Sentinel::verifyIntegrity('global', from: 1, to: 500);

if (! $result->isIntact()) {
    // $result->reason, $result->sequence and $result->auditId locate the break
}
```

Or a single entry, from the model itself:

```php
use ElPandaPe\Sentinel\Models\Audit;

$audit = Audit::query()->firstOrFail();

$audit->verifyIntegrity(); // bool
```

A verification failure never throws. It dispatches `Events\IntegrityVerificationFailed` and reports
through `Integrity\VerificationResult`, for one of six reasons (`Enums\IntegrityBreak`):

| Reason | Means |
|---|---|
| `hash_mismatch` | The row no longer reproduces its own hash — its content changed. |
| `link_mismatch` | Its `previous_hash` no longer matches the entry before it — the order changed. |
| `sequence_gap` | A `sequence` is missing from the stream and nothing accounts for it. A range [you retired](#verifying-a-trail-you-have-pruned) is stepped over instead, and only when an anchor reaches past it. |
| `signature_mismatch` | The signature does not verify under the key the row names. |
| `projection_mismatch` | `sentinel_audit_relations` no longer matches the lines the entry sealed. The chain is intact; the index is not. |
| `checkpoint_mismatch` | An anchor no longer folds to the root it recorded, over a range whose entries are otherwise sound. |

An entry is immutable through every path the model exposes: `save()`, `update()`, `delete()` and
`destroy()` all throw `Exceptions\ImmutableAuditException` once the row exists. That guard runs on
Eloquent's model events, so it only sees a change that goes through the model — an `update()` issued
through the query builder (`Audit::query()->where(...)->update([...])`) never fires those events and
does not pass through it.

### Verifying the whole trail

`verifyIntegrity()` answers about one chain. `verifyEverything()` walks all of them:

```php
$report = Sentinel::verifyEverything();

$report->isIntact();       // bool
$report->checked();        // how many entries were walked
$report->signatures();     // ['unsigned' => 4000, 'signed' => 120]
$report->firstBreak();     // the VerificationResult that failed, or null
```

Listing the chains is a capability, not a requirement: `Contracts\EnumeratesStreams` is declared by
`DatabaseLedger`, `MemoryLedger` and `FanoutLedger`, and not by `NullLedger`, which keeps nothing to
list. A driver that does not declare it is refused rather than answered with an empty report —
"nothing is broken" about a list nobody could build reads as reassurance and means nothing.

## Signing the chain

Chaining proves an entry has not changed *relative to the ones around it*. It does not stop someone
who can rewrite the table from rewriting the whole chain and rehashing it. A signature is what makes
that require a key as well as write access.

```php
// config/sentinel.php
'integrity' => [
    'signature' => [
        'enabled' => true,
        'signer' => 'hmac',            // hmac | openssl | null
        'algorithm' => 'sha256',
        'key_id' => 'default',
        'keys' => ['default' => env('SENTINEL_SIGNING_KEY')],
        'private_key' => env('SENTINEL_SIGNING_PRIVATE_KEY'),
    ],
],
```

**The signature is over the `hash`, never over the payload.** Signing is then one operation on
sixty-four characters instead of one over two snapshots and a diff, and verifying it needs neither
the entry recomposed nor anything decrypted. Neither `signature` nor `signature_key_id` is inside
`CanonicalPayload::COLUMNS`, so filling them does not touch the hash and does not move
`payload_version`.

### `keys` verifies, `private_key` signs

The split is the point. `keys` maps an identifier to what **verifies** with it — the shared secret
under `hmac`, the public key under `openssl` — and `private_key` names only what the current
identifier signs with. Hand an auditor the ring and they can prove every entry untouched and write
none.

Under `hmac` one secret does both, and `keys.default` left null is derived from `APP_KEY` under a
label of its own, so the signing secret is not the same bytes as the digest salt of
[hashed fields](#what-each-one-does-and-does-not-promise).

### Rotating is moving on, retiring is staying put

```php
'key_id' => 'v2',
'keys' => [
    'v1' => env('SENTINEL_SIGNING_KEY_V1'),   // retired: verifies, never signs again
    'v2' => env('SENTINEL_SIGNING_KEY_V2'),   // current
],
```

Every row records the key that signed it, so yesterday's entries keep verifying with yesterday's key
while today's are written with today's. Retiring a key is leaving it on the ring; **removing** it is
what makes its history unverifiable, and that is reported as such rather than as forgery.

### Four states, and why they are not three

```php
use ElPandaPe\Sentinel\Enums\SignatureState;

$audit->verifySignature();   // Signed | Unsigned | Invalid | UnknownKey
```

| State | Means |
|---|---|
| `Signed` | The signature verifies under the key the row names. |
| `Unsigned` | The row carries none. Written before signing was switched on, and not a defect. |
| `Invalid` | The key was resolved and the signature does not verify. **This is a defect.** |
| `UnknownKey` | The row names a key the ring does not hold. Nothing can be decided. |

Collapsing `Unsigned` into `Invalid` would turn every installation that has not switched signing on
into a wall of failures. Collapsing `UnknownKey` into either one would have the verifier deliver a
verdict it is not entitled to. The distinction is [RFC 4033 §5](https://www.rfc-editor.org/rfc/rfc4033.html)'s,
which drew it for DNSSEC for the same reason.

`$audit->verifyIntegrity()` keeps meaning exactly what it meant — whether the row reproduces its own
hash — and returns a `bool`. Four states do not fit in one, and making an unsigned entry return
`false` would call the whole history before signing a failure.

### Three tiers of threat model, stated plainly

| Signer | Stops | Does not stop |
|---|---|---|
| `HmacSigner` | Someone with database access and no application access: a stolen backup, a replica, a console, a SQL-injection sink | Anyone who can read `APP_KEY` or the configured secret — which usually means anyone who compromised the app |
| `OpenSslSigner` | All of the above, **and the machine's own administrator**, when the private key lives off the machine the entries do | Whoever holds the private key. [RFC 5848 §8.3](https://www.rfc-editor.org/rfc/rfc5848.html) acknowledges this rather than mitigating it, and so does this |
| `NullSigner` | Nothing. It signs nothing and attests to nothing | Everything |

A fourth tier exists and is deliberately out of scope: a **forward-secure** MAC that evolves its key
and erases the old one, so entries written before a compromise stay provable. `systemd-journald`
ships one. Sentinel does not, and says so rather than implying the HMAC is more than it is.

What no signature proves is that the content is **true**. It proves nobody touched the row after it
was written. Someone with application access at capture time produces a perfectly intact, perfectly
signed chain of false statements. That is append-time integrity, and it is the honest limit of it.

### Signing costs what it costs

Median of three passes, SQLite with `synchronous` and journal off, the four rows inside one run:

| Variant | µs per write | Δ vs unsigned |
|---|---|---|
| unsigned | 2400.3 | — |
| `HmacSigner` (sha256) | 2436.9 | +1.5 % |
| `OpenSslSigner` (RSA-2048, sha256) | 3252.3 | **+35.5 %** |
| `NullSigner` | 2226.1 | −7.3 % |

The `NullSigner` row is the noise gauge: it adds one method call and still moves between −10 % and
+12 % across passes, so the HMAC figure is indistinguishable from zero and the RSA one is not. RSA
costs roughly **850 µs of private-key work per entry**, on the write path, every write. If that
matters, sign with `hmac` and put the trust boundary somewhere else — an
[anchor](#anchoring-ranges) is the thing worth exporting to where an administrator cannot reach it.

## Anchoring ranges

Verifying a chain means walking it. On a trail that only grows, that eventually means reading
everything to answer a question about anything. A **checkpoint** is an anchor over a range: the root
that range folds to, signed, so the history can be verified without being reread — and so a range
that does not add up can be found without walking the ranges that do.

Anchors are off by default:

```php
'integrity' => [
    'enabled' => true,
    'checkpoints' => [
        'enabled' => true,
        'every' => 1000,
    ],
],
```

`every` is a **fixed window**, not "whatever is pending". A window of a thousand anchors `[1, 1000]`,
then `[1001, 2000]`, and the trailing incomplete window is left alone until it fills. If the window
were whatever happened to be pending, the two ends of a range would depend on when the emission ran
and the root of one history would stop being reproducible.

### What an anchor is

```
name    = "fold-" + algorithm                      // fold-sha256
root₀   = H( name ⑴ stream ⑴ from ⑴ to ⑴ (previous root ?? "") )
rootᵢ   = H( rootᵢ₋₁ ⑴ hashᵢ )                      // every entry of the range, in sequence
```

`⑴` is the same `\x1f` separator the entry hash uses. Three things are worth knowing about that
formula:

- **It folds; it is not a Merkle tree.** A tree buys inclusion proofs, and nothing in Sentinel
  consumes one. Git and RFC 5848 fold for the same reason; the tree turns up where a remote client
  that does not trust the log asks for a single record — and CT, Trillian, immudb and QLDB all keep
  a chain underneath the tree anyway. The `algorithm` column records `fold-sha256`, which is the
  door a `merkle-sha256` comes through later without touching a row already written.
- **The previous anchor goes into the fold.** Contiguous integers are not linkage. Without it,
  whoever rewrites a range and reissues its anchor produces a history that agrees with itself; with
  it, reissuing one anchor forces reissuing every anchor after it. There is no column for it — the
  previous anchor is the row whose `sequence_to` is this one's `sequence_from` minus one.
- **The prefix separates domains.** The construction, the stream and both ends of the range go in,
  so a range of one cannot collide with the entry it contains and the same range over another stream
  cannot land on the same root. It is why RFC 6962 §2.1 prefixes leaves and nodes: the ambiguity is
  unfixable after the first anchor is written.

Anchors are signed with the same `Signer` as entries and record their `key_id`. **An unsigned anchor
is a row anyone with write access can reissue** — anchoring without signing buys speed and no trust.

### Three depths, and what each one actually proves

This is the part worth reading before trusting a number:

| Call | What it reads | What it proves | What it does not |
|---|---|---|---|
| `Sentinel::verifyAnchors($stream)` | the anchors, and the tail no anchor covers | the anchors are a contiguous signed chain, and the tail links | nothing about the current content of the anchored entries |
| `Sentinel::verifyRoots($stream)` | `(sequence, hash)` of each range; walks whole only a range that does not fold back | no hash was rewritten or reordered, and **where** | that an entry's content did not change while its `hash` column stayed put |
| `Sentinel::verifyIntegrity($stream)` | every entry, rehashed | everything | — |

The fold is over the stored `hash` of each entry, not over its content. So someone who edits a
canonical column *and* leaves `hash` alone passes both shallow walks and is caught only by the deep
one. `verifyIntegrity()` is unchanged by any of this and stays the walk it always was: switching
anchoring on never makes an installation verify less than it did the day before.

Because of that, **a range under a valid anchor is reported as `anchored`, never as `intact`**. The
report keeps the two numbers apart:

```php
$verification = Sentinel::verifyAnchors('global');

$verification->chain->checked;  // entries read
$verification->covered;         // entries taken on an anchor's word
$verification->anchors;         // ['anchored' => 41]
$verification->isIntact();      // nothing came back wrong — not "everything was read"
```

A stream nobody has anchored reports `['absent' => 1]` and is walked whole, which gives the same
answer the deep walk gives, only having paid for it. A missing anchor is a state, not a failure;
`checkpoint_mismatch` is the failure.

### Emitting them

The recommended route is the command, on the application's own schedule:

```php
// routes/console.php
Schedule::command('sentinel:checkpoint')->hourly();
```

**The schedule belongs to the application.** Sentinel registers commands and nothing else; a package
that puts itself on a scheduler is a surprise in somebody else's application.

The other route is a threshold, evaluated after each write and outside the sealing transaction:

```php
'checkpoints' => ['enabled' => true, 'every' => 1000],
```

That puts the fold on whichever write completes a window. Median of three passes, SQLite with
`synchronous` and journal off, all rows inside one run:

| Variant | µs per write | Δ vs unanchored |
|---|---|---|
| not anchored | 2204.6 | — |
| every 1000 entries | 2428.8 | +10.2 % |
| every 100 entries | 2448.3 | +11.1 % |
| every 100 entries, HMAC signed | 2468.1 | +12.0 % |

The unanchored row spans 7.5 % across its own three passes, so what is left is small and — this is
the part that matters — no longer grows with the window. Anchoring on a schedule keeps it off the
write path entirely, which is why that is the route the table recommends.

Only the ledger that assigns sequences anchors. `MemoryLedger` and `NullLedger` keep nothing to fold,
and a `FanoutLedger` anchors through the primary that sealed the entries.

## Retention & pruning

`sentinel_audits` only ever grows. Retention is how it stops: a policy per logical audit type, a
command that says what it would remove before it removes anything, and a verification that can tell
a range you retired from an entry that went missing.

```php
// config/sentinel.php
'retention' => [
    'model:App\Models\User' => '7 years',
    'auth'                   => '90 days',
],
```

A key is a **logical type**, never a table. `model:<FQCN>` names what an entry is about and beats a
bare `audit_type`, which names what kind of entry it is. The colon is the whole discriminator —
`model` on its own is a legal `audit_type` and means something else. The class is resolved through
your morph map before it is compared to anything, so a `model:` key is not quietly inert on an
application that declares one.

**What no policy names is kept forever.** Retention is something you opt into one logical type at a
time, not something that starts deleting the day it is switched on. A malformed period, or two keys
that would govern the same entries, is a configuration error and says so rather than being resolved
by hash order.

The clock is `created_at` — how long the *record* is kept — and not `occurred_at`, which the caller
can set.

```bash
php artisan sentinel:prune --action=delete --dry-run
php artisan sentinel:prune --action=delete
php artisan sentinel:prune --action=delete --stream=tenant:acme
php artisan sentinel:prune --action=delete --batch=200
```

`--batch` names the size for one run. The configured size is what a schedule uses; a run made by
hand is where somebody is watching the load and wants a smaller slice this once, and editing the
config to get it would leave the smaller slice behind for the schedule too.

```
+-------------+--------+---------+---------+------------------------------------------+
| Stream      | Ranges | Entries | Rate    | Note                                     |
+-------------+--------+---------+---------+------------------------------------------+
| global      | 3      | 3000    | 4,182/s | —                                        |
| tenant:acme | 0      | 0       | —       | Retention still keeps model:invoice at    |
|             |        |         |         | sequence 2044 of tenant:acme…            |
+-------------+--------+---------+---------+------------------------------------------+
Removed 3000 entries in 3 ranges across 2 streams.
```

`--action` defaults to `archive`, because the action that loses nothing is the one an operator should
get for forgetting a flag. It was deliberately not a default while `delete` was the only action there
was: a default that meant *remove* then and *write it out first* now would have changed what a
scheduled command did without the schedule changing.

### The unit is the anchored window, not the entry

This is the part to read before declaring a policy, because it decides what a policy actually does.

A range leaves only when **an [anchor](#anchoring-ranges) covers it and every entry in it has been
released**. That is not a shortcut, it is the only unit the chain admits: a window is folded whole,
so a partly emptied one could never reproduce its root again, and scattered deletions would leave a
hole per row with nothing able to answer for any of them.

The consequence, stated plainly: **the effective retention of a range is that of its longest-lived
entry.** Under the shipped `integrity.stream => 'tenant'` a stream mixes logical types, so one
seven-year entry keeps its whole window — and `'auth' => '90 days'` frees nothing in that window.
Entry-level removal needs the tombstone, and that is a later version.

Two more rules fall out of the chain rather than out of preference:

- **The window holding the highest sequence of a stream is never offered.** The writer derives the
  next sequence from that row, so a stream emptied to nothing would hand the next write sequence 1
  and start a second chain under the first one's name.
- **A held window does not hold the ones behind it.** The offer is not a prefix, which is what makes
  retiring a range in the middle possible at all.

Because of all this an operator can watch a ninety-day policy do nothing for a reason that is
perfectly correct, so a stream that released nothing says which of four things is holding it:
nothing declared, nothing anchored, everything in the live tail, or something still kept — and in
the last case, which entry and which policy.

### What a purge does not touch

- **Anchors.** A `sentinel_checkpoints` row covering a retired range is never removed: it is now the
  only thing standing behind entries that are gone.
- **Headers.** A `sentinel_transactions` row goes when its last entry does, asked across every
  stream, and its `audits_count` is left alone — it is what the operation captured, and a purge does
  not change what happened.
- **The evidence of a tampering.** Every window is refolded against the root its anchor recorded
  *before* a row is touched. A range that no longer folds stops the run on that stream and leaves
  every row where it is.

Labels and relation lines do go, with the entries they hang off. They are found through the entries
and removed in the same transaction, because rows carrying only an `audit_id` are rows nothing
surviving could name again.

### It does not reclaim disk space, and it says so

InnoDB does not return freed pages, and `OPTIMIZE TABLE` locks the table for reads and writes;
PostgreSQL leaves dead tuples for autovacuum, and `VACUUM FULL` takes an exclusive lock. Both are
DBA operations with a maintenance window attached, so this command runs neither. On a partitioned
table the question changes shape entirely — a purge becomes a partition drop — and that is a later
version.

### Verifying a trail you have pruned

A retired range is not a gap, and the reverse also holds: a gap nobody accounts for is still a gap.

`sentinel:verify` steps over an absence only when **two** things account for it at once — the
manifest says the range was retired, and the anchors reach past it. Neither alone will do. Nothing
in `sentinel_archives` is hashed or signed, so on its own it would make *delete the rows, then
insert one row* a supported way of laundering a gap.

What was stepped over is counted apart from what was read, everywhere it is reported:

```
+--------+---------------+--------+-------------------------------------------------+
| Stream | Entries       | Chain  | Anchors                                         |
+--------+---------------+--------+-------------------------------------------------+
| global | 208 (+41000 retired) | intact | 41 anchored, 10 retired (covering 51000…) |
+--------+---------------+--------+-------------------------------------------------+
```

**The seam is not faked.** The hash the first surviving entry links to left with the range, so the
walk treats it exactly as it treats the edge of a bounded range: not checked, and not invented
either. That is why those entries are counted apart — an entry nobody read is not an entry that
verified.

## Cold archiving

Retention decides what stops being kept. This decides where it goes instead of nowhere.

`Ledger\ArchiveLedger` writes NDJSON to any disk `Storage` can reach — configure S3, R2 or MinIO in
`filesystems.php` and it works there, because this package only ever talks to the Filesystem
contract and has no idea those exist.

```php
// config/sentinel.php
'ledger' => [
    'ledgers' => [
        'archive' => [
            'disk' => env('SENTINEL_ARCHIVE_DISK', 'local'),
            'path' => 'sentinel',
            'codec' => 'gzip',   // or null for plain text
            'batch' => 1000,
        ],
    ],
],
```

```bash
php artisan sentinel:prune --dry-run     # archive is the default action
php artisan sentinel:prune
```

`--action` defaults to `archive`, because the action that loses nothing is the one you should get
for forgetting a flag. `--action=delete` still removes a range without writing it anywhere.

### Nothing is removed until the batch has been read back and rehashed

The order is deliberate and it is the whole feature:

1. build the lines, compress them, and digest the bytes that are about to be written;
2. write them;
3. **read them back** and digest again — the only proof the Filesystem contract offers that the
   bytes landed at all;
4. **rebuild every entry out of what came back and rehash it** against the hash it carries sealed;
5. only then record the batch in `sentinel_archives`;
6. only then remove a row.

Step 4 is not belt and braces. RFC 8785 canonicalisation sorts keys, and a PHP map whose keys happen
to be `{0..n-1}` in the wrong order goes out as an object and comes back as a **list** — a shape the
database round trip preserves and a JSON file does not. Without that check, such an entry is
archived, purged, and found to be unrestorable years later, when there is nothing left to do about
it. It has a test, and a second one pinning that the hot rows are still there when it fires.

An interruption anywhere before step 5 leaves a file nobody points at. That is garbage, never
evidence loss, and it is what makes the run resumable.

### What a batch holds

One JSON object per line, and every line names its own kind:

| Kind | What it is |
|---|---|
| `batch` | The first line: the container's own `format` version, the stream, the range, the count |
| `entry` | One sealed entry — the forty columns of `sentinel_audits`, plus its labels |
| `operation` | The `sentinel_transactions` header of an operation the range touches |

The rule for an entry line is one sentence: **its key set is the entry's column set plus `tags`**.
Nothing derived, nothing computed, nothing left out — and a test compares it against the live schema,
so a column added later is a loud failure rather than a silent loss.

The `operation` lines are there because a purge removes a header once its last entry is gone, and no
column of an entry holds an operation's *name*. Without them, archiving would save an operation's
entries and destroy what it was called.

Relation lines are **not** in the batch and do not need to be: they live inside the entry's own
`changes`, which the chain seals, and the database ledger re-derives the projection when an entry is
put back.

The checksum is over the exact bytes written, compression included, so you can `sha256sum` a
downloaded object and get the recorded value. It is stored self-describing — `sha256:…` — the same
way an anchor records `fold-sha256`.

### The archive is a destination, not a hot ledger

It refuses to be `ledger.default`, with an error that says why. The tail of a stream lives on the
instance, because `sentinel_archives` holds no hash and could never hand one back, so a second
process would start a second chain under the same name. Name it as a fanout destination, or let
`sentinel:prune` write to it.

```php
'ledger' => [
    'default' => 'fanout',
    'ledgers' => [
        'fanout' => ['destinations' => ['database', 'archive'], 'on_failure' => 'strict'],
    ],
],
```

Two more things it declares rather than pretends. It does not implement `Contracts\Deduplicates`:
answering whether a capture has settled would be a scan with no index behind it. And `find()` and
`query()` are scans of the batches it wrote, not index lookups — it answers every published filter,
and it answers them by reading files.

**It never writes to `sentinel_archives`.** A row there means a range *left the hot table*, and the
purge's tamper guard and the verification both read those rows as licence. A cold copy of a range
that is still hot would disarm both, so the manifest has exactly one writer: the purge.

### Bringing a batch back

A batch goes back into the hot table exactly as it left — same `sequence`, same `hash`, same link,
same labels, same `version`, same `created_at` — and with the operation headers the purge removed,
without which every restored entry would point at something that no longer exists.

```php
use ElPandaPe\Sentinel\Archive\Rehydrator;

app(Rehydrator::class)->restore('global', 5000, 6000);
```

It is **idempotent and not atomic**. What is already there with the same hash is skipped, a sequence
held by a different hash is refused, and an interruption leaves a prefix a second pass finishes. The
check happens before the write rather than inside it, so rehydration is single-writer — two passes at
once can still collide on a unique index.

Every entry is rehashed before it goes in. "Without recomputing hashes" means without *reassigning*
one; recalculating to compare is exactly what is called for when rows return to the table that serves
as evidence.

**The manifest row stays.** It is the only place `disk`, `path`, `checksum` and the codec exist — not
even the file's own header carries them — so withdrawing it would leave bytes this package could
neither find nor verify. What a standing row would have licensed is the range being *absent* again,
and the purge no longer takes it as licence: it asks the rows instead.

**What `version` stops guaranteeing.** A restored entry brings its original number back, so a subject
whose whole history was purged and then written to again can end up with two entries claiming
version 1. Renumbering is not an option — `version` is inside the canonical payload, so a renumbered
entry stops reproducing its own hash. `whereVersion()` is therefore a filter that may legitimately
return several entries, and `compare()` can pair two eras of one subject.

## Redaction

Sometimes content has to be destroyed: an erasure request arrives, and no hash chain outranks it.

Sentinel does not pretend the two can both be satisfied, and does not claim they are incompatible
either. It says what it commits to: **Sentinel commits to content, so deleting the content breaks the
proof of content — and this is what survives.**

What survives a redaction: the entry's existence, its `sequence`, its `hash`, the `previous_hash` of
the entry after it, and a new entry saying who destroyed it and why. What does not: the proof of what
it said.

```php
use ElPandaPe\Sentinel\Redaction\Redactor;
use ElPandaPe\Sentinel\Support\Reference;

$tombstone = app(Redactor::class)->redact($entry, 'GDPR erasure request 4711', Reference::to($officer));

$tombstone->sequence;     // where it was, and still is
$tombstone->redactedHash; // the second hash, over what is left
$tombstone->trail;        // the entry that records who did this
```

Or from a terminal, where the actor is required because a console process resolves nobody:

```bash
php artisan sentinel:redact 01JD3M4N7QK5V8YBXWZ6TFCEHR \
    --reason="GDPR erasure request 4711" --actor=user:9 --dry-run
```

### What a tombstone empties

**All six content columns of the canonical payload**: `context`, `before`, `after`, `changes`,
`metadata` and `criteria` — plus the entry's labels and its relation lines, which live in their own
tables and are as much content as the columns.

Three of them are not obvious and matter more than the obvious ones. `changes` carries the literal
old and new values, so an entry whose `before` was emptied and whose `changes` was not is not
redacted at all. `context` carries the ip, the user agent, the url, the route and the method.
`criteria` carries the bindings of a mass operation. And **a relation entry keeps its content only in
`changes`**, so emptying `before`/`after`/`metadata` would redact nothing whatsoever.

`metadata` goes whole, which destroys facts that are not personal data — including the `reason` of a
transition. Redacting by key would leave an operator deciding which part of the content survives, and
that discretion is what a tombstone exists not to have.

### The three states, and what the second hash is worth

Verification stops having two answers and starts having three, **per entry**:

```php
$entry->verifyContent(); // ContentState::Sealed | Redacted | Altered
$entry->verifyIntegrity(); // still a bool, still "does this row reproduce its own hash"
```

`redacted_at` decides and `redacted_hash` corroborates, in that order. An entry whose content columns
were already empty redacts to the bytes it already had, so its two hashes are equal — asked the other
way round, that tombstone would report as sealed.

**What the second hash proves** is that the remains of a tombstone are the ones the redaction left: it
catches a later write into a redacted row. **What it does not prove** is anything against someone who
can write the row. It is outside the canonical payload, outside the signature and outside the fold, so
whoever can empty `before` can equally write `redacted_at` and a recomputed `redacted_hash`. What
separates a declared redaction from an attack is the **trail entry** — chained and signed like any
other — and an attacker simply does not write one.

The reason is outside it too: `redaction_reason` can be rewritten later without the state changing.

### What the verifier does with one

A declared redaction is counted, never announced. It does not stop the walk — it cannot, because the
tombstone keeps the hash the next entry links to — it does not fill `reason`, and it does not invert
`isIntact()`. `sentinel:verify` exits **0** for a stream whose only finding is redactions:

```
| global          | 402 (3 redacted)  | intact | 8 anchored (covering 8000 entries nobody read)  |

Verified 402 entries across 1 streams. The chain is intact, and 3 of them were redacted on purpose:
their contents are gone and the record of their destruction is not.
```

A real tampering still wins. It is `HashMismatch`, it stops the walk, and it is what gets reported —
otherwise a tombstone would be a place to hide one behind.

**The anchors and the roots keep reading a redacted range as `anchored`.** Neither opens an entry:
`verifyRoots` folds the `hash` column, which a tombstone keeps, and `verifyAnchors` reads no entry at
all. Making them see a redaction would cost a third column per row, which is the cost model they
exist to provide. All three depths agree on the thing that matters: none of them calls a tombstone a
tampering.

### What redaction does not reach

- **A range already archived or purged**, directly. Redacting an entry whose range left the hot table
  is refused, naming the batch that holds it — but the round trip is open: bring the range back with
  `Archive\Rehydrator`, redact it there, and let the next prune write it out again. A batch holds a
  tombstone perfectly well, and comes back redacted.
- **A bucket that keeps versions.** A batch's path is a pure function of its range, so writing one out
  again overwrites the same object key. With versioning or object-lock on — which this README
  recommends — the previous version of the batch survives, with the unredacted content in it. The
  package cannot promise a deletion the storage undoes.
- **Replicas, backups and copies you made yourself.** The package offers no way to prove a redaction
  completed everywhere, and does not pretend to.
- **Finding every batch that holds one person.** `sentinel_archives` is indexed by stream and range,
  never by subject, so an erasure request over someone's whole history is answered range by range.

Redaction is also not masking. `security.redaction.*` in the config masks values **as they are
captured**, before an entry is ever sealed; `Redactor` destroys the contents of an entry that was
sealed long ago. They share a word and nothing else.

## Compliance mode

**Sentinel certifies nothing.** It ships technical primitives — a chain, signatures, anchors, a
tombstone, an access record — and whether a given regime is satisfied by them is a question for
somebody who knows that regime. This section describes what the switch hardens, and nothing more.

```php
'compliance' => true,
```

**It refuses to boot without the things it would otherwise be claiming.** Signatures and anchors have
to be on. The chain is not on that list because it cannot be turned off; everything built on top of it
can, and an installation that calls itself compliant while running without them is making a claim its
configuration does not support. The failure happens at boot, not at the first write — by the time the
first write arrives, the entries that should have been signed are not.

**A redaction has to name who ordered it.** Outside compliance mode the actor is optional; inside it,
the one operation that destroys evidence cannot be the one with nobody's name on it.

**Deleting requires archiving.** `sentinel:prune --action=delete` refuses a range that has no archive
batch. The evidence is the manifest row of a real file, not a flag somebody set.

**Every read is recorded, in two places.** An entry with `audit_type = 'access'` — chained, hashed and
signed like any other — and a row in `sentinel_access_log` carrying the shape of the question, how
many results came back, and who asked. The entry is what makes a read provable; the row is what makes
it searchable. The editable copy is deliberately the second one and never the only one, because an
access log that can be edited proves nothing about who looked.

```php
$reads = AuditAccess::query()
    ->where('actor_id', $suspect->getKey())
    ->latest('created_at')
    ->get();

$reads->first()->audit(); // the entry that proves it, if it has not been pruned
```

It does not audit itself: writing the access entry is a write, not a read, and there is a reentrancy
latch behind that so no future caller can turn one read into a chain of them.

**What it costs**, measured over a hundred reads of fifty entries each on one machine: `0.309s`
without it, `0.557s` with it — about **+2.5ms per read**, or 1.8× what a read cost before. That buys a
chained entry and a row per query. On a read-heavy installation, decide with the number in front of
you rather than with an impression.

### Handing the trail to someone else

```bash
php artisan sentinel:export --format=ndjson --tenant=acme --disk=exports --path=trail.ndjson
```

Every export carries a manifest beside it — entry count, the digest of the body, and a signature over
that digest with the key this installation signs entries with. A recipient with the verifying half of
the key can tell the bytes are the bytes that were exported, without being given access to anything.

`ndjson` round-trips. `csv` flattens the nested columns into JSON inside cells: fine for a person with
a spreadsheet, wrong for anything that would be read back in. A redacted entry exports **as redacted**,
carrying its redaction block, so what leaves the building says the contents were destroyed rather than
pretending they were empty.

In compliance mode an export is a read like any other, and leaves the same two records. That is the
point rather than an oversight: it is the largest read a trail ever serves.

### Rotating the key without rewriting anything

```bash
php artisan sentinel:rekey --key=2027-q1 --tenant=acme --dry-run
```

Rotation writes; it never rewrites. Each entry carrying protected fields gets a **new** entry holding
the same values under the new key and pointing back at the one it stands in for. The original keeps
its hash, its link and its sequence, and keeps verifying for as long as its old key stays on the
keyring.

Which is the opposite of a redaction, and why no path of this command calls that one: a tombstone
destroys content, a rekey preserves it under a different lock.

## Scaling

A trail only grows. Nothing in this package deletes an entry to make room, so at some point the
question stops being how fast a write is and becomes what the table costs to keep. There are two
answers here and they are independent: an index for the two filters that read JSON, and a table
divided into partitions.

Neither is on by default and neither is a migration the package runs for you.

### The JSON index

`whereIp()` and `whereRoute()` read inside `context`. Publishing this makes them find by index
instead of scanning:

```bash
php artisan vendor:publish --tag=sentinel-json-indexes
php artisan migrate
```

PostgreSQL 16 gets a B-tree over the expression; MySQL 9 gets a `VIRTUAL` generated column with an
index on it, added instantly and invisible to `select *`. What it costs is in
[the filters](#the-two-that-live-inside-the-context), measured rather than estimated.

There is no GIN index here and that was a decision with a number behind it. Over `context` it costs
eight points more per write than the expression index and four times the space, to serve the one
plan this API publishes worse — 1.18 ms against 0.067 ms. Over `changes` it is not used at all:
`whereFieldChanged()` walks the array in a correlated `exists`, and the plan with a GIN present is
still a sequential scan.

### Partitioning

Three alternatives to the base migration, published one at a time. **They replace it**: the file
lands under the same name the package's own carries, and Sentinel stops loading its own.

```bash
php artisan vendor:publish --tag=sentinel-partitioned-pgsql-range    # PostgreSQL, by month
php artisan vendor:publish --tag=sentinel-partitioned-pgsql-tenant   # PostgreSQL, by tenant
php artisan vendor:publish --tag=sentinel-partitioned-mysql-range    # MySQL 9, by month
php artisan migrate
```

They are for a **new installation**, before the first entry. Converting a table that already holds
entries is a maintenance window with real risk, so the package does not attempt it — `UPGRADE.md`
describes the procedure, including the way back.

| Stub | Divides by | Engine | What it is for |
|---|---|---|---|
| `pgsql-range` | `created_at`, one partition per month | PostgreSQL 16 | Retiring old ranges as a catalogue operation |
| `pgsql-tenant` | `LIST (tenant_id)` | PostgreSQL 16 | Multi-tenant, and the only one that keeps the chain's guarantee |
| `mysql-range` | `RANGE (TO_DAYS(created_at))`, one partition per month | MySQL 9 | Retiring old ranges, with a caveat below |

**What you give up, said plainly.** Both engines require every unique key of a partitioned table to
carry the partitioning column. Under a division by date that means `(stream, sequence)` and
`capture_id` gain `created_at` and stop being enforced **across** partitions: the engine will accept
a duplicate sequence planted in another month. What still holds the chain is the ledger's own
sequence assignment and `sentinel:verify`, which fails on exactly that — there is a test that plants
one and watches the verification catch it. The safety net is narrower here than on a flat table.
That is the price, and it is not hidden.

**The tenant division does not give it up.** With `stream = tenant` — the multi-tenant default — every
entry of a stream lives in one partition, and the unique indexes are created **on each partition**
rather than on the parent, which makes them exactly the guarantee the flat table had. This is the
stub to reach for if losing it is not acceptable. It also means every partition you add by hand
needs its two indexes:

```sql
create table sentinel_audits_acme partition of sentinel_audits for values in ('acme');
create unique index sentinel_audits_acme_ss on sentinel_audits_acme (stream, sequence);
create unique index sentinel_audits_acme_capture on sentinel_audits_acme (capture_id);
```

`tenant_id` stays nullable, and there is a partition for the entries that have none. It has to stay
nullable: a primary key carrying `tenant_id` would make it `NOT NULL` and stop a command or a queue
worker from recording anything, and filling it with a placeholder is worse — `tenant_id` is inside
the canonical payload, so an empty string where the hash was sealed over `null` makes the entry fail
its own verification.

**MySQL cannot offer the same.** Its partitions are not tables, so there is no per-partition index to
fall back on. `ERROR 1503` rejects any unique key without the partitioning column and that is the end
of it.

### Keeping partitions supplied

```bash
php artisan sentinel:partitions                              # this month and the next three
php artisan sentinel:partitions --ahead=6
php artisan sentinel:partitions --retire="18 months"
php artisan sentinel:partitions --table=access_log
php artisan sentinel:partitions --dry-run
```

Put it on the scheduler. It is idempotent — what should exist comes from the clock, what does exist
comes from the catalogue, and only the difference is issued — and an undivided table exits `0`
saying there was nothing to maintain.

`--table=access_log` maintains `sentinel_access_log`, the table [compliance mode](#compliance-mode)
writes a row to on every read and the one that grows with reads rather than with writes. There is no
published stub that divides it — its shape is small enough to declare by hand, and the mechanics are
the same as the audit table's:

```sql
-- PostgreSQL. Do this before the table has anything worth keeping: it drops what is there.
drop table sentinel_access_log;

create table sentinel_access_log (
    id char(26) not null, audit_id char(26) not null,
    actor_type varchar(255), actor_id varchar(64), tenant_id varchar(64),
    query jsonb not null, results integer not null, context jsonb not null,
    created_at timestamp(6) not null,
    primary key (id, created_at)
) partition by range (created_at);

create table sentinel_access_log_default partition of sentinel_access_log default;
create index on sentinel_access_log (actor_type, actor_id, created_at);
create index on sentinel_access_log (audit_id);
```

Once it is divided, the command maintains it exactly as it maintains the trail. Nothing in that
table is hashed or chained — it is a projection, and the entry it points at is what makes a read
provable — so dividing it costs none of what dividing the trail costs.

```php
Schedule::command('sentinel:partitions --ahead=6')->monthly();
```

**A forgotten run does not break a write.** The date stubs create a `DEFAULT` partition (PostgreSQL)
and a `MAXVALUE` one (MySQL) precisely so that an entry whose clock matches no declared month lands
somewhere instead of failing. What it degrades to is one fat partition, never a failed insert.

It has its own cost on PostgreSQL, worth knowing before relying on it: attaching a new range to a
table whose `DEFAULT` partition already holds rows makes PostgreSQL scan that partition first, to
prove none of them belong in the range being added. Run the command on a schedule and it never
happens.

**Do not create more partitions than you need.** This is the sharpest edge of the whole feature and
it is not obvious, because the cost lands on planning rather than on execution. Every write reads the
tail of its stream — the chain covers the sequence and the previous hash, so no insert can compute
its own link — and that read is a `Merge Append` across every partition, because nothing in
`where stream = ?` tells the planner which one holds the highest sequence:

| Reading the tail of a stream | Planning | Execution |
|---|---|---|
| Over a table of 41 partitions | **13.4 ms** | 1.0 ms |
| Over one partition | 0.58 ms | 0.05 ms |

Twenty-three times the planning, paid on every write. Keep `--ahead` to a few months rather than a
few years, and give `--retire` a period, so the number of partitions settles instead of growing with
the age of the installation.

**If you have `pg_partman`, use it instead of this command.** The stubs use native declarative
partitioning and nothing in them depends on the extension, so `pg_partman` can take over maintenance
of the table without any change on this side — point it at `sentinel_audits` and stop scheduling
`sentinel:partitions`. The command exists because most managed PostgreSQL offerings do not let you
install the extension, not because it does the job better.

**`--retire` is deliberately timid.** It drops a partition when its month is behind the cutoff **and
it holds no entries**, which is the state `sentinel:prune --action=archive` leaves it in. A partition
that still holds entries is kept and the report says why: dropping it would remove a range of the
trail as a catalogue operation, without archiving it and without recording that it went. `--force`
lifts that — except under [compliance mode](#compliance-mode), where the refusal is unconditional.

```
+---------------------------+---------+------------------------------------------+
| Partition                 | Action  | Note                                     |
+---------------------------+---------+------------------------------------------+
| sentinel_audits_p2027_01  | Created | —                                        |
| sentinel_audits_p2025_06  | Retired | —                                        |
| sentinel_audits_p2025_07  | Kept    | Still holds entries, and compliance mode |
|                           |         | does not let a range leave without a     |
|                           |         | copy of it existing first.               |
+---------------------------+---------+------------------------------------------+
```

The order that actually works is: `sentinel:prune --action=archive` writes the range out and removes
its rows, then `sentinel:partitions --retire` drops the empty shell. The first is what makes the
range provable; the second is what makes the space go back.

### What it costs at volume

Eight runs: a million entries and ten million, on PostgreSQL 16.15 and MySQL 9.7.2, each flat and
partitioned. All of it on one machine — an i7-12700KF, 20 threads, 15 GB, both engines on disk with
`fsync` off — reproducible with `make bench-volume`. A number without the machine it came from is not
a number, and none of these are gates: they are a report.

**The write path.** Two thousand entries written through the package — pipeline, context,
canonicalisation, hash and insert — onto a table that already holds the stated volume:

| Per entry | PostgreSQL 1M | PostgreSQL 10M | MySQL 1M | MySQL 10M |
|---|---|---|---|---|
| Flat | 2.47 ms | 2.20 ms | 1.93 ms | 1.97 ms |
| Partitioned | 3.13 ms | **8.34 ms** | 2.08 ms | 3.06 ms |

Two things worth reading twice. **Volume itself costs nothing**: 2.20 ms at ten million against
2.47 ms at one, because a B-tree gains a level and not much else. And **partitioning is what costs**,
by a lot on PostgreSQL and a little on MySQL — for the reason in the box above, and with 41
partitions, which is more than a real installation with a retention policy would ever have.

Put against the baseline this package has published since `v0.16.0` — the same machine, the same
session, `sync` mode over SQLite with a table of a few thousand rows — the headline is that there is
no headline:

| Per write | |
|---|---|
| `sync` over SQLite, small table (the [modes baseline](#what-the-modes-actually-cost)) | 2.07 ms |
| PostgreSQL 16 on disk, ten million entries, flat | 2.20 ms (**+6 %**) |
| MySQL 9 on disk, ten million entries, flat | 1.97 ms (**−5 %**) |

A ten-million-entry trail on a real engine costs what a small one on SQLite costs. What a write pays
is the pipeline, the canonicalisation and the hash — not the size of the table it lands in.

**The JSON index, measured end to end.** Across all eight runs the delta of publishing it lands
between −5.4 % and +7.2 % — which is to say it is noise. That is not a contradiction of the +15 % and
+21 % [quoted earlier](#the-two-that-live-inside-the-context): those are what the index costs the
*engine* on the `INSERT`, and this is what it costs a *Sentinel write*, where the pipeline, the
canonicalisation and the hash dominate and the insert is a fraction. Both numbers are true. Use the
first to reason about a bulk load or the `buffered` mode, and the second to decide whether to publish
it at all.

**Retiring a range.** The same ~260 000 entries removed, as a `DELETE` on the flat table and as a
`DROP PARTITION` on the divided one. This is the argument for partitioning, and it is not a small
one:

| ~260 000 entries | PostgreSQL 1M | PostgreSQL 10M | MySQL 1M | MySQL 10M |
|---|---|---|---|---|
| `DELETE` | 424 ms | 1 997 ms | 16 698 ms | **59 029 ms** |
| `DROP PARTITION` | 1 031 ms | **24 ms** | **22 ms** | 71 ms |

At a million entries on PostgreSQL the `DELETE` is the faster of the two on the clock — and it leaves
260 000 dead tuples for `VACUUM` to reclaim afterwards, which that number does not include and the
drop does not create. Everywhere else the catalogue operation wins by two or three orders of
magnitude. On MySQL at ten million it is the difference between a minute and a blink.

**Walking the chain.** `LedgerStream` over the whole stream, which is what `sentinel:verify` does:

| | 1M | 10M |
|---|---|---|
| PostgreSQL, flat | 35.3 s | 351.5 s |
| PostgreSQL, partitioned | 37.9 s | 427.0 s |
| MySQL, flat | 37.8 s | 392.4 s |
| MySQL, partitioned | 38.5 s | 444.9 s |

Linear, about 35 µs per entry, and partitioning adds roughly a fifth. A full verification of ten
million entries is minutes rather than hours — which is the number to have in hand when deciding
between `--depth=entries` and the [shallower walks](#artisan-commands).

**Reading it back.** Every published filter, over ten million entries, taking fifty:

| Filter | PostgreSQL flat | PostgreSQL part. | MySQL flat | MySQL part. |
|---|---|---|---|---|
| `for()` | 19.6 ms | 31.6 ms | 182.9 ms | 228.3 ms |
| `by()` | 9.1 ms | 76.6 ms | 153.8 ms | 213.9 ms |
| `whereSeverity()` | 10.3 ms | 18.7 ms | 22.0 ms | 19.4 ms |
| `forTenant()` | 5.1 ms | 7.6 ms | 19.4 ms | 9.0 ms |
| `whereType()` | 29.7 ms | 32.8 ms | 13.5 ms | 6.8 ms |
| `whereIp()` | 4.2 ms | 19.7 ms | 31.6 ms | 31.6 ms |
| `whereRoute()` | 113.4 ms | 88.2 ms | **5 118 ms** | 183.5 ms |
| `whereEvent()` | 301.7 ms | 57.7 ms | **32 589 ms** | 2 460 ms |

The two outliers are the same outlier, and the README already named it for `whereEvent()`: a filter
that selects a **category** rather than an entity reaches its index and then has to sort everything
it matched. At ten million entries with three hundred distinct routes, `whereRoute()` behaves exactly
like `whereEvent()` — the index finds thirty thousand rows and the order is what costs. Put a filter
in front of either, or reach for `whereIp()`, which selects an entity and stays in single-digit
milliseconds on PostgreSQL at any volume tested.

## Artisan commands

| Command | What it does | Exit codes |
|---|---|---|
| `sentinel:flush` | Settles everything the buffer is holding | `0` settled · `1` the flush failed · `2` not in `buffered` mode |
| `sentinel:verify` | Walks the chain and reports what it found | `0` intact · `1` broken · `2` could not run |
| `sentinel:checkpoint` | Anchors every complete window the streams still owe | `0` anchored, or nothing left to anchor · `2` could not run |
| `sentinel:prune` | Applies the retention policies and reports what went | `0` removed, or nothing to remove · `1` a range no longer folds to its root · `2` could not run |
| `sentinel:redact` | Destroys the contents of one entry and leaves the rest of it standing | `0` redacted, or already redacted · `1` refused: archived, or no longer reproducing its hash · `2` could not run |
| `sentinel:export` | Hands the trail to somebody who does not have the database | `0` exported · `2` a format it does not write |
| `sentinel:rekey` | Re-encrypts a range of the trail under another key | `0` re-encrypted, or nothing to re-encrypt · `2` could not run |
| `sentinel:partitions` | Keeps a partitioned trail supplied with months ahead and clear of the empty ones behind | `0` maintained, or nothing to maintain · `1` refused to retire a partition still holding entries · `2` could not run |

```bash
php artisan sentinel:verify
php artisan sentinel:verify --stream=global --from=1 --to=500
php artisan sentinel:verify --projections
php artisan sentinel:verify --depth=anchors
php artisan sentinel:checkpoint
php artisan sentinel:checkpoint --stream=tenant:acme
```

```
+-----------------+---------+--------+---------+------------+
| Stream          | Entries | Chain  | Anchors | Signatures |
+-----------------+---------+--------+---------+------------+
| global          | 41208   | intact | —       | 41208 signed |
| tenant:acme     | 3106    | BROKEN | —       | 2980 signed, 1 INVALID |
+-----------------+---------+--------+---------+------------+
Audit 01J… carries a signature that its own key does not verify, at sequence 2981 of stream tenant:acme.
```

`--depth` picks which walk to run: `entries` (the default, reads and rehashes every one), `roots`
(folds each range again) or `anchors` (reads only the anchors). The shallow two take no `--from` and
no `--to` — a range is what the deep one answers — and they report what they did **not** read:

```
+-----------------+---------+--------+--------------------------------------------------+
| Stream          | Entries | Chain  | Anchors                                          |
+-----------------+---------+--------+--------------------------------------------------+
| global          | 208     | intact | 41 anchored (covering 41000 entries nobody read)  |
+-----------------+---------+--------+--------------------------------------------------+
Read 208 entries and took 41000 on the word of their anchors, across 1 streams. Nothing came back
wrong, which is not the same as every entry having been read.
```

**Three exit codes and not two.** A broken chain and a command that could not run are different
facts, and a watchdog that cannot tell them apart will eventually treat one as the other. A range
without a `--stream`, or a ledger that cannot list its chains, exits `2` — not `1`.

**A trail nobody has signed exits `0`.** It is sound. The table says how many entries are unsigned so
an operator sees it without a cron waking anyone at 3am over it. `sentinel:checkpoint` exits `0` when
there was nothing left to anchor, for the same reason: that is the ordinary outcome of running it on
a schedule.

`--projections` is opt-in because it reads a second table over the same rows. Nobody should pay for
that question while asking whether the chain holds.

### Verifying without the keys

This is what the design is for, and it is worth stating as a procedure. A third party can be handed
the rows — or their `toArray()` export — plus `integrity.signature.keys`, and prove every entry
untouched while holding:

- **no encryption key.** The hash is taken over the ciphertext ([above](#the-hash-covers-the-ciphertext)),
  and `CanonicalPayload::from()` decrypts nothing, so canonicalisation reproduces byte for byte
  without it. The protected values stay unreadable throughout.
- **no private key**, under `openssl`. `keys` holds only public halves.

Rotating an encryption key does not break this: `Rekeyer` writes a **new entry** rather than
re-encrypting in place, so the original keeps its bytes and keeps verifying. Re-encrypting a sealed
row would change what the hash covers, which is exactly why it does not.

## Ledger drivers

`Contracts\Ledger` ships five drivers. Four are chosen by `ledger.default`; the fifth is a
destination:

```php
// config/sentinel.php
'ledger' => [
    'default' => env('SENTINEL_LEDGER', 'database'), // 'database', 'fanout', 'memory' or 'null'
],
```

| Driver | What it does |
|---|---|
| `Ledger\DatabaseLedger` | Writes to `sentinel_audits`: serializes the stream, reads its tail, builds the link and inserts, retrying if a concurrent writer claims the same `(stream, sequence)` first |
| `Ledger\FanoutLedger` | Sends one entry to several destinations at once |
| `Ledger\MemoryLedger` | The whole contract over plain arrays. A reference implementation and a test double — everything it holds dies with the instance, so it is never a store |
| `Ledger\NullLedger` | Auditing off without taking the code path apart: it still builds, seals and chains, and keeps nothing. `find()` answers nothing and `stream()` walks nothing |
| `Ledger\ArchiveLedger` | [Cold storage](#cold-archiving) over any `Storage` disk: NDJSON batches, checksummed and rehashed before anything is removed. A destination, never `ledger.default` |

All five run the same contract suite, so they cannot drift apart.

### Writing to more than one place

```php
// config/sentinel.php
'ledger' => [
    'default' => 'fanout',
    'ledgers' => [
        'fanout' => [
            'destinations' => ['database', 'memory'],
            'on_failure' => 'strict',
        ],
    ],
],
```

The **first destination is the primary**, and the only one that assigns the sequence and seals the
hash. The rest are handed the entry it sealed, through `append()`. Two ledgers each numbering their
own chain would produce two different truths about one fact, so only one of them numbers. Reads go
to the primary for the same reason.

`on_failure` decides what a destination refusing an entry means:

- `strict` — the write fails. Whatever the earlier destinations already took stays with them: an
  entry is sealed before it is handed out and nothing can unseal it.
- `primary` — only the first destination is critical. Every other refusal raises
  `Events\LedgerDestinationFailed`, carrying the destination, the stream, the sequence and the entry
  id, and the write settles.

## Writing your own driver

A driver implements `Contracts\Ledger` and is held to one thing: **the chain**. Within a stream the
sequence is dense and monotonic, and every entry links to the one before it. Three guarantees are
deliberately *not* part of the contract, because a store without transactions cannot honour them and
a contract nobody can implement is a contract that gets ignored:

- `writeMany()` is not atomic. It either returns everything that settled, or throws having made a
  best effort to leave nothing behind.
- No read is promised to see a write that just returned.
- Idempotency by `capture_id` belongs to the caller.

The package publishes the suite that checks the rest, as production code rather than as a dev
dependency — a contract nobody outside this package can execute is a promise, not a verification:

```php
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Testing\LedgerContractTestCase;

final class RedisLedgerContractTest extends LedgerContractTestCase
{
    protected function ledger(): Ledger
    {
        return new RedisLedger(/* ... */);
    }
}
```

It needs `phpunit/phpunit` and `orchestra/testbench`, both declared in `suggest`. Two hooks let a
driver say what it is instead of failing for being it:

- `retains(): bool` — answer `false` if your driver keeps nothing. It is not an exemption: a driver
  that answers `false` is held to keeping nothing as strictly as the others are held to keeping
  everything.
- `settle(Ledger $ledger): void` — called between a write and the read that checks it. If your
  reads are eventually consistent, make what was just written visible here.

One capability is opt-in, and only worth declaring if your store can honour it:

```php
use ElPandaPe\Sentinel\Contracts\Deduplicates;

final class RedisLedger implements Deduplicates, Ledger
{
    /**
     * @param  non-empty-list<string>  $captureIds
     * @return list<string>
     */
    public function settled(array $captureIds): array
    {
        // Which of these captures already have an entry here.
    }
}
```

Declaring it lets an asynchronous mode ask before it writes, so a retry of something that already
landed costs one query instead of a sealed chain the store throws away. It is not what makes the
write idempotent — a unique constraint on the column is — and a driver that cannot answer reliably
should not declare it: saying "no" when the answer is "yes" writes the same fact twice.

Two things that are easy to get wrong and are not obvious from the interface:

- **Write the entries before you advance whatever tracks the tail of the stream.** The other order
  burns a sequence number if the process dies in between, and the gap in the chain is permanent.
- **Hydrate with `setRawAttributes()`, never `forceFill()`.** `forceFill` runs the *set* casts, so a
  JSON column that already arrives encoded gets encoded a second time and the entry stops
  reproducing its own hash — which verification will then report, correctly, as tampering.

## Development

The project runs entirely in Docker — no PHP or Composer needed on your machine.

```bash
make build      # build the dev image
make install    # composer install
make test       # run the suite
make coverage   # tests + 100% coverage gate
make types      # 100% type coverage gate
make stan       # PHPStan at level max
make lint       # Pint (check only)
make rector     # Rector (dry-run)
make ci         # everything CI runs
make test-dbs   # suite against MySQL and PostgreSQL
make bench      # write-path baseline (a report, never a gate)
make shell      # a shell inside the container
```

## Credits & license

Built by [Carlos Mayorga](https://carlosmayorga.me/).

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
