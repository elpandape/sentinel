# Sentinel

> Ledger-first audit & integrity engine for Laravel.
> **Know what happened. Know who did it. Prove the record.**

[![Version](https://img.shields.io/badge/version-v0.6.0-blue)](https://github.com/elpandape/sentinel/releases)
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
"require": { "elpandape/sentinel": "v0.6.0" }
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

Everything else is on the roadmap: relationship auditing, business transactions, custom events,
state transitions, restore, advanced verification (checkpoints and signatures), retention and
compliance, performance modes and distributed tracing.

`v0.4.0` is the version that starts auditing: a model with the trait writes its own chained entries.
`v0.5.0` is the one that answers what changed, instead of leaving you two states to compare.
`v0.6.0` is the one that answers who did it and from where, instead of every entry claiming it came
from nowhere.

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

> **Context carries sensitive data.** Addresses, user agents, urls, session identifiers and command
> arguments now sit in `context`. `CommandResolver` masks any argument whose name matches
> `resolvers.command.redact` — `password`, `token` and `secret` by default — and nothing else is
> redacted until `v0.7.0`.

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

## Configuration

`config/sentinel.php` ships every section the package will use through 1.0, with future features
turned off. Read it once and you know what is coming.

## Schema & models

`sentinel_audits` ships complete: forty columns and eleven indexes, created once and never altered
by a later minor. Most columns stay empty until the version that writes them lands — an empty column
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

`--tag=sentinel-factories` publishes `AuditFactory` as a **reference copy**: it keeps the package
namespace, so your application autoloads the packaged one either way. The supported way to change
what the factory builds is `models.audit`, below.

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

`Contracts\Ledger` ships two drivers, chosen by `ledger.default`:

```php
// config/sentinel.php
'ledger' => [
    'default' => env('SENTINEL_LEDGER', 'database'), // 'database' or 'null'
],
```

- `Ledger\DatabaseLedger` writes to `sentinel_audits`: it serializes the stream, reads its tail,
  builds the link and inserts, retrying if a concurrent writer claims the same `(stream, sequence)`
  first.
- `Ledger\NullLedger` computes the exact same chain but keeps it only on the instance that runs it —
  nothing reaches storage. It runs the same contract suite as `DatabaseLedger`, so the two drivers
  cannot drift apart.

> The `Ledger` contract is **unstable until `v0.8.0`**. It is declared now so every driver has one
> shape to answer to, and it gets tensioned against a non-SQL backend before the freeze.

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
