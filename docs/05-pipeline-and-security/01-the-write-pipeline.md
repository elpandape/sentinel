# 🛡️ The write pipeline

> The seven stages every audit entry passes through between capture and ledger — what each one
> receives, what it may change, what it must leave alone, and how to add, move or remove one.

**On this page:** [What the pipeline is](#what-the-pipeline-is) · [The stage contract](#the-stage-contract) · [The seven stages](#the-seven-stages) · [Why the order is the order](#why-the-order-is-the-order) · [The last word: the `Auditing` event](#the-last-word-the-auditing-event) · [Removing a stage](#removing-a-stage) · [Adding your own stage](#adding-your-own-stage) · [When a stage throws](#when-a-stage-throws) · [Cost and the performance modes](#cost-and-the-performance-modes) · [⚠️ Pitfalls](#️-pitfalls) · [✅ Best practices](#-best-practices)

---

## What the pipeline is

Three components own three questions. The capture decides *what happened*. The pipeline decides
*what the entry may say*. The ledger decides *what the entry is, in the chain*. `Pipeline\Pipeline`
is the middle one, and it is the only place in the package where an entry is transformed.

What travels is a `Data\AuditData`: a plain mutable object whose properties are named after the
audit columns, so that no translation layer sits between what a stage writes and what gets hashed.
It deliberately has no `sequence`, no `hash` and no `previous_hash` — those are the ledger's, minted
inside the same operation as the write. `AuditData::fromPayload()` throws
`DispatchException::proposedItsOwnPlaceInTheChain()` if a payload carries one of the three.

The stage list is configuration, not inheritance:

```php
// config/sentinel.php
'pipeline' => [
    ElPandaPe\Sentinel\Pipeline\Stages\FilterUnchanged::class,
    ElPandaPe\Sentinel\Pipeline\Stages\ResolveContext::class,
    ElPandaPe\Sentinel\Pipeline\Stages\ResolveTags::class,
    ElPandaPe\Sentinel\Pipeline\Stages\NormalizeData::class,
    ElPandaPe\Sentinel\Pipeline\Stages\MaskSensitiveData::class,
    ElPandaPe\Sentinel\Pipeline\Stages\EncryptSensitiveData::class,
    ElPandaPe\Sentinel\Pipeline\Stages\EnforcePolicies::class,
],
```

That list is also `Pipeline::DEFAULT_STAGES`, and the constant is what an empty or absent config key
falls back to.

> 🔒 **Security.** The pipeline runs **during the capture, in the capturing process**, under every
> performance mode. It never runs behind a queue job or a buffer flush, because a declared sensitive
> value must not exist untransformed even for the moment it waits to be written. `tests/Security/NoPlaintextTest.php`
> asserts this against the persisted row, every dispatched event payload, the serialised queue job,
> the buffer contents and the failure log line.

## The stage contract

A stage is any class implementing `Contracts\Transformer`. One method:

```php
namespace ElPandaPe\Sentinel\Contracts;

use Closure;
use ElPandaPe\Sentinel\Data\AuditData;

interface Transformer
{
    /** @param Closure(AuditData): ?AuditData $next */
    public function handle(AuditData $audit, Closure $next): ?AuditData;
}
```

| Fact | Consequence |
|---|---|
| The stage is resolved with `$container->make()` **on every entry** | Constructor injection works, and a stage may be `final readonly` with dependencies. |
| Mutating `$audit` in place is normal | Every shipped stage does it. `Pipeline::process()` hands back the same object, not a copy. |
| Returning `null` discards the entry | The chain never sees it, no `sequence` is spent, no gap is left. |
| Returning `$next($audit)` continues | Whatever the rest of the chain hands back is what the stage returns. |
| Not calling `$next()` and returning `$audit` | Silently skips every later stage — including masking, encryption and policies. Never do this. |

Discarding is announced with `Pipeline\Discard`, injected into the stage:

```php
use ElPandaPe\Sentinel\Pipeline\Discard;

$this->discard->because('routine reads are not kept');

return null;
```

`return null` is the mechanism; `because()` is only what gives `Events\AuditDiscarded` a reason
other than `unspecified`. The first stage to return `null` owns the discard — stages wrapping it
only see that null travelling back out. Calling `because()` when no pass is open throws
`DiscardException::outsideThePipeline()`, which names `verifyIntegrity()` in its message: a discard
after the ledger has assigned a sequence would leave a gap that verification reports as tampering.
Full detail in [Discarding entries](06-discarding-entries.md).

## The seven stages

| # | Stage | Reads | Writes | Can stop the entry |
|---|---|---|---|---|
| 1 | `FilterUnchanged` | `audit_type`, `event`, `changes`, `affected_rows` | nothing | ✅ reason `unchanged` |
| 2 | `ResolveContext` | the runtime (request, auth, session, console, tenant) | 9 columns + `context` | ❌ |
| 3 | `ResolveTags` | `subject_type`, `tags`, config | `tags` | ❌ (throws on an over-long label) |
| 4 | `NormalizeData` | `before`, `after`, `metadata`, `context` | the same four | ❌ |
| 5 | `MaskSensitiveData` | `subject_type` + the six content containers | the six content containers | ❌ |
| 6 | `EncryptSensitiveData` | `subject_type` + the six content containers | the containers + `encryption` | ❌ (throws on a bad key) |
| 7 | `EnforcePolicies` | the whole entry | nothing | ✅ reason `policy` |

### 1 — `FilterUnchanged`

**Receives** the entry exactly as the capture built it: plaintext, no context, no labels.

**Mutates** nothing. It is a gate, not a transformation.

**Returns `null`** when the comparison ran and came back empty — which means three different things
by kind of entry:

| Entry | Dropped when |
|---|---|
| a model `updated` | `changes === []` |
| a `relation` entry (`attached`, `detached`, `synced`, …) | `changes === []`, for any of its events |
| a `mass` entry | `affected_rows === 0` |
| anything else | never |

A `created` with no comparable fields is kept — creating still happened. A `restored` whose only
moved column is not audited is kept — restoring still happened. The reason attached is
`FilterUnchanged::REASON` (`'unchanged'`), and the rendered message is *"The updated to user 7
changed nothing that is audited, so no entry was written."*

**What it does not do:** it never re-compares `before` against `after`. It reads the diff the
capture already produced. That is why it goes on working wherever you put it in the order — see
[Why the order is the order](#why-the-order-is-the-order).

### 2 — `ResolveContext`

**Receives** the entry with its context columns empty.

**Mutates** nine columns and one container, by delegating wholesale to `Context\ContextEngine`:
`actor_type`, `actor_id`, `impersonator_type`, `impersonator_id`, `tenant_id`, `request_id`,
`trace_id`, `span_id`, `source`, and then `context`. The stage itself resolves nothing — it wraps
the engine so there is exactly one answer to "what was the context". See
[The ten resolvers](../04-context/02-resolvers-reference.md).

**Assigns every promoted column on every pass, absent value included.** That is deliberate: a second
pass produces the same entry as the first rather than leaving the first pass's residue. Two
consequences follow.

> ⚠️ **Warning.** `$audit->context` is **replaced**, not merged: `[...resolver payload, ...manual
> context]`. A stage placed *before* `ResolveContext` that writes a key into `context` loses it. Put
> context annotations in a stage placed **after** `ResolveContext`, or push them through
> `Sentinel::withContext()`, which the engine merges last.

The second consequence is that an actor named explicitly at the call site would be overwritten here.
`Capture\Recorder::attribute()` therefore re-applies it *after* the pipeline, clearing the
impersonator columns with it. Policies in stage 7 see the **resolved** actor, not the named one.

**Returns `null`** never.

### 3 — `ResolveTags`

**Receives** the entry with whatever labels the caller put on it.

**Mutates** `tags`, and only when `config('sentinel.tags.enabled')` is true. The value is the union
of three sources, deduplicated, in this order: what the model declares in `$auditTags`, what the
caller already put on the entry, then `config('sentinel.tags.default')`.

```php
// model declares ['billing', 'refund'], config default is ['audited']
$audit->tags; // ['billing', 'refund', 'audited']
```

**Throws** `ConfigurationException::tagTooLong()` for a label over `ResolveTags::MAX_LENGTH` (64),
measured in characters and not bytes. It is refused here rather than at the ledger, where it would
arrive as a constraint violation on a write that had already sealed a chain.

**What it does not do:** labels are **outside** the canonical payload — `tags` is not in
`Integrity\CanonicalPayload::COLUMNS`. Adding, changing or removing a label changes no hash. See
[Labels](../06-reading/06-labels.md).

**Returns `null`** never.

### 4 — `NormalizeData`

**Receives** the entry with `context` freshly filled by stage 2.

**Mutates** `before`, `after`, `metadata` and `context` — recursively `ksort`ing every map. A PHP
list keeps the order it arrived in, because there position is the meaning; maps nested inside a list
are still sorted. `null` stays `null` rather than becoming `[]`.

**Leaves `changes` alone.** The diff's shape is the operation contract of the diff engine, and `[]`
there already means "compared, nothing moved" rather than "nothing to compare".

**What it does not do:** it does not make the hash stable. `Integrity\JsonCanonicalizer` sorts
object keys itself, by UTF-16 code unit, at hash time. This stage exists so that two entries
carrying the same facts *read* the same way in the stored row, whichever order the source produced
them in. Removing it changes no hash. See [Canonicalization](../07-integrity/03-canonicalization.md).

**Returns `null`** never.

### 5 — `MaskSensitiveData`

**Receives** the entry with everything still in plaintext.

**Mutates** the six content containers, twice, through `Security\Fields::protect()` — first with the
redaction list, then with the hashing list:

| Container | Walked how |
|---|---|
| `before`, `after`, `metadata`, `context` | by key name, at any depth |
| `changes` | a protected pointer path transforms `old`/`new`; otherwise the operation is walked by key |
| `criteria` | only `wheres`, including nested groups |

The field list is the **union** of the model's `$auditRedact` / `$auditHash` (read through
`Support\PolicyRegistry` from `subject_type`) and `security.redaction.fields` /
`security.hashing.fields`. The config list is the only way to protect a key no model owns —
`ip`, `session_id`, a console argument name.

> ⚠️ **Warning.** Redaction runs first and hashing second, on the same entry. A field named in
> **both** lists is digested from its mask, not from its value. Nothing warns about the combination.
> Pick one treatment per field.

Details: [Protecting sensitive data](02-protecting-sensitive-data.md),
[Hashing and the salt](04-hashing-and-the-salt.md), [Writing a masker](05-writing-a-masker.md).

**Returns `null`** never.

### 6 — `EncryptSensitiveData`

**Receives** the entry with masks and digests already applied.

**Mutates** the same six containers, replacing each declared value with ciphertext **inline, in the
same key** — the shape of a snapshot does not change, the value does. It then writes
`$audit->encryption = ['fields' => …, 'key_id' => …]`, but only when at least one declared field was
found. An entry where none surfaced gets `encryption = null`, not an empty block.

If the declared list is empty the stage returns immediately without touching the entry and without
asking the keyring for anything.

**Throws** `EncryptionException::unknownKey()` or `::unusableKey()` when `security.encryption.key_id`
names a key the keyring cannot build. Both name the key by *identifier* and never repeat key
material. See [Encryption and the keyring](03-encryption-and-the-keyring.md).

> 📌 **Note.** `encryption` **is** inside the canonical payload, so a stored `key_id` or ciphertext
> that was altered afterwards no longer reproduces the entry's hash. The field *names* are stored in
> the clear and are part of the hash; only values are protected.

**Returns `null`** never.

### 7 — `EnforcePolicies`

**Receives** the finished entry: context resolved, labels attached, keys sorted, values masked,
digested and encrypted. That is the point of it being last — a policy deciding on plaintext would be
reading something the ledger never gets to see.

**Mutates** nothing.

**Returns `null`** when any predicate registered with `Sentinel::filter()` returns `false`. Every
predicate must allow the entry; the register is evaluated with `array_all`. The reason attached is
`EnforcePolicies::REASON` (`'policy'`).

```php
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Facades\Sentinel;

// in a service provider's boot()
Sentinel::filter(static fn (AuditData $audit): bool => $audit->subject_type !== Session::class);
```

`Support\Policies` is bound as a **plain singleton**, not scoped, so a policy registered at boot
survives a queue worker's scope resets between jobs.

## Why the order is the order

Each position earns itself against the one before it:

| Position | Why not later | Why not earlier |
|---|---|---|
| `FilterUnchanged` first | — | Cost. A discarded entry then pays no context resolution, no mask and no encryption. |
| `ResolveContext` second | Everything after it protects or classifies what it produced; `context` must exist to be masked. | Nothing before it needs the context, and `FilterUnchanged` reading it would be work spent on an entry about to be dropped. |
| `ResolveTags` third | Labels are gathered before anything can discard, so a `tags`-based policy has something to read. | It needs `subject_type`, which the capture set. |
| `NormalizeData` fourth | It sorts `context`, so it must follow the stage that fills it. | It must precede nothing in particular — the canonicaliser re-sorts anyway. |
| `MaskSensitiveData` fifth | Masking must reach `context`, so it follows `ResolveContext`. | — |
| `EncryptSensitiveData` sixth | The masked and digested values are what get encrypted. | Encrypting first would hand the masker ciphertext. |
| `EnforcePolicies` last | — | A policy must decide on the entry as it will be written. |

Two of these are load-bearing rather than merely tidy:

**`FilterUnchanged` first is a cost decision, not a correctness one.** The stage reads `changes`; it
never re-compares `before` against `after`. Move it to the end and it still drops the same entries —
but a discarded entry has by then paid for the full context resolution, both protection passes and
every `encrypt()` call. `tests/Pipeline/StageOrderTest.php` asserts both halves of that.

**`EncryptSensitiveData` after `MaskSensitiveData` decides what a doubly-declared field ends up as.**
Swap them and a field named in both lists is encrypted first and then masked: the entry still records
it under `encryption.fields`, while the stored value is a mask of the ciphertext that no key opens.

## The last word: the `Auditing` event

After the last stage — inside the same pass, so a refusal here leaves by the same door — the
pipeline announces `Events\Auditing` with `$events->until()`. A listener returning `false` discards
the entry with the reason `Auditing::REASON` (`'cancelled'`).

It is announced at the **end** and not the start for the reason stage 7 is last: a listener holds an
entry with nothing in the clear that the ledger will not also hold. Before the pipeline it would
carry the plaintext of every declared field, and a listener putting that on a queue is exactly the
route the pipeline exists to close.

```php
use ElPandaPe\Sentinel\Events\Auditing;
use Illuminate\Support\Facades\Event;

Event::listen(static function (Auditing $event): ?bool {
    $event->audit->metadata = [...$event->audit->metadata ?? [], 'release' => config('app.release')];

    return $event->audit->audit_type === 'access' ? false : null;
});
```

> ⚠️ **Warning.** `subject_type` and `subject_id` are restored in a `finally` immediately after the
> event — they name what the entry is about and, under some stream strategies, which chain signs it.
> `tenant_id` is **not** restored. Under `integrity.stream = 'tenant'` a listener that rewrites
> `tenant_id` genuinely moves the entry onto another chain. Do not do it.

However an entry is stopped — a stage, a policy, or a listener — it leaves through the one door,
`Events\AuditDiscarded`, which carries `auditType`, `event`, `subjectType`, `subjectId`, `stage` and
`reason` and **nothing else**. No `before`, no `after`, no `changes`, no `metadata`: `FilterUnchanged`
runs before the protections, so an event carrying the payload would be the leak the pipeline closes.
See [Events and listeners](../09-operations/04-events-and-listeners.md).

## Removing a stage

Declare the list without it. There is no `disable` flag and no per-stage toggle.

```php
// config/sentinel.php — everything except NormalizeData
'pipeline' => [
    ElPandaPe\Sentinel\Pipeline\Stages\FilterUnchanged::class,
    ElPandaPe\Sentinel\Pipeline\Stages\ResolveContext::class,
    ElPandaPe\Sentinel\Pipeline\Stages\ResolveTags::class,
    ElPandaPe\Sentinel\Pipeline\Stages\MaskSensitiveData::class,
    ElPandaPe\Sentinel\Pipeline\Stages\EncryptSensitiveData::class,
    ElPandaPe\Sentinel\Pipeline\Stages\EnforcePolicies::class,
],
```

> ⚠️ **Warning.** `'pipeline' => []` does **not** mean "no stages". `Support\Config::pipelineStages()`
> treats an empty array and a missing key alike and returns `Pipeline::DEFAULT_STAGES`. Laravel's
> `mergeConfigFrom` is one level deep, so an installation that published `config/sentinel.php`
> before the key existed would otherwise transform nothing at all and say nothing about it. If you
> want a stage gone, name the other six.

The flip side of declaring the list is that it **pins** the installation. A stage a later version of
the package adds to `DEFAULT_STAGES` will not run until you add it to your list, silently. If you
have no stage of your own, delete the `pipeline` key from your published config and let the constant
lead.

Malformed lists are refused at resolution time, with the key named:

| What you wrote | What you get |
|---|---|
| `'pipeline' => 'FilterUnchanged'` | `ConfigurationException` — *expected a list of stage class-strings* |
| `'pipeline' => [42]` | `ConfigurationException` — *expected a list of stage class-strings* |
| `'pipeline' => ['App\Sentinel\Nowhere']` | `ConfigurationException` — not a `Transformer`; a class that does not exist cannot be one |
| `'pipeline' => [stdClass::class]` | `ConfigurationException` — not a `Transformer` |

## Adding your own stage

```php
namespace App\Sentinel;

use Closure;
use ElPandaPe\Sentinel\Contracts\Transformer;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Pipeline\Discard;

final readonly class DropRoutineReads implements Transformer
{
    public function __construct(private Discard $discard) {}

    /** @param Closure(AuditData): ?AuditData $next */
    public function handle(AuditData $audit, Closure $next): ?AuditData
    {
        if ($audit->severity === Severity::Info && $audit->audit_type === 'access') {
            $this->discard->because('routine reads are not kept');

            return null;
        }

        $audit->metadata = [...$audit->metadata ?? [], 'release' => config('app.release')];

        return $next($audit);
    }
}
```

Then name it in the list, in the position you want:

```php
'pipeline' => [
    ElPandaPe\Sentinel\Pipeline\Stages\FilterUnchanged::class,
    App\Sentinel\DropRoutineReads::class,          // before the protections: a discard costs nothing
    ElPandaPe\Sentinel\Pipeline\Stages\ResolveContext::class,
    ElPandaPe\Sentinel\Pipeline\Stages\ResolveTags::class,
    ElPandaPe\Sentinel\Pipeline\Stages\NormalizeData::class,
    ElPandaPe\Sentinel\Pipeline\Stages\MaskSensitiveData::class,
    ElPandaPe\Sentinel\Pipeline\Stages\EncryptSensitiveData::class,
    ElPandaPe\Sentinel\Pipeline\Stages\EnforcePolicies::class,
],
```

### Where to put it

| Your stage | Position |
|---|---|
| may discard | **before** `MaskSensitiveData` — the dropped entry then pays no mask and no encryption |
| annotates `metadata` or `tags` | **after** `ResolveContext`, so it is not overwritten; before `EnforcePolicies` if a policy reads it |
| writes into `context` | **after** `ResolveContext` — the engine replaces the whole container |
| reads a protected value | **before** `MaskSensitiveData` — after it, the value is gone |
| must see the entry as it will be written | **after** `EncryptSensitiveData` |

### The three rules

1. **Call `$next($audit)` exactly once, and return what it gives you** — or return `null` to
   discard, having called `Discard::because()` first. Returning `$audit` without calling `$next()`
   skips every stage after yours, masking and encryption included.
2. **Leave the entry canonicalisable.** `context`, `before`, `after`, `changes`, `metadata` and
   `criteria` may hold only `null`, `bool`, `int`, `float`, `string` and arrays of those. An object
   anywhere in them throws `CanonicalizationException::unsupportedType()` **at write time**, from
   the canonicaliser — not from your stage, and not with your stage's name on it. So do a
   `->toArray()` yourself; do not hand the entry a value object, a Carbon instance or an enum.
3. **Never recompute `changes` from `before`/`after` after `EncryptSensitiveData`.** Two ciphertexts
   of the same value never match — the IV is random — so every field would report as changed and
   `FilterUnchanged` would stop filtering anything.

Beyond those: `sequence`, `hash` and `previous_hash` are not properties of `AuditData` and cannot be
set from a stage at all. Every other property you change lands in the entry, and every property in
`CanonicalPayload::COLUMNS` lands in the hash.

> 💡 **Tip.** If all you need is a keep/drop decision, use `Sentinel::filter()` instead of a stage.
> It needs no config change, it survives a worker's scope resets, and it runs in stage 7 where the
> entry is already complete.

## When a stage throws

**The exception propagates out of the write that caused it.** Nothing in the capture path catches
it: `Capture\Recorder::prepared()` calls `Pipeline::process()` unguarded, and audit capture happens
inside the Eloquent model event, so the exception comes out of `$invoice->save()` — after the row was
written, unless you were inside a database transaction, in which case it rolls back with everything
else.

> ⚠️ **Warning.** `on_write_failure` does **not** cover this. That setting is read by the dispatch
> strategies (`Dispatch\SyncStrategy`, `QueueStrategy`, `BufferStrategy`) and wraps only the ledger
> write, which happens after the pipeline. A stage that throws is an application bug, and it is
> reported as one. See [Failure policy](../09-operations/05-failure-policy.md).

The discard pass is still closed correctly — `Pipeline::process()` calls `Discard::end()` in a
`finally`, so a throwing stage does not leave `Discard` believing a pass is open and does not leak
one entry's stage and reason into the next.

Two shipped stages throw by design, and both are configuration errors rather than runtime failures:
`ResolveTags` on a label over 64 characters, and `EncryptSensitiveData` on a key the keyring cannot
build. Both surface on the first write after a bad deploy.

## Cost and the performance modes

**The pipeline runs in the capturing process under all three modes.** `mode` decides where and when
the entry *lands*, never where it is transformed:

| `mode` | What the request pays | What the pipeline sees | What is waiting |
|---|---|---|---|
| `sync` | pipeline + ledger write | the request's context | nothing |
| `queue` | pipeline + one enqueue | the request's context | a `Jobs\SettleAudit` carrying an already-transformed `AuditData` |
| `buffered` | pipeline + one buffer append | the request's context | already-transformed `AuditData` in Redis or memory |

Two things follow. The entry's context describes the **request**, not the worker or the flushing
process — resolving it later would file every entry under a machine that did nothing. And nothing
sensitive ever waits anywhere in the clear: the queue payload, the buffer contents and the
`on_write_failure = log` line are all swept by `tests/Security/NoPlaintextTest.php`.

On cost, what the code guarantees is the shape rather than a number: `Pipeline::process()` rebuilds
the closure chain with `stack()` on every entry, and `handle()` calls `$container->make($stage)` for
each stage of each entry. There is no memoised stack. So the floor is *n* container resolutions per
captured entry, before any stage does work — which is why removing a stage you do not need is a real
saving and why `FilterUnchanged` earns its place at the front.

> 🧪 **Verify it.** The package's own harness measures the stage list in isolation. From the repo:
> `make bench` prints a `pipeline only (no ledger, no write)` row alongside the full write path. Run
> it on your hardware with your config; any number from someone else's machine is not yours.

> 🐘 **Engine.** Nothing in the pipeline is engine-specific. `NormalizeData`'s sort is cosmetic in
> the stored row — PostgreSQL `jsonb` and MySQL `json` give no key-order guarantee on storage, while
> SQLite keeps text order — and hash stability comes from re-canonicalising in PHP on read. Never
> compare a JSON column as text to check a protected entry.

One place in the package deliberately runs a **different** list: `Import\Importer` filters
`ResolveContext` out of whatever list is configured. A backfill happens years later in somebody's
terminal, and resolving context there would sign every historical action with the name of whoever
ran the migration. Every other stage runs whole, discards included. See
[The import runbook](../12-migrating/03-the-import-runbook.md).

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| You set `'pipeline' => []` to turn transformation off, and everything is still masked and encrypted | An empty list means `Pipeline::DEFAULT_STAGES` — a guard against a shallow config merge leaving an old installation transforming nothing | There is no "off". Name the stages you want; to keep only some, list only those |
| A stage a new package version added never runs | Your published config declares an explicit list, which pins the installation | Add the new stage to your list, or delete the `pipeline` key entirely if you have no custom stage |
| Your stage writes a `context` key and the entry has no trace of it | `ResolveContext` **replaces** `$audit->context` wholesale | Move your stage after `ResolveContext`, or use `Sentinel::withContext()`, which the engine merges last |
| A field you declared `$auditEncrypt` decrypts to a mask instead of the value | It is in `$auditRedact` too. `MaskSensitiveData` runs first and `EncryptSensitiveData` then encrypts the mask | Declare each field in exactly one of the four lists |
| `ConfigurationException` about a label on every write after a deploy | `ResolveTags` refuses a label over 64 characters (measured in characters) | Shorten the label in `tags.default` or in the model's `$auditTags` |
| `CanonicalizationException: cannot canonicalize a value of type [App\Money]` at write time, pointing at no stage of yours | Your stage put an object into `metadata`, `context` or a snapshot; the canonicaliser refuses it at the ledger, not at the stage | Convert to array/scalar in the stage before returning |
| Every field reports as changed and `FilterUnchanged` stopped filtering | A custom stage recomputes `changes` from `before`/`after` after encryption; two ciphertexts of the same value never match | Never recompute the diff after `EncryptSensitiveData` — read the diff the capture produced |
| `DiscardException` mentioning `verifyIntegrity()` | `Discard::because()` was called outside an open pass — from a listener after the ledger, or after the pipeline returned | Discard from a stage, from a `Sentinel::filter()` policy, or by returning `false` from an `Auditing` listener |
| `AuditDiscarded::$stage` names a stage you did not expect | The **first** stage to return `null` owns the discard; outer stages only see the null travelling back | The stage named is the innermost one reached — the one that actually returned `null` — never an outer stage that forwarded it |
| `$event->reason` is `unspecified` | A stage returned `null` without calling `Discard::because()` first | Call `because()` with a stable string before returning `null` |
| A stage throws and the request dies, despite `on_write_failure = log` | That setting wraps the ledger write, not the pipeline | Handle the failure inside your own stage; the package will not swallow it for you |
| Under `queue`, `$invoice->latestAudit()` is `null` right after `save()` | The pipeline ran, but the write is in a worker | Assert on the dispatched `Jobs\SettleAudit`, or read the entry after the worker settles it |

## ✅ Best practices

✅ **Do** — put a discarding stage before the protection stages. A dropped entry then pays for no
mask, no digest and no `encrypt()` call.

```php
'pipeline' => [
    FilterUnchanged::class,
    App\Sentinel\DropRoutineReads::class,   // discards here
    ResolveContext::class,
    ResolveTags::class,
    NormalizeData::class,
    MaskSensitiveData::class,
    EncryptSensitiveData::class,
    EnforcePolicies::class,
],
```

❌ **Don't** — park it at the end, after everything has been resolved and sealed.

```php
'pipeline' => [
    FilterUnchanged::class,
    ResolveContext::class,
    ResolveTags::class,
    NormalizeData::class,
    MaskSensitiveData::class,
    EncryptSensitiveData::class,        // every dropped entry paid for this
    App\Sentinel\DropRoutineReads::class,
    EnforcePolicies::class,
],
```

---

✅ **Do** — give every discard a stable reason. `AuditDiscarded::message()` renders the package's own
reasons through `resources/lang` and hands an application's reason back verbatim, so your string is
what an operator reads in the log.

```php
$this->discard->because('routine reads are not kept');

return null;
```

❌ **Don't** — return `null` bare. The entry vanishes and the event says `unspecified`, naming only
the class basename.

```php
return null; // "Stage DropRoutineReads discarded the read entry for … before it reached the ledger."
```

---

✅ **Do** — reach for `Sentinel::filter()` when the decision is keep-or-drop. No config change, it
survives a queue worker's scope resets, and it runs on the entry as it will be written.

```php
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Facades\Sentinel;

Sentinel::filter(static fn (AuditData $audit): bool => $audit->subject_type !== HealthCheck::class);
```

❌ **Don't** — write a whole `Transformer`, pin your `pipeline` list forever and inherit the
maintenance, just to say no.

```php
final readonly class DropHealthChecks implements Transformer { /* 20 lines to return null */ }
```

---

✅ **Do** — hand the entry only scalars, arrays and `null`. The canonicaliser is the thing that
refuses an object, and it does so at the ledger with no mention of your stage.

```php
$audit->metadata = [...$audit->metadata ?? [], 'total' => $order->total->toDecimalString()];
```

❌ **Don't** — put a value object, an enum or a Carbon instance into `metadata` or `context`.

```php
$audit->metadata = ['total' => $order->total, 'at' => now()];
// CanonicalizationException: … a value of type [Brick\Money\Money] … at write time
```

---

✅ **Do** — annotate the entry *after* `ResolveContext`, so the engine does not overwrite what you
wrote.

```php
'pipeline' => [
    FilterUnchanged::class,
    ResolveContext::class,
    App\Sentinel\StampRelease::class,   // writes into context, safely
    ResolveTags::class,
    /* … */
],
```

❌ **Don't** — write into `context` from a stage placed before it. `ContextEngine` assigns
`$audit->context` wholesale and your key is gone with no error.

```php
'pipeline' => [
    App\Sentinel\StampRelease::class,   // silently discarded one stage later
    ResolveContext::class,
    /* … */
],
```

---

✅ **Do** — delete the `pipeline` key from your published config when you have no stage of your own,
and let `Pipeline::DEFAULT_STAGES` lead. Upgrades then bring new stages with them.

❌ **Don't** — keep a copied-out list you will not maintain. It pins the installation to seven
stages, and the eighth one a future version ships will not run and will not complain.

---

**See also:** [Discarding entries](06-discarding-entries.md) · [Protecting sensitive data](02-protecting-sensitive-data.md) · [Encryption and the keyring](03-encryption-and-the-keyring.md) · [The write path](../01-concepts/03-the-write-path.md) · [Execution context](../04-context/01-execution-context.md) · [Events and listeners](../09-operations/04-events-and-listeners.md) · [Performance modes](../09-operations/01-performance-modes.md) · [Canonicalization](../07-integrity/03-canonicalization.md) · [Configuration](../99-reference/02-configuration.md)
