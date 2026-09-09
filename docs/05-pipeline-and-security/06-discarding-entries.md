# 🛡️ Discarding entries

> How an entry stops existing before it settles, why that is only legal before the ledger touches it,
> and how to tell a filter that removes noise from one that quietly removes evidence.

**On this page:** [Where a discard is legal](#where-a-discard-is-legal) ·
[The three ways in](#the-three-ways-in) ·
[Where each one sits in the order](#where-each-one-sits-in-the-order) ·
[What a policy actually sees](#what-a-policy-actually-sees) ·
[Naming the reason](#naming-the-reason) ·
[The AuditDiscarded event](#the-auditdiscarded-event) ·
[Which entries a filter reaches](#which-entries-a-filter-reaches) ·
[A filter that earns its place](#a-filter-that-earns-its-place) ·
[A filter that loses evidence](#a-filter-that-loses-evidence)

---

## Where a discard is legal

An entry has no identity until the ledger gives it one. `Ledger::write()` reads the tail of the
stream, assigns `sequence`, copies the tail's `hash` into the new entry's `previous_hash`, and seals
its own `hash` over the canonical payload — all inside one operation. Until that moment the entry is
a mutable `Data\AuditData` object that nothing has counted, and dropping it costs nothing.

After that moment, dropping it would leave a hole. `sequence` is contiguous per stream by definition,
and `Integrity\Verifier` reports a missing number as `IntegrityBreak::SequenceGap` — indistinguishable
from someone deleting a row to hide it. So the package makes the late discard impossible rather than
discouraged: `Pipeline\Discard::because()` throws `DiscardException::outsideThePipeline()` the moment
it is called with no pipeline pass open.

```
      capture ──▶ pipeline ──▶ dispatcher ──▶ ledger ──▶ entry
                     │                          │
                     │                          └─ assigns sequence, previous_hash, hash
                     │                             in the same operation as the write
                     └─▶ AuditDiscarded

      ├──── a discard is free here ────┤├── DiscardException from here on ──┤
```

> 📌 **Note.** This is the whole reason the pipeline exists between capture and ledger, and the whole
> reason `EnforcePolicies` is the *last* stage rather than the first. Everything that may refuse an
> entry has to happen while the entry is still anonymous.

> ⚠️ **Warning.** A discard leaves no trace in the database. Nothing records that an entry was built
> and dropped — the only announcement is `Events\AuditDiscarded`, dispatched in-process at the moment
> it happens. If you need to know what your filters are throwing away, you have to listen for it.

`sequence`, `hash`, `previous_hash` and the canonical payload are load-bearing: a change to any of
them bumps `payload_version` and ships a backwards-compatibility test. Discarding never touches them
precisely because it happens before they are computed. See
[The hash chain](../07-integrity/01-the-hash-chain.md).

---

## The three ways in

| Route | What you write | Reason it reports | Reaches |
|---|---|---|---|
| A pipeline stage returns `null` | A class implementing `Contracts\Transformer`, named in `sentinel.pipeline` | Whatever you pass to `Discard::because()`, else `unspecified` | Every entry, at the position you put the stage |
| A registered policy returns `false` | `Sentinel::filter(Closure $policy)` | `policy` | Every entry, at the very end of the pipeline |
| An `Auditing` listener returns `false` | `Event::listen(Auditing::class, …)` | `cancelled`, or your own via `Discard::because()` | Every entry, after every stage has run |

The two stages the package ships use the first route themselves:

| Shipped stage | Discards when | Reason |
|---|---|---|
| `Stages\FilterUnchanged` | `changes === []` and the event is `updated`, or the entry is an `audit_type` of `relation`; or a mass entry whose `affected_rows` is `0` | `unchanged` |
| `Stages\EnforcePolicies` | any registered `Sentinel::filter()` closure returned `false` | `policy` |

### There is no model-level veto hook

A model declares ten properties through `Concerns\Auditable`, and **not one of them is a filter**.
There is no `shouldAudit()`, no `$auditFilter`, no `auditWhen()`. A model influences discarding only
indirectly, through `FilterUnchanged`: `$auditInclude` and `$auditExclude` decide which columns the
snapshot compares, and an `updated` capture whose comparison came back empty is dropped.

```php
use ElPandaPe\Sentinel\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

final class Invoice extends Model
{
    use Auditable;

    /** @var list<string> */
    protected array $auditExclude = ['last_viewed_at'];
}

$invoice->update(['last_viewed_at' => now()]);   // no entry: changes came back []
$invoice->update(['status' => 'paid']);          // an entry, sequence follows the previous one
```

This is the cheapest way to drop noise, and the one to reach for first: an excluded column never
enters the pipeline at all, so nothing downstream can mask it, encrypt it or leak it. See
[What a model declares](../02-getting-started/03-what-a-model-declares.md).

> 📌 **Note.** `FilterUnchanged` keeps a `created` with nothing to compare, and keeps a `restored`
> whose only moved column is unaudited — creating and restoring still happened. It only drops an
> entry whose comparison ran and came back empty.

---

## Where each one sits in the order

`Pipeline::DEFAULT_STAGES` is, in order:

| # | Stage | May discard | Sees |
|---|---|---|---|
| 1 | `FilterUnchanged` | yes | the plaintext diff |
| 2 | `ResolveContext` | no | — |
| 3 | `ResolveTags` | no | — |
| 4 | `NormalizeData` | no | — |
| 5 | `MaskSensitiveData` | no | — |
| 6 | `EncryptSensitiveData` | no | — |
| 7 | `EnforcePolicies` | yes | the entry exactly as it will be written |
| — | the `Auditing` event | yes | the same, after every stage |

Two positions, two reasons.

`FilterUnchanged` is **first for cost**: an entry it drops pays no context resolution, no masking and
no encryption. It is safe there because it reads the `changes` the capture already produced — it
never re-compares values, which is what would break after encryption, where two ciphertexts of the
same value never match.

`EnforcePolicies` is **last for honesty**. A policy that ran earlier would decide on data the ledger
never gets to see. Because it runs last, the `AuditData` your closure receives is byte-for-byte what
would have been written: masked, digested, encrypted, labelled, with context resolved.

That ordering is what your own stage has to be placed against. A stage that only annotates belongs
after `EncryptSensitiveData`; a stage that may discard belongs early, where the discard is cheap —
but a stage placed before `MaskSensitiveData` sees the values in the clear, so keep what it reads to
what it needs.

```php
// config/sentinel.php — declaring the list means declaring all of it, in order
'pipeline' => [
    ElPandaPe\Sentinel\Pipeline\Stages\FilterUnchanged::class,
    App\Sentinel\DropDeviceTelemetry::class,          // early: the drop costs nothing
    ElPandaPe\Sentinel\Pipeline\Stages\ResolveContext::class,
    ElPandaPe\Sentinel\Pipeline\Stages\ResolveTags::class,
    ElPandaPe\Sentinel\Pipeline\Stages\NormalizeData::class,
    ElPandaPe\Sentinel\Pipeline\Stages\MaskSensitiveData::class,
    ElPandaPe\Sentinel\Pipeline\Stages\EncryptSensitiveData::class,
    ElPandaPe\Sentinel\Pipeline\Stages\EnforcePolicies::class,
],
```

> ⚠️ **Warning.** `'pipeline' => []` means *use the shipped list*, not *run no stages*. And a list you
> do declare pins you: a stage a later version of the package adds will not run until you name it.
> Details in [The write pipeline](01-the-write-pipeline.md).

---

## What a policy actually sees

`Sentinel::filter()` hands your closure the `AuditData` as it stands at stage 7. Most columns are
already final; two are not, and deciding on those is the most common way to write a filter that
does something other than what it reads like.

| Field | State inside a policy | Note |
|---|---|---|
| `audit_type`, `event`, `severity`, `source` | final | The safest things to decide on |
| `subject_type`, `subject_id` | final | Settled at capture; an `Auditing` listener cannot change them either |
| `before`, `after`, `changes`, `criteria`, `metadata`, `context` | final, and **protected** | Masked, digested and encrypted already |
| `tenant_id`, `request_id`, `trace_id`, `span_id` | final | Resolved by `ResolveContext` at stage 2 — and a tenant the capture states outright, as a redaction trail does, is applied there too |
| `transaction_id`, `capture_id` | final | Stamped by `Capture\Recorder` before the pipeline |
| `tags` | final | Resolved by `ResolveTags` at stage 3 |
| `actor_type`, `actor_id`, `impersonator_type`, `impersonator_id` | final | Resolved at stage 2, and an actor named with `->actor()` is applied there too, with the impersonator cleared: a policy decides on the actor the entry will carry |
| `stream` | **usually `null`** | `Integrity\Stream::resolve()` runs in the ledger, from `integrity.stream` |
| `sequence`, `hash`, `previous_hash` | **not on the object at all** | `AuditData` has no such properties; the ledger owns them |

The actor is the one people ask about. A capture that names its actor — a custom event, a
transition, an authentication event — has it applied inside `ResolveContext`, so this filter
decides on the actor the entry will be written with:

```php
Sentinel::filter(static fn (AuditData $audit): bool => $audit->actor_id !== $robotId);
```

Before `v1.0.0-rc.2` the named actor was put back *after* the pipeline and a policy saw whoever was
authenticated instead; `UPGRADE.md` has the before and after. If you need the stream, derive it from
`tenant_id` or `subject_type` the way `integrity.stream` does — do not read `$audit->stream`.

> 📌 **Note.** Policies are evaluated with `array_all()`, which short-circuits: once one closure
> returns `false` the rest are not called. The register is typed `Closure(AuditData): bool`, so give
> every closure a `: bool` return type and a real `bool` on every path — one declared that way and
> returning `null` raises a `TypeError` out of your own `save()`.

---

## Naming the reason

Returning `null` is the mechanism. `Pipeline\Discard::because()` is what makes the discard legible.

```php
use Closure;
use ElPandaPe\Sentinel\Contracts\Transformer;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Pipeline\Discard;

final readonly class DropDeviceTelemetry implements Transformer
{
    public function __construct(private Discard $discard) {}

    /**
     * @param  Closure(AuditData): ?AuditData  $next
     */
    public function handle(AuditData $audit, Closure $next): ?AuditData
    {
        if ($audit->subject_type !== \App\Models\DeviceReading::class) {
            return $next($audit);
        }

        $this->discard->because('device telemetry is not part of the trail');

        return null;
    }
}
```

Three rules the class enforces, all of them visible in `tests/Pipeline/DiscardTest.php`:

- **The first stage to return `null` owns the discard.** Stages wrapping it only see that `null`
  travelling back out, so the event names the innermost stage.
- **The first reason given wins.** `because()` uses `??=`; a later call does not overwrite it.
- **A pass opened inside another suspends it rather than replacing it.** A stage or a listener is free
  to write to an audited model — that begins a pass within the open one, and the outer pass is handed
  back intact when the inner one ends.

The four reasons the package ships render through `resources/lang/{en,es}`:

| Reason | Constant | English sentence |
|---|---|---|
| `unchanged` | `FilterUnchanged::REASON` | *The :event to :type :id changed nothing that is audited, so no entry was written.* |
| `policy` | `EnforcePolicies::REASON` | *A policy discarded the :event entry for :type :id before it reached the ledger.* |
| `cancelled` | `Auditing::REASON` | *A listener cancelled the :event entry for :type :id before it reached the ledger.* |
| `unspecified` | — | *Stage :stage discarded the :event entry for :type :id before it reached the ledger.* |

A reason of your own has no translation key, so `AuditDiscarded::message()` hands it back verbatim.
That is deliberate: your sentence is what an operator reads, so write it for one.

---

## The AuditDiscarded event

Every stopped entry leaves by one door, dispatched from `Pipeline::process()`.

| Property | Type | Holds |
|---|---|---|
| `$auditType` | `string` | `model`, `relation`, `mass`, `custom`, `transition`, `auth`, `restore`, `security`, `access` |
| `$event` | `string` | `created`, `updated`, `synced`, your own domain-event name … |
| `$subjectType` | `?string` | The subject's class, or `null` |
| `$subjectId` | `?string` | The subject's key, or `null` |
| `$stage` | `class-string` | The stage that returned `null` — or `Events\Auditing::class` when a listener refused |
| `$reason` | `string` | One of the four above, or your own |
| `message()` | `string` | The rendered sentence |

**It carries no payload, and that is not an oversight.** `FilterUnchanged` runs before masking and
encryption, so an entry can be discarded while its `before`/`after`/`changes` still hold plaintext.
An event carrying them would be the exact route by which a declared secret escaped the pipeline that
exists to transform it — and it would escape into logs, notifications and queued jobs, which is worse
than the row it was protecting. If you need to know *what* was in a discarded entry, you cannot have
it from here; that is the trade.

```php
use ElPandaPe\Sentinel\Events\AuditDiscarded;
use Illuminate\Support\Facades\Event;

Event::listen(AuditDiscarded::class, static function (AuditDiscarded $event): void {
    logger()->channel('audit')->info($event->message(), [
        'audit_type' => $event->auditType,
        'event' => $event->event,
        'subject' => $event->subjectType.'#'.$event->subjectId,
        'stage' => $event->stage,
        'reason' => $event->reason,
    ]);
});
```

> ⚠️ **Warning.** A stage that *throws* dispatches nothing. `Pipeline::process()` closes the discard
> window in a `finally`, but the exception escapes before the dispatch line — and because the pipeline
> runs before every `try`/`catch` that consults `on_write_failure`, it lands in your own `save()`.
> Throwing is not discarding. See [Failure policy](../09-operations/05-failure-policy.md).

---

## Which entries a filter reaches

Nearly everything the package writes goes through `Capture\Recorder` and therefore through the
pipeline. A filter written for model changes sees far more than model changes.

| `audit_type` | Written by | A filter can discard it |
|---|---|---|
| `model` | `Capture\ModelCapture` | yes |
| `transition` | `Sentinel::transition()`, and an update moving an `$auditTransitions` column | yes |
| `relation` | `Capture\RelationCapture` (`attach`, `detach`, `sync`, `toggle`) | yes |
| `mass` | `Mass\MassCapture` (`->auditing()` queries) | yes |
| `custom` | `Sentinel::event()` | yes |
| `auth` | `Capture\AuthenticationSubscriber` | yes |
| `restore` | `Restore\Restorer` | yes |
| `security` / `redacted` | `Redaction\Redactor` — the trail a redaction leaves | **yes, and this one hurts** |
| `access` | `Compliance\AccessLog` under compliance mode | yes |
| `security` / `rekeyed` | `Security\Rekeyer` | **no** — it writes straight to `Ledger::write()` |

The redaction row is the trap. `Redactor::redact()` destroys the entry's content *first*, then records
a chained `security` / `redacted` entry through the recorder. A filter that discards that entry leaves
the content destroyed, the tombstone in place and `Tombstone::$trail` as `null` — a hole in the record
with nothing saying who made it or why. Under compliance mode that is precisely the shape the whole
regime exists to forbid.

> 🔒 **Security.** A filter is not a security control. It is application code that anyone who can
> deploy application code can register, and it leaves no evidence of what it removed. Treat it as a
> volume knob, never as an access rule.

---

## A filter that earns its place

A model that is audited only as a side effect of a shared base class, whose rows nobody will ever be
asked about, and whose entries would bury the ones that matter.

```php
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Facades\Sentinel;
use Illuminate\Support\ServiceProvider;

final class AuditFilterProvider extends ServiceProvider
{
    public function boot(): void
    {
        Sentinel::filter(static fn (AuditData $audit): bool =>
            $audit->subject_type !== \App\Models\DeviceReading::class);
    }
}
```

Why this one is legitimate:

- It drops by **what the entry is about**, not by who caused it or how bad it looks.
- The dropped entries answer no question anyone can ask later. Nobody will subpoena a temperature
  reading, and no investigation is narrowed by their absence.
- It is total for that subject type. A reader who knows the rule can state exactly what is missing:
  *this trail holds no `DeviceReading` entries at all.* A partial filter cannot be described that way.
- It is registered once, in a provider, where a singleton belongs.

---

## A filter that loses evidence

Three shapes, all of which look reasonable while being written.

```php
// 1. Dropping by who did it. This is the axis an investigation searches on.
Sentinel::filter(static fn (AuditData $audit): bool =>
    $audit->actor_type !== \App\Models\Admin::class);

// 2. Dropping by how consequential the change was. Deletions are the entries that get asked about.
Sentinel::filter(static fn (AuditData $audit): bool => $audit->event !== 'deleted');

// 3. The subtle one: an allowlist written while thinking only about model changes.
Sentinel::filter(static fn (AuditData $audit): bool => $audit->audit_type === 'model');
```

The first two remove exactly the entries an audit trail exists to hold, and they remove them
invisibly: the chain stays contiguous and `verifyIntegrity()` returns intact, because from the ledger's
point of view nothing ever happened. An intact chain of entries that were never offered proves nothing
about what it does not contain.

The third is worse than it looks. It silently drops every `auth` entry, every `transition`, every
`restore`, every mass-operation summary, every compliance `access` entry — and the `security` /
`redacted` trail, so a redaction destroys content and records nobody as having ordered it.

**The test.** Before registering a filter, ask what question the dropped entries would have answered.
If the answer is *"none — this subject has no evidentiary value at all"*, the filter is a volume
control. If the answer names a person, a kind of change, or a severity, you are not filtering noise;
you are choosing what the record will not be able to say. Use a retention policy for volume over time
and `$auditExclude` for a noisy column — both of them leave the shape of what is missing visible. See
[Retention and pruning](../08-lifecycle/01-retention-and-pruning.md).

---

## Seeing what was dropped

Two places tell you.

`Events\AuditDiscarded`, in-process, as shown above — and it fires under every mode, because the
pipeline always runs in the capture and never behind the queue or the buffer.

`php artisan sentinel:import`, which counts them. Its report separates `written`, `repeated`,
`unreadable` and `discarded`, and says out loud: *":unreadable rows could not be read and :discarded
were refused by the pipeline, so they are not in the trail."* An import that silently lost a third of
its rows to a filter is the failure this counter exists to make visible. See
[The import runbook](../12-migrating/03-the-import-runbook.md).

> 🧪 **Verify it.** Register a listener on `AuditDiscarded` in a local environment, exercise the flow
> you think is being over-filtered, and read `$event->stage` — it names the class that returned `null`,
> which is the fastest way to tell `FilterUnchanged` (a declaration problem) from `EnforcePolicies`
> (a filter of yours) from `Events\Auditing` (a listener of yours).

> 🐘 **Engine.** `FilterUnchanged` drops a mass entry when `affected_rows === 0`, and the engines do
> not agree on that number: MySQL 9 counts rows *changed*, while SQLite and PostgreSQL count rows
> *matched*. A mass `update()` that matched rows and set them to the values they already held writes
> no entry on MySQL and does write one on the other two. The count is stored exactly as the database
> reported it — see [Mass operations](../03-capture/05-mass-operations.md).

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| An update you know happened wrote no entry | `FilterUnchanged` dropped it: the only column that moved is in `$auditExclude`, or outside a declared `$auditInclude`, so `changes` came back `[]` | Add the column to the declaration, or listen on `AuditDiscarded` and read `$event->stage` to confirm |
| A filter written against `->actor()` never matches | You are on a release before `v1.0.0-rc.2`, where a declared actor was reapplied after the pipeline and the policy saw the resolved one | Upgrade: `ResolveContext` applies the declared actor, and the policy decides on it |
| `$audit->stream` is `null` inside a filter | The ledger resolves the stream from `integrity.stream` at write time; `AuditData` carries it only if a caller set it | Derive from `tenant_id` / `subject_type`, mirroring your stream strategy |
| `DiscardException: Sentinel was asked to discard an entry outside the pipeline` | `Discard::because()` was called from an `AuditCreating`/`AuditCreated` listener, a model observer, or ordinary application code | Move the decision into a stage, a `Sentinel::filter()` policy, or an `Auditing` listener |
| A redaction destroyed content but no `security` / `redacted` entry exists | A filter discarded the trail entry; `Tombstone::$trail` came back `null` | Never allowlist by `audit_type`; exclude by subject and let every other kind through |
| `AuditDiscarded::$reason` reads `unspecified` | A stage returned `null` without calling `because()` first | Call `because()` on the line before the `return null` |
| `TypeError: … must be of type bool` out of `save()` | A policy closure declared `: bool` returned `null` on some path | Return a real `bool` from every path |
| A stage threw and no `AuditDiscarded` was dispatched, and `on_write_failure` did not catch it | The pipeline runs before the dispatch strategy's `try`/`catch`; the exception escapes before the dispatch line | Handle it inside the stage; throwing is not a way to discard |
| Filters accumulate over a long-lived worker | `Support\Policies` is a singleton with no removal on the published surface, and `add()` only appends | Register in a service provider's `boot()`, once |
| A `rekeyed` entry ignores every filter | `Security\Rekeyer` writes straight to `Ledger::write()`, bypassing the pipeline and the dispatcher | By design — its values are already transformed; see [Encryption and the keyring](03-encryption-and-the-keyring.md) |

---

## ✅ Best practices

✅ **Do** — register filters once, from a service provider's `boot()`. `Support\Policies` is a
singleton with no removal on the published surface, so a filter registered anywhere per-request
stacks up for the life of the worker process.

```php
public function boot(): void
{
    Sentinel::filter(static fn (AuditData $audit): bool =>
        $audit->subject_type !== \App\Models\DeviceReading::class);
}
```

❌ **Don't** — register one from a controller, a job handler or a middleware. It is appended on every
pass; after ten thousand jobs the pipeline is evaluating ten thousand identical closures per entry,
and nothing in the public surface removes them.

```php
public function store(Request $request): RedirectResponse
{
    Sentinel::filter(static fn (AuditData $audit): bool => $audit->event !== 'updated');
    // …one more closure on the singleton, every request, forever.
}
```

---

✅ **Do** — drop by what the entry is *about*. A rule stated over `subject_type` is one a reader can
describe: this trail holds no entries for that model, and it holds every entry for everything else.

```php
Sentinel::filter(static fn (AuditData $audit): bool =>
    $audit->subject_type !== \App\Models\DeviceReading::class);
```

❌ **Don't** — drop by who did it or by how serious it was. Actor and event are the two axes an
investigation searches on, and an entry that was never offered leaves an intact chain that proves
nothing about its own completeness.

```php
Sentinel::filter(static fn (AuditData $audit): bool =>
    $audit->actor_type !== \App\Models\Admin::class && $audit->event !== 'deleted');
```

---

✅ **Do** — reach for a model declaration before a filter when the noise is one column. An excluded
column never enters the pipeline, so nothing downstream can mask, encrypt or leak it, and the entry is
dropped by `FilterUnchanged` at stage 1.

```php
final class Invoice extends Model
{
    use Auditable;

    /** @var list<string> */
    protected array $auditExclude = ['last_viewed_at'];
}
```

❌ **Don't** — spend the whole pipeline building an entry only to refuse it at the last stage for a
reason a declaration already knew. `EnforcePolicies` runs seventh: context resolution, label resolution,
normalisation, masking and encryption have all been paid for by then.

```php
Sentinel::filter(static fn (AuditData $audit): bool => ! array_any(
    $audit->changes ?? [],
    static fn (array $change): bool => $change['path'] === '/last_viewed_at',
));
```

---

✅ **Do** — name a reason before returning `null`, and write it as a sentence for a human. A reason the
package does not ship has no translation key, so `AuditDiscarded::message()` hands yours back verbatim
and it becomes the log line an operator reads.

```php
$this->discard->because('device telemetry is not part of the trail');

return null;
```

❌ **Don't** — return `null` silently. The entry still leaves through `AuditDiscarded`, but with reason
`unspecified`, which renders a generic sentence naming only your class — and by then you are debugging
a disappearance with no statement of intent anywhere.

```php
return null;   // reason: 'unspecified'
```

---

✅ **Do** — return `null` from an `Auditing` listener that has no opinion. The event is dispatched with
`until()`, so `null` (or nothing) is the only return that lets the listeners behind you run.

```php
use ElPandaPe\Sentinel\Events\Auditing;
use ElPandaPe\Sentinel\Pipeline\Discard;
use Illuminate\Support\Facades\Event;

Event::listen(Auditing::class, static function (Auditing $event): ?bool {
    if ($event->audit->subject_type !== \App\Models\DeviceReading::class) {
        return null;
    }

    app(Discard::class)->because('device telemetry is not part of the trail');

    return false;
});
```

❌ **Don't** — return `true` to mean "keep it". `until()` returns the first non-null response, so `true`
silently halts every later listener while letting the entry through — which looks exactly like the
other listeners never being registered.

```php
Event::listen(Auditing::class, static fn (Auditing $event): bool => true);
```

---

✅ **Do** — use `Sentinel::withoutAuditing()` for a bounded operation that genuinely should not be
audited. It saves the previous state and restores it in a `finally`, so it nests and cannot leak.

```php
Sentinel::withoutAuditing(fn () => $importer->backfill());
```

❌ **Don't** — install a permanent filter to switch auditing off for a migration and then forget it.
A filter has no scope, no expiry and no removal; the operation ends and the veto does not.

```php
Sentinel::filter(static fn (AuditData $audit): bool => false);   // still true next Tuesday
```

---

**See also:** [The write pipeline](01-the-write-pipeline.md) ·
[Protecting sensitive data](02-protecting-sensitive-data.md) ·
[Events and listeners](../09-operations/04-events-and-listeners.md) ·
[Events reference](../99-reference/05-events.md) ·
[Exceptions reference](../99-reference/06-exceptions.md) ·
[The hash chain](../07-integrity/01-the-hash-chain.md) ·
[Redaction and tombstones](../08-lifecycle/04-redaction-and-tombstones.md) ·
[Turning auditing off](../02-getting-started/04-turning-auditing-off.md)
