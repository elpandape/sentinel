# 📥 What gets audited

> Which Eloquent events produce an audit entry, which produce nothing, and the writes Sentinel cannot see at all.

**On this page:** [The blind spot](#the-blind-spot-a-mass-statement-fires-nothing) · [The events observed](#the-events-the-trait-observes) · [The six entry kinds](#the-six-entry-kinds) · [Soft deletes](#soft-deletes-restores-and-force-deletes) · [Changed nothing](#updates-that-changed-nothing) · [Never audited](#what-is-never-audited-at-all)

---

## The blind spot: a mass statement fires nothing

Start here, because it is the one thing that surprises everybody and the one thing every auditing
package in this ecosystem has to answer for.

```php
use App\Models\Invoice;

Invoice::query()->where('status', 'draft')->update(['status' => 'void']);
Invoice::query()->where('created_at', '<', now()->subYears(7))->delete();
```

Both statements change rows. Neither produces an audit entry, and neither produces an *empty* one —
there is simply nothing in the trail to say it happened.

The reason is in the framework, not in Sentinel. `Illuminate\Database\Eloquent\Builder::update()`
hands its values straight to the base query builder; `Builder::delete()` does the same, or on a
soft-deleting model runs the `onDelete` callback `SoftDeletingScope` installed, which is itself a
query-builder `update()`. No model is hydrated, so no model event is fired, so `Capture\ModelObserver`
is never called.

Sentinel closes the gap per query, and only per query:

```php
Invoice::query()
    ->where('status', 'draft')
    ->auditing()
    ->update(['status' => 'void']);
```

`auditing()` is a macro registered on the Eloquent builder by `SentinelServiceProvider`. A query that
does not call it costs exactly what it cost before: no listener, no extra statement, no branch. There
is no global switch that turns mass auditing on, and that is a decision rather than an omission —
intercepting every mass update would turn a one-line statement into thousands of inserts nobody asked
for, on queries that have nothing to do with auditing.

The model must declare itself auditable. `Mass\AuditedQuery::guard()` throws
`Exceptions\ConfigurationException::notAuditable` for a model that uses neither the `Auditable` trait
nor `Contracts\Auditable`, because the model's `$auditExclude` / `$auditRedact` / `$auditEncrypt` /
`$auditHash` are what say which columns of the criteria may be written down.

> ⚠️ **Warning.** The exception message names only the trait ("does not use the Auditable trait"). A
> model that implements `Contracts\Auditable` passes the check; the message just does not say so.

Everything about what a mass entry records — the three modes, `criteria`, `affected_rows`, the
per-engine meaning of the row count — is in [Mass operations](05-mass-operations.md).

> 🧪 **Verify it.** In `php artisan tinker`, run the bulk update against a model you audit and then
> count the trail: `Invoice::query()->whereKey($id)->update(['status' => 'void']);` followed by
> `Invoice::find($id)->audits()->count();` — the count does not move. Repeat with `->auditing()`
> before `update()` and it does.

---

## The events the trait observes

`use ElPandaPe\Sentinel\Concerns\Auditable;` runs `bootAuditable()`, which registers five Eloquent
events with `static::registerModelEvent()`. Four of them write; the fifth writes nothing.

| Eloquent event | Observed | What happens |
|---|---|---|
| `created` | ✅ | One `created` entry. |
| `updating` | ✅ | **Writes nothing.** Vets declared state moves — see below. |
| `updated` | ✅ | One `updated`, `restored` or `transition` entry, plus any `belongsTo` hand-over entries. |
| `deleted` | ✅ | One `deleted` entry — *unless* the model is force deleting, in which case nothing. |
| `forceDeleted` | ✅ | One `force_deleted` entry. |
| `saving`, `saved`, `creating`, `deleting` | ❌ | Nothing. |
| `restoring`, `restored`, `trashed`, `forceDeleting` | ❌ | Nothing. `restored` is derived from `updated` instead. |
| `retrieved`, `replicating` | ❌ | Nothing. Reading a record is never an audit entry. |

The listener is registered as `[ModelObserver::class, $event]` — a class-callable, not an instance —
so the container resolves the observer, and with it the scoped ledger, at the moment the event fires
rather than at boot. `Model::observe()` is not used because it instantiates the model, which cannot
happen while the model is booting.

### `updating` is the only place a move can be refused

Nothing is written on `updating`. `ModelCapture::vet()` walks the columns the model declared in
`$auditTransitions`, and for each one whose value actually moved calls `Transitions\Machine::allow()`.
A model implementing `Contracts\DeclaresTransitions` gets to answer; returning `false` raises
`Transitions\IllegalTransition` and the save is abandoned.

This happens before the row is written on purpose. Refusing on `updated` would leave the record
holding a state the trail says never happened. A model that declares no machine consents to
everything — Sentinel records the transition, it does not govern the workflow. See
[State transitions](08-state-transitions.md).

> 📌 **Note.** `vet()` returns immediately when `Sentinel::isRecording()` is false. Auditing that is
> paused or switched off does not refuse moves either: refusing a move it would not have recorded
> would be governing the workflow, which is the one thing this package does not do.

---

## The six entry kinds

Four writing Eloquent events produce six **(`event`, `audit_type`) pairs**: `updated` alone accounts
for three of them, because `Capture\ModelObserver` and `Capture\ModelCapture` derive `restored` and
`transition` from it rather than listening for a separate event.

Six pairs is not six audit types. Five of the six carry `audit_type = 'model'` and the sixth carries
`transition`, and the whole table below is two of the nine values enumerated in
[The audit record](../01-concepts/02-the-audit-record.md#kinds-of-entry). Count pairs here, and
`audit_type` values there.

| `event` | `audit_type` | Written when | `before` | `after` | Default severity |
|---|---|---|---|---|---|
| `created` | `model` | The row is inserted | `null` | The full state | `info` |
| `updated` | `model` | Any audited attribute moved | The state before | The state after | `info` |
| `transition` | `transition` | An update moved a column named in `$auditTransitions` | The state before | The state after | `info` |
| `deleted` | `model` | `delete()`, soft or hard | The state leaving | `null` | `notice` |
| `restored` | `model` | The update that cleared the deletion mark | The state in the bin | The state restored | `info` |
| `force_deleted` | `model` | `forceDelete()` | The last known state | `null` | `warning` |

Severity comes from `sentinel.severity.events` with `sentinel.severity.default` behind it, and a
model's `$auditSeverity` beats both. The full state — not the dirty set — is what a snapshot holds;
[Snapshots](02-snapshots.md) explains why and what filters it.

### `transition` is an `updated` wearing a different name

`ModelCapture::moved()` looks for a declared transition column among the diff paths. If it finds one,
the entry's `audit_type` and `event` both become `transition` and `metadata.transition.attribute`
names the column. The whole diff still travels on that one entry — one save is one thing the
application did, and splitting it into a transition plus an update would invent a second fact.

Only an `updated` is re-labelled this way. A restore that also moves the state column stays a
`restored`, because calling it a state change would lose the more important of the two facts.

> 📌 **Note.** A model event **does** produce `transition` entries — that is what the row above
> describes — but only through `$auditTransitions`. A model that declares no transition column never
> writes one from an Eloquent event. The other producer is `Sentinel::transition()`, whose
> `Transitions\TransitionBuilder` writes the same `audit_type` for a move the application states
> outright, with no save behind it. Both are in [State transitions](08-state-transitions.md).

### `null` and `[]` are different answers

`before`, `after` and `changes` are all nullable, and the two empty-ish values do not mean the same
thing:

| Value | Means |
|---|---|
| `null` | Does not apply to this event. A creation has no `before`; a deletion has no `after`. |
| `[]` | It applied, the comparison ran, and it found nothing. |

`ModelCapture::changes()` returns `null` only when both sides of the pair are absent. Consumers must
not coalesce the two — the distinction is audited information.

### Other capture paths, other `audit_type` values

Model events are one source among several. Each writes through the same pipeline and the same ledger:

| `audit_type` | Written by | Page |
|---|---|---|
| `model` | `Capture\ModelCapture` | This page |
| `relation` | `Capture\RelationCapture`, `Capture\ParentCapture` | [Relationship auditing](04-relationships.md) |
| `mass` | `Mass\MassCapture` | [Mass operations](05-mass-operations.md) |
| `transition` | `Capture\ModelCapture`, `Transitions\TransitionBuilder` | [State transitions](08-state-transitions.md) |
| `custom` | `Capture\PendingEvent` | [Custom and authentication events](07-custom-and-authentication-events.md) |
| `auth` | `Capture\AuthenticationSubscriber` | [Custom and authentication events](07-custom-and-authentication-events.md) |
| `restore` | `Restore\Restorer` | [Restoring state](../06-reading/08-restoring-state.md) |
| `security` | `Redaction\Redactor`, `Security\Rekeyer` | [Redaction and tombstones](../08-lifecycle/04-redaction-and-tombstones.md) |
| `access` | `Compliance\AccessLog` | [Compliance mode](../08-lifecycle/05-compliance-mode.md) |

---

## Soft deletes, restores and force deletes

`restored` and `force_deleted` only ever exist on a model that uses `Illuminate\Database\Eloquent\SoftDeletes`.
The `Auditable` trait neither requires it nor adds it: `ModelObserver` asks `method_exists($model, 'getDeletedAtColumn')`
and `method_exists($model, 'isForceDeleting')`, and a model without them can only produce `created`,
`updated`, `transition` and `deleted`.

```php
use App\Models\Invoice;   // uses Auditable and SoftDeletes

$invoice = Invoice::query()->create(['status' => 'draft']);  // created
$invoice->update(['status' => 'sent']);                      // updated (or transition)
$invoice->delete();                                          // deleted
$invoice->restore();                                         // restored
$invoice->forceDelete();                                     // force_deleted

$invoice->audits()->pluck('event')->all();
// ['created', 'updated', 'deleted', 'restored', 'force_deleted']
```

### A force delete writes one entry, not two

`SoftDeletes::forceDelete()` calls `delete()` with `$forceDeleting` set, so Eloquent fires `deleted`
on its way to `forceDeleted`. `ModelObserver::deleted()` returns early when `isForceDeleting()` is
true, so only the second one becomes an entry. That holds whether the record was already in the bin
or not.

### A restore is detected structurally, not by intent

By the time Eloquent fires `restored`, `save()` has already called `syncOriginal()` and the state the
record had in the bin is gone. So Sentinel does not listen to `restored` at all. `ModelObserver::restorePoint()`
asks two questions of the `updated` event instead:

1. Is `getRawOriginal($deletedAtColumn)` non-null?
2. Is `getAttribute($deletedAtColumn)` null?

Both yes means this update cleared the deletion mark, and `before` is built from `getRawOriginal()` —
the whole state as it sat in the bin.

> ⚠️ **Warning.** The test is structural, so **any** save with that shape is a restore as far as the
> trail is concerned. `$invoice->update(['deleted_at' => null])` writes a `restored` entry. Sentinel
> cannot tell it from a real `restore()` call and does not try.

The inverse also holds and is easy to miss: moving `deleted_at` from one timestamp to another (a
re-delete) leaves the current value non-null, so it is a plain `updated`.

### Restores that write nothing

```php
use App\Models\Invoice;

$invoice->restore();               // already not trashed: deleted_at is already null,
                                   // nothing is dirty, `updated` never fires, no entry

Invoice::onlyTrashed()->restore(); // the SoftDeletingScope macro — a query-builder update.
                                   // No model events, no entries, however many rows come back.

$invoice->restoreQuietly();        // wrapped in Model::withoutEvents(): nothing fires
```

> 📌 **Note.** `event = 'restored'` is Eloquent's soft-delete revival. `audit_type = 'restore'` is
> Sentinel putting a record back from a recorded entry — a different feature entirely, documented in
> [Restoring state](../06-reading/08-restoring-state.md).

---

## Updates that changed nothing

An `updated` whose comparison came back empty writes no entry at all. There are two distinct ways to
get there, and it is worth telling them apart when you are debugging a missing row.

**Nothing was dirty.** Eloquent fires no `updated` event, so Sentinel is never called:

```php
$invoice->update(['status' => $invoice->status]);   // no event, no entry
```

**Something moved, but nothing audited moved.** Eloquent fires `updated`, the snapshot pair is built,
the diff comes back `[]`, and `Pipeline\Stages\FilterUnchanged` discards the entry:

```php
// $auditExclude = ['internal_notes'] on the model
$invoice->update(['internal_notes' => 'chased twice']);   // entry discarded
```

`FilterUnchanged` runs **first** in the shipped pipeline, deliberately, on the plaintext. Two
ciphertexts of the same value never match — the IV is random — so a filter placed after
`EncryptSensitiveData` would report every field as changed and never drop anything.

### What it filters, and what it does not

`FilterUnchanged::untouched()` is narrow on purpose:

| Entry | Dropped when |
|---|---|
| `audit_type = 'mass'` | `affected_rows === 0`. A statement whose `where` matched no row wrote no row. |
| `event = 'updated'` | `changes === []` |
| `audit_type = 'relation'` | `changes === []` — a `sync()` that attached and detached nothing did nothing. |
| Everything else | Never. |

A `created` with nothing comparable still happened. A `restored` whose one moved column is not
audited is still a restore. `changes === null` — the comparison did not apply — is never a discard.

### What a discard costs, and how you see it

A discarded entry never reaches the ledger, so it consumes no `sequence` and leaves no gap: the chain
never learns it existed. Verification will not flag it. The only announcement is an
`Events\AuditDiscarded`, and it carries identity only — `audit_type`, `event`, `subject_type`,
`subject_id`, `stage`, `reason` — because `FilterUnchanged` runs before redaction and encryption and
an event carrying the payload would be the exact route by which plaintext escaped the pipeline.

```php
use ElPandaPe\Sentinel\Events\AuditDiscarded;
use Illuminate\Support\Facades\Event;

Event::listen(function (AuditDiscarded $discarded): void {
    logger()->debug($discarded->message());
    // "The updated to App\Models\Invoice 42 changed nothing that is audited, so no entry was written."
});
```

The reason string is `FilterUnchanged::REASON`, which is `'unchanged'`.

### Keeping those entries

There is no switch. Dropping a stage means declaring `sentinel.pipeline` without it:

```php
// config/sentinel.php
'pipeline' => [
    // ElPandaPe\Sentinel\Pipeline\Stages\FilterUnchanged::class,  ← removed
    ElPandaPe\Sentinel\Pipeline\Stages\ResolveContext::class,
    ElPandaPe\Sentinel\Pipeline\Stages\ResolveTags::class,
    ElPandaPe\Sentinel\Pipeline\Stages\NormalizeData::class,
    ElPandaPe\Sentinel\Pipeline\Stages\MaskSensitiveData::class,
    ElPandaPe\Sentinel\Pipeline\Stages\EncryptSensitiveData::class,
    ElPandaPe\Sentinel\Pipeline\Stages\EnforcePolicies::class,
],
```

Those entries then take real sequence numbers in the chain. See
[The write pipeline](../05-pipeline-and-security/01-the-write-pipeline.md).

> ⚠️ **Warning.** `$invoice->touch()` **is** audited by default. `updated_at` is an ordinary attribute
> and reaches the snapshot like any other, so the diff is non-empty and the entry is written. If you
> do not want those, put your timestamp columns in `$auditExclude`.

---

## What is never audited at all

Nothing below is a bug, a configuration mistake, or something a flag turns on. Sentinel captures
Eloquent model events and the paths it explicitly wraps. Anything that goes round those is invisible.

| Write | Why nothing is recorded |
|---|---|
| `DB::table('invoices')->update([...])`, `DB::statement(...)`, `DB::update(...)` | No model, no event. There is no query listener and no database trigger. |
| Migrations and seeders using the schema or query builder | Same. A schema change is not an audit record in this package. |
| `Invoice::query()->update()` / `->delete()` / `->upsert()` without `auditing()` | Documented above. |
| `Invoice::query()->increment('views')` | A query-builder increment. No model is hydrated. |
| `saveQuietly()`, `updateQuietly()`, `deleteQuietly()`, `restoreQuietly()`, `forceDeleteQuietly()` | All wrap the call in `Model::withoutEvents()`, which suppresses every model event including Sentinel's listeners. |
| Anything inside `Model::withoutEvents(fn () => …)` | Same mechanism. |
| Reading a record | `retrieved` is not observed. Reads of your business models are never audited. |
| Anything while `Sentinel::isRecording()` is false | Every capture path asks first. See [Turning auditing off](../02-getting-started/04-turning-auditing-off.md). |
| A change to a model that does not `use Auditable` | No listeners were ever registered on it. |

Two that go the other way, and are easy to assume wrong:

```php
$invoice->increment('reminder_count');   // ✅ audited — Model::incrementOrDecrement() fires
                                         //    `updating` and `updated`, and syncs the original
                                         //    only afterwards, so `before` is correct

Invoice::destroy([1, 2, 3]);             // ✅ audited — destroy() loads each model and calls
                                         //    delete() on it, so events fire per record.
                                         //    Three entries, not one.
```

### The immutability guard has the same blind spot

`Models\Audit::booted()` registers `updating` and `deleting` hooks that throw
`Exceptions\ImmutableAuditException`. Every path the model exposes — `save()`, `update()`, `fill()->save()`,
`delete()`, `destroy()` — is refused once the row exists, and the exception names the entry it
refused to touch. A configured subclass of `Models\Audit` inherits the guard.

Because it runs on model events, it never sees a statement that does not go through the model:

```php
use ElPandaPe\Sentinel\Models\Audit;

Audit::query()->firstOrFail()->update(['event' => 'created']);   // ❌ ImmutableAuditException
Audit::query()->where('id', $id)->update(['event' => 'created']); // ⚠️ succeeds — no model event
```

That is not the layer that protects the trail. The hash chain is: an edited row no longer reproduces
its own hash, and every entry after it fails its link check. What catches a query-builder edit is
`Sentinel::verifyIntegrity()`, not the guard. See [The hash chain](../07-integrity/01-the-hash-chain.md)
and [Verification](../07-integrity/06-verification.md).

> 🔒 **Security.** Treat the guard as a defence against accident and the chain as the defence against
> intent. If entries must be unreachable by a query builder at all, give them their own connection
> with its own credentials — [A database of its own](../10-database-engines/07-a-database-of-its-own.md).

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| A bulk status change left no trace at all | `Builder::update()` fires no model event | Add `->auditing()` to that query — [Mass operations](05-mass-operations.md) |
| `->auditing()` throws `ConfigurationException` naming the model | The model uses neither `Auditable` nor `Contracts\Auditable` | Add the trait, or drop the `auditing()` call |
| An update wrote nothing and no exception was raised | Only excluded (or non-included) columns moved, so `FilterUnchanged` discarded it | Listen for `AuditDiscarded`; check `$auditExclude` / `$auditInclude` |
| Sequence numbers look contiguous although an entry "went missing" | A discard happens before the ledger, so it spends no sequence and leaves no gap | Correct behaviour — the chain never learns of a discarded entry |
| `$record->update(['deleted_at' => null])` produced a `restored` entry | Restore detection is structural: raw original non-null, current null | Expected; there is no way to make that update read as an `updated` |
| `Model::onlyTrashed()->restore()` restored rows but wrote nothing | That `restore()` is a `SoftDeletingScope` builder macro — a mass update | Load the models and call `restore()` on each, or use `->auditing()->update([...])` |
| `restore()` on a live record wrote nothing | `deleted_at` was already null, so nothing was dirty and `updated` never fired | Expected |
| A force delete produced only one entry, not `deleted` + `force_deleted` | `ModelObserver::deleted()` returns early while `isForceDeleting()` | Expected — one operation, one entry |
| `touch()` fills the trail with entries | `updated_at` is an ordinary audited attribute | Put the timestamp columns in `$auditExclude` |
| A `saved` listener's changes are missing from the entry | Eloquent fires `updated` inside `performUpdate()`, before `save()` fires `saved`; Sentinel photographs the model at `updated` | Mutate on `saving`/`updating` instead, or accept the second entry the listener's own save produces |
| An `Audit` row changed and nothing threw | The immutability guard runs on model events; a query-builder update bypasses it | `Sentinel::verifyIntegrity()` is what detects this |
| An entry appeared for a model you never expected to audit | A parent declared it in `$auditParents`, so a child hand-over wrote a `relation` entry against it | [Relationship auditing](04-relationships.md) |

---

## ✅ Best practices

✅ **Do** — opt a mass statement in explicitly, at the call site that matters. The trail is silent
about it otherwise, and silence is the failure mode an audit engine can least afford.

```php
use App\Models\Invoice;

Invoice::query()
    ->where('due_at', '<', now())
    ->auditing()
    ->update(['status' => 'overdue']);
```

❌ **Don't** — assume a bulk statement is covered because the model uses the trait. The trait
registers listeners for model events, and `Builder::update()` fires none.

```php
Invoice::query()->where('due_at', '<', now())->update(['status' => 'overdue']);
// Rows changed. Nothing in the trail says so, and nothing warned you.
```

✅ **Do** — use `Model::destroy()` or a loop when you need a per-record trail of a bulk deletion.
`destroy()` hydrates each model and calls `delete()`, so every record gets its own entry with its own
`before`.

```php
Invoice::destroy($ids);   // one `deleted` entry per record
```

❌ **Don't** — reach for the quiet variants to "avoid noise". They suppress every model event, which
means the audit entry as well, and nothing distinguishes the resulting gap from a write that never
happened.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

$invoice->updateQuietly(['status' => 'void']);   // no entry, no discard event, no trace

// If you genuinely want auditing off for a block, say so:
Sentinel::withoutAuditing(fn () => $importer->run());
```

✅ **Do** — treat `null` and `[]` as different answers when you read `before`, `after` or `changes`.
`null` means the event had no such state; `[]` means the comparison ran and found nothing.

```php
$audit->changes === null;   // no pair to compare — e.g. an entry with no snapshots
$audit->changes === [];     // compared, and nothing audited moved
```

❌ **Don't** — coalesce them in a presenter or an export. `$audit->changes ?? []` throws away the
difference between "this event has no diff" and "this event changed nothing", and that difference is
audited information.

```php
$rows = $audit->changes ?? [];   // two different facts, now indistinguishable
```

✅ **Do** — subscribe to `AuditDiscarded` in the environment where you are validating your
configuration. It is the only signal that an entry was built and then dropped.

```php
use ElPandaPe\Sentinel\Events\AuditDiscarded;

Event::listen(fn (AuditDiscarded $e) => logger()->debug($e->message()));
```

❌ **Don't** — remove `FilterUnchanged` from the pipeline to "see everything". Those entries take
real sequence numbers in the hash chain forever, and a chain full of links saying nothing happened is
harder to audit, not easier.

```php
// config/sentinel.php — every touch() and every excluded-column save now chains
'pipeline' => [ /* … without FilterUnchanged … */ ],
```

✅ **Do** — decide what a state column is before you ship, and declare it in `$auditTransitions` so
the move is recorded as a `transition` rather than as an ordinary edit.

```php
use ElPandaPe\Sentinel\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

final class Invoice extends Model
{
    use Auditable;

    /** @var list<string> */
    protected array $auditTransitions = ['status'];
}
```

❌ **Don't** — rely on the `Audit` model's immutability guard as your tamper defence. It refuses
`save()` and `delete()`; it cannot see a query-builder statement.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Models\Audit;

Audit::query()->where('id', $id)->update(['before' => null]);   // succeeds, silently
Sentinel::verifyIntegrity('global')->isIntact();                // false — this is the real defence
```

---

**See also:** [Snapshots](02-snapshots.md) · [Diffs](03-diffs.md) · [Relationship auditing](04-relationships.md) · [Mass operations](05-mass-operations.md) · [State transitions](08-state-transitions.md) · [What a model declares](../02-getting-started/03-what-a-model-declares.md) · [Turning auditing off](../02-getting-started/04-turning-auditing-off.md) · [The write pipeline](../05-pipeline-and-security/01-the-write-pipeline.md) · [Discarding entries](../05-pipeline-and-security/06-discarding-entries.md) · [Restoring state](../06-reading/08-restoring-state.md) · [Verification](../07-integrity/06-verification.md) · [Events and listeners](../09-operations/04-events-and-listeners.md) · [Enums](../99-reference/04-enums.md)
