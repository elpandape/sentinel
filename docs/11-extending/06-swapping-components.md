# 🧩 Swapping components

> Every seam in the package, what ships behind it, how you replace it, and the three that will cost
> you a chain nobody can verify if you replace them anyway.

**On this page:** [Everything that can be replaced](#everything-that-can-be-replaced) · [The three tiers](#the-three-tiers) · [Replacing the models](#replacing-the-models) · [The canonicalizer and the signer](#the-canonicalizer-and-the-signer) · [Extending, decorating, wrapping](#extending-decorating-wrapping) · [What @internal means for you](#what-internal-means-for-you) · [Pitfalls](#-pitfalls) · [Best practices](#-best-practices)

---

## Everything that can be replaced

Sentinel has thirteen public interfaces in `ElPandaPe\Sentinel\Contracts\` and two non-final models
that a configuration key can point somewhere else. That list is the whole extension surface. Every
other class the package ships is `final` — the abstract `Testing\LedgerContractTestCase` aside — and
most of them are `@internal` as well.

| Seam | Ships as | How you swap it | What it changes | Chain |
|---|---|---|---|---|
| `Contracts\Ledger` | `Ledger\DatabaseLedger` | `ledger.default` for the five shipped names; a container rebind for anything else | Where an entry is stored and who assigns its `sequence` | Safe, if the driver keeps `sequence` dense and every entry linked |
| `Contracts\LedgerStream` | `Ledger\DatabaseStream` | returned by your own driver's `stream()` | How a chain is walked for verification | Safe |
| `Contracts\Transformer` | the seven stages in `Pipeline::DEFAULT_STAGES` | the `pipeline` list | What the entry says before it is sealed | Safe, but a stage's output is inside the hash |
| `Contracts\Resolver` | the ten resolvers in `Context\ContextEngine` | `resolvers.<name>.class` | The nine promoted columns and the `context` payload | Safe, but resolved values are inside the hash |
| `Contracts\Masker` | `Security\PartialMasker` | `security.redaction.masker`, or `security.redaction.maskers.<field>` | How one redacted value renders | Safe |
| `Contracts\StreamResolver` | the `global` / `tenant` / `subject_type` strategies in `Integrity\Stream` | `integrity.stream` | Which chain an entry joins | **Forks the chain** on an installation that already has data |
| `Contracts\SpanContextProvider` | an OpenTelemetry adapter when the SDK is present, otherwise `Telemetry\NullSpanContextProvider` | container rebind | Where `trace_id` and `span_id` come from | Safe |
| `Models\Audit` | `Models\Audit` | `models.audit` | The Eloquent class every read and every write hydrates | Safe unless you override `casts()`, `getTable()` or `booted()` |
| `Models\AuditTransaction` | `Models\AuditTransaction` | `models.transaction` | The class of the business-transaction header row | Safe |
| `Contracts\Auditable` | `Concerns\Auditable` | implement it on the model instead of using the trait | How the ten declarations are produced | Safe |
| `Contracts\DeclaresTransitions` | nothing — nobody implements it | implement it on the model | Whether a move the domain forbids becomes an entry | Safe |
| `Contracts\Deduplicates` · `EnumeratesStreams` · `DeclaresFilters` | declared by the shipped drivers | declare them on your own driver | What the driver may be asked to do | Safe |
| `Contracts\Canonicalizer` — `@internal` | `Integrity\JsonCanonicalizer` | container rebind | The exact bytes every hash is taken over | **Breaks every entry already written** |
| `Contracts\Signer` | `hmac`, `openssl` or `null` | *there is no registration point* — see below | — | — |

Four things that look like seams and are not:

| Looks configurable | Actually | Where |
|---|---|---|
| The buffer store | a closed match over `redis` and `memory`; anything else throws | `SentinelServiceProvider::buffer()` |
| The performance mode | a closed match over the three `Enums\Mode` cases | `Dispatch\Dispatcher` |
| The signature driver | a closed match over `hmac`, `openssl`, `null` | `Integrity\Signers::build()` |
| The `Sentinel` manager | `final`, resolved `scoped`, reached through the facade | `src/Sentinel.php` |

> 📌 **Note.** `Contracts\Signer` is a published interface with no supported wiring. `Integrity\Signers`
> is `final`, `@internal`, and injected by concrete type into `EntryBuilder`, `Checkpoints`,
> `Verifier` and `Compliance\Export` — so a class of yours implementing `Signer` is a class the
> package will never build. Implement it only if you are verifying signatures outside Sentinel.

---

## The three tiers

### Tier 1 — a class name in the configuration

This is the sanctioned route and the only one that is validated. `Support\Config` checks
`class_exists()` and `is_a()` before handing the name back, and names the offending key in the
exception:

| Key | Must be | Refusal |
|---|---|---|
| `models.audit` | a `Models\Audit` or a subclass | `ConfigurationException::invalidClass('models.audit', …)` |
| `models.transaction` | a `Models\AuditTransaction` or a subclass | `ConfigurationException::invalidClass('models.transaction', …)` |
| `resolvers.<name>.class` | `Contracts\Resolver` | `ConfigurationException::invalidClass('resolvers.<name>.class', …)` |
| `pipeline[]` | `Contracts\Transformer` | `ConfigurationException::invalidClass('pipeline', …)` |
| `security.redaction.masker` | `Contracts\Masker` | `ConfigurationException::invalidClass('security.redaction.masker', …)` |
| `security.redaction.maskers.<field>` | `Contracts\Masker` | `ConfigurationException::invalidClass('security.redaction.maskers.<field>', …)` |
| `integrity.stream` | `global` · `tenant` · `subject_type`, a `Closure`, or a `Contracts\StreamResolver` class-string | `ConfigurationException::unknown('integrity.stream', …)` |
| `ledger.default` | `archive` · `database` · `fanout` · `memory` · `null` | `ConfigurationException::unknown('ledger.default', …)` |

```php
// config/sentinel.php
'models' => [
    'audit' => App\Models\AuditEntry::class,
    'transaction' => null,
],

'resolvers' => [
    'actor' => ['class' => App\Sentinel\ApiKeyActorResolver::class, 'guard' => null],
],

'security' => [
    'redaction' => [
        'masker' => null,                                        // null means the shipped masker
        'maskers' => ['ip' => App\Sentinel\NetworkMasker::class], // per field, checked first
    ],
],

'integrity' => ['stream' => App\Sentinel\RegionStream::class],
```

Every one of these is resolved through the container, so a replacement may take constructor
dependencies. Two consequences worth knowing:

- **`Support\Config` memoizes nothing.** Each accessor re-reads the repository and re-validates, so a
  bad class name planted at runtime surfaces as a `ConfigurationException` in the middle of a write
  path, not at boot.
- **A published `pipeline` list is taken verbatim.** An empty list or a missing key falls back to the
  shipped order; a non-empty one replaces it entirely. Adding a stage means declaring the whole list,
  and an installation that declared it will not run a stage a later package version adds.

> ⚠️ **Warning.** `resolvers` has exactly ten keys — `source`, `host`, `request`, `session`,
> `command`, `trace`, `actor`, `impersonator`, `tenant`, `job`. `Context\ContextEngine` iterates that
> fixed list, so an eleventh key you invent is never read and never called. To add a fact to the
> entry, push it with `Sentinel::withContext()` or write a `Contracts\Transformer`.

### Tier 2 — a binding in the container

Two bindings are meant to be replaced from your own service provider, and only two:

```php
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Contracts\SpanContextProvider;
use Illuminate\Support\ServiceProvider;

final class SentinelBindings extends ServiceProvider
{
    public function register(): void
    {
        // Scoped, exactly as the package binds it.
        $this->app->scoped(Ledger::class, fn (): Ledger => new ElasticLedger(/* … */));

        // Bound, exactly as the package binds it.
        $this->app->bind(SpanContextProvider::class, App\Sentinel\XRayProvider::class);
    }
}
```

**Keep the lifetime the package chose.** `Contracts\Ledger` is `scoped` so a queue worker does not
carry one request's chain tail into the next; binding it as a `singleton` makes a long-lived worker
reuse whatever state the driver keeps between jobs. `Config`, `Contracts\Canonicalizer` and
`Support\Policies` are singletons; `Sentinel`, `ExecutionContext`, `Ledger`, `Buffer`,
`TransactionScope`, `PolicyRegistry`, `Runtime`, `Signers`, `Keyring`, `Maskers` and `Envelope` are
all `scoped`.

A container rebind is the **only** way to run a driver the package does not ship.
`SentinelServiceProvider::driver()` is a closed match over the five shipped names and throws
`ConfigurationException::unknown` for anything else, so there is no `ledger.ledgers.elastic` you can
add. The cost of the rebind is that `ledger.default` stops describing what actually runs — say so in
the provider, because nothing else will.

### Tier 3 — the ones you must not touch

| Seam | Why not |
|---|---|
| `Contracts\Canonicalizer` | Every hash — new entries and verification of old ones — goes through the one binding. Replacing it invalidates the whole trail. |
| `Integrity\Signers`, `Hasher`, `Verifier`, `Checkpoints`, `Fold`, `Stream`, `Content` | `final`, `@internal`, and injected by concrete type. There is no seam, only a class you cannot substitute. |
| `Security\Keyring`, `Maskers`, `Digester`, `Fields`, `PartialMasker` | Same shape. Reach them through `Contracts\Masker` and the `security.*` config instead. |
| `Sentinel` | `final` and `scoped` with eight collaborators. The facade, `Support\Config` and the container bindings above are the extension surface. |
| `Support\Config` | `final readonly`. Nothing in `src/` calls `config('sentinel.*')` directly — that is what makes a wrong type fail loudly in one place. |

---

## Replacing the models

`Models\Audit` and `Models\AuditTransaction` are deliberately not `final` so that an installation can
add accessors, scopes and relations without forking the package.

```php
namespace App\Models;

use ElPandaPe\Sentinel\Models\Audit;

final class AuditEntry extends Audit
{
    public function isFinancial(): bool
    {
        return in_array($this->subject_type, [Invoice::class, Payment::class], true);
    }
}
```

```php
// config/sentinel.php
'models' => ['audit' => App\Models\AuditEntry::class, 'transaction' => null],
```

The container binding for `Models\Audit` resolves whatever `models.audit` names, so the subclass is
what `Ledger\EntryBuilder` instantiates on the write path, what `DatabaseLedger` hydrates on the read
path, and what `$invoice->audits()` returns — `Concerns\Auditable` reads the same key.

**What replacing the model does not do:**

- It does **not** change the table. Table names come from `tables.prefix` plus `tables.audits`, read
  by `Audit::getTable()`. Overriding `getTable()` in your subclass breaks that indirection for every
  query, migration and command at once.
- It does **not** let you add a column to the hash. The canonical payload is the twenty-seven names
  in `Integrity\CanonicalPayload::COLUMNS`, and nothing else in the package may enumerate them. A
  column you add to the table is outside the hash and outside every signature.
- It does **not** reach the five other models. `Models\AuditTag`, `AuditRelation`, `AuditCheckpoint`,
  `AuditArchive` and `AuditAccess` have no configuration key. They are non-final because Eloquent
  models are, not because subclassing them is supported.
- It does **not** propagate through every relation. `AuditTransaction::audits()` is declared as
  `hasMany(Audit::class, 'transaction_id')` against the package class, so a header's entries hydrate
  as `Models\Audit` even when `models.audit` names something else. Read through
  `Sentinel::audits()->…` if you need your own class there.

> ⚠️ **Warning.** `Models\Audit::booted()` registers the two guards that make an entry immutable —
> `updating` and `deleting` both throw `ImmutableAuditException`. A subclass that declares
> `booted()` without calling `parent::booted()` removes them silently, and `$audit->update(…)`
> starts succeeding. Same for `casts()`: return `[...parent::casts(), …]` or the JSON columns stop
> decoding.

> 🔒 **Security.** Never cast one of the twenty-seven canonical columns to an object type. The
> canonicalizer accepts only `null`, `bool`, `int`, `float`, `string` and arrays of those; anything
> else throws `CanonicalizationException::unsupportedType` — on the write, from the canonicalizer,
> not from your cast.

---

## The canonicalizer and the signer

These two look symmetrical and are not, and the asymmetry is exactly the compatibility rule.

### The canonicalizer is inside the payload

The chain hash is
`algorithm(payload_version ⑴ stream ⑴ sequence ⑴ previous_hash ⑴ canonical(core))`, where
`canonical(core)` is the RFC 8785 encoding of the twenty-seven columns. `Integrity\Hasher` reads the
`algorithm` **off the row**, so changing `integrity.algorithm` only governs new entries and old ones
go on verifying. It resolves the `Contracts\Canonicalizer` **from the container**, and nothing on the
row says which one produced the bytes.

So a rebound canonicalizer is not a "new format alongside the old one". It is the only format, applied
retroactively to the verification of entries that were written under a different one — every one of
which now reports `hash_mismatch`.

That is why `Contracts\Canonicalizer` is marked `@internal` and why the canonicalization is a
**`payload_version` decision that belongs to the package**: a change to the canonical columns, to how
they are encoded, or to the link formula bumps `EntryBuilder::PAYLOAD_VERSION` and ships a
backwards-compatibility test. An application rebinding the interface has made that change without
the version bump, and there is no configuration key that would let it record one.

> 🧪 **Verify it.** Before and after any change near the chain, `php artisan sentinel:verify` and
> check the exit code: `0` sound, `1` a bad finding from a run that happened, `2` a run that could
> not happen. If a deploy turns a green trail red across its whole history, look at what the
> container is handing `Hasher`.

### The signature is outside the payload

`signature` and `signature_key_id` are not among the twenty-seven columns, so writing them costs no
`payload_version` — and switching signing on later breaks nothing. A signature is taken over the
64-character `hash` string, never over the payload, which is what lets a third party verify one
without recomposing the entry or holding the key that encrypted half of it.

What a signature swap breaks is the attestation, not the chain:

| You change | Old entries | Why |
|---|---|---|
| `integrity.signature.key_id`, leaving the old key in `keys` | keep verifying | Every row records the key that signed it; `Signers::for()` resolves it by identifier |
| `integrity.signature.key_id`, removing the old key | `SignatureState::UnknownKey` | A verdict the verifier is not entitled to give — not a forgery |
| `integrity.signature.signer` (`hmac` → `openssl`) | `UnknownKey`, or worse `Invalid` | The row records the identifier, **never the driver**. `Signers::build()` builds under whatever `signer` says today, so an HMAC signature is handed to `OpenSslSigner::verify()` |
| `integrity.signature.enabled` from `false` to `true` | stay `Unsigned` | Signing is not retroactive, and deliberately never will be |

`Unsigned` and `UnknownKey` are not failures. `Invalid` is the only defect — and the third row of
that table is how you manufacture a fleet of them out of a configuration edit. **Rotate `key_id`;
never rotate `signer` on an installation that has already signed anything.**

---

## Extending, decorating, wrapping

Three verbs, and only one of them applies to most of this package.

**Extend** — available for exactly three declarations: `Models\Audit`, `Models\AuditTransaction`, and
the abstract `Testing\LedgerContractTestCase` your own driver's test case extends. Everything else in
`src/` is `final`.

**Implement** — the normal route. Thirteen public interfaces, from one method (`Contracts\Resolver`)
to twelve (`Contracts\Auditable`). Write a class, name it in the configuration or bind it, done.

**Decorate** — how you reuse a shipped `final` class. You cannot subclass `Ledger\DatabaseLedger`,
but you can hold one and delegate to it:

```php
use ElPandaPe\Sentinel\Contracts\DeclaresFilters;
use ElPandaPe\Sentinel\Contracts\Deduplicates;
use ElPandaPe\Sentinel\Contracts\EnumeratesStreams;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Contracts\LedgerStream;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Ledger\DatabaseLedger;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Query\AuditQuery;
use ElPandaPe\Sentinel\Support\AuditCollection;

final readonly class MeteredLedger implements DeclaresFilters, Deduplicates, EnumeratesStreams, Ledger
{
    public function __construct(private DatabaseLedger $inner) {}

    public function write(AuditData $audit): Audit
    {
        $started = hrtime(true);

        try {
            return $this->inner->write($audit);
        } finally {
            Metric::observe('sentinel.write', hrtime(true) - $started);
        }
    }

    public function writeMany(array $audits): AuditCollection
    {
        return $this->inner->writeMany($audits);
    }

    public function append(Audit $audit): Audit
    {
        return $this->inner->append($audit);
    }

    public function find(string $id): ?Audit
    {
        return $this->inner->find($id);
    }

    public function query(AuditQuery $query): AuditCollection
    {
        return $this->inner->query($query);
    }

    public function stream(string $stream): LedgerStream
    {
        return $this->inner->stream($stream);
    }

    public function settled(array $captureIds): array
    {
        return $this->inner->settled($captureIds);
    }

    public function streams(): array
    {
        return $this->inner->streams();
    }

    public function supportedFilters(): array
    {
        return $this->inner->supportedFilters();
    }
}
```

The important line is the `implements` clause. `DatabaseLedger` declares `DeclaresFilters`,
`Deduplicates`, `EnumeratesStreams` and `Ledger`; **a wrapper inherits none of them.** Omit
`EnumeratesStreams` and `Sentinel::verifyEverything()` throws
`QueryException::cannotEnumerateStreams` and `sentinel:verify` with no `--stream` exits `2`. Omit
`Deduplicates` and a retried batch is re-sealed instead of being recognised as already settled. Omit
`DeclaresFilters` and every published filter is assumed answerable.

Run the wrapper through the conformance suite either way — it is shipped as production code, not as
a dev dependency, precisely so a driver can be held to the chain from outside the package.

---

## What @internal means for you

`@internal` on a declaration means it is outside the 1.0 freeze. A patch release may rename it,
change its signature, split it, or delete it, with no deprecation and no note. The boundary is an
invariant rather than a list you have to trust: `tests/SurfaceTest.php` reads **every** PHP file in
`src/`, matches the marker against two curated lists of internal namespaces and internal
declarations, and fails the build in both directions — an internal declaration that lost its marker
and a public one that gained one are the same red suite.

Where the line falls, in the places you are most likely to reach for:

| Public — you may name it | `@internal` — you may not |
|---|---|
| `Contracts\` (13 of 17 interfaces) | `Contracts\Buffer`, `Canonicalizer`, `DispatchStrategy`, `MassStrategy` |
| The seven `Pipeline\Stages\*`, `Pipeline\Pipeline`, `Discard`, `Discarded` | `Ledger\`, `Buffer\`, `Dispatch\`, `Mass\` — every declaration |
| The ten `Context\Resolvers\*`, `ExecutionContext`, `ContextEngine`, `Runtime` | `Security\PartialMasker`, `Keyring`, `Maskers`, `Digester`, `Fields` |
| `Integrity\CanonicalPayload`, `VerificationResult`, `StreamVerification`, `IntegrityReport` | `Integrity\Verifier`, `Signers`, `Hasher`, `Checkpoints`, `Fold`, `Stream`, and the three signers |
| `Models\Audit`, `Security\Rekeyer`, `Presentation\AuditPresenter`, `Testing\LedgerContractTestCase` | `Support\AuditPolicy`, `Policies`, `PolicyRegistry`, `Reference`, `AuditSchema`, `SentinelServiceProvider` |

Two consequences that catch people:

- **The default masker is internal.** `Security\PartialMasker` produces the output you see —
  `c****s@e****e.c****m` — but naming the class in `security.redaction.masker` is building on a
  declaration outside the freeze. Leave the key `null`; that is what selects it.
- **An internal enum can still have public string values.** `Enums\CheckpointState` is `@internal`,
  and its values `anchored` / `archived` / `absent` are the keys of the public
  `StreamVerification::$anchors` and `IntegrityReport::anchors()` tallies. Read the strings; do not
  reference the enum.

> 📌 **Note.** The practical test: if the class is `final`, marked `@internal`, and injected by
> concrete type rather than behind an interface, there is no seam there at all. Wanting one is a
> reasonable thing to say in an issue; reaching around it is building on sand.

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `ConfigurationException: [sentinel.ledger.default] has unknown value [elastic]. Accepted: archive, database, fanout, memory, null.` | `SentinelServiceProvider::driver()` is a closed match; there is no driver-registration hook | Leave `ledger.default` alone and rebind `Contracts\Ledger` as `scoped` in your own provider |
| A stage a new package version added never runs | `pipeline` was published as an explicit list, and a non-empty list is taken verbatim | Re-declare the full list including the new stage; re-check it on every upgrade |
| Every entry ever written reports `hash_mismatch` right after a deploy | `Contracts\Canonicalizer` was rebound. `Hasher` uses the one binding for both writing and verifying, and no column records which one produced the bytes | Restore the shipped binding; the canonicalization is a `payload_version` decision, not an application setting |
| Yesterday's entries verify `Invalid` today, though nothing wrote to the table | `integrity.signature.signer` was changed. The row records `signature_key_id`, never the driver | Restore the driver. Rotate `key_id` and leave old keys in `keys`; never rotate `signer` |
| `Audit::query()->update(...)` on a subclassed model succeeds where it used to throw | The subclass declared `booted()` without `parent::booted()`, dropping the immutability guards | Call `parent::booted()` first |
| `CanonicalizationException: unsupported type` on every write after adding a cast | The audit subclass cast one of the twenty-seven canonical columns to an object type | Only cast columns outside `CanonicalPayload::COLUMNS`, and merge `parent::casts()` |
| `sentinel:verify` exits `2` with "cannot enumerate streams" after a custom driver went in | The driver — or a decorator wrapping one that does — never declared `Contracts\EnumeratesStreams` | Declare the capability interface on the wrapper and forward `streams()` |
| An eleventh key added under `resolvers` is never called | `Context\ContextEngine` iterates a fixed list of ten names | Use `Sentinel::withContext()` or write a `Contracts\Transformer` |
| A worker's second job continues the first job's chain tail | `Contracts\Ledger` was rebound as a `singleton` instead of `scoped` | Use `$this->app->scoped(Ledger::class, …)` |
| `$transaction->audits()` returns `Models\Audit` although `models.audit` names a subclass | `AuditTransaction::audits()` is declared against the package class | Read through `Sentinel::audits()->…` when the subclass matters |

---

## ✅ Best practices

✅ **Do** — swap through the configuration whenever a key exists for it. `Support\Config` validates
`class_exists()` and `is_a()` and names the exact key in the exception; a container rebind is
unvalidated and fails somewhere else entirely.

```php
// config/sentinel.php
'resolvers' => ['actor' => ['class' => App\Sentinel\ApiKeyActorResolver::class, 'guard' => null]],
```

❌ **Don't** — rebind something the configuration already names. You then have two sources of truth
and the file lies about what runs.

```php
// The config key is read on every capture; this binding is not what ContextEngine asks for.
$this->app->bind(ActorResolver::class, ApiKeyActorResolver::class);
```

---

✅ **Do** — keep the package's own lifetime when you rebind `Contracts\Ledger`. `scoped` is what
makes a worker start each job with a fresh chain tail.

```php
use ElPandaPe\Sentinel\Contracts\Ledger;

$this->app->scoped(Ledger::class, fn (): Ledger => new ElasticLedger(/* … */));
```

❌ **Don't** — promote it to a singleton because "it is stateless anyway". The driver's tail cache
then outlives the request that filled it, for the life of the worker process.

```php
$this->app->singleton(Ledger::class, ElasticLedger::class);
```

---

✅ **Do** — re-declare every capability interface on a decorator and forward the methods. A wrapper
inherits nothing from the class it holds.

```php
final readonly class MeteredLedger implements Deduplicates, EnumeratesStreams, Ledger
{
    public function streams(): array
    {
        return $this->inner->streams();
    }
}
```

❌ **Don't** — wrap `DatabaseLedger` in a plain `Ledger` and expect verification to keep working.
`Sentinel::verifyEverything()` refuses a ledger it cannot ask for a stream list rather than reporting
it empty.

```php
final readonly class MeteredLedger implements Ledger { /* streams() is gone */ }
```

---

✅ **Do** — subclass `Models\Audit` for accessors, scopes and relations, and chain into the parent
whenever you override a hook.

```php
protected static function booted(): void
{
    parent::booted();

    static::addGlobalScope('tenant', new TenantScope);
}
```

❌ **Don't** — override `getTable()`, `getConnectionName()`, or `casts()` for a canonical column.
The first two are how `tables.prefix` and `database.connection` reach every query in the package;
the third decides what `CanonicalPayload::from()` hands the canonicalizer.

```php
public function getTable(): string
{
    return 'audits'; // tables.prefix now applies to everything except this model
}
```

---

✅ **Do** — rotate a signing key by moving `integrity.signature.key_id` and leaving the retired key
on the ring. Every row records the identifier that signed it.

```php
'signature' => [
    'signer' => 'openssl',
    'key_id' => 'v2',
    'keys' => ['v1' => env('SENTINEL_SIGNING_PUBLIC_V1'), 'v2' => env('SENTINEL_SIGNING_PUBLIC_V2')],
],
```

❌ **Don't** — change `signer` on an installation that has signed entries. Nothing on the row says
which construction made the signature, so every old one is verified with the new driver.

```php
'signature' => ['signer' => 'openssl', 'key_id' => 'default'], // was 'hmac' yesterday
```

---

✅ **Do** — leave `security.redaction.masker` at `null` and name your own maskers per field. `null`
is what selects the shipped default, and the per-field map is checked before the global one.

```php
'redaction' => ['masker' => null, 'maskers' => ['ip' => App\Sentinel\NetworkMasker::class]],
```

❌ **Don't** — name `Security\PartialMasker` explicitly. It is `@internal`; a patch release may move
it, and the arch test that holds the boundary will not warn you.

```php
'redaction' => ['masker' => ElPandaPe\Sentinel\Security\PartialMasker::class],
```

---

✅ **Do** — declare the full `pipeline` list when you insert a stage, and put a discarding stage
before `MaskSensitiveData` so a discarded entry pays for no masking and no encryption.

```php
'pipeline' => [
    ElPandaPe\Sentinel\Pipeline\Stages\FilterUnchanged::class,
    App\Sentinel\DropRoutineReads::class,
    ElPandaPe\Sentinel\Pipeline\Stages\ResolveContext::class,
    ElPandaPe\Sentinel\Pipeline\Stages\ResolveTags::class,
    ElPandaPe\Sentinel\Pipeline\Stages\NormalizeData::class,
    ElPandaPe\Sentinel\Pipeline\Stages\MaskSensitiveData::class,
    ElPandaPe\Sentinel\Pipeline\Stages\EncryptSensitiveData::class,
    ElPandaPe\Sentinel\Pipeline\Stages\EnforcePolicies::class,
],
```

❌ **Don't** — set `'pipeline' => []` to turn the pipeline off. An empty list means the shipped
order, so that installation transforms exactly as much as it did before.

```php
'pipeline' => [], // reads as "use the seven shipped stages"
```

---

**See also:** [The Ledger contract](01-the-ledger-contract.md) · [The shipped drivers](02-shipped-drivers.md) · [Writing a ledger driver](03-writing-a-ledger-driver.md) · [The contract test suite](04-the-contract-test-suite.md) · [Fanout](05-fanout.md) · [Architecture](../01-concepts/05-architecture.md) · [The write pipeline](../05-pipeline-and-security/01-the-write-pipeline.md) · [Writing a masker](../05-pipeline-and-security/05-writing-a-masker.md) · [Writing your own resolver](../04-context/07-writing-your-own-resolver.md) · [Streams](../07-integrity/02-streams.md) · [Canonicalization](../07-integrity/03-canonicalization.md) · [Signing the chain](../07-integrity/04-signing.md) · [Configuration](../99-reference/02-configuration.md) · [API stability](../99-reference/09-api-stability.md) · [Exceptions](../99-reference/06-exceptions.md)
