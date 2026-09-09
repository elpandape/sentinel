# 🔎 Restoring state

> How to put a record back into the state an entry photographs — the whole state, named fields, or a
> many-to-many relation — as one new append-only entry that points back at the source.

**On this page:** [What a restore is](#what-a-restore-is) · [The two calls](#the-two-calls) · [The decision comes first](#the-decision-comes-first) · [Reading a RestoreResult](#reading-a-restoreresult) · [The fourteen reasons](#the-fourteen-reasons) · [The two events](#the-two-events) · [Who may restore](#who-may-restore) · [A restore is a write, and it is audited like one](#a-restore-is-a-write-and-it-is-audited-like-one) · [Restoring a relation](#restoring-a-relation) · [Restoring into a schema that moved](#restoring-into-a-schema-that-moved) · [What a restore refuses](#what-a-restore-refuses) · [A restoration is not a transition](#a-restoration-is-not-a-transition) · [Transactions, connections and the async modes](#transactions-connections-and-the-async-modes) · [⚠️ Pitfalls](#️-pitfalls) · [✅ Best practices](#-best-practices)

---

## What a restore is

An entry is a photograph of a record at a moment. Restoring it means going back to **that** moment —
not to whatever came before it.

The state an entry portrays is its `after`, falling back to its `before` only when `after` is empty
(`Restore\Planner::portrait()`). A deletion is the case that proves the rule: its `after` is null
because there was no record left to photograph, so the state it portrays is the one on its `before`.

Nothing is rewritten, deleted or reordered. A restoration appends **one** new entry with
`audit_type = 'restore'` and `event = 'restore'`, carrying `source_audit_id` pointing at the entry it
came from. `source_audit_id` and `metadata` are both inside the canonical payload
(`Integrity\CanonicalPayload::COLUMNS`), so the link and the decision are sealed by the hash like
everything else on the entry.

```
v1 created  →  v2 updated  →  v3 updated  →  v4 restore (source_audit_id = v1)
```

> 📌 **Note.** `event = 'restored'` is **not** this engine. That is Eloquent's soft-delete revival —
> `$model->restore()` on a trashed record. This engine's entries are `audit_type = 'restore'`.
> Filter with `whereType('restore')`, never with `whereEvent('restored')`.

---

## The two calls

Both live on `Models\Audit` and both resolve `Restore\Restorer` out of the container.

| Call | Signature | Puts back |
|---|---|---|
| Whole state | `restore(?array $fields = null): RestoreResult` | Every key of the state the entry portrays |
| Named fields | `restore(['email', 'role']): RestoreResult` | Only those keys; everything else is untouched |
| Relation | `restoreRelationship(string $relation): RestoreResult` | The `BelongsToMany` lines the entry recorded |

```php
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Models\Audit;

$entry = Sentinel::audits()->for($invoice)->whereVersion(3)->get()->first();

$result = $entry->restore();                       // the whole recorded state
$result = $entry->restore(['total', 'status']);    // only those two columns
```

Neither call throws an exception of its own. There is no `RestoreException`: asking to restore
something unrestorable is a question with an answer, and the answer comes back in the result. What
*does* propagate is whatever the subject's own `save()` throws — a model observer, a database
constraint, a cast that rejects the stored value — and `Exceptions\SnapshotException::unsupportedType`
if a live attribute cannot be snapshotted. In every one of those cases the transaction has rolled
back, the record is untouched and no restore entry exists.

---

## The decision comes first

`Restore\Planner` (fields) and `Restore\RelationPlanner` (relations) build an internal `Restore\Plan`
before a single row is touched. The plan says exactly what would move and, for everything else, an
`Enums\Omission` saying why not. An impossible restoration therefore costs reads and no writes at
all.

`Restore\Planner::for()` runs its whole-entry checks in this fixed order, and stops at the first one
that fails:

1. **Subject** — `Restore\Subject::of()` finds the record the entry names → `SubjectMissing`.
2. **Redaction** — `redacted_at !== null` → `EntryRedacted`.
3. **Integrity** — `Integrity\Verifier::verifyEntry()` → `EntryTampered`.
4. **State** — `after`, then `before`; both empty → `EntryStateless`.
5. **Origin** — `source === Source::Import` **and** no fields were named → `EntryImported`.

Then, per field, `Planner::weigh()` decides in this order: not in the entry's state →
`UnrecordedField`; the model's key name → `IdentityField`; not a column of the table today →
`UnknownField`; declared redacted or hashed → `RedactedField` / `HashedField`; encrypted under a key
that is gone → `KeyUnavailable`; equal to what the record already holds → `Unchanged`. Everything
else is applied.

Both sides of that last comparison are **snapshots**, built by `Snapshot\SnapshotBuilder`. Comparing
the stored string against a live `CarbonImmutable` would report a change on every date the record
holds.

> 🔒 **Security.** The entry's hash is re-verified before anything is touched. Restoring is the only
> operation in the package that writes into your business model out of what the ledger holds, so an
> entry that no longer reproduces its own hash writes nothing at all — a tampered entry would be
> false data in production, not merely a bad audit.

---

## Reading a RestoreResult

`Restore\RestoreResult` is a `final readonly` class with four public properties and one method.

| Member | Type | Meaning |
|---|---|---|
| `$applied` | `list<string>` | Keys that moved. Sorted alphabetically. **Keys, never values.** |
| `$skipped` | `array<string, Omission>` | Key → why it was left behind. Sorted by key. |
| `$refused` | `?Omission` | The whole restoration was declined, for this reason |
| `$entry` | `?Audit` | The entry that **recorded** the restoration, when this process settled it |
| `reason(string $key)` | `?Omission` | Why this key is not in `applied`; falls back to `$refused` |

There is deliberately no boolean anywhere on it, and a test asserts that no public method returns
one. Four fields out of six is neither a success nor a failure, and `true` would hide the two that a
masked value and a dropped column left behind.

`$applied` carries keys and not values on purpose: handing values back a second time is how a value
the security pipeline masked or hashed on the way in escapes on the way out.

**Read it in three steps, in this order.**

```php
use ElPandaPe\Sentinel\Enums\Omission;

$result = $entry->restore();

$message = match (true) {
    $result->refused instanceof Omission => $result->refused->message(),
    $result->applied === []              => 'The record already holds everything this entry would put back.',
    default                              => 'Restored: '.implode(', ', $result->applied),
};

foreach ($result->skipped as $field => $omission) {
    logger()->info($omission->message($field));
    // "The email was stored masked, and the original is gone."
    // "The id identifies the record rather than describing its state."
}
```

Three outcomes, not two. `refused` means the entry or the record could not answer for itself.
An empty `applied` with `refused === null` means there was simply nothing to move — restoring the
same entry twice lands here, with every key coming back `Omission::Unchanged`.

> ⚠️ **Warning.** Do not test `$result->refused` to find out whether anything happened. A restoration
> in which every key was already correct has `refused === null` **and** `applied === []`, and writes
> no entry. Test `$result->applied === []`.

`Omission::message(string $key = '')` reads the reason out of `sentinel::sentinel.restore.<value>`
in the application's current locale. English and Spanish ship with the package, and every one of the
fourteen reasons has a line in both.

---

## The fourteen reasons

`Enums\Omission` is frozen: a case may be added, none may be removed or renamed. The first six refuse
the whole restoration; the other eight refuse one key and let the rest through.

| Case | Value | Scope | When |
|---|---|---|---|
| `SubjectMissing` | `subject_missing` | whole | The entry names no record, or the record is gone for good, or its morph type no longer resolves to a model |
| `EntryRedacted` | `entry_redacted` | whole | `redacted_at` is set: the contents were destroyed on purpose |
| `EntryTampered` | `entry_tampered` | whole | The row no longer reproduces its own hash |
| `EntryStateless` | `entry_stateless` | whole | No `before` and no `after` — or, for a relation, no lines about that relation |
| `Cancelled` | `cancelled` | whole | An `AuditRestoring` listener returned `false` |
| `EntryImported` | `entry_imported` | whole | `source = import` and no fields were named |
| `UnknownField` | `unknown_field` | key | The table no longer has that column |
| `UnrecordedField` | `unrecorded_field` | key | The entry does not record that key |
| `IdentityField` | `identity_field` | key | It is the model's primary key |
| `RedactedField` | `redacted_field` | key | Stored masked; the original is gone |
| `HashedField` | `hashed_field` | key | Stored as a digest; it cannot be reversed |
| `KeyUnavailable` | `key_unavailable` | key | The encryption key the entry named has left the keyring |
| `RelatedMissing` | `related_missing` | key | The related record of a relation line no longer exists |
| `Unchanged` | `unchanged` | key | The record already holds that value |

Two of these are permanent by construction. A **masked** value and a **digest** are one-way, so the
engine refuses them forever rather than writing a mask or a hash into your business model. An
**encrypted** field is different: it is decrypted with the `key_id` the *entry* recorded, not the
current one, so yesterday's key still restores while it stays on the ring — and once it leaves, the
field is skipped as `KeyUnavailable` rather than written back as ciphertext.

> 💡 **Tip.** If a sensitive field must remain restorable, put it in `$auditEncrypt`, never in
> `$auditRedact` or `$auditHash`. Keep retired key ids on `security.encryption.keys` for as long as
> you want their entries to stay restorable.

---

## The two events

Two events bracket the write. Both are `final readonly` classes in `ElPandaPe\Sentinel\Events`.

| Event | Dispatched | Cancellable | Carries |
|---|---|---|---|
| `AuditRestoring` | Before anything moves, **outside** the transaction, with `Dispatcher::until()` | Yes — return `false` | `$entry`, `$subject`, `list<string> $applying`, `?string $relation` |
| `AuditRestored` | From a commit callback registered behind the ledger's own | No | `$entry`, `$subject`, `RestoreResult $result` |

`AuditRestoring::$applying` holds the sorted keys about to move — keys, not values, so a field the
pipeline masked does not escape in an event payload. `$relation` is the relation name for a relation
restore and `null` for a field restore. `AuditRestored::$entry` is the entry restored **from**, while
`$result->entry` is the entry that **recorded** the restoration.

```php
use ElPandaPe\Sentinel\Events\AuditRestored;
use Illuminate\Support\Facades\Event;

Event::listen(function (AuditRestored $event): void {
    $event->entry;          // the entry it was restored FROM
    $event->subject;        // the record that moved
    $event->result->entry;  // the entry that RECORDED the restoration
    $event->result->applied;
});
```

`AuditRestoring` fires **only** when the plan has something to apply. It does not fire for a
restoration that would move nothing, nor for one refused on the entry — redacted, tampered,
stateless, imported, subject gone. It is therefore a gate on *movements*, not a log of *attempts*.

---

## Who may restore

Sentinel imposes no gate, on purpose. Restoring writes into your business model, and an audit engine
has no standing to impose that policy on you. `AuditRestoring` is the whole of the answer.

```php
use ElPandaPe\Sentinel\Events\AuditRestoring;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;

Event::listen(function (AuditRestoring $event): ?bool {
    if ($event->subject->getAttribute('tenant_id') !== auth()->user()?->tenant_id) {
        return false;
    }

    return Gate::allows('restore-audit', $event->subject) ? null : false;
});
```

Two rules for that listener.

**Return `null` to allow, never `true`.** The event is dispatched with `until()`, which stops at the
first non-null response. Only `=== false` cancels — but a listener returning `true` halts the chain
and silences every listener after it, including the one that would have denied.

**Check tenancy yourself.** `Restore\Subject::of()` finds the record with `newQueryWithoutScopes()`,
so global scopes — a tenant scope included — do not constrain it. That is deliberate: a record in the
soft-delete recycle bin is exactly the one a restoration is usually about, and a scoped lookup would
hide it. The consequence in a multi-tenant application is that an entry can name a record the current
tenant should not touch, and the engine will find it. `AuditRestoring` is the only place to stop
that.

---

## A restore is a write, and it is audited like one

The write phase (`Restore\Restorer::apply()`) does four things inside **one** transaction opened on
the **subject's** connection:

1. Snapshots the record as it stands.
2. Applies the plan with `forceFill($plan->applying)->save()`, with Sentinel's own recording paused.
3. Builds the restore entry — `before`/`after` from the two snapshots, `changes` from the diff
   between them, `metadata['restore']` from the plan.
4. Records it through the normal Context Engine → pipeline → Ledger path, so it gets a `stream`, a
   `sequence`, a `previous_hash` and a `hash` like any other entry.

Either the applicable set goes back and the ledger settles the entry, or the record is untouched.

Recording is paused for the save only so the trail carries **one** fact — the restoration — rather
than a restoration plus an `updated` describing the same movement backwards. The pause goes through
`Sentinel::withoutAuditing()`, which puts the previous state back in a `finally`, so a restore that
throws does not silently leave auditing off for the rest of the process.

> 📌 **Note.** `forceFill()` means `$fillable` and `$guarded` protect nothing from a restoration —
> but casts, mutators and **your own Eloquent model events still run**. Only Sentinel's recording is
> paused. A `saving` listener that throws aborts the restore and rolls the transaction back.

A restoration is recorded **even inside `Sentinel::withoutAuditing()` and even when `sentinel.enabled`
is false**. `Restorer` calls the recorder directly and is the one caller that never asks
`Sentinel::isRecording()`. Every other capture path does ask. A trail that can put a record back
without saying so would mislead by omission about the only thing it does that is not merely
observing.

What the entry seals:

```php
$restoration->metadata;
// ['restore' => [
//     'applied' => ['name'],
//     'skipped' => [['field' => 'id', 'reason' => 'identity_field']],
// ]]
```

`skipped` is a **list of `{field, reason}` pairs**, never a map keyed by field name. The security
stages match a protected field by key name at *any* depth of `metadata`
(`Security\Fields::protect()`), so a map would get the *reason* masked in place of a field called
`email` — the transformation landing on the explanation, sealed where nothing can correct it.

Following a restoration back to its source is a plain lookup. There is no Eloquent relation for it
and no `whereId()` filter on the Query API:

```php
$restorations = Sentinel::audits()->whereType('restore')->for($invoice)->get();

foreach ($restorations as $restoration) {
    $source = Audit::query()->find($restoration->source_audit_id);
}
```

---

## Restoring a relation

`restoreRelationship()` reads the relation lines the entry carries in its own `changes` — not the
`sentinel_audit_relations` projection, because only the entry's copy is inside the payload the chain
seals.

What the entry **attached** stays attached with its `pivot_after`; what it **detached** stays
detached; a pivot it **changed** goes back to the value it left. Result keys are
`"{relation}/{related_id}"`, not field names.

```php
$entry = Sentinel::audits()->for($project)->whereType('relation')->latest()->take(1)->get()->first();

$result = $entry->restoreRelationship('members');

$result->applied;               // ['members/7', 'members/9']
$result->reason('members/12');  // Omission::RelatedMissing — that member is gone
$result->entry;                 // ONE entry for the whole relation, carrying every line
```

Only `BelongsToMany` — and `MorphToMany`, which extends it — can be restored. `HasMany`, `HasOne` and
`BelongsTo` are not supported: `RelationPlanner::for()` and `Restorer::reattach()` both gate on
`instanceof BelongsToMany`, and the only operations available are `attach`, `detach` and
`updateExistingPivot`. A related record that no longer exists is skipped with `RelatedMissing` rather
than breaking referential integrity halfway through.

Two asymmetries with `restore()`, both verified in `Restore\RelationPlanner`:

- **No imported-entry guard.** `RelationPlanner::for()` checks redaction, tampering and whether the
  entry carries lines for that relation, and nothing else. An entry with `source = import` carrying
  relation lines will restore them.
- **A relation the entry says nothing about answers `EntryStateless`, not `UnknownField`** — even
  when the model does declare it. `lines()` filters the entry's `changes` by relation name first, and
  an empty result short-circuits before the relation is ever inspected.

> ⚠️ **Warning.** Do not call `restore()` on a relation-restore entry. It carries only relation lines
> and no `before`/`after`, so it refuses with `EntryStateless`.

---

## Restoring into a schema that moved

There is no translation layer and no old→new column mapping anywhere in the engine. Here is what each
kind of drift does, read off `Planner::weigh()` and `Restorer::apply()`.

| The schema did this | On a whole restore | If you name the field |
|---|---|---|
| **Dropped a column** | The old key is skipped `UnknownField`; every other field still goes back | `UnknownField` |
| **Renamed a column** | The old name is skipped `UnknownField`. Nothing maps it to the new one | The new name is `UnrecordedField` — the entry does not record it |
| **Added a column** (nullable or not) | Never considered, and it does **not** appear in `skipped` either. The `UPDATE` touches only the planned keys, so the column keeps whatever it currently holds | `UnrecordedField` |
| **Changed a cast** | No translation. The entry's stored snapshot value goes to today's setters as-is | Same |

The whole-restore loop iterates `array_keys($state)` — the keys the *entry* recorded. A column added
by a later migration is not one of them, which is why an added `NOT NULL` column is neither restored
nor a problem: the restoration issues an update over the planned columns only, and the new column is
left alone.

The changed-cast case is the one to watch. The entry holds the **normalized snapshot** form — dates
as `Y-m-d\TH:i:s.uP`, backed enums as their value — and `forceFill()` hands that straight to the
model's setters. If today's cast refuses it (a backed enum that no longer has that case, an integer
cast with a strict setter), `save()` throws, the transaction rolls back, the record is untouched and
no restore entry exists. That is what the code does; nothing in `tests/Restore/` pins it, so treat
the exact failure mode as implementation behaviour rather than a contract.

A field listed in `$auditExclude` behaves like a column that was never there: `SnapshotBuilder`
drops it before the snapshot is written, so it is not in the entry's state and a restore never
considers it.

> 💡 **Tip.** A whole restore of a model with timestamps moves `updated_at` **backwards**.
> `updated_at` is an ordinary column in the snapshot, `forceFill()` makes it dirty, and Laravel
> refuses to overwrite a dirty `updated_at`. Put it in `$auditExclude` if that is not what you want.

---

## What a restore refuses

**A tampered entry restores nothing.** `Planner::for()` calls `Verifier::verifyEntry()`, which is
true only when the row's content re-hashes to `ContentState::Sealed`. Anything else and the whole
restoration comes back `refused = EntryTampered`, with no read of the record's fields and no write.

**A redacted entry restores nothing.** `redacted_at` is checked before the hash, and it outranks
every other refusal — including the imported guard. A redaction destroyed the contents on purpose;
there is nothing left to put back.

**An imported entry cannot be whole-record restored.** `source = import` plus `$fields === null`
gives `EntryImported`. The guard exists because an imported entry is not guaranteed to be a full
snapshot, and it does **not** discriminate by event or by origin, on purpose:

- `owen-it/laravel-auditing` records only what Eloquent called dirty on an update, so an imported
  update portrays the fields that moved rather than the record. Its creates and deletes do carry
  everything — but a rule that has to be reasoned about per event is one somebody will reason about
  wrongly.
- `altek/accountant` writes a full snapshot on every row and no earlier values at all, so an imported
  row's `after` is real and complete and its `before` is empty. Restoring "everything present" would
  quietly restore a fraction of the record while claiming the whole.

Naming the fields lifts the refusal, because then the caller knows what they asked for:

```php
$entry->restore();                    // refused = Omission::EntryImported
$entry->restore(['email', 'name']);   // planned key by key, like any other entry
```

**A model with `$auditSnapshots = false`, or an installation with `snapshots.enabled = false`, cannot
be restored at all.** Without `before` and `after` the entry portrays no state, so every restoration
is refused `EntryStateless` — even though `changes` still holds the old values. Reconstructing state
from the diff is out of scope.

**A pruned or archived-and-pruned entry is not a row any more,** so there is nothing to call
`restore()` on. Rehydrate the range first with `Archive\Rehydrator::restore($stream, $from, $to)` —
a different operation with an unfortunately similar name, which puts archived entries back on the
ledger and touches no business record.

---

## A restoration is not a transition

A `restore` entry never appears in `Sentinel::transitions()`, and `DeclaresTransitions` does not
govern it — even when the restoration moves a column named in `$auditTransitions`. The state machine
consults `Sentinel::isRecording()`, and the save runs paused.

That is a decision, not an oversight. A lifeline answers which states the workflow moved through, and
a correction made by an operator is not one of them. What moved is still on the entry, in its
`changes`, and the entry is still `audit_type = 'restore'`.

---

## Transactions, connections and the async modes

The restoration opens its own transaction on the subject's connection. With
`transactions.after_commit = true` (the default) the ledger write is deferred to the commit of the
**outermost** transaction, which changes what the *return value* can tell you.

| Situation | `$result->entry` | `AuditRestored` |
|---|---|---|
| `sync` mode, no application transaction | The entry — the restore's own transaction is the outermost one, so it commits inside the call | Fires after that commit, with the entry |
| `sync` mode, inside your `DB::transaction()` | `null` — yours is the outermost one and has not committed | Fires after **your** commit, with the entry |
| Your transaction rolls back | `null` | Never fires at all; the record keeps its current values |
| `queue` or `buffered` mode | Always `null` — the strategy answers `Handover::accepted()` and a worker settles the entry | Fires, but `$result->entry` is still `null` |
| A pipeline stage discarded the entry | `null` — the record moved and no entry was written | Fires, with `$result->entry` null |

```php
use Illuminate\Support\Facades\DB;

DB::transaction(function () use ($entry): void {
    $result = $entry->restore();

    $result->entry;   // null — the record moved, the entry is queued behind your commit
    $result->applied; // ['total'] — the decision is already made and closed
});
```

> 📌 **Note.** When you need the entry that recorded a restoration, listen to `AuditRestored` rather
> than dereferencing the return value. It is dispatched from a commit callback registered behind the
> ledger's own, so the entry exists by the time a listener runs — on whichever branch wrote it.

### Engine notes

> 🐘 **Engine.** MySQL returns a JSON object's keys ordered by length and then alphabetically, where
> SQLite and PostgreSQL keep insertion order. `Plan::keys()` sorts and `Plan::of()` ksorts precisely
> so that `applied` and `metadata.restore.applied` are the same on all three — the summary travels
> inside the sealed payload, and an engine-dependent order would make the same restoration hash
> differently.

> 🐘 **Engine.** `Restore\Columns` exists because `getColumnListing()` is a round trip to
> `information_schema` on MySQL and PostgreSQL. It memoizes the column list per connection and table
> and is registered `scoped`, so it resets per request or job — a migration that runs and then
> restores in the same request will use the stale list.

> 🐘 **Engine.** PostgreSQL hands back a real boolean where SQLite and MySQL return `0`/`1` for a
> boolean column with no model cast. The engine compares two snapshots on both sides so it is not
> confused, but a test asserting on the raw restored value has to account for it. Pivot values are
> compared as strings (`RelationPlanner::text()`) for the same reason.

> 🐘 **Engine.** With `database.connection` set, audits live on a dedicated connection while the
> restoration's transaction is opened on the **subject's**. The ledger insert is then outside that
> transaction, so a rollback of the business write does not roll back the entry.

> 🧪 **Verify it.** `make test ARGS=tests/Restore` runs the engine's own suite on SQLite;
> `make test-dbs` runs the chain-touching tests on SQLite, MySQL 9 and PostgreSQL 16.

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `$result->entry` is null but the record did move | You were inside your own `DB::transaction()`, or the mode is `queue`/`buffered`, or a pipeline stage discarded the entry | Listen to `AuditRestored` instead of reading the return value |
| Restoring the same entry twice looks like a failure | Every key came back `Unchanged`, so `applied === []`, `refused === null` and no entry was written | Test `applied === []`, not `refused` |
| An authorization listener never sees an attempt | `AuditRestoring` fires only when the plan has something to apply — never for a refused or no-op restoration | Log attempts at your own call site, before calling `restore()` |
| A denying listener is ignored | An earlier listener returned `true`; `until()` stopped at it | Return `null` to abstain, `false` to deny; never `true` |
| A tenant restored another tenant's record | `Subject::of()` uses `newQueryWithoutScopes()`, so the tenant scope does not apply | Check the tenant inside the `AuditRestoring` listener |
| `updated_at` jumped backwards after a restore | It is an ordinary snapshot column and `forceFill()` made it dirty, so Laravel did not refresh it | Add `updated_at` to `$auditExclude` |
| A column added by a later migration is missing from `skipped` | On a whole restore only the entry's own keys are considered; the new column is never a candidate | Name it explicitly to get `UnrecordedField`, or accept that it is untouched |
| `restoreRelationship('members')` answers `EntryStateless` on a model that has `members()` | The entry records no lines for that relation; `lines()` short-circuits before the relation is inspected | Restore from an entry whose `audit_type` is `relation` and that names that relation |
| A soft-deleted record was not revived | Revival happens only on a **whole** restore whose portrait shows the record not deleted (`Planner::revived()`) — a granular restore never revives, and neither does the deletion entry itself | Call `restore()` with no arguments, from an entry written before the deletion |
| `restore()` on a relation entry refuses `EntryStateless` | Relation entries carry lines in `changes` and no `before`/`after` | Use `restoreRelationship()` |
| An encrypted field came back as `KeyUnavailable` | The `key_id` the entry recorded is no longer on `security.encryption.keys` | Keep retired key ids on the ring for as long as their entries must stay restorable |
| `whereEvent('restored')` returns Eloquent revivals, not restorations | `restored` is the soft-delete event; this engine writes `audit_type = 'restore'` | Use `whereType('restore')` |
| A restore of an old entry throws out of `save()` | A cast narrowed since the entry was written and rejects the stored snapshot value | The transaction rolled back and nothing was written; fix the cast or restore the fields that still fit |

---

## ✅ Best practices

✅ **Do** — read the result in three steps: `refused`, then `applied === []`, then `skipped`. Anything
else conflates "impossible" with "already correct".

```php
match (true) {
    $result->refused instanceof Omission => report($result->refused->message()),
    $result->applied === []              => report('Nothing to put back.'),
    default                              => report('Restored: '.implode(', ', $result->applied)),
};
```

❌ **Don't** — treat a restoration as a boolean, or write a helper that turns one into one. Four
fields out of six is neither outcome, and the class deliberately exposes no method returning `bool`.

```php
if ($entry->restore()) {          // always truthy — RestoreResult is an object
    flash('Restored!');           // and it says nothing about the two fields left behind
}
```

✅ **Do** — take the entry from `AuditRestored`, which fires only once the restoration is durable and
always carries a closed result.

```php
Event::listen(function (AuditRestored $event): void {
    Notification::send($event->subject->owner, new RecordRestored($event->result->entry));
});
```

❌ **Don't** — dereference `$result->entry` at the call site. It is null inside your own uncommitted
transaction, null under `queue` and `buffered`, and null whenever nothing was written.

```php
$id = $entry->restore()->entry->id;   // TypeError on three ordinary paths
```

✅ **Do** — gate restoration in an `AuditRestoring` listener, returning `false` to deny and `null` to
abstain, and check the tenant there yourself.

```php
Event::listen(fn (AuditRestoring $e): ?bool => Gate::allows('restore-audit', $e->subject) ? null : false);
```

❌ **Don't** — return `true` to allow. `until()` halts at the first non-null response, so a permissive
listener silences every listener registered after it, including the one that would have denied.

```php
Event::listen(fn (AuditRestoring $e): bool => true);   // nothing after this ever votes
```

✅ **Do** — name the fields for a surgical correction. A granular restore leaves everything else
exactly where it is, and it is the only form that works on an entry imported from another package.

```php
$entry->restore(['email']);          // works on an imported entry too
```

❌ **Don't** — reach for a whole restore to fix one column. It moves every recorded key that differs,
`updated_at` included, and on an imported entry it is refused outright.

```php
$entry->restore();                   // Omission::EntryImported, or six columns you did not mean
```

✅ **Do** — encrypt a sensitive field that must remain restorable, and keep its key on the ring.

```php
protected array $auditEncrypt = ['tax_id'];
```

❌ **Don't** — redact or hash a field you will one day want back. A mask and a digest are one-way by
construction, and the engine refuses them forever with `RedactedField` and `HashedField`.

```php
protected array $auditRedact = ['tax_id'];   // permanently unrestorable
```

✅ **Do** — filter restorations by type and read the sealed decision off the entry.

```php
Sentinel::audits()->whereType('restore')->for($invoice)->get();
```

❌ **Don't** — point a bulk job at `restore()` expecting throughput. Each call re-canonicalizes and
re-hashes the source entry, builds two snapshots of the record, and asks the schema for its columns —
and restoring one named field is not appreciably cheaper than restoring all of them.

```php
Invoice::query()->cursor()->each(fn ($i) => $i->audits()->first()->restore());
```

---

**See also:** [The Query API](01-the-query-api.md) · [Field history and comparing versions](04-field-history.md) · [Snapshots](../03-capture/02-snapshots.md) · [Relationship auditing](../03-capture/04-relationships.md) · [State transitions](../03-capture/08-state-transitions.md) · [Protecting sensitive data](../05-pipeline-and-security/02-protecting-sensitive-data.md) · [Encryption and the keyring](../05-pipeline-and-security/03-encryption-and-the-keyring.md) · [Verification](../07-integrity/06-verification.md) · [Redaction and tombstones](../08-lifecycle/04-redaction-and-tombstones.md) · [Rehydration](../08-lifecycle/03-rehydration.md) · [Performance modes](../09-operations/01-performance-modes.md) · [Events and listeners](../09-operations/04-events-and-listeners.md) · [From altek/accountant](../12-migrating/02-from-altek.md) · [Enums](../99-reference/04-enums.md) · [Events](../99-reference/05-events.md)
