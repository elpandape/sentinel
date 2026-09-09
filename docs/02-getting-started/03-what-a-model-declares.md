# 🚀 What a model declares

> Every property the `Auditable` trait reads off a model, what each one produces in the entry, and
> whether it adds to a configuration list or replaces it.

**On this page:** [The opt-in](#the-opt-in) · [Trait or contract](#trait-or-contract) · [The ten declarations](#the-ten-declarations) · [How a declaration is read](#how-a-declaration-is-read) · [Union or override](#union-or-override) · [The state column must stay readable](#the-state-column-must-stay-readable) · [A fully annotated model](#a-fully-annotated-model) · [The relation and the methods the trait adds](#the-relation-and-the-methods-the-trait-adds) · [Two optional contracts](#two-optional-contracts) · [What the trait does not do](#what-the-trait-does-not-do) · [Pitfalls](#-pitfalls) · [Best practices](#-best-practices)

---

## The opt-in

One trait. No interface to implement, no observer to register, no configuration entry naming the
model.

```php
use ElPandaPe\Sentinel\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

final class Invoice extends Model
{
    use Auditable;
}
```

`bootAuditable()` registers listeners for four Eloquent events — `created`, `updated`, `deleted`,
`forceDeleted` — plus `updating`, which writes nothing and exists only so a state machine can refuse
a move while the row is still unwritten. Each listener is registered as
`[ModelObserver::class, $event]` rather than as an instance, so the container re-resolves the
observer — and with it the scoped ledger — on every fire.

Everything else on this page is optional. A model that declares nothing is audited with its complete
attribute set, graded by `sentinel.severity`, tagged with `sentinel.tags.default`, and stored with
`before` and `after` intact.

> 📌 **Note.** The declarations are read, never written. The trait declares none of the ten
> properties itself; your model declares the ones it wants and the trait's reader methods return the
> empty answer for the rest.

---

## Trait or contract

`ElPandaPe\Sentinel\Contracts\Auditable` is an interface declaring twelve of the methods the trait
supplies: the ten declaration readers plus `audits()` and `relationHistory()`. `latestAudit()` and
the two relation factories are trait-only and are **not** part of it.

A trait cannot implement an interface in PHP, so **`use Auditable;` never makes a
model `instanceof Contracts\Auditable`** — and nothing in the package asks it to.

`Support\AuditPolicy` is the single place that accepts either shape:

```php
// src/Support/AuditPolicy.php
return $model instanceof Auditable
    || in_array(AuditableConcern::class, class_uses_recursive($model), true);
```

Every consumer of a model declaration — the snapshot builder, the capture classes, the mass-operation
guard — goes through `AuditPolicy`, so no other file in `src/` performs an `instanceof` against the
contract. A model that is neither a trait user nor a contract implementer gets `AuditPolicy::none()`:
all lists empty, snapshots on, no severity override.

| | `use Auditable;` | `implements Contracts\Auditable` |
|---|---|---|
| Eloquent listeners registered | Yes, by `bootAuditable()` | **No** — you register your own or also use the trait |
| Pivot auditing (`attach`/`detach`/`sync`/`toggle`) | Yes, via `newBelongsToMany()` / `newMorphToMany()` | **No** — those overrides live in the trait |
| `audits()` relation | Yes | You write it (the contract requires the method) |
| `latestAudit()` | Yes | **Not in the contract** — write it if you want it |
| `relationHistory()` | Yes | You write it |
| Recognised by `AuditPolicy` | Yes | Yes |
| Accepted by `Builder::auditing()` | Yes | Yes |
| Declarations may be computed | No — they must be real properties | Yes — they are your methods |

Implement the contract when a declaration has to be computed rather than written down; use the trait
otherwise. Combining them is legal and is the usual answer when you want computed declarations *and*
automatic capture: use the trait for the listeners and override the reader methods you need.

> ⚠️ **Warning.** `Builder::auditing()` accepts a contract implementer, but the exception it raises
> for a model that is neither says *"does not use the Auditable trait"*. The message names only the
> trait; the check accepts both. See [Mass operations](../03-capture/05-mass-operations.md).

---

## The ten declarations

| Property | Type | What it does | What it changes in the entry | Combines with config | Deeper |
|---|---|---|---|---|---|
| `$auditInclude` | `list<string>` | Whitelist of attributes the snapshot keeps | `before`, `after`, `changes` hold only these | **Overrides** `$auditExclude`, `$hidden` and `snapshots.include_hidden` | [Snapshots](../03-capture/02-snapshots.md) |
| `$auditExclude` | `list<string>` | Attributes dropped before the pipeline ever sees them | Key absent from `before`, `after` and `changes` | Ignored while `$auditInclude` is non-empty | [Snapshots](../03-capture/02-snapshots.md) |
| `$auditRedact` | `list<string>` | Fields the `MaskSensitiveData` stage replaces with a mask | Value replaced in place | **Union** with `security.redaction.fields` | [Protecting sensitive data](../05-pipeline-and-security/02-protecting-sensitive-data.md) |
| `$auditHash` | `list<string>` | Fields replaced with a salted digest — comparable, irreversible | Value replaced in place | **Union** with `security.hashing.fields` | [Hashing and the salt](../05-pipeline-and-security/04-hashing-and-the-salt.md) |
| `$auditEncrypt` | `list<string>` | Fields replaced with ciphertext | Value replaced in place, plus the `encryption` column | **Union** with `security.encryption.fields` | [Encryption and the keyring](../05-pipeline-and-security/03-encryption-and-the-keyring.md) |
| `$auditTags` | `list<string>` | Labels every entry of this subject is born with | `tags` | **Union** with `tags.default` and whatever the caller set | [Labels](../06-reading/06-labels.md) |
| `$auditTransitions` | `list<string>` | Columns whose movement is a state change rather than an edit | `audit_type` and `event` become `transition`; `metadata.transition.attribute` names the column | No config union; `transitions.attribute` is only the fallback for `Sentinel::transition()` | [State transitions](../03-capture/08-state-transitions.md) |
| `$auditParents` | `array<string, string>` | `belongsTo` relations whose parents get an entry when the child changes hands | Two extra `relation` entries, on the parent left and the parent joined | — | [Relationship auditing](../03-capture/04-relationships.md) |
| `$auditSnapshots` | `bool` | Whether `before`/`after` are stored for this model | Both columns become `null`; `changes` is unaffected | **ANDed** with `snapshots.enabled` | [Snapshots](../03-capture/02-snapshots.md) |
| `$auditSeverity` | `Severity` or its backed value | Grades every entry of this subject | `severity` | **Overrides** `severity.events` and `severity.default` | [Enums](../99-reference/04-enums.md) |

Each is exposed through a reader method the contract also declares: `auditIncluded()`,
`auditExcluded()`, `auditRedacted()`, `auditEncrypted()`, `auditHashed()`, `auditTags()`,
`auditTransitions()`, `auditParents()`, `auditSnapshotsEnabled()`, `auditSeverity()`.

> 📌 **Note.** The three protection lists match by **key name at any depth**, across six containers:
> `before`, `after`, `changes`, `metadata`, `context` and `criteria`. Declaring a field once protects
> it wherever it surfaces — including inside a resolver's context or the search clauses a mass
> operation recorded. `$auditExclude` is the only list that works by removal instead.

A malformed declaration is refused at read time, not at boot:

| Declaration | Bad value | Exception |
|---|---|---|
| the seven `list<string>` properties | not an array, or an array holding a non-string | `ConfigurationException::expected('<property>', 'a list of strings', …)` |
| `$auditParents` | not a map of non-empty string to non-empty string | `ConfigurationException::expected('auditParents', 'a map of relation name to relation name', …)` |
| `$auditSnapshots` | not a boolean | `ConfigurationException::expected('auditSnapshots', 'a boolean', …)` |
| `$auditSeverity` | a string outside `info, notice, warning, critical` | `ConfigurationException::unknown('auditSeverity', …)` |
| `$auditSeverity` | neither the enum nor a string | `ConfigurationException::expected('auditSeverity', 'a Severity or its value', …)` |

"At read time" means on the first write the model performs — the reader runs on the create path too,
not only on the operation the declaration is about.

---

## How a declaration is read

```php
// src/Concerns/Auditable.php
private function auditProperty(string $property): mixed
{
    return property_exists($this, $property) ? $this->{$property} : null;
}
```

Two consequences follow, and both bite silently.

**It must be a real declared property.** `public`, `protected` and `private` all work, because the
trait is compiled into the class and reads in class scope. A value produced by `__get()`, or assigned
to an undeclared dynamic property in a boot hook, is invisible: `property_exists()` answers false and
the model is treated as declaring nothing.

**There are two paths, and they see different things.** On the capture path a real, hydrated model is
in hand and `AuditPolicy::of($model)` reads it directly. On the pipeline path the model object is
gone — the entry is a `Data\AuditData` named after its `subject_type` — so `Support\PolicyRegistry`
rebuilds a model with `newInstanceWithoutConstructor()` and reads the declarations off that.

| Declaration | Read on the capture path | Read on the pipeline path |
|---|---|---|
| `$auditInclude`, `$auditExclude` | `Snapshot\SnapshotBuilder` | — |
| `$auditSnapshots` | `SnapshotBuilder::retains()` | — |
| `$auditSeverity` | `Capture\ModelCapture` | — |
| `$auditTransitions` | `ModelCapture`, `Transitions\TransitionBuilder` | — |
| `$auditParents` | `Capture\ParentCapture` | — |
| `$auditRedact`, `$auditHash` | — | `Pipeline\Stages\MaskSensitiveData` |
| `$auditEncrypt` | — | `Pipeline\Stages\EncryptSensitiveData` |
| `$auditTags` | — | `Pipeline\Stages\ResolveTags` |

> ⚠️ **Warning.** The pipeline path never runs a constructor and never resolves relations, so a
> declaration that depends on constructor work or on instance state is invisible to redaction,
> hashing, encryption and labelling — while still working perfectly on the capture path. Nothing warns
> you. Keep the four pipeline-read declarations literal.

`PolicyRegistry` memoizes one policy per `subject_type` for the life of the container scope, so
changing a declaration mid-request is not picked up after the first entry of that type. It also
resolves a morph alias through `Relation::getMorphedModel()`, so a morph map is honoured.

---

## Union or override

The rule is not uniform, and getting it backwards is the usual cause of a field you thought was
protected appearing in the clear.

**Union — the config list adds to what the model declared.** `security.redaction.fields`,
`security.hashing.fields`, `security.encryption.fields` and `tags.default` are appended to the
model's list and de-duplicated. There is no way to make a config list cancel a model declaration.
This is deliberate: the config list is the only lever that reaches entries with no model subject at
all, such as those written by `Sentinel::event()` or by the authentication subscriber.

```php
// config/sentinel.php
'security' => ['redaction' => ['fields' => ['ip_address', 'session_id']]],

// App\Models\Patient
protected array $auditRedact = ['national_id'];

// Effective for a Patient entry: ['national_id', 'ip_address', 'session_id']
```

**Override — the model wins outright.**

- A non-empty `$auditInclude` is the *only* list `SnapshotBuilder` consults. `$auditExclude`,
  `$hidden` and `snapshots.include_hidden` are never reached.
- `$auditSeverity` beats both `severity.events` and `severity.default`, for every event.

**Conjunction.** `$auditSnapshots` is ANDed with `snapshots.enabled`: either one being false drops
`before` and `after`. Neither one stops the pair being *built* — the diff needs it — so the flag buys
storage, not time.

> 📌 **Note.** A name in `$auditInclude` that is not among the model's current attributes is dropped
> silently. `SnapshotBuilder` intersects the include list with the attribute keys, so a typo, a
> renamed column, or a column not selected into the instance produces no error — just a snapshot
> missing the field you asked for.

---

## The state column must stay readable

`AuditPolicy`'s constructor refuses a column that is both a declared transition and unreadable in the
entry. A lifeline answered with a row of asterisks is not a lifeline.

| Combination | Exception |
|---|---|
| in `$auditTransitions` and `$auditExclude` | `ConfigurationException::unreadableTransition($column, 'auditExclude')` |
| in `$auditTransitions` and `$auditRedact` | `…::unreadableTransition($column, 'auditRedact')` |
| in `$auditTransitions` and `$auditEncrypt` | `…::unreadableTransition($column, 'auditEncrypt')` |
| in `$auditTransitions` and `$auditHash` | `…::unreadableTransition($column, 'auditHash')` |
| in `$auditTransitions`, absent from a non-empty `$auditInclude` | `ConfigurationException::omittedTransition($column)` |

The check runs in the constructor, and `AuditPolicy::of()` is built on every capture path including
creation. **The refusal therefore fires on the model's first insert, not on its first transition.**

> 💡 **Tip.** Declare exactly one `$auditTransitions` column when you can. With one column,
> `Sentinel::transition()` infers it and the call site never repeats it. With two or more, every call
> site must name one with `->on()` or `ConfigurationException::ambiguousTransition` is raised.

---

## A fully annotated model

```php
use ElPandaPe\Sentinel\Concerns\Auditable;
use ElPandaPe\Sentinel\Enums\Severity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

final class Invoice extends Model
{
    use Auditable;
    use SoftDeletes;

    /**
     * Whitelist. Non-empty, so $auditExclude below is dead code and $hidden is bypassed.
     * Every declared transition column has to appear here.
     *
     * @var list<string>
     */
    protected array $auditInclude = ['status', 'total', 'customer_email', 'customer_id', 'issued_at'];

    /** @var list<string> */
    protected array $auditExclude = ['internal_notes'];

    /** @var list<string> */
    protected array $auditRedact = ['customer_email'];

    /** @var list<string> */
    protected array $auditHash = ['tax_id'];

    /** @var list<string> */
    protected array $auditEncrypt = ['bank_account'];

    /** @var list<string> */
    protected array $auditTags = ['billing'];

    /** @var list<string> */
    protected array $auditTransitions = ['status'];

    /** @var array<string, string> */
    protected array $auditParents = ['customer' => 'invoices'];

    protected bool $auditSnapshots = true;

    protected Severity $auditSeverity = Severity::Warning;

    // Eloquent truncates dates on assignment, before any snapshot exists.
    protected $dateFormat = 'Y-m-d H:i:s.u';

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
```

Read that model back:

```php
$invoice->update(['status' => 'paid']);

$entry = $invoice->latestAudit();

$entry->audit_type;   // 'transition' — status is a declared transition column
$entry->event;        // 'transition'
$entry->severity;     // Severity::Warning — $auditSeverity beat severity.events
$entry->metadata;     // ['transition' => ['attribute' => 'status']]
$entry->after['customer_email'];  // masked, not the address
$entry->toArray()['tags'];        // ['billing', ...tags.default] — sorted labels, not AuditTag models
```

Note what is *not* there: `internal_notes`, `bank_account` and `tax_id` — none of them is in
`$auditInclude`, so none of them reaches `before`, `after` or `changes` at all.

That does **not** make `$auditHash` and `$auditEncrypt` dead declarations. `Security\Fields` matches
by key name at any depth across six containers — `before`, `after`, `changes`, `metadata`, `context`
and `criteria` — so a field named there is still protected if it surfaces in the metadata a caller
passed, in a resolver's context, or in the `criteria` of a
[mass operation](../03-capture/05-mass-operations.md). A whitelist decides what the *snapshot* holds;
it does not narrow the protection lists.

> 🧪 **Verify it.** `php artisan tinker`, then
> `App\Models\Invoice::first()->latestAudit()->only(['audit_type', 'event', 'severity', 'metadata'])`.

---

## The relation and the methods the trait adds

```php
/** @return MorphMany<Audit, $this> */
public function audits(): MorphMany;      // oldest first, tags eager-loaded
public function latestAudit(): ?Audit;    // audits()->reorder()->orderByDesc('id')->first()
public function relationHistory(string $relation): AuditQuery;
```

`audits()` is a `morphMany` onto the class named by `sentinel.models.audit`, ordered by `id` — a ULID
minted at write time — with `->with('tags')`. The eager load is not an optimisation you can drop:
`Audit::toArray()` reads tags unconditionally and that shape is frozen, so without it the serialiser
is an N+1 and a `LazyLoadingViolationException` under `Model::preventLazyLoading()`.

`relationHistory()` resolves the `Sentinel` manager from the container and returns
`audits()->for($this)->whereRelation($relation)` — a query, not a result, so it composes and pages
like any other read. See [The Query API](../06-reading/01-the-query-api.md).

**The trait adds no query scope and no global scope.** `Builder::auditing()`, the per-query
opt-in for mass operations, is a macro the service provider registers on
`Illuminate\Database\Eloquent\Builder` itself — it exists on every model in the application, audited
or not, and refuses a model that declares nothing. It is not something the trait grants.

The trait also overrides two protected relation factories, `newBelongsToMany()` and
`newMorphToMany()`, returning `AuditedBelongsToMany` and `AuditedMorphToMany`. Eloquent fires no
model event for `attach`, `detach`, `sync` or `toggle`, so subclassing the relation is the entire
mechanism by which pivot changes are audited. `morphedByMany()` routes through `newMorphToMany()`, so
it is covered too.

---

## Two optional contracts

**`Contracts\DeclaresTransitions`** — a model that knows which moves between its states are legal.

```php
use ElPandaPe\Sentinel\Contracts\DeclaresTransitions;

final class Invoice extends Model implements DeclaresTransitions
{
    use Auditable;

    /** @var list<string> */
    protected array $auditTransitions = ['status'];

    public function allowsTransition(string $attribute, bool|float|int|string|null $from, bool|float|int|string|null $to): bool
    {
        return $attribute !== 'status' || $to !== 'paid' || $from === 'approved';
    }
}
```

`Transitions\Machine` asks on the `updating` event — before the row is written — and a false answer
raises `Transitions\IllegalTransition`, abandoning the save. A model that does not implement it
consents to every move: Sentinel records transitions, it does not govern the workflow. See
[State transitions](../03-capture/08-state-transitions.md).

**`Contracts\DeclaresFilters` is not a model contract.** It is implemented by a *ledger driver* whose
backend cannot translate every published filter, and it changes nothing about a model. It is listed
here only because the name invites the confusion. See
[The Ledger contract](../11-extending/01-the-ledger-contract.md).

---

## What the trait does not do

- It does not observe `saving`, `saved`, `creating`, `deleting`, `restoring`, `restored`,
  `retrieved` or `replicating`. `restored` in particular is derived from the `updated` that clears
  the deletion mark, because by the time Eloquent fires `restored` the original is already synced and
  the state the record had in the bin is unreachable.
- It does not capture `Model::query()->update([...])` or `->delete()`. Eloquent fires no model event
  for either; the answer is the explicit per-query
  [`->auditing()`](../03-capture/05-mass-operations.md) opt-in.
- It does not add soft deletes. `restored` and `force_deleted` entries appear only if the model uses
  `SoftDeletes` on its own.
- It does not add a per-model off switch. There is no `$auditEnabled`; see
  [Turning auditing off](04-turning-auditing-off.md).
- It does not validate `$auditParents` at boot. A relation that is missing, is not a `belongsTo`, or
  is a `morphTo` raises `ConfigurationException::notAParent` from `Capture\ParentCapture`, which runs
  on the `updated` event only — so the refusal arrives on the next update of the child, in
  production, not at deploy.
- It does not make old entries change. A declaration added today governs entries written from today;
  history is append-only and nothing rewrites what is already in the chain.

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| A field you named in `$auditRedact` is stored in the clear | The model uses neither the trait nor `Contracts\Auditable`, so `AuditPolicy::none()` is returned and every declaration is ignored in silence | Add `use Auditable;` (or implement the contract) |
| The same, on a model that does use the trait | The declaration is computed in the constructor or served by `__get()`; the pipeline reads it off a `newInstanceWithoutConstructor()` replica with `property_exists()` | Make it a literal declared property, or name the field in `security.redaction.fields` |
| `ConfigurationException: … auditExclude` on a plain `create()`, before any transition happened | A `$auditTransitions` column is also excluded, redacted, hashed or encrypted; `AuditPolicy`'s constructor runs on every capture path | Remove the column from the conflicting list |
| A field named in `$auditInclude` is missing from `before`/`after` | The include list is intersected with the model's current attribute keys — a typo or an unselected column vanishes silently | Check the spelling against the table columns |
| A `$hidden` attribute is audited even though `snapshots.include_hidden` is `false` | A non-empty `$auditInclude` is the only list consulted; the branch that subtracts `$hidden` is never reached | Drop the attribute from `$auditInclude`, or accept it |
| `Sentinel::transition()` throws `ambiguousTransition` | The model declares more than one transition column, so nothing can be inferred | Name the column with `->on('status')` at the call site |
| Changing a declaration mid-request has no effect from the second entry onwards | `PolicyRegistry` memoizes one policy per `subject_type` for the container scope | Treat declarations as boot-time facts |
| `LazyLoadingViolationException` when serialising entries | Something re-queried the relation without `->with('tags')`; `Audit::toArray()` reads tags unconditionally | Read through `audits()`, which eager-loads them |
| `attach()`/`sync()` write no entry | The model overrides `newBelongsToMany()` or `newMorphToMany()` without returning the package's audited subclasses | Call through to the trait's override, or drop yours |

---

## ✅ Best practices

✅ **Do** — use the trait when the declarations are literal, and add the contract on top only when
one of them has to be computed. `AuditPolicy` accepts either, but only the trait registers listeners.

```php
final class Order extends Model implements \ElPandaPe\Sentinel\Contracts\Auditable
{
    use Auditable;   // the listeners and the relation factories

    /** @return list<string> */
    public function auditExcluded(): array   // the one declaration that is computed
    {
        return $this->tenant?->auditsPricing() ? [] : ['unit_price'];
    }
}
```

❌ **Don't** — implement the contract alone and expect entries. Nothing subscribes to Eloquent, and
the model is silently never captured.

```php
final class Order extends Model implements \ElPandaPe\Sentinel\Contracts\Auditable
{
    // twelve methods, zero listeners, zero entries
}
```

---

✅ **Do** — reach for `$auditRedact` / `$auditHash` / `$auditEncrypt` when the *fact that a field
changed* matters. The change stays on the record while the value stays unreadable.

```php
final class Patient extends Model
{
    use Auditable;

    /** @var list<string> */
    protected array $auditRedact = ['diagnosis'];
}
```

❌ **Don't** — use `$auditExclude` for that. Exclusion drops the key before the pipeline, so the
diff, the transition detection and a later restore can say nothing at all about the field.

```php
protected array $auditExclude = ['diagnosis'];   // the trail cannot even say it moved
```

---

✅ **Do** — keep every declared transition column inside a declared include list, and out of every
protection list. The check is a constructor invariant and fires on the model's first insert.

```php
/** @var list<string> */
protected array $auditInclude = ['status', 'total'];

/** @var list<string> */
protected array $auditTransitions = ['status'];
```

❌ **Don't** — protect the state column. The exception names the offending property, but you meet it
on a `create()`, weeks before you meant to use transitions.

```php
protected array $auditTransitions = ['status'];
protected array $auditHash = ['status'];   // ConfigurationException::unreadableTransition
```

---

✅ **Do** — name cross-cutting fields that no model owns in the configuration lists. They are a union
with model declarations and are the only lever that reaches an entry with no model subject.

```php
// config/sentinel.php
'security' => ['redaction' => ['fields' => ['ip_address', 'session_id', 'authorization']]],
```

❌ **Don't** — expect a configuration list to cancel a model declaration. `Support\Config` appends
and de-duplicates; there is no subtraction anywhere in the package.

```php
'security' => ['redaction' => ['fields' => []]],   // does not un-redact $auditRedact
```

---

✅ **Do** — declare the ten properties as real, typed, literal properties on the model class.

```php
/** @var list<string> */
protected array $auditTags = ['billing', 'finance'];
```

❌ **Don't** — assign them dynamically or serve them from `__get()`. `property_exists()` answers
false and the model is treated as declaring nothing — with no warning and no error.

```php
protected static function booted(): void
{
    static::retrieved(static fn (self $model) => $model->auditTags = ['billing']);  // invisible
}
```

---

✅ **Do** — turn `$auditSnapshots` off on genuinely wide tables and know exactly what you buy.

```php
final class TelemetryReading extends Model
{
    use Auditable;

    protected bool $auditSnapshots = false;   // before/after dropped; changes, chain and hash intact
}
```

❌ **Don't** — expect it to make writes faster. The pair is built either way because the diff needs
it; the flag governs storage only.

---

**See also:** [Your first audit](02-your-first-audit.md) · [Turning auditing off](04-turning-auditing-off.md) · [What gets audited](../03-capture/01-what-gets-audited.md) · [Snapshots](../03-capture/02-snapshots.md) · [Relationship auditing](../03-capture/04-relationships.md) · [State transitions](../03-capture/08-state-transitions.md) · [Protecting sensitive data](../05-pipeline-and-security/02-protecting-sensitive-data.md) · [Configuration](../99-reference/02-configuration.md) · [Exceptions](../99-reference/06-exceptions.md) · [API stability](../99-reference/09-api-stability.md)
