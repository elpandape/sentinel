# 🔎 Field history and comparing versions

> The life of one attribute across a record's history: how to ask for it, the two numbers on screen
> and what each of them counts, and how to compare two versions that are not next to each other.

**On this page:** [Asking a field for its history](#asking-a-field-for-its-history) · [What "touched this field" means](#what-touched-this-field-means) · [The two numberings](#the-two-numberings) · [version under concurrency](#version-is-assigned-without-a-lock) · [Comparing two versions](#comparing-two-versions) · [Rendering a field's history](#worked-example-a-fields-history-as-a-table) · [Pitfalls](#️-pitfalls) · [Best practices](#-best-practices)

---

## Asking a field for its history

There are two ways in, and they mean exactly the same thing about an entry.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

// Through the Query API — driver-agnostic, and the read compliance mode records.
$history = Sentinel::audits()
    ->for($invoice)
    ->whereFieldChanged('total')
    ->get();

// Through the relation the model already has — Eloquent, straight at the audits table.
$history = $invoice->audits()->field('total')->get();
```

Both return a `Support\AuditCollection` of `Models\Audit`. Both are backed by one predicate:
`Ledger\ChangedFieldPredicate`, reached from `Query\AuditQuery::whereFieldChanged()` and from the
`Models\Audit::field()` Eloquent scope. One implementation, so the two cannot drift.

They are not interchangeable in every respect:

| | `whereFieldChanged()` | `field()` scope |
|---|---|---|
| Goes through | `Contracts\Ledger::query()` | Eloquent, on the audits connection |
| Answered by | whichever driver is configured | the database table only |
| Composes with | every other criterion on `AuditQuery` | every other Eloquent clause |
| Default order | the ledger's clock, then `id` | `id` ascending (set by `Concerns\Auditable::audits()`) |
| Refused when the driver cannot translate it | yes — `LedgerException::cannotFilterBy` | not applicable |
| Recorded by compliance mode | yes, as `changed_field` | **no** — `Compliance\AccessLog` is reached from `Query\AuditQuery` and from nowhere else |

> 📌 **Note.** Use the Query API unless you specifically want Eloquent. A read through `field()` leaves
> no access entry even with compliance mode on. See
> [The Query API](01-the-query-api.md).

### The engines that can answer it

`ChangedFieldPredicate` emits a different JSON expression per engine — `json_valid` + `json_each` on
SQLite, `jsonb_typeof` + `jsonb_array_elements` on PostgreSQL, a derived table plus `JSON_TABLE` with
`collate utf8mb4_bin` on MySQL. Anything else throws `LedgerException::cannotTranslateOn` naming the
driver rather than guessing a dialect. Supported: `sqlite`, `mysql`, `pgsql`. MariaDB is not.

> 🐘 **Engine.** `changes` carries no index in the shipped schema, so the field filter is a **refiner**:
> it narrows a set another filter already found, and on its own it walks the table. `tests/Query/QueryPlanTest.php`
> asserts exactly that — an index is reached with `for()` in front of it and none is reached without.
> Put an indexed filter first. See [Indexes and JSON](../10-database-engines/05-indexes-and-json.md).

---

## What "touched this field" means

Dot notation is read as an RFC 6901 JSON Pointer by `Diff\Pointer::of()`, and a pointer matches
**itself or anything beneath it** (`Diff\Pointer::covers()`). The slash is the whole mechanism.

| You ask for | It finds | It does not find |
|---|---|---|
| `email` | `/email` | `/email_verified_at`, `/emails`, `/Email` |
| `profile` | `/profile`, `/profile/address/city` | `/profiles` |
| `profile.address.city` | `/profile/address/city` | `/profile` (an ancestor is not the field) |
| `/profile/address/city` | the same as above — a literal pointer passes through untouched | |

Three consequences worth stating outright:

- **Matching is case-sensitive on all three engines.** `/Email` is a different field from `/email`; on
  MySQL that costs an explicit `collate utf8mb4_bin` in the predicate.
- **`_` and `%` are inert.** The predicate compares whole pointer values, not a `LIKE` pattern, so a
  field named `a_b` finds only `a_b`.
- **It reads the `changes` column, not the snapshots.** An entry whose `changes` is `null` — an event
  with no pair to diff — is never matched, even though `$audit->diff()` would compute one from
  `before`/`after`. An entry whose `changes` is `[]` is not matched either.

`$audit->diffFor('total')` applies the identical reading to one entry. If the filter found the entry,
`diffFor()` on it is non-empty; the two answers cannot disagree.

---

## The two numberings

A field's history has two counters running over it, and both numbers you see are true.

| | `version` | the ordinal |
|---|---|---|
| Counts | entries **about the subject** | changes **to this field** |
| Lives in | the `version` column of `sentinel_audits` | nowhere — it is computed while rendering |
| Assigned by | the ledger driver, at write time | `Presentation\AuditPresenter::fieldHistory()`, at read time |
| Survives export, archiving, rehydration | yes — it is inside the canonical payload | no |
| Leads back to the whole entry | yes | no |
| Contiguous for the field | no, it skips | yes, 1, 2, 3… |
| `null` / `0` when | the entry names no subject | never |

`version` is a per-subject counter. `Ledger\DatabaseLedger::version()` reads `max(version)` for the
entry's `subject_type` + `subject_id` and adds one; the array-backed drivers keep the same count in
`Ledger\SubjectVersions`. The only thing it checks is that the entry **has** a subject — so a relation
entry, a state transition, a restoration and the trail entry a redaction leaves all take the next
number for that subject, exactly like an update does.

> ⚠️ **Warning.** `version` counts entries about the subject, **not** changes to it. If an invoice has
> `version` 12, that does not mean it was edited twelve times; it means twelve entries name it.

The ordinal is the presenter's, and only the presenter's. It counts the entries it was handed, in the
order it was handed them, starting at 1. Hand it a filtered, ordered collection and it numbers that
collection.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Presentation\AuditPresenter;

$history = Sentinel::audits()->for($user)->whereFieldChanged('email')->get();

echo app(AuditPresenter::class)->fieldHistory($history, 'email');
// 1. v1  ada@example.com
// 2. v4  ada@work.example
// 3. v7  ada@home.example
```

The line comes from `resources/lang/en/sentinel.php` under `presenter.field`, whose format is
`':ordinal. v:version  :value'` — two spaces before the value. The Spanish catalogue carries the same
key. Change the format and both numbers move with it.

### When they diverge, and which one to show

They diverge the moment anything else touches the subject. In the sequence *email changed → name
changed → email changed*, the field's history is two lines and reads `1. v1` then `2. v3`. There is no
`v2` line, because v2 was the name change.

**Show both.** The ordinal is what a person reads ("the second time this address changed"); `version`
is what an operator uses to find the entry, to call `compare()`, or to quote in a ticket. Showing only
the ordinal makes a history look contiguous when it is not; showing only `version` makes a reader
count gaps and wonder what is missing.

An entry the ledger never numbered — one with no subject — renders as `v0`. Zero is not a version any
subject reaches; it is the presenter's way of saying the column was null.

### version is assigned without a lock

`DatabaseLedger::version()` runs `max(version) + 1` inside the sealing transaction, but nothing locks
the subject. What is locked is the **stream**: `Ledger\StreamGate` takes an advisory lock by stream
name on PostgreSQL and a `lockForUpdate()` on the stream's tail row on MySQL. Two consequences:

- One subject's entries can be split across streams — under the `tenant` strategy, under a custom
  `Contracts\StreamResolver`, or when a caller sets the stream itself — and writers in different
  streams are not serialised against each other.
- SQLite ignores the locking clause outright, so two concurrent writers there can read the same
  maximum whatever the stream.

Either way, two writes can claim the same number.

What follows from that:

- `whereVersion(7)` may legitimately return more than one entry.
- `compare()` resolves a repeated number to the **newest** entry carrying it — it calls `latest()`
  before picking, so `firstWhere('version', …)` lands on the last one written.
- The chain is unaffected. `(stream, sequence)` is unique and dense; `version` is a convenience
  counter beside it, not the ordering the integrity model rests on. See
  [The hash chain](../07-integrity/01-the-hash-chain.md).

> 🔒 **Security.** Do not build an authorisation or reconciliation rule on `version` being unique. Use
> the entry `id` (a ULID) or the `(stream, sequence)` pair, both of which are.

[Rehydration](../08-lifecycle/03-rehydration.md) compounds this: an archived batch comes back carrying
the numbers it left with. A subject whose history was pruned and then written to again, and whose old
batch is later restored, can hold two entries claiming version 1. Renumbering is impossible —
`version` is one of the twenty-seven columns in `Integrity\CanonicalPayload::COLUMNS`, so changing it
would break the entry's own hash and cost a `payload_version` bump for every row.

---

## Comparing two versions

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

$comparison = Sentinel::audits()->for($invoice)->compare(1, 7);

$comparison->from;   // Audit — the entry at version 1
$comparison->to;     // Audit — the entry at version 7
$comparison->diff;   // Diff  — Countable, IteratorAggregate
```

`Query\AuditQuery::compare()` is `whereVersion($from, $to)->latest()->get()` followed by picking the
entry that carries each number: **one read**, whatever the distance between the two versions. Prior art
does this with a loop in PHP or documents it as out of reach.

Two entries you already hold compare the same way, with the same return type:

```php
$comparison = $entry->comparedTo($other);
```

### What comes back

`Query\Comparison` is a `final readonly` value object with three public properties and a private
constructor. It carries **both entries and not only the diff**, because a diff has one way to say
"nothing" and several ways to arrive there:

| The diff is empty because | How to tell |
|---|---|
| Nothing changed between the two versions | `from->after` and `to->after` are both populated and equal |
| The model sets `$auditSnapshots = false` | both `after` values are `null` |
| One of the entries has been redacted | `redacted_at` is set on it; `after` was emptied |
| The event never had a pair to snapshot | `after` is `null` on one side |

`$comparison->diff->toArray()` returns a list of `{path, op, old, new}` with `path` an RFC 6901
pointer and `op` one of `add`, `remove`, `replace`.

### A field that did not exist in the older version

`Comparison::between()` computes `Diff::between($from->after ?? [], $to->after ?? [])`, and
`Diff\Comparator` compares the two documents key by key:

| Situation | `op` | `old` | `new` |
|---|---|---|---|
| Key absent in `from`, present in `to` | `add` | `null` | the newer value |
| Key present in `from`, absent in `to` | `remove` | the older value | `null` |
| Key in both, different | `replace` | the older value | the newer value |
| Key in both, equal | no entry at all | | |

So a column added by a migration between two versions reads as `add` with `old: null` — the same shape
as a field that existed and was genuinely null before. The two are not distinguishable from the diff
alone; `$comparison->from->after` is where you look to tell them apart.

> 📌 **Note.** `old` is always present in a diff built by `compare()`, because `Diff\Comparator` always
> knows it. The `old?` (absent, not null) case in the published shape comes from elsewhere:
> `Diff::fromJsonPatch()` reconstructing a `replace` or a `remove` that had no guarding `test`
> operation, or a stored `changes` entry that carries no `old` key at all. See
> [Diffs](../03-capture/03-diffs.md).

### The direction is the one you asked for

`compare(1, 7)` and `compare(7, 1)` are different questions and give different answers: the first
entry named is the `from`. Reversing the arguments reverses `old` and `new` in every change.

### The three refusals

| Exception | Thrown when | Message you will see |
|---|---|---|
| `ComparisonException::withoutSubject()` | `compare()` on a query that was not narrowed with `for()` | *Comparing two versions needs to know whose versions they are; narrow the query with for() first.* |
| `ComparisonException::missingVersion($n)` | no entry of that subject carries the number | *No entry of this subject carries version 7, so there is nothing at that point to compare.* |
| `ComparisonException::acrossSubjects()` | `comparedTo()` between entries of different subjects | *…have no shared history to compare; an empty diff would read as agreement, which is not what happened.* |

All three extend `InvalidArgumentException`. Comparing entries of two different subjects is refused
rather than answered with an empty diff, because an empty diff would read as agreement.

---

## Worked example: a field's history as a table

The whole shape — narrow, read, and render both numberings into an HTML table with the entry id kept
so a row leads somewhere.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Models\Audit;

/** @return list<array{ordinal: int, version: int|null, at: string, actor: ?string, old: mixed, new: mixed, id: string}> */
function creditLimitHistory(\App\Models\Customer $customer): array
{
    $entries = Sentinel::audits()
        ->for($customer)
        ->whereFieldChanged('credit_limit')
        ->take(200)
        ->get();

    $entries->loadReferences();

    $rows = [];
    $ordinal = 0;

    foreach ($entries as $entry) {
        /** @var Audit $entry */
        $change = $entry->diffFor('credit_limit')->toArray()[0] ?? null;

        if ($change === null) {
            continue;
        }

        $rows[] = [
            'ordinal' => ++$ordinal,
            'version' => $entry->version,
            'at' => $entry->occurred_at->format(Audit::SERIALIZED_AT),
            'actor' => $entry->actor?->name,
            'old' => $change['old'] ?? null,
            'new' => $change['new'],
            'id' => $entry->id,
        ];
    }

    return $rows;
}
```

Four things that example is doing on purpose:

1. **`take(200)`.** A bare `get()` refuses with `QueryException::unbounded` the moment the filter
   matches more than 500 entries — exactly 500 still comes back whole. `take()` asks for a prefix
   knowingly. See
   [Order, paging and walking](03-order-paging-and-walking.md).
2. **`loadReferences()`.** Resolves `tags`, `subject` and `actor` in a query per morph type instead of
   a query per row. It does **not** load `impersonator` — render that and you are back to a query per
   line, or a `LazyLoadingViolationException` in an application that forbids lazy loading.
3. **`$entry->actor?->name`.** The actor may be gone, or its recorded type may name no class at all.
   `AuditCollection::loadReferences()` leaves such an entry unresolved rather than fatal, so the
   relation is null and the null-safe operator is doing real work.
4. **The ordinal is computed here, not read.** It is a property of this list, not of the entry.

For a rendered string rather than rows, hand the same collection to the presenter:

```php
use ElPandaPe\Sentinel\Presentation\AuditPresenter;

echo app(AuditPresenter::class)->fieldHistory($entries, 'credit_limit');
```

The presenter renders each value through one of four language keys — `nothing` for null, `yes`/`no`
for booleans, `structure` for an array or object — and prints any other scalar as it is. It shows the
**new** value only. If you need the old one, read `diffFor()` yourself as above. See
[Presenting and serializing](07-presenting-and-serializing.md).

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `whereFieldChanged('email')` also returns entries that changed `email_verified_at` | It does not — but `whereFieldChanged('profile')` does return `/profile/address/city`. A pointer matches itself or anything beneath it. | Name the full path when you mean a leaf: `whereFieldChanged('profile.address.city')`. |
| A field you know changed does not appear in its own history | The entry has `changes = null` (no pair to diff) or has been redacted (`changes` emptied). The filter reads `changes`, never `before`/`after`. | Check `$entry->redacted_at` and `$entry->changes`. A redacted entry is intentionally invisible to the field filter. |
| `LedgerException: … cannot filter by field, so whereFieldChanged() is not part of the query it answers` | The configured driver does not declare `Filter::FieldChanged`. A driver that does not implement `Contracts\DeclaresFilters` is assumed to answer only the nine filters in `Filter::assumed()`, and this is not one of them. | Implement `DeclaresFilters` on the driver and name the filter, or query through a driver that does. |
| `LedgerException: … has no field predicate for the [mariadb] engine` | Only `sqlite`, `mysql` and `pgsql` have a JSON dialect in `ChangedFieldPredicate`. | Use a supported engine for the audits connection. |
| The history query is slow and the plan shows a full scan | `changes` has no index; the field filter is a refiner. | Put `for()`, `by()` or another indexed filter in front of it. |
| `compare()` throws `missingVersion` for a version you can see in the table | `compare()` inherits the query it is called on, including `take()` and `after()`. A bounded query may not reach the entry carrying that number. | Call `compare()` on an unbounded query: `Sentinel::audits()->for($x)->compare(1, 7)`. |
| `compare()` reports "nothing changed" between two versions that obviously differ | The model sets `$auditSnapshots = false`, so both `after` documents are `null` and the diff is empty. The `changes` on each entry are still there. | Read `$comparison->from` and `$comparison->to`, or use each entry's own `diff()`. |
| A comparison spanning a redacted entry reports every field as `add` | A redaction nulls `before`, `after` and `changes`. Diffing `[]` against a populated document yields one `add` per key. | Check `redacted_at` on both entries before presenting a comparison. |
| An encrypted field shows a change on every comparison | The value in `after` is ciphertext — `Pipeline\Stages\EncryptSensitiveData` replaces it inline — and Laravel's `Illuminate\Encryption\Encrypter` produces a different ciphertext for the same plaintext each time. | Exclude encrypted fields from a diff shown to a user, or decrypt both sides before comparing. See [Encryption and the keyring](../05-pipeline-and-security/03-encryption-and-the-keyring.md). |
| `whereVersion(7)` returns two entries | `version` is assigned without a lock, and rehydration restores original numbers. | Treat it as a filter, not a key. Use `id` when you need one entry. |
| `whereVersion()` with no arguments narrows nothing | It is variadic with no emptiness guard: `versions` stays `[]` and the driver's `when()` never fires. | Pass at least one number. |
| A history read of a hard-deleted record returns nothing | `for(Invoice::class, 500)` passes a class-string through unchanged, but under `Relation::enforceMorphMap()` the column holds the alias. | Pass the alias the entry actually recorded, or pass a model instance. |

---

## ✅ Best practices

✅ **Do** — put an indexed filter in front of the field filter. `changes` has no index, so the
predicate refines a set rather than finding one.

```php
Sentinel::audits()->for($invoice)->whereFieldChanged('total')->take(100)->get();
```

❌ **Don't** — run the field filter alone on a growing trail. It walks the whole table on MySQL and
PostgreSQL, and `get()` then refuses with `QueryException::unbounded` after paying for the scan.

```php
Sentinel::audits()->whereFieldChanged('total')->get();
```

---

✅ **Do** — show both numbers, and keep the entry id behind them. `version` is what leads back to the
whole entry; the ordinal is what a person reads.

```php
"{$ordinal}. v{$entry->version} — {$entry->id}";
```

❌ **Don't** — renumber a field's history 1, 2, 3 and call the ordinal a version. The gaps are
information: v2 was a real entry about this subject, and hiding it makes the history look complete
when it is not.

```php
"v{$ordinal}";   // claims a version the subject never had at that point
```

---

✅ **Do** — call `compare()` on an unbounded, subject-narrowed query, and read both entries when the
diff comes back empty.

```php
$comparison = Sentinel::audits()->for($invoice)->compare(1, 7);

if ($comparison->diff->isEmpty()) {
    $reason = $comparison->from->after === null ? 'no snapshots kept' : 'nothing changed';
}
```

❌ **Don't** — bound the query first and then compare. `compare()` inherits `take()` and `after()`, and
a prefix that misses the entry throws `ComparisonException::missingVersion` — which reads as "that
version does not exist" when it means "that version is not in the prefix you asked for".

```php
Sentinel::audits()->for($invoice)->take(10)->compare(1, 7);
```

---

✅ **Do** — key anything that must be unique on `id` or on `(stream, sequence)`.

```php
$entries->keyBy('id');
```

❌ **Don't** — treat `version` as a primary key for the subject. It is assigned with `max(version) + 1`
and no lock, `StreamGate` serialises by stream rather than by subject, and rehydration brings old
numbers back.

```php
$entries->keyBy('version');   // two entries can collide, silently
```

---

✅ **Do** — call `loadReferences()` before rendering a page of history that names actors or subjects.

```php
$history = Sentinel::audits()->for($customer)->whereFieldChanged('credit_limit')->take(200)->get();
$history->loadReferences();
```

❌ **Don't** — render `$entry->impersonator` over a collection. `loadReferences()` resolves `tags`,
`subject` and `actor` only; the impersonator is a query per line.

```php
foreach ($history as $entry) {
    echo $entry->impersonator?->name;   // N queries, or a LazyLoadingViolationException
}
```

---

✅ **Do** — reach the history through the Query API when the read has to be accountable. Compliance
mode records `changed_field` and `versions` in `sentinel_access_log`.

```php
Sentinel::audits()->for($patient)->whereFieldChanged('diagnosis')->take(50)->get();
```

❌ **Don't** — use the `field()` scope for a read a regulator will ask about. `Compliance\AccessLog` is
reached from `Query\AuditQuery` and from nowhere else, so this read leaves no access entry at all.

```php
$patient->audits()->field('diagnosis')->get();   // no access entry, even with compliance on
```

---

**See also:** [The Query API](01-the-query-api.md) · [Filters reference](02-filters-reference.md) · [Order, paging and walking](03-order-paging-and-walking.md) · [The timeline](05-the-timeline.md) · [Presenting and serializing](07-presenting-and-serializing.md) · [Diffs](../03-capture/03-diffs.md) · [Snapshots](../03-capture/02-snapshots.md) · [Restoring state](08-restoring-state.md) · [The hash chain](../07-integrity/01-the-hash-chain.md) · [Rehydration](../08-lifecycle/03-rehydration.md) · [Redaction and tombstones](../08-lifecycle/04-redaction-and-tombstones.md) · [Exceptions](../99-reference/06-exceptions.md)
