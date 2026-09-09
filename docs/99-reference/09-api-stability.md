# 📚 API stability

> What this package promises not to change, what it explicitly reserves the right to change, and the
> one contract that binds tighter than semantic versioning does.

**On this page:** [The shape of the promise](#the-shape-of-the-promise) ·
[What is frozen](#what-is-frozen) · [What is not frozen](#what-is-not-frozen) ·
[The `@internal` boundary](#the-internal-boundary) ·
[Versioning after the freeze](#versioning-after-the-freeze) ·
[The integrity contract](#the-integrity-contract-is-stricter-than-semver) ·
[Deprecation policy](#deprecation-policy) ·
[What a security fix may break](#what-a-security-fix-may-break) ·
[Depending on this package](#depending-on-this-package-in-a-way-that-survives) ·
[⚠️ Pitfalls](#️-pitfalls) · [✅ Best practices](#-best-practices)

---

## The shape of the promise

The public surface stopped moving at `v1.0.0-rc.1`. Between that tag and `v1.0.0`, only bugfixes and
documentation land. After `v1.0.0`, ordinary semantic versioning applies.

Because the current tag is a release candidate, Composer will not resolve it under the default
stability. Say so for this package and for nothing else:

```bash
composer require elpandape/sentinel:^1.0@RC
```

`"minimum-stability": "RC"` in your `composer.json` does the same thing for **every** package you
require, which is almost never what was meant. The `@RC` suffix is the narrow form.

> 🧪 **Verify it.** `php artisan about --only=sentinel` prints the installed version, the performance mode,
> the ledger driver, the `payload_version` the package writes, and whether compliance mode and
> telemetry are on. `ElPandaPe\Sentinel\Console\About` deliberately prints no key, no key identifier
> and no signer configuration, because `about` output ends up pasted into issues and deploy logs.

Two documents travel inside the installed package and are the record you check between tags:
`vendor/elpandape/sentinel/UPGRADE.md` for anything that changed behaviour or shape, and
`CHANGELOG.md` for versions that only added. `.gitattributes` export-ignores the workbench — `tests/`,
`docker/`, `.github/`, the `Makefile`, `CONTRIBUTING.md`, `SECURITY.md` — and ships `src/`,
`resources/`, `stubs/`, this `docs/` tree, the README, the upgrade guide and the changelog.
None of that needs a network connection to read.

---

## What is frozen

| Surface | What the promise covers | Where it lives |
|---|---|---|
| The facade and the manager | The nineteen methods of `ElPandaPe\Sentinel\Sentinel`, reached through `Facades\Sentinel` or the root `Sentinel` alias registered in `composer.json` | `src/Sentinel.php`, `src/Facades/Sentinel.php` |
| The `Auditable` trait | `audits()`, `latestAudit()`, `relationHistory()`, and the ten declarations a model makes — `$auditInclude`, `$auditExclude`, `$auditRedact`, `$auditEncrypt`, `$auditHash`, `$auditTags`, `$auditTransitions`, `$auditParents`, `$auditSnapshots`, `$auditSeverity` | `src/Concerns/Auditable.php` |
| Thirteen of the seventeen contracts | `Auditable`, `Ledger`, `LedgerStream`, `Deduplicates`, `EnumeratesStreams`, `DeclaresFilters`, `DeclaresTransitions`, `Masker`, `Resolver`, `Signer`, `SpanContextProvider`, `StreamResolver`, `Transformer` | `src/Contracts/` |
| The entry, as data and as a model | `Data\AuditData`, `Data\RelationLine`, `Models\Audit` — including `toArray()`, whose keys only ever grow | `src/Data/`, `src/Models/Audit.php` |
| The Query API | `Query\AuditQuery`, `Query\AuditPage`, `Support\AuditCollection`, `Enums\Filter` | `src/Query/` |
| Result shapes | `Restore\RestoreResult`, `Redaction\Tombstone`, `Integrity\VerificationResult`, `Integrity\StreamVerification`, `Integrity\IntegrityReport`, `Archive\Rehydration` | `src/Restore/`, `src/Integrity/` |
| The eleven events | `Auditing`, `Audited`, `AuditCreating`, `AuditCreated`, `AuditDiscarded`, `AuditRestoring`, `AuditRestored`, `AuditWriteFailed`, `BufferFlushFailed`, `LedgerDestinationFailed`, `IntegrityVerificationFailed` | `src/Events/` |
| The eleven commands | Each command's **name, options and exit codes** — `sentinel:install`, `verify`, `checkpoint`, `prune`, `redact`, `rekey`, `export`, `import`, `show`, `flush`, `partitions` | `src/Console/` |
| Configuration | Every key in `config/sentinel.php`, and `Support\Config` as the typed reader over it | `config/sentinel.php`, `src/Support/Config.php` |
| The serialised entry | The `toArray()` shape and the same shape over HTTP through `AuditResource` | `src/Models/Audit.php` |
| The driver conformance suite | `Testing\LedgerContractTestCase` — shipped in `require`, not `require-dev` | `src/Testing/` |
| Fourteen of the eighteen enums | Their cases **and their backed values**, which is the part that matters: several land in columns the hash covers | `src/Enums/` |

Two of these are worth reading twice.

**A command is frozen at the terminal, not at the class.** `Console\VerifyCommand` carries
`@internal`; `php artisan sentinel:verify --stream=… --depth=entries` and its exit codes do not. The
class may be split, renamed or absorbed; the invocation and the code it returns to your monitoring
will not move. Build a watchdog on the exit code, never on the class.

**`Testing\LedgerContractTestCase` is production code.** It sits in `src/` and ships in the tarball
because a contract nobody outside the package can run is a promise rather than a verification.
PHPUnit and Testbench stay in `suggest`, so nothing is installed on your behalf.

---

## What is not frozen

| Surface | Why it is outside | What is inside, instead |
|---|---|---|
| Everything marked `@internal` | See the next section | The contract, facade or command in front of it |
| The four internal contracts — `Contracts\Buffer`, `Canonicalizer`, `DispatchStrategy`, `MassStrategy` | `tests/SurfaceTest.php` classifies them as *"the seams the package uses to talk to itself, not the points somebody extends"* | Configuration: `mode`, `buffer.store`, `mass_operations.mode` |
| The four internal enums — `Enums\BatchLine`, `CheckpointState`, `PruneAction`, `RetentionHold` | *"values only an internal writes and only an internal reads"* — you meet them in command output, never in a signature | The command's printed vocabulary |
| The table layout | The frozen list names `Models\Audit` and its `toArray()`; it does not name columns. The twenty-seven columns inside the canonical payload are pinned separately, by `payload_version` | `Models\Audit`, the Query API, `toArray()` |
| Log lines, translated strings and command output text | `resources/lang/en` and `resources/lang/es` are wording, not contract | Exit codes, and the events a listener can subscribe to |
| Benchmark numbers | Measurements of a machine, not promises of one | — |

> 📌 **Note.** `Sentinel` itself is `final` and is bound `scoped` in the container. You cannot
> subclass it. The extension points are the container bindings, the configuration, and the contracts
> — see [Swapping components](../11-extending/06-swapping-components.md).

---

## The `@internal` boundary

The rule is an invariant rather than a list: **every declaration the package ships is either part of
the frozen surface or carries `@internal` in its own docblock, and one that is neither fails the
build.**

`tests/SurfaceTest.php` holds it, and holds it in both directions. It reads the marker straight out
of each file under `src/` and compares the result against two tables of expectations — whole
namespaces that are internal (`Buffer`, `Dispatch`, `Ledger`, `Mass`, `Compliance`, `Console`,
`Import`, `Partitions`, `Retention`, `Jobs`, `Snapshot`, `Telemetry\OpenTelemetry`) and individual
declarations inside namespaces that are not. It then asserts two failure buckets are empty:

- **unmarked but internal** — a new class landed with no marker and nobody decided which side it was on;
- **marked but public** — a class on the frozen list quietly grew an `@internal` marker.

Each table entry is keyed by the *reason* it is internal, and a second test asserts every reason is a
real sentence and unique — because an exception with no reason written next to it is a hole, and the
reason is what lets somebody move a line from one side to the other on purpose. A third test pins by
name the declarations a reader actually reaches (the facade, `Sentinel`, `Concerns\Auditable`,
`Data\AuditData`, `Models\Audit`, `Query\AuditQuery`, `Support\Config`, `Diff\Diff`,
`Integrity\CanonicalPayload`, `Testing\LedgerContractTestCase` and the rest), so none of them can
drift internal by accident.

### What happens if you build on the wrong side

An `@internal` declaration can be renamed, resplit, given a different constructor or deleted **in a
patch release**, with no deprecation cycle and no entry owed in `UPGRADE.md`. The failure mode is a
fatal error or a signature mismatch on `composer update`, at the worst possible moment: in the code
that records what your application did.

| If you reached for… | Use this instead |
|---|---|
| `Ledger\DatabaseLedger`, `MemoryLedger`, `FanoutLedger` | `Contracts\Ledger`, resolved from the container |
| `Capture\ModelObserver`, `Capture\ModelCapture`, `Snapshot\SnapshotBuilder` | The `Auditable` trait, plus the `Auditing` / `Audited` events |
| `Integrity\Verifier`, `Integrity\Hasher`, `Integrity\Checkpoints` | `Sentinel::verifyIntegrity()`, `verifyAnchors()`, `verifyRoots()`, `verifyEverything()` |
| `Restore\Restorer`, `Restore\Planner`, `Restore\Plan` | `$audit->restore()`, which returns `Restore\RestoreResult` |
| `Security\Keyring`, `Security\Maskers`, `Security\Digester` | `Contracts\Masker` and the `security.*` configuration; `Security\Rekeyer` is public |
| `Diff\Comparator`, `Diff\Normalizer`, `Diff\Pointer` | `$audit->diff()`, returning `Diff\Diff` and `Diff\Change` |
| `Telemetry\Tracer`, `Telemetry\TraceParent` | `Sentinel::trace()`, returning `Telemetry\TraceContext` or `null` |
| `Archive\BatchWriter`, `Archive\Manifest` | `Archive\Rehydrator`, returning `Archive\Rehydration` |
| `Mass\AuditedQuery` | The `auditing()` macro on `Illuminate\Database\Eloquent\Builder` |
| `Support\AuditPolicy`, `Support\PolicyRegistry`, `Support\Policies` | The model's own declarations, and `Sentinel::filter()` |
| `Console\VerifyCommand` and the other ten classes | `Artisan::call('sentinel:verify', …)` and the exit code |

---

## Versioning after the freeze

After `v1.0.0`, the ordinary rules apply. What follows is those rules applied to this package's
actual seams, with the source of each in the last column.

| Change | Costs | Why |
|---|---|---|
| A new key appended to `toArray()` or to its `integrity` block | MINOR | The shape only ever grows: no key is renamed, removed or reinterpreted under the same name (`Models\Audit::toArray()` docblock) |
| A new optional configuration key with a default | MINOR | `Support\Config` re-declares nested defaults in code, because Laravel's config merge is one level deep — a published config that predates the key still resolves it |
| A new opt-in capability interface for ledger drivers | MINOR | `Contracts\DeclaresFilters` exists *as a separate interface* for exactly this reason: "adding a method to a contract published one version ago would break every driver that does not need it either" |
| A new filter on the Query API | MINOR — and a driver that cannot translate it declares so | `Contracts\DeclaresFilters` makes the refusal land as the filter is added, not when the query runs |
| A new pipeline stage in the shipped default order | MINOR, **but it will not run** on an installation whose published `config/sentinel.php` has a non-empty `pipeline` | `Config::pipelineStages()` takes a non-empty list verbatim; any version that adds a stage says so in `UPGRADE.md` |
| A new migration | MINOR — and it is skipped for a file you published under the same name | `Support\PackageMigrations` decides **per file**, so publishing one migration does not stop the rest arriving |
| A new method on `Contracts\Ledger`, `Masker`, `Resolver`, `Signer` or `Transformer` | MAJOR | Every implementer outside the package breaks; the package's answer is to not do it, and to add a capability interface instead |
| Renaming or removing a `toArray()` key | MAJOR | The only-ever-added rule is the contract |
| Changing an enum's **backed value** | MAJOR **and** a `payload_version` bump if that value lands in a canonical column | `severity`, `source`, `audit_type` and `event` are all inside the hash |
| A fix inside an `@internal` class that changes no published behaviour | PATCH | Nothing owed, nothing announced beyond the changelog |

Until `v1.0.0` the release-candidate rule sits on top of all of this: nothing breaks, and a break
that has to happen renumbers the candidate (see [below](#what-a-security-fix-may-break)).

---

## The integrity contract is stricter than semver

Every entry carries a `payload_version` column, default `1`, written from
`Ledger\EntryBuilder::PAYLOAD_VERSION`. It is the version of the **hash recipe**, and it is separate
from the package's own version on purpose.

The recipe, in `Integrity\Hasher::hash()`:

```
hash = algorithm( payload_version ␟ stream ␟ sequence ␟ (previous_hash ?? '') ␟ canonical )
```

`␟` is the ASCII unit separator (`\x1f`), so that `("a", 11)` and `("a1", 1)` cannot fold into the
same prefix. `canonical` is the RFC 8785 canonical JSON of exactly twenty-seven columns, frozen in
`Integrity\CanonicalPayload::COLUMNS` — and nothing else in the package may enumerate them, because a
second list of them is a second payload format.

`tests/Integrity/CanonicalPayloadTest.php` asserts both halves: the twenty-seven that are in, and the
thirteen that are deliberately out — `stream`, `sequence`, `previous_hash`, `payload_version`, `hash`,
`signature`, `signature_key_id`, `algorithm`, `created_at`, `capture_id`, `redacted_at`,
`redaction_reason`, `redacted_hash`. Some are out because including them would make the hash
circular; the rest because they change after the seal.

**The rule.** Anything touching `sequence`, `hash`, `previous_hash` or the canonical payload bumps
`payload_version` and ships a backwards-compatibility test against a frozen dataset. That is
`CONTRIBUTING.md`'s gate, not a preference.

### What that guarantees you

1. **An entry keeps verifying under the format it was written with.** `payload_version` travels in
   the row *and* in the hash prefix, so a future format cannot silently be applied to an old row.
2. **Changing `integrity.algorithm` does not invalidate history.** `Hasher::hash()` reads `algorithm`
   off the row, never from current configuration. Old entries keep verifying under what sealed them.
3. **A column can be added outside the frozen twenty-seven at no cost.** That is how signing arrived:
   `signature` and `signature_key_id` are not in the list the hash covers, so writing them cost no
   `payload_version` at all.
4. **You can verify without this package.** `Integrity\CanonicalPayload` is public. The repository's
   suite proves the point by recomputing a frozen hash with nothing but `hash('sha256', …)`, a JCS
   canonicaliser and the column list — which means your verification tooling can be written in
   another language and still agree byte for byte.
5. **A bump rewrites nothing.** History is append-only. At this tag `payload_version` is `1`
   everywhere and no second format exists; the column and the prefix are the mechanism that lets one
   arrive later without touching a single row already written.

> 🔒 **Security.** `stream` and `sequence` are inside the hash prefix. A chain is never moved by an
> `UPDATE`: renaming a stream in place or renumbering a range invalidates every hash in it, and
> `sentinel:verify` will report exactly that. Moving a chain means writing new entries.

---

## Deprecation policy

At this tag `src/` carries no `@deprecated` marker at all — nothing is on its way out. The policy is
the one that applies when something has to be:

- **`toArray()` keys are only ever added.** A key is never renamed, never removed and never
  reinterpreted under the same name.
- **A shape that must change arrives beside the one it replaces.** The old shape stays until `v2`.
- **The deprecation is written in `UPGRADE.md`**, which ships inside the installed package.
- **A new key may be three-valued.** `integrity.verified` is the shipped example: `null` means "not
  checked in this call", never "failed". Read it as three states, not as a boolean.

```php
match ($entry['integrity']['verified']) {
    true => 'verified',
    false => 'TAMPERED',
    null => 'not checked',
};
```

When a block written by an older version has a different shape, branch on the shape rather than
assuming. This is the actual guidance for `metadata.restore.skipped`, which was a map keyed by field
and became a list of pairs — entries written before the change keep the old shape and still reproduce
their hashes, so both live in the same table forever:

```php
$skipped = $entry->metadata['restore']['skipped'] ?? [];

$reasons = array_is_list($skipped)
    ? array_column($skipped, 'reason', 'field')
    : $skipped;
```

---

## What a security fix may break

One question decides whether a change is allowed to break the freeze: **does it correct something
incorrect, insecure or unverifiable, or only something uncomfortable?**

| Kind of change | What it costs |
|---|---|
| Correctness, security or integrity | Breaks the freeze, lands, and the release is renumbered `rc.N+1` with the feedback period starting again from zero |
| Ergonomics or naming, if it fits additively | Waits for a `1.x` |
| Ergonomics or naming, if it does not | Waits for `2.0` |
| Anything at all, between the last `rc.N` and `v1.0.0` | Not allowed — that route is closed |

The precedent in this package's own history is the one to reason from. `metadata.restore.skipped`
used to be a map keyed by field name, and the security stages match a protected field **by key name
at any depth of `metadata`**. A model that declared `email` as redacted therefore sealed a mask where
the *reason* `redacted_field` belonged; one that declared it hashed sealed a digest of it; one that
declared it encrypted sealed ciphertext and then advertised `email` in `encryption.fields` for a
value the entry never carried. All of it inside the canonical payload, so nothing could correct it
after the write: the entry verified, and what it said was wrong. That is the class of defect that
outranks a published shape.

Two limits on what a fix may do, both structural rather than promised:

- **It cannot rewrite an existing entry.** History is append-only. A fix changes what the package
  writes from that release forward; rows already sealed keep their content and their hash.
- **It reaches the latest release only.** Per `SECURITY.md`, only the latest released version gets
  security fixes, and there are no backports before `1.0.0`. Staying two minors behind means the fix
  is an upgrade, not a patch.

> 🔒 **Security.** Report a vulnerability privately to the address in `SECURITY.md` — never as a
> public issue. A vulnerability in this package is a vulnerability in your evidence.

---

## Depending on this package in a way that survives

**Constrain per package, not globally.** `^1.0@RC` accepts a candidate for Sentinel and for nothing
else. After `v1.0.0` ships, `^1.0` is the whole constraint.

**Type-hint contracts, never implementations.** The entire `Ledger` namespace is internal; the
interface in front of it is not.

```php
use App\Sentinel\ElasticsearchLedger;
use ElPandaPe\Sentinel\Contracts\Ledger;
use Illuminate\Support\ServiceProvider;

final class AuditBindings extends ServiceProvider
{
    public function register(): void
    {
        // Same lifetime the package uses, so a worker never carries one request's chain tail
        // into the next.
        $this->app->scoped(Ledger::class, fn (): Ledger => new ElasticsearchLedger(/* ... */));
    }
}
```

**Extend the model instead of editing it.** `Models\Audit` is deliberately non-final — an arch test
keeps the whole `Models` namespace open so configuration can replace it — and `Config::model()`
enforces that your class really is a subclass.

```php
namespace App\Models;

use ElPandaPe\Sentinel\Models\Audit as SentinelAudit;

class Audit extends SentinelAudit
{
    public function isHighSeverity(): bool
    {
        return $this->severity->atLeast(\ElPandaPe\Sentinel\Enums\Severity::Warning);
    }
}

// config/sentinel.php
// 'models' => ['audit' => App\Models\Audit::class],
```

`$model->audits()` resolves the configured class too, so nothing at the call site changes.

**Run the conformance suite in your own repository if you ship a driver.** It is the mechanism that
turns a tightened contract into a red test in your CI instead of a surprise in production.

```php
namespace Tests\Ledger;

use App\Sentinel\ElasticsearchLedger;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Testing\LedgerContractTestCase;

final class ElasticsearchLedgerTest extends LedgerContractTestCase
{
    protected function ledger(): Ledger
    {
        return new ElasticsearchLedger(/* ... */);
    }

    protected function settle(Ledger $ledger): void
    {
        // A store whose reads are eventually consistent says so here rather than failing for it.
        $this->refreshIndex();
    }
}
```

**Publish only the configuration you are changing.** A published `config/sentinel.php` with a
non-empty `pipeline` pins you to the stage list you published — that is the one section a shallow
merge cannot rescue. Leave it empty unless you are genuinely reordering stages, and re-read
`UPGRADE.md` for that key on every upgrade.

**Read the trail through the frozen surface.** `Sentinel::audits()`, `Models\Audit` and `toArray()`
are named in the freeze; the table layout is not. A raw query over `sentinel_audits` also bypasses the
compliance access log — `Compliance\AccessLog` is reached from `Query\AuditQuery` and from nowhere
else in `src/`, so a read that skips the Query API is a read nothing records.

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `composer require elpandape/sentinel` cannot find a matching version | The current tag is a release candidate and the default stability is `stable` | `composer require elpandape/sentinel:^1.0@RC` — do not raise `minimum-stability` globally |
| An upgrade renamed a class you called and `UPGRADE.md` says nothing about it | That class carries `@internal`; nothing was owed | Move to the contract, facade or command in the table [above](#what-happens-if-you-build-on-the-wrong-side) |
| A pipeline stage the release notes announce never runs | Your published `config/sentinel.php` has a non-empty `pipeline`, which `Config::pipelineStages()` takes verbatim | Add the stage class to your list, or empty the key to follow the package's default order |
| A migration the release notes mention is never applied | You published a migration of the same name; `PackageMigrations` skips it per file | Apply the change to your published copy — the other migrations still arrive |
| You implemented `Contracts\Canonicalizer` (or `Buffer`, `DispatchStrategy`, `MassStrategy`) and it broke | Four of the seventeen contracts are internal seams, not extension points | Use configuration: `mode`, `buffer.store`, `mass_operations.mode` |
| `sentinel:verify` reports a hash mismatch across a whole stream after a "harmless" data move | `stream` and `sequence` are inside the hash prefix, so renaming or renumbering in place invalidates every entry | Restore the original values; a chain is relocated by writing new entries, never by an `UPDATE` |
| A dashboard shows every entry as tampered | `integrity.verified` is `null` for any entry not checked in that call, and `null` was read as falsy | Match on all three states — `true`, `false`, `null` |
| Reading `metadata.restore.skipped` throws on old entries | The shape changed from a map to a list of pairs, and old entries keep the old shape forever | Branch with `array_is_list()` before reading |

---

## ✅ Best practices

✅ **Do** — pin the release candidate for this package alone, and let the rest of your project stay
stable. The `@RC` suffix is scoped to one requirement.

```bash
composer require elpandape/sentinel:^1.0@RC
```

❌ **Don't** — relax stability project-wide to get the same result. It applies to every package you
require, and the next `composer update` can pull an unrelated dependency's release candidate.

```json
{ "minimum-stability": "RC" }
```

✅ **Do** — type-hint the contract and let the container decide what answers it. `Contracts\Ledger` is
frozen; the driver that implements it is not.

```php
use ElPandaPe\Sentinel\Contracts\Ledger;

public function __construct(private readonly Ledger $ledger) {}
```

❌ **Don't** — name a concrete driver. The whole `Ledger` namespace is `@internal` and may be
restructured in a patch release.

```php
use ElPandaPe\Sentinel\Ledger\DatabaseLedger;

public function __construct(private readonly DatabaseLedger $ledger) {}
```

✅ **Do** — subclass `Models\Audit` and point `models.audit` at your class when you need extra
behaviour. `Config::model()` checks the inheritance, and `$model->audits()` resolves your class.

```php
// config/sentinel.php
'models' => ['audit' => App\Models\Audit::class],
```

❌ **Don't** — edit the model inside `vendor/`, or copy it into your application. The first is undone
by the next `composer install`; the second detaches your copy from every fix and every added key.

✅ **Do** — watch a command's exit code. The name, the options and the codes are the published
surface, and they survive any refactor of the class behind them.

```php
use Illuminate\Support\Facades\Artisan;

$code = Artisan::call('sentinel:verify', ['--depth' => 'entries']);
// 0 sound · 1 a finding · 2 could not run — and 1 and 2 are not the same alert
```

❌ **Don't** — instantiate `Console\VerifyCommand` or read its properties. It carries `@internal`, and
a test that couples to it will break on a version that changes nothing you can observe.

✅ **Do** — treat every value the package added later as three-valued or shape-varying until you have
checked it, because entries written years apart share one table.

```php
$skipped = $entry->metadata['restore']['skipped'] ?? [];
$reasons = array_is_list($skipped) ? array_column($skipped, 'reason', 'field') : $skipped;
```

❌ **Don't** — assume today's shape for a block sealed by an older release. The entry cannot be
rewritten to match: it would stop reproducing its own hash.

✅ **Do** — run `Testing\LedgerContractTestCase` against your own driver in your own CI. It ships in
`require` precisely so that a contract tightened upstream fails your build rather than your ledger.

❌ **Don't** — assume the contract is stronger than it says. `writeMany()` is not atomic, no read
promises to see a write that just returned, and idempotency by `capture_id` belongs to the caller —
all three are stated in the `Contracts\Ledger` docblock and asserted by the suite.

---

**See also:** [The Sentinel facade](01-facade-api.md) · [Configuration](02-configuration.md) ·
[Serialization](08-serialization.md) · [Exit codes](07-exit-codes.md) · [Enums](04-enums.md) ·
[Canonicalization](../07-integrity/03-canonicalization.md) ·
[The Ledger contract](../11-extending/01-the-ledger-contract.md) ·
[The contract test suite](../11-extending/04-the-contract-test-suite.md) ·
[Swapping components](../11-extending/06-swapping-components.md) ·
[Installation](../02-getting-started/01-installation.md)
