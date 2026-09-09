# 🧭 Multi-tenancy

> How Sentinel learns which tenant an entry belongs to, and why turning that on also decides the
> shape of the hash chain.

**On this page:** [Resolving the tenant](#resolving-the-tenant) · [The tenant is the default stream scope](#the-tenant-is-the-default-stream-scope) · [Wiring a tenant on a trail that already has entries](#wiring-a-tenant-on-a-trail-that-already-has-entries) · [Reading across tenants](#reading-across-tenants) · [Verifying one tenant, or all of them](#verifying-one-tenant-or-all-of-them) · [Retention and pruning per tenant](#retention-and-pruning-per-tenant) · [Redaction and export scoped to one tenant](#redaction-and-export-scoped-to-one-tenant) · [⚠️ Pitfalls](#️-pitfalls) · [✅ Best practices](#-best-practices)

---

## Resolving the tenant

Sentinel has no opinion about how your application decides which tenant is current. It asks. The
question is a closure at `resolvers.tenant.using`, and `ElPandaPe\Sentinel\Context\Resolvers\TenantResolver`
is the whole implementation: call the closure, accept a string or an integer, cast it to a string,
put it in `tenant_id`.

```php
// config/sentinel.php
'resolvers' => [
    'tenant' => ['using' => fn (): ?string => Tenant::current()?->getKey()],
],
```

That is the supported integration point for every tenancy package on the market. Whatever
`stancl/tenancy`, `spatie/laravel-multitenancy` or your own middleware considers the current tenant,
the closure reads it and Sentinel stays ignorant of the mechanism.

What the closure may return, and what happens:

| Return value | Result |
|---|---|
| `string` | `tenant_id` is that string |
| `int` | `tenant_id` is that integer cast to a string — `42` becomes `'42'` |
| `null` | The resolver returns nothing; `tenant_id` stays `null` |
| anything else | `ConfigurationException::expected('resolvers.tenant.using', 'a closure returning a string, an integer or null', …)` at capture time |

The config accessor guards the other half: a value at `resolvers.tenant.using` that is not a
`Closure` and not `null` throws `ConfigurationException::expected('resolvers.tenant.using', 'a closure or null', …)`.

> 📌 **Note.** The tenant resolver is **not** memoised. `ContextEngine` memoises `source`, `host`,
> `request`, `session` and `command` for the life of the container scope; `tenant` runs on every
> capture, precisely because a request can switch tenants halfway through. You do not need to flush
> anything after a tenant switch.

### When a closure is not enough

Name a class instead. It must implement `ElPandaPe\Sentinel\Contracts\Resolver`, and it is built
through the container, so it may take constructor dependencies.

```php
namespace App\Sentinel;

use ElPandaPe\Sentinel\Contracts\Resolver;
use Spatie\Multitenancy\Models\Tenant;

final class CurrentTenantResolver implements Resolver
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

// config/sentinel.php
'resolvers' => [
    'tenant' => ['class' => App\Sentinel\CurrentTenantResolver::class],
],
```

The cast to string is not decoration. `ContextEngine::column()` writes a promoted value only when
`is_string()` is true and writes `null` otherwise, so returning `$tenant->id` as an integer or as a
`Uuid` object produces a null column and no warning. See
[Writing your own resolver](07-writing-your-own-resolver.md).

`tenant_id` is a promoted column, which means `Sentinel::withContext(['tenant_id' => 'acme'], …)`
cannot fill it. That array lands inside the `context` JSON; the column stays whatever the resolver
said. Changing the tenant means changing what the resolver returns.

---

## The tenant is the default stream scope

This is the part that surprises people, and it is worth understanding before you write the closure.

`config/sentinel.php` ships with `integrity.stream => 'tenant'`. A **stream** is the name of a hash
chain, and `ElPandaPe\Sentinel\Integrity\Stream` derives it from that setting:

| `integrity.stream` | Stream name | Fallback |
|---|---|---|
| `'global'` | `global` | — |
| `'tenant'` *(shipped default)* | `tenant:<tenant_id>` | `global` when `tenant_id` is `null` |
| `'subject_type'` | `type:<morph alias>` | `global` when there is no subject |
| `Closure(AuditData): string` | whatever it returns | — (must return a string) |
| class-string implementing `Contracts\StreamResolver` | whatever `resolve()` returns | — |

So `'tenant'` behaves exactly like `'global'` until a tenant actually resolves. **The moment your
closure first returns a key, that entry stops going onto the `global` chain and opens a new one.**
The stream name is part of the hash prefix, so a new stream is a genuinely new chain:

```
sequence      = 1
previous_hash = null
stream        = 'tenant:acme'
```

That is the behaviour the suite pins: *"opens a chain of its own once a tenant resolves"* in
`tests/Feature/CapturedContextTest.php`.

### What that means for a trail that already has entries

Nothing is rewritten, moved or renumbered. Concretely, on an installation that has been writing to
`global` for six months and then wires a tenant resolver:

- The `global` chain keeps every entry it had, keeps its sequences, and **keeps verifying**.
  `Sentinel::verifyIntegrity('global')` is intact before and after.
- Entries written from then on with a resolved tenant go to `tenant:<id>`, starting at sequence 1.
- `global` does **not** necessarily stop growing. Any capture where the closure returns `null` — a
  console command, a queue worker, the scheduler, anything running before your tenancy package has
  bootstrapped — still lands on `global`. You end up with `global` as a permanent catch-all
  alongside one chain per tenant.
- Nothing links the two. The first entry of `tenant:acme` has `previous_hash = null`. There is no
  cryptographic statement connecting a tenant's history before the switch to its history after it.

That last point is the reason to decide this at install time. A chain fork is not corruption and no
verification reports it as a break — it is simply two chains where an auditor was told there would
be one, and the split is permanent because the stream name is sealed into every hash.

> ⚠️ **Warning.** Set `integrity.stream` explicitly **before** wiring a tenant resolver on an
> installation that already has history. If you do not want the partition, pin it to `'global'`
> first — the tenant still gets recorded in the `tenant_id` column and `forTenant()` still works;
> only the chain stays single.

### The name has a hard ceiling

`Stream::guard()` refuses an empty name (`ConfigurationException::streamEmpty`) and a name over
`Stream::MAX_LENGTH` — 64 characters — with `ConfigurationException::streamTooLong`. It never
truncates: a truncated stream name would be a different chain wearing the right label.

The `tenant:` prefix costs seven characters, so **a tenant key longer than 57 characters cannot be
used with `integrity.stream => 'tenant'`**, even though the `tenant_id` column itself holds 64.

The guard runs in `DatabaseLedger::groupByStream()`, inside the sealing transaction — not at
capture. So an over-long key surfaces as a `ConfigurationException` on the *write*, and is therefore
subject to `on_write_failure`: with the default `'throw'` it reaches the caller, and under a deferred
after-commit write it is announced and logged instead.

> 🐘 **Engine.** `groupByStream()` also means one write of several entries spanning several tenants
> is split by stream, and each group reads its own chain tail under its own write gate. On
> PostgreSQL there is also a published partitioning stub that divides `sentinel_audits` by
> `tenant_id`; it is the division that keeps the chain's guarantee, precisely *because*
> `integrity.stream => 'tenant'` puts every entry of a stream in one partition, which makes a
> per-partition `unique (stream, sequence)` exactly what the flat table had. See
> [Partitioning](../10-database-engines/06-partitioning.md).

---

## Wiring a tenant on a trail that already has entries

Two supported routes, and you pick before the first tenanted write.

**Route A — accept the partition.** Leave `integrity.stream` at `'tenant'`. Anchor and verify what
you have first, so the `global` chain's state is on record, then wire the closure.

```bash
php artisan sentinel:verify --stream=global
php artisan sentinel:checkpoint --stream=global
```

**Route B — keep one chain.** Pin the strategy before anything resolves a tenant.

```php
// config/sentinel.php
'integrity' => [
    'stream' => 'global',
],
```

`tenant_id` is still resolved, still stored, still indexed and still queryable with `forTenant()`.
What you give up is per-tenant verification and per-tenant retention windows; what you gain is one
chain with one continuous genealogy.

> 📌 **Note.** Changing `integrity.stream` later never renames a chain and never migrates rows. It
> forks: old rows keep the stream in their hash prefix, new rows get the new name. There is no
> supported way back, because rehashing existing rows is the one thing the package must never do.

---

## Reading across tenants

`tenant_id` is a first-class column with its own index, `(tenant_id, created_at)`.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

// Indexed seek.
$entries = Sentinel::audits()->forTenant('acme')->take(200)->get();

// Ordered by the fact rather than by the settlement.
$timeline = Sentinel::timeline()->forTenant('acme')->take(200)->get();
```

`forTenant()` reaches an index on all three engines — that is asserted in `tests/Query/QueryPlanTest.php`
against a real query plan, not assumed. `Sentinel::timeline()->forTenant(…)` reaches it too, but
still pays a sort outside the index: the index is ordered by `created_at` and `byOccurrence()` asks
for `occurred_at`.

What the Query API does **not** offer:

- **No `whereIn` for tenants.** `forTenant(string $tenant)` takes one key. Two tenants is two
  queries, and there is no union.
- **No tenant scoping of its own.** Sentinel registers no global scope on `Models\Audit` and no
  middleware that narrows reads. If your application lets a user see the trail, it is your
  application's job to call `forTenant()` with the right key. Sentinel records the tenant; it does
  not enforce isolation.
- **No cross-tenant read that is also unbounded.** `get()` with no `take()` refuses once more than
  500 entries match, rather than hand back a prefix shaped like a complete answer — use `take()` for
  a prefix, or `paginate()` / `after()` to walk. The probe and the reasoning are in
  [Order, paging and walking the trail](../06-reading/03-order-paging-and-walking.md#how-much-comes-back).

```php
// Refused once more than 500 entries match — which is most trails, across all tenants.
Sentinel::audits()->get();

// Either bound it,
Sentinel::audits()->take(500)->get();

// or walk it.
$page = Sentinel::audits()->paginate(perPage: 200, page: 1);
```

> ⚠️ **Warning.** Pass a real key. `forTenant('')` is accepted by the query object —
> `AuditQuery::accepting()` only checks that the driver declares `Filter::Tenant` — and what happens
> next is driver-dependent: `DatabaseLedger` applies the clause through `Builder::when()`, which does
> nothing for a falsy value, so an empty string reads as *no tenant filter at all*. Guard the value
> before you build the query.

`Filter::Tenant` is in `Filter::assumed()`, the set a ledger driver is taken to answer when it
declares nothing, and every driver the package ships declares `Filter::cases()` — so `forTenant()`
works on all of them. A third-party driver that declares a narrower set refuses it outright with
`LedgerException::cannotFilterBy`. See
[Filters reference](../06-reading/02-filters-reference.md).

---

## Verifying one tenant, or all of them

Under `integrity.stream => 'tenant'`, verification becomes a per-tenant question, because a stream
is what a verification walks.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

// One tenant's chain, read and rehashed end to end.
$result = Sentinel::verifyIntegrity('tenant:acme');

// A slice of it — a range is a question about one stream.
$slice = Sentinel::verifyIntegrity('tenant:acme', from: 1_000, to: 1_500);

// Every chain the ledger can name, walked deep, one at a time.
$report = Sentinel::verifyEverything();
```

| Call | Scope | Cost |
|---|---|---|
| `verifyIntegrity('tenant:acme')` | one tenant | reads and rehashes every entry of that tenant |
| `verifyAnchors('tenant:acme')` | one tenant | reads that tenant's anchors plus its unanchored tail |
| `verifyRoots('tenant:acme')` | one tenant | refolds each of that tenant's roots from the stored hashes |
| `verifyEverything()` | every stream | the **deep** walk on each — with N tenants, that is the whole trail |

`verifyEverything()` takes no range for exactly this reason: sequence `1..500` means a different set
of entries in every tenant's chain, so a range across all of them is a range across nothing. It also
refuses a ledger that cannot list its chains, throwing `QueryException::cannotEnumerateStreams` —
`NullLedger` does not implement `Contracts\EnumeratesStreams`.

On the command line the same split applies:

```bash
php artisan sentinel:verify --stream=tenant:acme
php artisan sentinel:verify --stream=tenant:acme --from=1000 --to=1500
php artisan sentinel:verify --depth=roots            # every stream, cheap sweep
php artisan sentinel:checkpoint --stream=tenant:acme
```

`--from` / `--to` are accepted only together with `--stream` **and** only at `--depth=entries`; both
violations exit `2` with a warning rather than `1`. Anchoring is per stream too, so a tenant with
fewer entries than `integrity.checkpoints.every` (default 1000) simply has no anchors yet and is
reported `['absent' => 1]` and walked whole.

> 💡 **Tip.** With many small tenants, per-tenant chains make anchoring less useful, not more: an
> anchor covers a fixed window of `every` entries and the trailing incomplete window is never
> anchored. A hundred tenants of 300 entries each yields zero anchors at the default window. Lower
> `integrity.checkpoints.every` or accept that verification is a full walk.

See [Streams](../07-integrity/02-streams.md) for the mechanics and
[Verification](../07-integrity/06-verification.md) for what each depth proves.

---

## Retention and pruning per tenant

Retention is computed per stream: `sentinel:prune --stream=tenant:acme` plans a frontier for that
chain alone, and with no `--stream` the command walks every one.

The unit a prune releases is the **anchored window**, not the entry, because a window is folded
whole and a partly emptied one could never reproduce its root. The consequence under tenant streams
is worth stating plainly: **the effective retention of a window is that of its longest-lived entry
in it.** A tenant's chain mixes every audit type that tenant produced, so one entry held for seven
years keeps its entire window, and a `'auth' => '90 days'` policy frees nothing inside it.

Removing a single entry ahead of its window is what redaction is for. See
[Retention and pruning](../08-lifecycle/01-retention-and-pruning.md).

---

## Redaction and export scoped to one tenant

### Export

```bash
php artisan sentinel:export --tenant=acme --format=ndjson --disk=s3 --path=exports/acme.ndjson
```

`--tenant` is `forTenant()` under a different spelling — the command uses the Query API rather than
a second query language. Two things to know:

- **`--limit` defaults to 500.** An export of a tenant's whole history is not what you get by
  default; pass `--limit` deliberately, or export in passes.
- **Every export carries a manifest** beside it — entry count, digest of the body, and a signature
  over that digest with the installation's signing key. `ndjson` round-trips; `csv` flattens nested
  columns into JSON strings inside cells and is lossy by design.

In compliance mode the export is a read like any other, so it writes an access entry and a row in
`sentinel_access_log`. That access entry is resolved by the ordinary context engine, so it carries
**the exporting run's** tenant, while the tenant that was asked for is recorded inside the logged
query shape. See [Compliance mode](../08-lifecycle/05-compliance-mode.md).

### Redaction, and what the trail carries across tenants

`sentinel:redact` takes an entry id, not a tenant. Erasing one person's history in one tenant means
finding their entries and redacting each one.

The trail is the part to know about. A redaction empties the entry's content columns in place and
then writes a **new** entry describing the redaction. That trail entry goes through the pipeline
like any other, and `ResolveContext` assigns every promoted column on every pass — and then applies
what the redaction states outright, which is **the tenant of the entry it redacts**:

> 📌 **Note.** The redaction trail entry carries the tenant of the entry it redacts, not the tenant
> of the run that redacted. Redact an `acme` entry from a console command with no tenancy active, and
> the trail entry has `tenant_id = 'acme'` and lands on `tenant:acme`, beside the tombstone it
> describes. An entry with no tenant gets a trail with none, on `global`, whichever tenant is active.
> The suite fixes both: *"keeps the tenant of the entry it redacted on the trail, from a run that
> resolves none"* and *"leaves the trail of a tenantless entry tenantless, whichever tenant is
> active"* in `tests/Redaction/RedactorTest.php`.

So a query for *everything that happened to tenant acme* returns the record of the erasure, and
nothing about the run needs arranging beforehand:

```php
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Redaction\Redactor;
use ElPandaPe\Sentinel\Support\Reference;

app(Redactor::class)->redact(
    Audit::query()->findOrFail($id),
    'GDPR erasure request',
    new Reference('user', '7'),
);   // the trail lands on the entry's chain, whatever tenant this process has current
```

Before `v1.0.0-rc.2` the trail carried the run's tenant, and the fix was operational — make the
tenant current for the length of the redaction. Trails those releases wrote keep what they recorded.

For comparison: `Security\Rekeyer` writes its entry straight to the ledger and deliberately **not**
through the pipeline — its values are already encrypted and running them through again would encrypt
ciphertext — so a rekey entry keeps `tenant_id` copied from the entry it stands in for. Same outcome
by a different route, for a reason you can read in each class.

See [Redaction and tombstones](../08-lifecycle/04-redaction-and-tombstones.md) and
[Export and rekey](../08-lifecycle/06-export-and-rekey.md).

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| A tenant's entries suddenly start at `sequence 1` with `previous_hash = null` | `integrity.stream` ships as `'tenant'`; the first resolved tenant key opens a new chain | Expected. Decide before the first tenanted write: keep it, or pin `integrity.stream => 'global'` |
| The `global` chain keeps growing after tenancy is live | Every capture where the closure returns `null` — commands, workers, the scheduler — still resolves to `global` | Make the tenant current in those processes, or accept `global` as the untenanted chain and verify it too |
| `ConfigurationException: Sentinel resolved the stream name [tenant:…], longer than the 64 characters the column holds` on a write | A tenant key over 57 characters plus the `tenant:` prefix exceeds `Stream::MAX_LENGTH`; the name is never truncated | Use a shorter key, or a `Contracts\StreamResolver` that hashes the key into a fixed-width name |
| `tenant_id` is `null` on entries written from a queue worker | The worker never made the tenant current, so the closure returned `null` — and Sentinel's own `SettleAudit` job is not the culprit, it carries context resolved at capture | Restore the tenant in the job (a tenancy package's job middleware, or Laravel's `Context`) before the model is touched |
| `Sentinel::withContext(['tenant_id' => 'acme'], …)` leaves the column empty | `tenant_id` is a promoted column; manual context can only reach the `context` JSON | Change what `resolvers.tenant.using` returns |
| A custom resolver returns `['tenant_id' => 42]` and the column is `null` | `ContextEngine::column()` writes only strings | `(string) $tenant->getKey()` |
| `forTenant('')` returns every tenant's entries | `DatabaseLedger` applies the clause with `Builder::when()`, which skips a falsy value | Guard the key before the query; never pass an empty string |
| `QueryException: unbounded` on a cross-tenant read | `get()` with no `take()` refuses once more than 500 entries match | `take()` for a prefix, `paginate()` or `after()` to walk |
| `sentinel:verify` with no `--stream` exits `2` | The configured ledger does not implement `Contracts\EnumeratesStreams` — `NullLedger` does not | Verify a named stream, or point the command at a ledger that can list its chains |
| `verifyEverything()` takes minutes after tenancy is on | It is the **deep** walk on every stream — with N tenants that is the whole trail | Sweep with `--depth=roots` and reserve `--depth=entries` for one stream at a time |
| A 90-day retention policy frees nothing for a tenant | A window is released whole, so its effective retention is that of its longest-lived entry, and a tenant chain mixes types | Redact the individual entry, or scope the chain more narrowly with a `StreamResolver` |
| The trail entry for a redaction has no tenant | It carries the redacting run's context, not the redacted entry's | Make the tenant current for the run |

---

## ✅ Best practices

✅ **Do** — decide `integrity.stream` at install, before the first entry, and write the decision
down. It is sealed into every hash and there is no migration back.

```php
// config/sentinel.php — one chain, tenant still recorded and queryable
'integrity' => ['stream' => 'global'],
'resolvers' => ['tenant' => ['using' => fn (): ?string => Tenant::current()?->getKey()]],
```

❌ **Don't** — add the tenant closure to an installation with six months of `global` history and
discover the fork afterwards. Nothing warns you, nothing is rewritten, and the two chains never link.

```php
// Six months of entries on `global`, then this — and tenant:acme starts at sequence 1.
'resolvers' => ['tenant' => ['using' => fn (): ?string => Tenant::current()?->getKey()]],
// with integrity.stream left at its shipped 'tenant'
```

✅ **Do** — cast the key to a string and keep it short. The column holds 64 characters and the
`tenant:` prefix eats seven of them.

```php
'tenant' => ['using' => fn (): ?string => Tenant::current()?->getKey()],  // getKey() may be an int
```

❌ **Don't** — return the model, a `Stringable`, or a `Uuid` object. The closure form accepts a
string, an integer or `null` and refuses everything else outright, at the first capture.

```php
'tenant' => ['using' => fn (): ?Tenant => Tenant::current()],  // ConfigurationException at capture
'tenant' => ['using' => fn () => Tenant::current()?->uuid],    // a Uuid object → the same throw
```

✅ **Do** — restore the tenant inside queued jobs, commands and scheduled tasks before touching an
audited model, so their entries land on the tenant's chain rather than on `global`.

```php
Tenant::find($this->tenantId)->makeCurrent();

$invoice->update(['status' => 'paid']);   // tenant_id = the tenant, stream = tenant:<id>
```

❌ **Don't** — assume Sentinel carries the tenant into your own queued work. It carries context for
*its own* settlement job, resolved at capture; a job of yours that mutates a model resolves fresh.

```php
// Dispatched from a tenanted request; the worker has no tenant, so the entry says none.
CloseInvoice::dispatch($invoice);
```

✅ **Do** — narrow every read to a tenant explicitly, and bound it.

```php
Sentinel::audits()->forTenant($tenant->getKey())->take(200)->latest()->get();
```

❌ **Don't** — treat the trail as tenant-scoped by default. There is no global scope on `Models\Audit`
and no isolation middleware; an unscoped read returns every tenant's entries, or is refused for being
unbounded.

```php
Sentinel::audits()->for($invoice)->get();   // no tenant clause — and every tenant's invoices
```

✅ **Do** — make the tenant current before a redaction run, so the trail entry the redaction writes
lands on the same chain as the tombstone it describes.

```php
Tenant::find('acme')->makeCurrent();
app(Redactor::class)->redact($entry, 'GDPR erasure request', new Reference('user', '7'));
```

❌ **Don't** — read a missing `tenant_id` on a redaction trail entry as a bug in the chain. It is
chained, hashed and signed; it under-reports one column, and `verifyIntegrity()` says so by saying
nothing.

✅ **Do** — verify and anchor per stream once tenancy is on, and keep `verifyEverything()` for the
periodic deep pass rather than the routine one.

```bash
php artisan sentinel:checkpoint                       # every stream, hourly
php artisan sentinel:verify --depth=roots             # every stream, daily
php artisan sentinel:verify --stream=tenant:acme      # one tenant, deep, on demand
```

❌ **Don't** — schedule `sentinel:verify` at its default depth over every stream on a large
multi-tenant trail. That is the deep walk over the whole table, once per run.

```bash
php artisan sentinel:verify   # every stream, every entry, rehashed
```

---

**See also:** [Streams](../07-integrity/02-streams.md) · [The ten resolvers](02-resolvers-reference.md) · [Execution context](01-execution-context.md) · [Queues, commands and schedulers](05-queues-commands-and-schedulers.md) · [Writing your own resolver](07-writing-your-own-resolver.md) · [Verification](../07-integrity/06-verification.md) · [Redaction and tombstones](../08-lifecycle/04-redaction-and-tombstones.md) · [Retention and pruning](../08-lifecycle/01-retention-and-pruning.md) · [Partitioning](../10-database-engines/06-partitioning.md) · [Configuration](../99-reference/02-configuration.md)
