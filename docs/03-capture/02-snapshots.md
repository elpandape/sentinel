# 📥 Snapshots

> How a record's state is frozen into `before` and `after`: what goes in, how each type is written
> down, and what a snapshot can never give back.

**On this page:** [What a snapshot is](#what-a-snapshot-is) · [The pair, per event](#the-pair-per-event) · [Which attributes reach the snapshot](#which-attributes-reach-the-snapshot) · [The serialisation rule, per type](#the-serialisation-rule-per-type) · [Dates and precision](#dates-and-precision) · [Turning snapshots off](#turning-snapshots-off) · [What a snapshot cannot round-trip](#what-a-snapshot-cannot-round-trip) · [What that means for restore](#what-that-means-for-restore)

---

## What a snapshot is

A snapshot is the **complete** state of the record at one instant, as a map of attribute name to a
value that can be written to a JSON column. Not the dirty set: an entry has to be readable months
later without the row in front of you.

`ElPandaPe\Sentinel\Snapshot\SnapshotBuilder` builds it. `build()` takes the model and a raw
attribute array, and:

1. Makes a throwaway replica with `$model->newInstance()` and fills it with `setRawAttributes()`.
2. Reads every selected key through `$replica->getAttributeValue($key)`, so the model's own casts,
   `Attribute` accessors and `get{Foo}Attribute()` methods all fire.
3. Normalises each value into something a JSON column can hold.
4. `ksort`s the top level, and every nested map under it.

That is the whole mechanism. A new cast in your application needs no change in the package, because
the package never reads the raw column — it reads what the model says the column means.

> ⚠️ **Warning.** The replica does **not** exist in the database (`newInstance()` leaves `exists`
> false) and has **no relations loaded**. An accessor that branches on `$this->exists` sees `false`;
> one that reaches through a relation issues a query, once per snapshot — twice per update, since an
> update builds two. `Model::preventLazyLoading()` does **not** catch it: Eloquent's
> `handleLazyLoadingViolation()` returns early for a model whose `exists` is false, so the load is
> silent. Keep relation access out of accessors on audited models.

Only keys that are actually in the attribute array are read. Three consequences worth stating:

- **`$appends` are not snapshotted.** An appended accessor is not an attribute; it never appears.
- **A column you did not `select()` is absent**, because it is not on the instance.
- **Timestamps are ordinary attributes.** `created_at` and `updated_at` are audited like anything
  else — there is no default exclusion list anywhere in the package. A bare `$model->touch()` moves
  `updated_at`, so it produces a real diff and a real entry.

`before` and `after` are part of the canonical payload that the chain hashes
(`Integrity\CanonicalPayload::COLUMNS`), so how a value is written down is a
[canonicalization](../07-integrity/03-canonicalization.md) concern, not a cosmetic one.

---

## The pair, per event

`SnapshotBuilder::pair()` chooses the two sides from the event, not from the data. What the table
below enumerates is the **shapes a snapshot pair takes**, keyed by `event` — not the nine
`audit_type` values of [The audit record](../01-concepts/02-the-audit-record.md#kinds-of-entry), and
not the six (`event`, `audit_type`) pairs of [What gets audited](01-what-gets-audited.md):

| Event | `before` | `after` |
|---|---|---|
| `created` | `null` | the state after the insert |
| `updated` | `$model->getRawOriginal()` | `$model->getAttributes()` |
| `deleted` (soft delete) | the state at deletion | `null` |
| `force_deleted` | the state at deletion | `null` |
| `restored` | the state the record had **in the bin** | the state it came back as |
| `custom`, auth events, anything else | `null` | `null` |

A `transition` entry is an `updated` that moved a column declared in `$auditTransitions`, so it
carries the update's pair unchanged — see [State transitions](08-state-transitions.md).

The restore point is not guesswork: `Capture\ModelObserver::restorePoint()` builds it from
`getRawOriginal()` during the `updated` event that clears the deletion column, because by the time
Eloquent fires `restored` the original has already been synced and the state in the bin is gone.

> ⚠️ **Warning.** `null` and `[]` are different answers and the package keeps them different.
> `null` means *this state does not apply to this event*; `[]` means *it applied and was empty*.
> `Snapshot\SnapshotPair` says so in one sentence, and every consumer downstream honours it.
> Collapsing them with `?? []` throws away audited information.

```php
$invoice = Invoice::query()->create(['status' => 'draft']);

$invoice->latestAudit()->before;  // null   — there was no record before
$invoice->latestAudit()->after;   // ['created_at' => …, 'id' => …, 'status' => 'draft', …]
```

---

## Which attributes reach the snapshot

`SnapshotBuilder::keys()` resolves one of two branches, and they do not compose:

| Declared | Effect |
|---|---|
| `$auditInclude` non-empty | The intersection of that list with the model's current attributes is the snapshot. **Nothing else is consulted** — not `$auditExclude`, not `$hidden`, not `snapshots.include_hidden`. |
| `$auditInclude` empty | Every attribute minus `$auditExclude`, minus `$model->getHidden()` when `snapshots.include_hidden` is `false`. |

```php
use ElPandaPe\Sentinel\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

final class Patient extends Model
{
    use Auditable;

    /** @var list<string> */
    protected array $auditExclude = ['import_checksum'];

    /** @var list<string> */
    protected $hidden = ['national_id'];
}
```

With the shipped defaults that snapshot carries `national_id`. **Hidden attributes are audited on
purpose**: `$hidden` is about what leaves over HTTP, and auditing is what this package is for.

Two global switches, both read through `Support\Config`:

| Key | Default | What it does | When you would change it |
|---|---|---|---|
| `snapshots.enabled` | `true` | When `false`, no entry anywhere in the application stores `before` or `after`. The pair is still built and the diff still written. | You want the trail to carry the change but never the full state. |
| `snapshots.include_hidden` | `true` | When `false`, every attribute in a model's `$hidden` is dropped from its snapshots, and therefore from its diffs. | `$hidden` on your models already means *never leaves the application* and you want that to hold for the trail too. |

> 🔒 **Security.** `include_hidden = false` is a blunt instrument: it removes the value from the
> record entirely, so nothing can prove the field moved. If you want the trail to say *this changed*
> without saying *to what*, redact or hash the field instead — see
> [Protecting sensitive data](../05-pipeline-and-security/02-protecting-sensitive-data.md).

Three ways a field policy silently does nothing:

- **A name in `$auditInclude` that is not a current attribute is dropped without a word.**
  `keys()` intersects against `array_keys($attributes)`, so a typo, a renamed column or a column
  that was not selected leaves you with a snapshot missing exactly the field you asked for.
- **A policy declared on a model that uses neither the trait nor `Contracts\Auditable` is ignored.**
  `Support\AuditPolicy::of()` hands back the empty policy for such a model and never reads the
  property.
- **`$auditInclude` beats `$hidden`.** An attribute that is both included and hidden is audited even
  with `include_hidden = false`, because the whitelist branch returns before the hidden list is ever
  subtracted.

---

## The serialisation rule, per type

`SnapshotBuilder::normalize()` is a `match (true)` and **the order of its arms is the rule**. The
first arm that matches decides how the value is written:

| Value | Written as | Notes |
|---|---|---|
| `null`, `int`, `float`, `string`, `bool` | itself, untouched | No coercion. `'1'` stays a string, `1` stays an int. |
| Backed enum | `$value->value` | `InvoiceStatus::Draft` → `'draft'`. |
| Pure (unit) enum | `$value->name` | It has no value, so the case name is what there is: `PureStatus::Published` → `'Published'`. |
| `DateTimeInterface` (`Carbon`, `CarbonImmutable`, `DateTimeImmutable`) | `format('Y-m-d\TH:i:s.uP')` | `SnapshotBuilder::DATE_FORMAT`. Always six fractional digits and a numeric offset. |
| `Illuminate\Contracts\Support\Arrayable` | `toArray()`, then every element normalised | Covers `Collection` and any value object that declares it. |
| `JsonSerializable` | `jsonSerialize()`, then normalised again | The result goes back through the same match. |
| `array` | element by element; maps `ksort`ed, lists left in order | `array_is_list()` decides which. |
| `Stringable` | `(string) $value` | The last resort before the throw. |
| anything else | throws `Exceptions\SnapshotException` | Resources, plain objects with no contract. |

Two consequences of that order:

- **`Arrayable` beats `Stringable`.** A value object that declares both is written as its
  `toArray()`. If you want the string form in the trail, do not declare `Arrayable`.
- **Nothing is skipped.** A value the match cannot reach raises `SnapshotException`, naming the
  attribute and telling you to exclude it or cast it — it is never written as `null` or as
  `"Object"`.

```php
use App\Casts\MoneyCast;
use ElPandaPe\Sentinel\Concerns\Auditable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;

final class Money implements Arrayable
{
    public function __construct(public int $amount, public string $currency) {}

    /** @return array{amount: int, currency: string} */
    public function toArray(): array
    {
        return ['amount' => $this->amount, 'currency' => $this->currency];
    }
}

final class Invoice extends Model
{
    use Auditable;

    protected $dateFormat = 'Y-m-d H:i:s.u';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,   // backed enum
            'total' => MoneyCast::class,        // a cast returning the Arrayable value object
            'issued_at' => 'immutable_datetime',
            'lines' => 'array',
        ];
    }
}

$invoice->latestAudit()->after;
// [
//   'created_at' => '2026-08-26T09:59:00.000000+00:00',
//   'id'         => '01K…',
//   'issued_at'  => '2026-08-26T10:00:00.123456+00:00',
//   'lines'      => [['sku' => 'A-1', 'qty' => 2]],
//   'status'     => 'draft',
//   'total'      => ['amount' => 1250, 'currency' => 'PEN'],
//   'updated_at' => '2026-08-26T09:59:00.000000+00:00',
// ]
```

Keys come back sorted because the builder sorts them — top level and every nested map. Lists keep
their order, because in a list the position **is** the meaning.

> 📌 **Note.** `SnapshotBuilder::DATE_FORMAT` is frozen with `payload_version` 1. Changing it
> changes the canonical payload, which re-hashes every golden vector and forces a
> `payload_version` bump plus a backwards-compatibility test. It is not a formatting preference.

---

## Dates and precision

**Date precision belongs to the audited model, not to Sentinel.** Eloquent truncates on *assignment*:
`setAttribute()` runs the value through `fromDateTime()`, which formats with the model's
`$dateFormat`. By the time the snapshot exists, the microseconds are already gone.

```php
// Default $dateFormat ('Y-m-d H:i:s'):
$patient->admitted_at = '2026-08-26 10:00:00.123456';
// snapshot: '2026-08-26T10:00:00.000000+00:00'

// With protected $dateFormat = 'Y-m-d H:i:s.u';
// snapshot: '2026-08-26T10:00:00.123456+00:00'
```

If sub-second ordering matters in your trail, set `$dateFormat` **and** give the column the matching
type (`datetime(6)` on MySQL, `timestamp(6)` on PostgreSQL). The snapshot faithfully reflects
whatever the model kept, and no more.

The offset is written as `+00:00`, not as `UTC` or `America/Lima`: `P` in the format string is a
numeric offset. A timezone *name* does not survive a snapshot.

---

## Turning snapshots off

Two levers, one global and one per model:

```php
// config/sentinel.php
'snapshots' => ['enabled' => false, 'include_hidden' => true],
```

```php
final class WideReport extends Model
{
    use Auditable;

    protected bool $auditSnapshots = false;
}
```

`SnapshotBuilder::retains()` is `config('sentinel.snapshots.enabled') && $policy->snapshots` — and
it is asked **only about storage**. The pair is built either way, because the
[diff](03-diffs.md) needs it:

```php
$report = WideReport::query()->create(['name' => 'Ada']);
$report->update(['name' => 'Grace']);

$last = $report->latestAudit();

$last->before;              // null
$last->after;               // null
$last->diff()->toArray();
// [
//   ['path' => '/name',       'op' => 'replace', 'old' => 'Ada', 'new' => 'Grace'],
//   ['path' => '/updated_at', 'op' => 'replace', 'old' => …,     'new' => …],
// ]

$last->previous_hash === $report->audits()->first()->hash;   // true
$last->verifyIntegrity();                                    // true
```

So the flag saves **rows and bytes, not CPU** — the comparison runs regardless. A creation under
this flag duplicates the whole state inside `changes` as a list of `add` entries rather than leaving
the entry empty, which is precisely what keeps the flag usable.

> ⚠️ **Warning.** An entry with no snapshots **cannot be restored**. `Restore\Planner` refuses it
> with `Omission::EntryStateless` before it looks at a single field. If restore is part of why you
> are auditing, do not turn snapshots off on that model.

---

## What a snapshot cannot round-trip

A snapshot is a *photograph*, not a serialised object graph. Types collapse on the way in, and
nothing puts them back:

| Written | Comes back as | What is lost |
|---|---|---|
| Backed enum | its scalar value | The class. `'draft'` is a string until your code re-casts it. |
| Pure enum | its case *name* | The class, and — if you ever rename a case — the link between old entries and the new name. |
| `DateTimeInterface` | a string in `DATE_FORMAT` | The class, and the timezone *name* (only the offset survives). |
| `Arrayable` / `JsonSerializable` value object | a plain map | The class. Reconstructing it is your code's job. |
| `Stringable` value object | a plain string | Everything except the string. |
| `array` cast holding a JSON object whose keys are `"0"`, `"1"`, … | a PHP list, re-encoded as a JSON **array** | The object shape: PHP turns numeric object keys into integers, and `array_is_list()` cannot tell the result from a real list. |
| a binary / non-UTF-8 string | passed through untouched | Nothing in the builder — but a JSON column cannot hold bytes that are not valid UTF-8, so the write fails downstream. Exclude blob columns. |
| an attribute the policy dropped, or that was never on the instance | absent — no key at all | The field is not in the entry, and no later read can invent it. |

> 💡 **Tip.** If a value object has to come back exactly, snapshot enough to rebuild it. A `Money`
> that writes `['amount' => 1250, 'currency' => 'PEN']` reconstructs; one that writes
> `'PEN 12.50'` through `Stringable` needs a parser you now have to maintain.

There is one more asymmetry worth knowing: **`SnapshotBuilder` has no depth limit, and the diff
does.** `each()` recurses unguarded, while `Diff\Normalizer` refuses anything past
`MAX_DEPTH = 64` with a `DiffException`. A pathologically nested attribute is snapshotted happily
and then fails at diff time, which is the same save.

---

## What that means for restore

[Restoring state](../06-reading/08-restoring-state.md) reads the snapshot and puts fields back.
Every refusal it can give traces to something the snapshot did or did not keep:

| Situation | `Enums\Omission` | Scope |
|---|---|---|
| Snapshots off for this model or globally | `EntryStateless` | The whole restoration |
| The entry was redacted | `EntryRedacted` | The whole restoration |
| The entry no longer reproduces its own hash | `EntryTampered` | The whole restoration |
| Field excluded, hidden-dropped, or simply never recorded | `UnrecordedField` | One field |
| Column dropped or renamed by a later migration | `UnknownField` | One field |
| The model's primary key | `IdentityField` | One field — never put back |
| Field is redacted or hashed | `RedactedField` / `HashedField` | One field — a mask cannot be undone |
| Field is encrypted and the key is gone | `KeyUnavailable` | One field |
| The record already holds that value | `Unchanged` | One field |

Two mechanics worth stating outright:

- **The comparison is snapshot against snapshot.** `Restore\Planner::weigh()` rebuilds the record's
  *current* state through the same `SnapshotBuilder` before comparing. Comparing the stored string
  against a live `CarbonImmutable` would report a change on every date the record holds.
- **The entry portrays one moment, and `after` is that moment.** `Planner::portrait()` takes
  `after`, and falls back to `before` only when `after` is null or empty — which is what makes a
  deletion restorable from the state it recorded on the way out.

---

## Engine notes

> 🐘 **Engine.** `before` and `after` are declared `jsonb` (`Support\AuditSchema`), which Laravel
> maps to `jsonb` on PostgreSQL, `json` on MySQL and text on SQLite. MySQL's binary JSON and
> PostgreSQL's `jsonb` **both reorder the keys of an object** on the way in. Snapshots are immune
> because the builder already `ksort`s every map it writes, so what comes back is the shape that
> went in. SQLite preserves insertion order, so this class of bug never shows there — which is
> exactly why anything touching snapshots is verified on all three with `make test-dbs`.

> 🧪 **Verify it.** `make test ARGS=tests/Snapshot` runs the builder and round-trip suites; add
> `make test-dbs` before you tag anything that touched serialisation.

There is no engine-specific code in `src/Snapshot/`. Everything engine-shaped is handled by
normalising the structure before it is stored, and by hashing the
[canonical form](../07-integrity/03-canonicalization.md) rather than the stored text.

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| A field named in `$auditInclude` is missing from every snapshot | The name is not among the model's current attributes — a typo, a renamed column, or a column left out of a `select()`. `keys()` intersects and drops the rest in silence. | Check the spelling against the table, and make sure the instance carries the column. |
| A hidden attribute still appears with `include_hidden = false` | A non-empty `$auditInclude` returns before the hidden list is subtracted. | Remove the attribute from `$auditInclude`, or stop relying on `$hidden` for this. |
| `$auditExclude` on a model has no effect at all | The model uses neither `Concerns\Auditable` nor `Contracts\Auditable`, so `AuditPolicy::of()` returns the empty policy and never reads the property. | Add the trait. Nothing else audits the model either. |
| Dates in the trail always end `.000000` | The model's `$dateFormat` truncates on assignment, before any snapshot exists. | Set `protected $dateFormat = 'Y-m-d H:i:s.u';` and give the column a matching precision. |
| `SnapshotException: Attribute [x] holds a resource…` on save | An attribute holds a resource or a plain object with no `Arrayable`, `JsonSerializable` or `Stringable`. | Exclude the attribute, or cast it to something serialisable. The package refuses rather than writing a placeholder. |
| Extra queries appear on every save of an audited model | An accessor reaches through a relation. The snapshot reads through a `newInstance()` replica with no relations loaded, so it lazy-loads — and `preventLazyLoading()` does not report it, because the replica's `exists` is false. | Do not read relations from an accessor on an audited model, or exclude that attribute. |
| A `touch()` writes a full audit entry | `updated_at` is an ordinary audited attribute; there is no default timestamp exclusion. | Add `updated_at` to `$auditExclude` if a touch is not an auditable fact for that model. |
| Restore refuses everything with `EntryStateless` | The entry has no `before` and no `after` — `$auditSnapshots = false` or `snapshots.enabled = false`. | Re-enable snapshots for models you intend to restore. Entries already written are never backfilled. |
| Two identical states produce different `after` maps | Something is writing a key order the builder cannot normalise — a list where you meant a map, or a JSON object with numeric keys. | Compare `array_is_list()` on both sides; a numeric-keyed object is a list to PHP. |

---

## ✅ Best practices

✅ **Do** — declare `$auditExclude` for volatile or non-auditable columns *before* the model goes
live. An entry is immutable once written, and the value also lands in `changes`.

```php
final class Order extends Model
{
    use Auditable;

    /** @var list<string> */
    protected array $auditExclude = ['search_vector', 'cache_key', 'updated_at'];
}
```

❌ **Don't** — rely on `$hidden` to keep a value out of the trail. `snapshots.include_hidden`
defaults to `true`, so hidden attributes are audited, and a non-empty `$auditInclude` audits them
even when it is `false`.

```php
protected $hidden = ['national_id'];   // still in every snapshot
```

---

✅ **Do** — give a value object a `toArray()` (or `jsonSerialize()`, or `__toString()`) and let the
model's own cast carry it. The package picks it up with no change on its side.

```php
final class Coordinates implements JsonSerializable
{
    public function __construct(public float $lat, public float $lng) {}

    /** @return array{lat: float, lng: float} */
    public function jsonSerialize(): array
    {
        return ['lat' => $this->lat, 'lng' => $this->lng];
    }
}
```

❌ **Don't** — put a resource, a stream or a plain object into an audited attribute and expect it to
be skipped. It raises `SnapshotException` on the save, not later.

```php
$patient->scan = fopen('php://memory', 'r');   // SnapshotException on save
```

---

✅ **Do** — read `null` and `[]` as different answers everywhere in `before`, `after` and `changes`.

```php
$state = $audit->after;                 // null means "no such state for this event"
if ($state === null) { /* creation? deletion? ask $audit->event */ }
if ($state === []) { /* it applied, and it was empty */ }
```

❌ **Don't** — coalesce them. `$audit->after ?? []` erases the distinction the package went out of
its way to preserve, and turns "this event has no after" into "the record was empty".

```php
$state = $audit->after ?? [];   // two very different facts, now indistinguishable
```

---

✅ **Do** — turn `$auditSnapshots = false` on for genuinely wide tables, knowing exactly what you
buy: storage. The entry still chains, still verifies, and still carries the diff.

```php
final class WideReport extends Model
{
    use Auditable;

    protected bool $auditSnapshots = false;
}
```

❌ **Don't** — turn it off on a model whose entries you intend to restore from. The restoration is
refused as a whole with `Omission::EntryStateless`, and there is no way to reconstruct the state
after the fact.

```php
$audit->restore();   // RestoreResult refused: entry_stateless
```

---

✅ **Do** — verify anything that changes what a snapshot looks like on all three engines before you
ship it.

```bash
make test-dbs
```

❌ **Don't** — change `SnapshotBuilder::DATE_FORMAT`, the normalisation order, or the key sorting
casually. All three are inside the canonical payload: a change re-hashes the golden dataset, bumps
`payload_version`, and needs a backwards-compatibility test.

```php
public const string DATE_FORMAT = 'Y-m-d H:i:s';   // breaks every existing hash
```

---

**See also:** [What gets audited](01-what-gets-audited.md) · [Diffs](03-diffs.md) · [What a model declares](../02-getting-started/03-what-a-model-declares.md) · [Protecting sensitive data](../05-pipeline-and-security/02-protecting-sensitive-data.md) · [Restoring state](../06-reading/08-restoring-state.md) · [Canonicalization](../07-integrity/03-canonicalization.md) · [Configuration](../99-reference/02-configuration.md)
