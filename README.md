# Sentinel

> Ledger-first audit & integrity engine for Laravel.
> **Know what happened. Know who did it. Prove the record.**

[![Version](https://img.shields.io/badge/version-v0.10.0-blue)](https://github.com/elpandape/sentinel/releases)
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
"require": { "elpandape/sentinel": "v0.10.0" }
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

Everything else is on the roadmap: relationship auditing, business transactions, custom events,
state transitions, restore, advanced verification (checkpoints and signatures), retention and
compliance, performance modes and distributed tracing.

`v0.4.0` is the version that starts auditing: a model with the trait writes its own chained entries.
`v0.5.0` is the one that answers what changed, instead of leaving you two states to compare.
`v0.6.0` is the one that answers who did it and from where, instead of every entry claiming it came
from nowhere. `v0.7.0` is the one that stops the answer from being a leak: no declared value reaches
the ledger in the clear, and nothing writes an entry that says nothing happened. `v0.8.0` is the one
that makes "extensible by drivers" a thing you can run rather than a thing this file claims. `v0.9.0`
is the one that lets you ask, which a trail you cannot read back is not. `v0.10.0` is the one that
answers the three questions a trail gets asked most — what happened to this field, what is this
entry about, and what happened at all — and says them in a sentence a person can read.

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
That is deliberate and `v0.16.0` inherits it: whatever gets queued has already been transformed.

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

`php artisan sentinel:rekey` arrives in `v0.19.0`; for now the rotation is a service you call.

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
| `whereSeverity()` | `severity` | `(severity, created_at)` | the same index |
| `forTenant()` | `tenant_id` | `(tenant_id, created_at)` | the same index |
| `inTransaction()` | `transaction_id` | `(transaction_id)` | none — one transaction is sorted |
| `withTrace()` | `trace_id` | `(trace_id)` | none — one trace is sorted |
| `whereTag()` / `whereAnyTag()` | the labels an entry carries | `(tag, audit_id)` on the labels table | none |
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

### `toArray()` is not a contract yet

`$audit->toArray()` gives the entry as data — who, what, the changes as the pointer list the column
holds, the context, the labels, the correlation ids and the integrity block. **Its keys can move in
any minor** until `v0.15.0`, which declares it stable and pins it with a snapshot test. Build against
it if you like; do not build a public API on it yet.

## Configuration

`config/sentinel.php` ships every section the package will use through 1.0, with future features
turned off. Read it once and you know what is coming.

Three sections are live today beyond the basics: `resolvers` decides who and where an entry came
from, `pipeline` is the ordered list of stages every entry travels through, and `security` holds the
redaction mask and field lists, the encryption keyring and the hashing salt.

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

`subject_id`, `actor_id` and `impersonator_id` are `string(64)`, so integer, UUID and ULID keys all
fit without a migration. There is no `updated_at`: the table is append-only.

`sentinel_audit_tags` sits beside it: `(audit_id, tag)` unique, with a `(tag, audit_id)` index the
other way round. No foreign key — date partitioning and batched purging both live badly with a
cascade — so cleaning up after a purged entry belongs to whoever purges it.

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
],
```

## Integrity chain

Chaining is unconditional: every entry a `Ledger` writes links to the one before it in its stream,
regardless of configuration. `integrity.enabled` does not govern this — it will govern only the
advanced verification that ships later (checkpoints and signatures), which does not exist yet.

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
through `Integrity\VerificationResult`, for one of three reasons (`Enums\IntegrityBreak`):

| Reason | Means |
|---|---|
| `hash_mismatch` | The row no longer reproduces its own hash — its content changed. |
| `link_mismatch` | Its `previous_hash` no longer matches the entry before it — the order changed. |
| `sequence_gap` | A `sequence` is missing from the stream — an entry is gone. |

An entry is immutable through every path the model exposes: `save()`, `update()`, `delete()` and
`destroy()` all throw `Exceptions\ImmutableAuditException` once the row exists. That guard runs on
Eloquent's model events, so it only sees a change that goes through the model — an `update()` issued
through the query builder (`Audit::query()->where(...)->update([...])`) never fires those events and
does not pass through it.

## Ledger drivers

`Contracts\Ledger` ships four drivers, chosen by `ledger.default`:

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

All four run the same contract suite, so they cannot drift apart.

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
