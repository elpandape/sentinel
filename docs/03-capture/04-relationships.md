# 📥 Relationship auditing

> How Sentinel records pivot writes that Eloquent fires no event for, what a relation entry says,
> and what the hash does and does not cover when it says it.

**On this page:** [Turning it on](#turning-it-on) · [The six operations](#the-six-operations) ·
[What an entry says](#what-an-entry-says) · [What a line says](#what-a-line-says) ·
[Reading it back](#reading-it-back) · [Rendering it](#rendering-it) ·
[The projection table](#the-projection-table-an-index-not-the-evidence) ·
[What the hash covers](#what-the-hash-covers-and-what-it-does-not) ·
[Protecting a pivot column](#protecting-a-pivot-column) ·
[When there is no pivot at all](#when-there-is-no-pivot-at-all) ·
[Restoring a relation](#restoring-a-relation) · [Pitfalls](#-pitfalls) · [Best practices](#-best-practices)

---

## Turning it on

Eloquent fires no model event for a pivot write: `attach()` inserts and `detach()` deletes through
the query builder, never through a model, so there is nothing to observe. The usual answer in this
ecosystem is to ask you to call a different method; Sentinel wraps the relation object instead, so
your call sites keep meaning what they always meant. The wrapping happens in `Concerns\Auditable`,
which overrides two Eloquent factories:

| Override | Returns | Covers |
|---|---|---|
| `newBelongsToMany()` | `Capture\Relations\AuditedBelongsToMany` | every `belongsToMany()` the model declares |
| `newMorphToMany()` | `Capture\Relations\AuditedMorphToMany` | every `morphToMany()` **and** `morphedByMany()` — Laravel routes the inverse through the same factory with `$inverse = true` |

Both subclasses carry one trait, `Capture\Relations\RecordsRelationChanges`, which photographs the
pivot rows, runs Laravel's implementation, photographs them again, and reads the difference.

```php
use ElPandaPe\Sentinel\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

final class Order extends Model
{
    use Auditable;

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class)->withPivot('quantity', 'unit_price');
    }

    public function categories(): MorphToMany
    {
        return $this->morphToMany(Category::class, 'categorizable');
    }
}
```

Nothing at the call site changes. `$order->products()` now returns an `AuditedBelongsToMany`, and
every return value is Laravel's own, untouched — `sync()` still hands back
`['attached' => …, 'detached' => …, 'updated' => …]`, `detach()` still returns a row count.

> 📌 **Note.** Using the trait is the whole opt-in. There is no config flag, no global listener and
> no `auditAttach()` alternative. A parent model without `Auditable` builds a plain `BelongsToMany`
> and no pivot write is ever seen.

> ⚠️ **Warning.** If your model overrides `newBelongsToMany()` or `newMorphToMany()` itself, one
> override wins and the other is lost. Nothing detects this: the relation simply goes unaudited,
> with no error and no warning. If you need your own factory, return the audited subclass from it.

The entry is written on the model the relation was **called on**: `$order->products()->attach($p)`
writes on `Order`, never on `Product`. For the other side, `Product` must declare its own inverse
relation and something must call it.

---

## The six operations

All six pass through one code path. What differs is which rows get photographed and what the entry
records about the call.

| Call | `metadata.api` | `event` | Rows photographed | Lines it typically writes |
|---|---|---|---|---|
| `attach($ids, $attributes)` | `attach` | `attached` | only the ids named | one `attach` per new row |
| `detach($ids)` | `detach` | `detached` | only the ids named | one `detach` per row that went |
| `detach()` — no argument | `detach` | `detached` | **the whole relation** | one `detach` per row |
| `updateExistingPivot($id, $attributes)` | `update_existing_pivot` | `synced` | only that id | one `update`, both pivot maps filled |
| `sync($ids, $detaching)` | `sync` | `synced` | **the whole relation** | `attach`, `detach` and `update` mixed |
| `syncWithoutDetaching($ids)` | `sync_without_detaching` | `synced` | **the whole relation** | `attach` and `update` only |
| `toggle($ids)` | `toggle` | `synced` | **the whole relation** | `attach` and `detach` mixed |

Four of the six record `event = synced`. The data model publishes three event names for six APIs,
so the method that was actually called travels in `metadata.api` — which is inside the canonical
payload, and therefore as tamper-evident as the change itself. `syncWithoutDetaching()` is
deliberately not delegated to `sync($ids, false)`: it calls Laravel's `sync()` directly so the entry
names the call your application made, not the one the framework spells it as.

> ⚠️ **Warning.** The four operations marked *the whole relation* pass a null scope, because they
> can reach rows the caller never named — a `sync()` detaches everything outside its list. That
> means two full reads of the pivot table around the operation. On a relation with many thousands
> of rows, that is the dominant cost of auditing it.

`RelationCapture::recording()` is consulted **before** the first photograph, so an installation with
`enabled` off — or code inside `Sentinel::withoutAuditing()` — pays for neither read.

---

## What an entry says

A relation entry is an ordinary audit entry with `audit_type = 'relation'`. Its subject is the
parent, so it lands in that model's own trail and orders alongside its attribute changes.

```php
$order->products()->attach($widget->id, ['quantity' => 2]);
$order->products()->sync([$gadget->id, $gizmo->id]);   // detaches the widget, attaches two

$audit = $order->audits()->get()->last();

$audit->audit_type;                             // 'relation'
$audit->event;                                  // 'synced'
$audit->metadata['api'];                        // 'sync'
count($audit->getAttribute('changes'));         // 3 — one detach, two attaches
```

> 📌 **Note.** Read the column with `getAttribute('changes')`. Inside an Eloquent model,
> `$audit->changes` is the framework's own dirty-attribute set, not the column.

**One business call is one entry.** A `sync()` that attaches two and detaches one writes a single
entry with three lines. Internally `sync()` and `toggle()` call `attach()`, `detach()` and
`updateExistingPivot()`, and those inner calls write nothing: a re-entrancy counter on the relation
**instance** — not a static — suppresses them, so two separate statements each audit normally.

A relation entry that ends up with **no lines** — a `sync()` that changed nothing — is discarded by
the `FilterUnchanged` pipeline stage before it reaches the ledger. It consumes no sequence number
and leaves no gap in the chain; an `AuditDiscarded` event is dispatched instead.

```php
$order->products()->sync([$widget->id]);
$order->products()->sync([$widget->id]);   // identical: no entry, no sequence

$order->audits()->get()->pluck('sequence')->all();   // [1] — not [1, 2]
```

---

## What a line says

The `changes` column of a relation entry holds a list of **relation lines**, one per related record.
The value object is `Data\RelationLine`.

| Key | Type | What it holds |
|---|---|---|
| `relation` | `string` | the relation name as declared on the parent |
| `operation` | `attach` · `detach` · `update` | what happened to **this record** |
| `related_type` | `string\|null` | the related model's morph class |
| `related_id` | `string\|null` | the related record's key, always rendered as a string |
| `pivot_before` | `array\|null` | the pivot columns before, minus the two foreign keys |
| `pivot_after` | `array\|null` | the pivot columns after |

`operation` describes the **effect on one record**, never the method. Most `attach` lines in a
real trail were produced by `sync()`, not by `attach()`. Code that maps `operation` back to an API
is reading the wrong field; `metadata.api` is the only thing that names the call.

### The pivot maps are three-valued

```php
$order->products()->attach($widget->id, ['quantity' => 2]);
$line = $order->audits()->get()->sole()->getAttribute('changes')[0];

$line['pivot_before'];   // null — the row did not exist
$line['pivot_after'];    // ['quantity' => 2, 'unit_price' => null]
```

- `null` — the pivot row did not exist.
- `[]` — it existed and carried nothing beyond the two foreign keys.
- a map — the columns it carried.

Collapsing `null` and `[]` when you render or export destroys a real distinction. On a pivot table
that holds only the two foreign keys, an attach produces `pivot_before: null` and `pivot_after: []`.

### Everything on the intermediate table travels

The photograph is `select *` on the pivot table, and only the two pivot key names are removed
afterwards. Any other column on that table — a surrogate `id`, an internal flag, timestamps, and on
a `morphToMany` the discriminator column (`categorizable_type`) — lands in `pivot_before` and
`pivot_after`, and therefore inside the hash. Columns you never named in `withPivot()` are included.

> 🔒 **Security.** That is the rule the package follows everywhere — everything is recorded unless
> you say otherwise — but it means a pivot table carrying anything sensitive needs an explicit
> declaration. See [Protecting a pivot column](#protecting-a-pivot-column).

---

## Reading it back

Three filters ask about relations, plus one convenience on the model.

```php
use ElPandaPe\Sentinel\Enums\RelationOperation;
use ElPandaPe\Sentinel\Facades\Sentinel;

// Everything one relation has ever done, as a composable query:
$order->relationHistory('products')->get();

// Only the times something was added to it:
$order->relationHistory('products')->whereOperation(RelationOperation::Attach)->get();

// Any of several; naming it twice accumulates rather than replaces:
$order->relationHistory('products')->whereOperation('attach', 'detach')->get();

// When was this product specifically removed from the order?
Sentinel::audits()
    ->whereRelation('products')
    ->whereRelated($widget)
    ->whereOperation(RelationOperation::Detach)
    ->get();

// Composes with every other filter and with the timeline:
Sentinel::timeline()->for($order)->whereRelation('products')->get();
```

| Method | Narrows by | Refuses when |
|---|---|---|
| `whereRelation(string)` | the relation name on the line | the ledger does not declare `Filter::Relation` |
| `whereRelated(object\|string, $id = null)` | the `related_type` + `related_id` pair | a model with no key (`QueryException::unsavedModel`), or a string type with no id (`QueryException::missingKey`), or an undeclared `Filter::Related` |
| `whereOperation(RelationOperation\|string ...)` | `attach` / `detach` / `update` | a string that is none of the three — `QueryException`: *"[deleted] is not something that happens to a relation. Expected: attach, detach, update."* |
| `relationHistory(string)` | shorthand for `Sentinel::audits()->for($this)->whereRelation($relation)` | as `whereRelation()` |

**The three narrow the same line.** They travel to the driver as one `Query\RelationCriteria`, and
an entry answers only when a **single line** satisfies every part at once. Asked as three
independent predicates, the query above would also be answered by an entry that attached that
product and detached something else — a different fact.

> ⚠️ **Warning.** `Filter::Relation`, `Filter::Related` and `Filter::Operation` are deliberately not
> in `Filter::assumed()`. A ledger driver that does not name them in `supportedFilters()` throws
> `LedgerException` — *"… cannot filter by relation, so whereRelation() is not part of the query it
> answers."* — rather than silently dropping the criterion. See
> [The Ledger contract](../11-extending/01-the-ledger-contract.md).

### What these filters will not answer

- `whereFieldChanged('products')` finds nothing, and returns an empty set rather than complaining.
  The field predicate reads each change element's `path`; a relation line has `relation` and
  `operation` and no `path` at all. A field is an attribute; a relation is not one. See
  [Field history](../06-reading/04-field-history.md).
- `whereEvent('synced')` is not "syncs". `toggle()`, `syncWithoutDetaching()` and
  `updateExistingPivot()` all record `synced` too. There is no filter over `metadata`, so the only
  way to separate them is to read `metadata['api']` on the entries you got back.

---

## Rendering it

A relation entry answers `diff()` with the same `Diff` type as any other entry, so a caller walking
a mixed trail never has to branch. This is **presentation only** — the entry stores lines, and the
diff is derived on read.

```php
$audit->diff()->toArray();
// [['path' => '/products/7', 'op' => 'add', 'old' => null, 'new' => ['quantity' => 2, …]]]

$audit->diffFor('products');   // narrows exactly as it does under an attribute
```

The pointer is `/{relation}/{related_id}`; `attach` reads as `add`, `detach` as `remove`, `update`
as `replace`, and the old/new values are the two pivot maps. `Presentation\AuditPresenter` renders
the sentence plus the touched records beneath it:

```php
use ElPandaPe\Sentinel\Presentation\AuditPresenter;

echo app(AuditPresenter::class)->entry($audit);
// Someone synced Order #1 · products
//   + Product #2
//   - Product #7
//   ~ Product #9
```

`+` attached, `-` detached, `~` kept its place and its pivot changed. Every string comes from
`resources/lang/{en,es}/sentinel.php`. An entry carrying no lines renders as the sentence alone.
See [Presenting and serializing](../06-reading/07-presenting-and-serializing.md).

---

## The projection table: an index, not the evidence

Each line is also written as a row of `sentinel_audit_relations`, in the same transaction that
seals the entry, by `Ledger\RelationProjection`. That table is what the three filters query — it
carries three indexes: on `audit_id`, on `(related_type, related_id, audit_id)`, and on
`(relation, audit_id)`.

`Models\AuditRelation` exposes a row — `audit_id`, `relation`, `operation`, `related_type`,
`related_id`, `pivot_before`, `pivot_after` — with no key of its own, no timestamps and no foreign
key to the entry.

```php
foreach ($audit->relations as $row) {
    $row->operation;      // 'attach'
    $row->pivot_after;    // cast back to an array
}
```

**What it cannot answer.** A projection row is an index over evidence, not evidence:

- Deleting rows there leaves `verifyIntegrity()` returning `true`. The lines the chain covers are
  inside the entry; the table is derivable from them.
- The table is not partitionable — `sentinel:partitions` accepts `audits` and `access_log` only.
- There is no `models.audit_relation` config key. Unlike the entry model, the projection model
  cannot be swapped through configuration.
- Rows can outlive their entry. There is no cascade. `Retention\Cascade` deletes lines *through*
  the entries inside one transaction per slice; anything that deletes from `sentinel_audits` by
  another route leaves lines behind. See
  [Retention and pruning](../08-lifecycle/01-retention-and-pruning.md).

The divergence between the two is real, and it is closed by a separate pass:

```bash
php artisan sentinel:verify --projections
```

`Integrity\Projections` re-derives the rows from each entry and compares. A mismatch is reported as
`IntegrityBreak::ProjectionMismatch` — its own kind of defect, never as a broken chain, because
calling it one would be a lie about what the hash protects. A row belonging to no line is as much a
divergence as a line with no row. Redacted entries are skipped: redaction destroys the lines and the
rows together, so comparing them would report every redacted relation entry as broken forever.

> 🧪 **Verify it.** `php artisan sentinel:verify --projections` runs the chain walk first and the
> projection check after. It reads a second table, which is why it is asked for rather than assumed.

---

## What the hash covers, and what it does not

**Covered.** The lines live in `changes`, and `metadata.api` in `metadata`. Both are columns of
`canonical(core)` for `payload_version` 1, so editing a `related_id`, reordering the lines, changing
a pivot value or rewriting the recorded API all break the entry's hash.

**Not covered.** `sentinel_audit_relations`. Emptying it changes nothing about the evidence.

```php
use ElPandaPe\Sentinel\Models\Audit;
use Illuminate\Support\Facades\DB;

$order->products()->attach($widget->id);
$audit = $order->audits()->get()->sole();

DB::table('sentinel_audit_relations')->delete();
$audit->relations()->count();     // 0
$audit->verifyIntegrity();        // true — the table is an index, not the fact

$lines = $audit->getAttribute('changes');
$lines[0]['related_id'] = '999';
DB::table('sentinel_audits')->where('id', $audit->id)
    ->update(['changes' => json_encode($lines, JSON_THROW_ON_ERROR)]);

Audit::query()->findOrFail($audit->id)->verifyIntegrity();   // false
```

That separation is deliberate: rebuilding an index must not be indistinguishable from tampering.

### Why line order is fixed at capture

`RelationLine::canonical()` sorts the lines by `relation`, then `related_type`, then `related_id`,
then `operation`, and `ksort`s each pivot map. The `NormalizeData` pipeline stage deliberately does
**not** touch `changes`, so this ordering at capture is the only thing that makes two runs of the
same `sync()` hash identically across engines and runs.

> ⚠️ **Warning.** `changes`, `payload_version`, `sequence`, `hash` and `previous_hash` are
> load-bearing. Changing the line shape, the key order, or the sort would change every relation
> entry's hash: it requires a `payload_version` bump and a backwards-compatibility path for entries
> already sealed. An importer or third-party driver that reorders lines produces entries that no
> longer verify. See [Canonicalization](../07-integrity/03-canonicalization.md).

---

## Protecting a pivot column

A pivot has no class of its own to declare anything on, so the **parent** declares for it, using the
same three properties it already uses for its own columns.

```php
use ElPandaPe\Sentinel\Concerns\Auditable;

final class Patient extends Model
{
    use Auditable;

    protected array $auditRedact  = ['relationship'];       // masked, irreversibly
    protected array $auditEncrypt = ['consent_reference'];  // recoverable with the key

    public function carers(): BelongsToMany
    {
        return $this->belongsToMany(Carer::class)
            ->withPivot('relationship', 'consent_reference');
    }
}

$patient->carers()->attach($carer->id, [
    'relationship' => 'daughter',
    'consent_reference' => 'CR-4471',
]);

$audit = $patient->audits()->get()->sole();
$line = $audit->getAttribute('changes')[0];

$line['pivot_after']['relationship'];       // masked, not 'daughter'
$line['pivot_after']['consent_reference'];  // ciphertext
$audit->encryption['fields'];               // ['consent_reference']
$audit->verifyIntegrity();                  // true — the hash is over the ciphertext
```

`Security\Fields::protect()` matches by key name at any depth on a `changes` element that carries no
`path` — which is exactly the shape of a relation line — so it reaches inside both pivot maps
without any relation-specific code. `$auditHash` works the same way, for a column you need change
detection on but must never read back.

Three things follow:

- **Both sides are protected**, not just the new one. After an `updateExistingPivot()`,
  `pivot_before` is masked or encrypted too.
- **The projection carries the protected value**, never the plaintext — it is derived after the
  pipeline has run.
- **A name in `security.redaction.fields` (or `.encryption.` / `.hashing.`) applies everywhere.**
  `Support\Config` unions the global list with the model's declaration, so a globally listed name
  also masks an identically-named pivot column on an unrelated relation. Prefer the model property
  for a column that is sensitive on one relation only.

The policy is resolved from the entry's `subject_type`, which for a relation entry is the parent —
declaring on the *related* model has no effect. See
[Protecting sensitive data](../05-pipeline-and-security/02-protecting-sensitive-data.md) and
[Encryption and the keyring](../05-pipeline-and-security/03-encryption-and-the-keyring.md).

---

## When there is no pivot at all

A `belongsTo` foreign key moving is also a relation change, on both ends, and there is no relation
object to wrap. `Capture\ParentCapture` runs on the child's `updated` event instead. It is opt-in,
declared on the **child**:

```php
use ElPandaPe\Sentinel\Concerns\Auditable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Invoice extends Model
{
    use Auditable;

    /** the belongsTo on THIS model => the name the PARENT gives that collection */
    protected array $auditParents = ['customer' => 'invoices'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}

$invoice->update(['customer_id' => $acme->id]);
```

One hand-over writes **three** entries: the invoice's own `updated` entry, a `detach` on the customer
it left, and an `attach` on the customer it joined. `subject_id` holds one subject, so the two ends
cannot share an entry. Under an HTTP request or a business transaction, `request_id` and
`transaction_id` are what tie the three together.

The lines have the shape you already know, with `pivot_before` and `pivot_after` both `null` —
there is no pivot — and `metadata.api` is `foreign_key`: no method was intercepted, the fact is that
the column moved.

```php
$oldCustomer->relationHistory('invoices')->whereOperation('detach')->get();   // 1
$acme->relationHistory('invoices')->whereOperation('attach')->get();          // 1
Sentinel::audits()->whereRelated($invoice)->get();                            // both ends
```

| Case | What happens |
|---|---|
| The key did not move | Nothing is written, however much else the update changed |
| One end is `null` | A single entry, on the end that exists |
| The old parent has since been deleted | It still gets its entry: when the `belongsTo` points at the primary key, the foreign key **is** the name and no query runs |
| The `belongsTo` points at a non-primary column | Each end costs one read, because `subject_id` must be a primary key; an end that resolves to nobody is skipped silently |
| The child is created or deleted | **No relation entry.** `ParentCapture` runs only on `updated`. The fact lives in the child's own `created` / `deleted` entry, which carries the foreign key |
| `$auditParents` names a `morphTo`, a `hasMany`, or a relation that no longer exists | `ConfigurationException` — *"Sentinel cannot audit the parent side of […]: `$auditParents` names belongsTo relations…"* |

> ⚠️ **Warning.** That configuration error is thrown at **write** time, not at boot. The relation is
> resolved lazily inside the observer, and it is resolved before the foreign key is compared — so
> **any** save that fires `updated` on the child raises it, not only one that moves the key. A
> `$auditParents` entry that goes stale after a refactor fails in production, on a save, not in your
> test suite unless a test updates that model.

To read the entries back with `relationHistory()` the parent model must use `Auditable` too — that
method and `audits()` come from the trait.

---

## Restoring a relation

`$audit->restoreRelationship('products')` puts a relation back the way that entry portrayed it: what
it attached is attached again with the pivot it left behind, what it detached stays detached.

```php
$order->products()->sync([$widget->id => ['quantity' => 2]]);
$entry = $order->audits()->get()->last();

$order->products()->detach();

$result = $entry->restoreRelationship('products');

$result->applied;                  // ['products/7']
$result->reason('products/7');     // an Omission when that line was skipped
$result->entry?->source_audit_id;  // === $entry->id
```

History stays append-only. The replay runs inside `withoutAuditing()` so the re-attaches write
nothing of their own, and **one** new entry of the restore type is written afterwards, carrying its
own relation lines and `source_audit_id`. Nothing is deleted, rewritten or reordered.

It refuses rather than throwing, with an `Enums\Omission`: `SubjectMissing`, `EntryStateless`,
`EntryTampered`, `EntryRedacted` and `Cancelled` refuse the whole restoration; `RelatedMissing`
skips one related record that is gone, `UnknownField` every line when the model no longer declares
that relation, and `Unchanged` reports that the relation already looks the way the entry left it —
in which case nothing is written. See [Restoring state](../06-reading/08-restoring-state.md).

---

## 🐘 Engine notes

> 🐘 **Engine.** **MySQL** reorders the keys of a JSON object on the way in — by length, then
> alphabetically — so a pivot map read back does not carry the key order it was written with.
> Compare pivot *content*, never pivot key order. The chain is unaffected: the canonicaliser sorts
> before hashing.

> 🐘 **Engine.** **PostgreSQL** stores `changes` as `jsonb` (which sorts an object's keys) and
> `pivot_before` / `pivot_after` as `json` (which keeps the text as it arrived). The same pivot map
> therefore comes back from the two columns in two different orders, which is why
> `Integrity\Projections` decodes and deep-sorts before comparing instead of comparing text.

> 🐘 **Engine.** On all three engines the projection comparison reduces both sides to a multiset
> keyed by the six line fields and counted, never by position: the table has no key of its own and a
> null `related_type` sorts differently on each engine. `related_id` is `string(64)`, like
> `subject_id`, so an int, a UUID and a ULID all fit.

> 🐘 **Engine.** A ledger driver that cannot write the lines and the entry in one transaction may
> omit the projection write entirely: the table is rebuildable from the entry, which is precisely
> why it is not part of the canonical payload. See
> [Indexes and JSON](../10-database-engines/05-indexes-and-json.md).

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `sync()` runs and no entry appears | The parent model does not use `Concerns\Auditable`, or overrides `newBelongsToMany()` / `newMorphToMany()` without returning the audited subclass | Add the trait to the model that owns the relation; if you need your own factory, return `AuditedBelongsToMany` / `AuditedMorphToMany` from it |
| An entry exists on the model you attached *from* but not the one you attached *to* | The subject is the parent the call was made on | Declare and call the inverse relation on the other model if you need that side too |
| `whereEvent('synced')` returns more than syncs | `sync`, `syncWithoutDetaching`, `toggle` and `updateExistingPivot` all record `synced` | Read `metadata['api']` on the results; the enum publishes three names for six APIs |
| `whereFieldChanged('products')` returns nothing, with no error | The predicate reads each change's `path`, which relation lines do not carry | Use `whereRelation('products')` |
| `LedgerException: … cannot filter by relation` | The configured ledger driver does not declare `Filter::Relation` / `Related` / `Operation` | Declare the three in `supportedFilters()` and compile the whole `RelationCriteria` as one existence check |
| `QueryException: [deleted] is not something that happens to a relation` | An event name was passed to `whereOperation()` | Pass `attach`, `detach`, `update`, or the `RelationOperation` case |
| The pivot payload carries columns you never declared in `withPivot()` | The photograph is `select *` on the pivot table, minus the two foreign keys | Drop the column, or declare it in `$auditRedact` / `$auditEncrypt` / `$auditHash` on the parent |
| A polymorphic relation's lines carry a `…_type` column inside the pivot maps | Only the two pivot key names are removed; the morph discriminator is a third column | Ignore it on read, or protect it if it matters — `related_type` on the line already says the same thing |
| `ConfigurationException` about `$auditParents` in production, never in tests | The relation is resolved lazily on the child's `updated` event, not at boot | Cover the model in a test that updates it; a stale map fails on the next save that fires `updated`, whether or not the key moved |
| One `belongsTo` change produced three entries | `subject_id` holds one subject, and a hand-over has two ends plus the child | Correlate on `request_id`, or wrap the change in a business transaction |
| A very large `sync()` is much slower than expected | `sync`, `syncWithoutDetaching`, `toggle` and argument-less `detach()` read the entire relation twice | Narrow the call, or turn auditing off for the bulk path with `Sentinel::withoutAuditing()` |
| A pivot table with two rows for the same pair produces one line | The photograph is keyed by related id, so the last row read wins | Add a unique constraint on the pair; `attach()` on an already-attached id inserts a duplicate |
| `$audit->relations` is empty but `verifyIntegrity()` is `true` | Projection rows were deleted or never written; the table is not covered by the hash | `php artisan sentinel:verify --projections`; the entry's `changes` is still the truth |
| Relation entries are missing under an asynchronous mode right after the call | The entry settles later; `record()` returns `null` in the request | See [Performance modes](../09-operations/01-performance-modes.md) |

---

## ✅ Best practices

✅ **Do** — put the trait on the model that owns the relation and leave the call sites alone. The
whole point of wrapping the relation object is that nothing in your application changes.

```php
final class Order extends Model
{
    use Auditable;   // this is the entire opt-in

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class)->withPivot('quantity');
    }
}
```

❌ **Don't** — reach for a bespoke helper, or override the relation factories without delegating.
There is no `auditAttach()`, and a lost override means the relation is unaudited with no warning.

```php
protected function newBelongsToMany(...$args): BelongsToMany
{
    return new BelongsToMany(...$args);   // silently un-audits every belongsToMany
}
```

✅ **Do** — read `metadata['api']` for the method and `operation` for the effect. Both are inside
the hashed payload, so both are tamper-evident.

```php
$audit->metadata['api'];                          // 'sync'
$audit->getAttribute('changes')[0]['operation'];  // 'attach'
```

❌ **Don't** — map `operation` back to a method name. Most `attach` lines were written by `sync()`,
which is exactly why the value describes the effect and not the call.

```php
$method = $line['operation'] === 'attach' ? 'attach()' : 'detach()';   // wrong most of the time
```

✅ **Do** — count lines when you want to know how many records an operation touched.

```php
count($audit->getAttribute('changes'));   // records touched
$audit->relations->count();               // the same, off the projection
```

❌ **Don't** — count entries. One call is one entry however many records it moved, so a count of
entries is a count of operations.

```php
$order->relationHistory('products')->get()->count();   // syncs, not products
```

✅ **Do** — chain the three relation filters when you mean one line. They compile into a single
existence check, so the answer is about one record and one thing that happened to it.

```php
Sentinel::audits()
    ->whereRelation('products')
    ->whereRelated($widget)
    ->whereOperation(RelationOperation::Detach)
    ->get();
```

❌ **Don't** — ask them as separate questions and intersect the results. An entry that attached this
product and detached another would answer "when was it detached" — a different fact.

```php
$byRelation = Sentinel::audits()->whereRelation('products')->get();
$byRecord   = Sentinel::audits()->whereRelated($widget)->get();   // wrong question, silently
```

✅ **Do** — declare sensitive pivot columns on the **parent** model. A pivot has no class to declare
on, and the pipeline resolves the policy from the entry's `subject_type`, which is the parent.

```php
final class Patient extends Model
{
    use Auditable;

    protected array $auditEncrypt = ['consent_reference'];
}
```

❌ **Don't** — declare them on the related model, or assume a global config name is scoped. A name
in `security.redaction.fields` masks that column on every model that has one.

```php
final class Carer extends Model
{
    protected array $auditEncrypt = ['consent_reference'];   // never consulted for the pivot
}
```

✅ **Do** — treat `sentinel_audit_relations` as an index and check it on its own schedule.

```bash
php artisan sentinel:verify --projections
```

❌ **Don't** — quote a projection row in a compliance artefact, or read a missing row as tampering.
The chain covers the lines inside the entry; the table is derived from them and is not evidence.

```php
$row = $audit->relations->sole();   // fine for querying, not for proving
```

✅ **Do** — declare `$auditParents` on the child, keyed by the `belongsTo` and valued by the name the
**parent** gives that collection. The entry hangs off the parent, so its line needs the parent's name
for it.

```php
protected array $auditParents = ['customer' => 'invoices'];
```

❌ **Don't** — reverse the map, or name anything that is not a plain `belongsTo`. A `morphTo` moves
the parent's type as well as its key and is refused by name rather than half-audited — on a save, in
production.

```php
protected array $auditParents = ['invoices' => 'customer'];   // throws on the next update of an Invoice
```

---

**See also:** [What gets audited](01-what-gets-audited.md) · [Diffs](03-diffs.md) ·
[What a model declares](../02-getting-started/03-what-a-model-declares.md) ·
[Filters reference](../06-reading/02-filters-reference.md) ·
[Restoring state](../06-reading/08-restoring-state.md) ·
[The hash chain](../07-integrity/01-the-hash-chain.md) · [Verification](../07-integrity/06-verification.md) ·
[Protecting sensitive data](../05-pipeline-and-security/02-protecting-sensitive-data.md) ·
[Schema](../99-reference/03-schema.md) · [Enums](../99-reference/04-enums.md)
