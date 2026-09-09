# 📥 Diffs

> The structured change list an entry carries: how it is computed, how a path is written, how it
> maps onto RFC 6902 JSON Patch, and how to read one off an entry.

**On this page:** [What a change looks like](#what-a-change-looks-like) · [How a diff is computed](#how-a-diff-is-computed) · [Every operation, with an example](#every-operation-with-an-example) · [Paths are JSON Pointers](#paths-are-json-pointers) · [Nested maps and lists](#nested-maps-and-lists) · [Reading a diff off an entry](#reading-a-diff-off-an-entry) · [The comparator on its own](#the-comparator-on-its-own) · [RFC 6902 interoperability](#rfc-6902-interoperability)

---

## What a change looks like

An entry's `changes` column holds a **list of entries**, each one a map with four keys in a fixed
order:

```php
[
    ['path' => '/profile/address/city', 'op' => 'replace', 'old' => 'Lima', 'new' => 'Arequipa'],
    ['path' => '/roles/1',              'op' => 'remove',  'old' => ['id' => 7], 'new' => null],
]
```

| Key | Type | Meaning |
|---|---|---|
| `path` | `string` | An RFC 6901 JSON Pointer into the compared structure. `''` is the root. |
| `op` | `string` | One of `add`, `remove`, `replace` — `Diff\Diff::OPERATIONS`, and nothing else. |
| `old` | `mixed` | The previous value. **Omitted entirely** when it was never recoverable. |
| `new` | `mixed` | The new value. Always present. |

In PHP the same thing is `ElPandaPe\Sentinel\Diff\Change`, an immutable object with public readonly
`path`, `op`, `old`, `new` and `oldKnown`.

`old` travels next to `new` because the previous value is the *point* of an audit and RFC 6902 has
no room for it. `oldKnown` is how the component says *there is no previous value here* rather than
letting `null` pretend to be one — and `Change::toArray()` drops the `old` key entirely when it is
false, so the distinction survives the database.

> 📌 **Note.** `op` is deliberately a plain string, not an enum. `ElPandaPe\Sentinel\Diff` imports
> nothing from the rest of the package — an enum would have to live in `Enums/` by repo convention
> and would break the component's isolation. Two tests enforce that: an architecture test
> enumerating the forbidden namespaces, and one that runs a comparison in a PHP process where
> `Illuminate\Container\Container` is never even declared.

---

## How a diff is computed

`Diff::between($before, $after)` is two steps:

1. **`Diff\Normalizer::value()`** reduces each side to scalars, lists and maps.
2. **`Diff\Comparator::compare()`** walks the two reduced structures with **strict equality**.

The normalizer mirrors what [the snapshot builder](02-snapshots.md#the-serialisation-rule-per-type)
already applied, for the caller who hands it two structures the package never built:

| Value | Reduced to |
|---|---|
| `null`, scalar | itself |
| Backed enum | `->value` |
| Pure enum | `->name` |
| `DateTimeInterface` | `format('Y-m-d\TH:i:s.uP')` — the same constant string as the snapshot builder, tied together by a test |
| `Arrayable` (including `Collection`) | `toArray()`, recursively |
| `JsonSerializable` | `jsonSerialize()`, then reduced again |
| `Jsonable` | `json_decode(toJson(), true)`, then reduced again |
| `array` | element by element, **order preserved** |
| `Stringable` | `(string) $value` |
| anything else | throws `DiffException::unsupportedType`, naming the path |

Two differences from the snapshot builder, both deliberate:

- The normalizer **does not sort map keys**. A diff over two already-sorted snapshots does not need
  it, and re-sorting an arbitrary caller-supplied structure would misrepresent its shape. Key order
  is not a change either way — the comparator sorts the union of keys before walking it.
- The normalizer **has a depth limit**: `MAX_DEPTH = 64`. Past it, `DiffException::tooDeep` names
  the path where it gave up.

Comparison is strict all the way down. None of these are equal:

```php
use ElPandaPe\Sentinel\Diff\Diff;

Diff::between(['a' => '1'],  ['a' => 1]);     // one replace
Diff::between(['a' => 1.0],  ['a' => 1]);     // one replace
Diff::between(['a' => false], ['a' => 0]);    // one replace
Diff::between(['a' => ''],   ['a' => null]);  // one replace

Diff::between(['b' => 2, 'a' => 1], ['a' => 1, 'b' => 2]);  // empty: key order is not a change
```

And the two null-shaped cases are told apart, which is most of why the diff exists:

```php
Diff::between(['a' => 1], ['a' => null])->toArray();
// [['path' => '/a', 'op' => 'replace', 'old' => 1, 'new' => null]]   the key stayed, the value went

Diff::between(['a' => 1], [])->toArray();
// [['path' => '/a', 'op' => 'remove',  'old' => 1, 'new' => null]]   the key itself went
```

---

## Every operation, with an example

| `op` | Emitted when | `old` | `new` | Entry |
|---|---|---|---|---|
| `add` | The key or index exists only in `after` | `null`, and *known* — there genuinely was none | the new value | `['path' => '/score', 'op' => 'add', 'old' => null, 'new' => 1]` |
| `remove` | The key or index exists only in `before` | the previous value | `null` | `['path' => '/name', 'op' => 'remove', 'old' => 'Ada', 'new' => null]` |
| `replace` | Present on both sides and not identical | the previous value | the new value | `['path' => '/name', 'op' => 'replace', 'old' => 'Ada', 'new' => 'Grace']` |
| `replace` at the root | Only one side is a structure, or the two values are plain scalars | the whole old side | the whole new side | `['path' => '', 'op' => 'replace', 'old' => 'Lima', 'new' => 'Arequipa']` |
| `replace` of a subtree | A populated map became a populated list, or the reverse | the whole old subtree | the whole new subtree | `['path' => '/a', 'op' => 'replace', 'old' => ['x' => 1], 'new' => [1]]` |
| `add` of a subtree | A whole branch appeared | `null` | the entire branch | `['path' => '/profile', 'op' => 'add', 'old' => null, 'new' => ['city' => 'Lima']]` |

There is no `move`, no `copy` and no `test` in a stored diff — `Diff::OPERATIONS` is exactly
`['add', 'remove', 'replace']`, and `Diff::fromEntries()` refuses anything else.

The shape rule has one exception, and it is load-bearing: **an empty array never counts as a shape
change**, because PHP cannot tell an empty map from an empty list (`array_is_list([]) === true`).
Without that guard a creation — which compares `[]` against a populated map — would emit a single
`replace` of the root instead of a list of `add`s.

```php
Diff::between([], ['name' => 'Ada', 'score' => 1])->toArray();
// [
//   ['path' => '/name',  'op' => 'add', 'old' => null, 'new' => 'Ada'],
//   ['path' => '/score', 'op' => 'add', 'old' => null, 'new' => 1],
// ]
```

So for a model entry: `created` produces only `add`s, `deleted` only `remove`s, and an `updated`
that changed nothing comparable produces `[]`.

> ⚠️ **Warning.** `[]` and `null` mean different things in `changes` too. `[]` is *the comparison
> ran and found nothing*; `null` is *there was nothing to compare* — an event with no snapshot pair,
> or an entry written before the diff existed. An `updated` whose diff is `[]` is discarded by the
> `FilterUnchanged` pipeline stage and never reaches the ledger; see
> [Discarding entries](../05-pipeline-and-security/06-discarding-entries.md).

---

## Paths are JSON Pointers

`Diff\Pointer` implements RFC 6901. Two characters are reserved and **the escape order matters**:

| Character | Escaped as | Order |
|---|---|---|
| `~` | `~0` | first |
| `/` | `~1` | second |

Unescaping runs the other way — `~1` → `/` before `~0` → `~` — so a tilde introduced by escaping a
slash is never escaped twice.

```php
Diff::between(['a/b' => 1, 'c~d' => 1], ['a/b' => 2, 'c~d' => 2])->toArray();
// [
//   ['path' => '/a~1b', 'op' => 'replace', 'old' => 1, 'new' => 2],
//   ['path' => '/c~0d', 'op' => 'replace', 'old' => 1, 'new' => 2],
// ]
```

**A dot is not reserved by RFC 6901**, so a key holding one keeps it verbatim: `['a.b' => 1]`
becomes the path `/a.b`.

`Pointer::of()` accepts two input forms wherever a path is asked for:

| You pass | Read as | Result for a key literally named `a.b` |
|---|---|---|
| `'/profile/address'` (leading slash) | a literal pointer, passed through untouched | `'/a.b'` finds it |
| `'profile.address'` | dot notation, split on `.` and each segment escaped | `'a.b'` becomes `/a/b` and finds nothing |
| `''` | the root — everything | — |

That is the one case where the two forms are not interchangeable. Reach for dot notation in the
common case and for the literal pointer whenever a key might contain a dot.

`Pointer::covers()` decides whether a pointer reaches a path: exact equality, or the pointer
followed by a slash. **A prefix never matches a sibling.**

```php
$diff = Diff::between(['role' => 1, 'roles' => 1], ['role' => 2, 'roles' => 2]);

$diff->for('role')->toArray();
// [['path' => '/role', 'op' => 'replace', 'old' => 1, 'new' => 2]]   /roles is not reached
```

This is the same rule [field history](../06-reading/04-field-history.md) applies, so
`whereFieldChanged('email')` never matches `/email_verified_at` either — the package has one answer
to *did this field change*, not two.

---

## Nested maps and lists

**Maps** are walked key by key. The union of both sides' keys is sorted, so a diff of the same two
structures always comes out in the same order, and the entry names the leaf that moved rather than
the branch above it:

```php
$before = ['profile' => ['address' => ['city' => 'Lima', 'zip' => '15001']]];
$after  = ['profile' => ['address' => ['city' => 'Arequipa', 'zip' => '15001']]];

Diff::between($before, $after)->toArray();
// [['path' => '/profile/address/city', 'op' => 'replace', 'old' => 'Lima', 'new' => 'Arequipa']]
```

**Lists** are matched one of two ways. `Comparator::identity()` switches to identity matching only
when **every element on both sides** is an array carrying a unique scalar `id` — or, failing that,
`uuid`. Otherwise the list is compared by position.

| Condition | Matching |
|---|---|
| Every element on both sides has a unique scalar `id` | by `id` |
| No `id`, but every element on both sides has a unique scalar `uuid` | by `uuid` |
| One element missing the key | by position |
| An id repeated within its own list | by position |
| An id that is not a scalar | by position |
| Elements are scalars | by position |
| Either side is empty | by position |

Under identity matching an element is *followed*, not compared where it happens to sit:

```php
$before = ['roles' => [['id' => 1, 'name' => 'admin'], ['id' => 3, 'name' => 'viewer']]];
$after  = ['roles' => [
    ['id' => 1, 'name' => 'admin'],
    ['id' => 2, 'name' => 'editor'],
    ['id' => 3, 'name' => 'viewer'],
]];

Diff::between($before, $after)->toArray();
// [['path' => '/roles/1', 'op' => 'add', 'old' => null, 'new' => ['id' => 2, 'name' => 'editor']]]
```

One addition — not *everything changed from here down*. Reordering an identified list is no change
at all, and an element that moved **and** changed reports only the leaf that moved.

Two consequences that surprise people:

- **An `add` is indexed on the document it appears in, a `remove` on the one it left**, so the two
  can legitimately land on the same index:

  ```php
  Diff::between(['roles' => [['id' => 1], ['id' => 2]]], ['roles' => [['id' => 1], ['id' => 3]]])->toArray();
  // [
  //   ['path' => '/roles/1', 'op' => 'add',    'old' => null,       'new' => ['id' => 3]],
  //   ['path' => '/roles/1', 'op' => 'remove', 'old' => ['id' => 2], 'new' => null],
  // ]
  ```

- **An id of `1` and an id of `'1'` are different elements.** The identity key is
  `var_export($value, true)`, which renders the type as well as the value, so strict equality holds
  through the matching step. Changing an id's type produces an `add` plus a `remove`, not a
  `replace`.

---

## Reading a diff off an entry

```php
use ElPandaPe\Sentinel\Diff\Diff;

$audit = $invoice->latestAudit();

$audit->diff();                       // Diff — Countable and IteratorAggregate
$audit->diff()->toArray();            // the entries as the column stores them
$audit->diff()->isEmpty();            // bool
count($audit->diff());                // int

foreach ($audit->diff() as $entry) {
    // $entry is the map, not a Change object:
    // ['path' => …, 'op' => …, 'old' => …, 'new' => …]
}

$audit->diffFor('profile.address');   // the same diff, narrowed to a subtree
$audit->diffFor('/profile/address');  // identical, written as a literal pointer
```

`Models\Audit::diff()` resolves in this order:

1. The stored `changes` column, when it is not null.
2. If those are **relation lines** rather than diff entries, they are read as a diff (see below).
3. When `changes` is null, the diff is **computed on read** from `before` and `after` — and the row
   is not touched. History is append-only: entries written before the diff existed are never
   backfilled.

An entry that has neither `changes` nor snapshots answers with an empty `Diff`, not an error.

> 📌 **Note.** Inside `Models\Audit` and any subclass of it, `$this->changes` is **Eloquent's
> dirty-attribute property**, not the column — `Illuminate\Database\Eloquent\Model` declares a
> protected `$changes` and it wins over `__get`. That is why `diff()` reads
> `$this->getAttribute('changes')`. From outside the model, `$audit->changes` works as expected.

### Relation entries

A `relation` entry stores **relation lines**, not diff entries. `Audit::diff()` sniffs which it is
from the data — a line says `relation` and `operation` where a diff entry says `path` and `op` —
and reads the lines through `Data\RelationLine::asDiff()` so one caller can walk a mixed trail:

| Relation line | Diff entry |
|---|---|
| `operation: attach` | `op: add` |
| `operation: detach` | `op: remove` |
| anything else (`update`, …) | `op: replace` |
| `relation` + `related_id` | `path: /{relation}/{related_id}` |
| `pivot_before` / `pivot_after` | `old` / `new` |

This is **presentation only** — nothing in that shape is ever written. And because a relation line
carries no `path`, `whereFieldChanged()` never matches a relation entry. See
[Relationship auditing](04-relationships.md).

### When a column holds neither shape

`Diff::fromEntries()` refuses an entry that lacks `path` or `new`, or that carries an `op` outside
`Diff::OPERATIONS`, with `DiffException::malformedEntry` naming the index. Only a row this package
did not write can be in that state — `Audit::toArray()` deliberately catches it and hands the column
back exactly as found, so one foreign row cannot stop a whole page of the trail from serialising.

---

## The comparator on its own

`Diff` knows nothing about Eloquent, about the database, or about the rest of Sentinel. Use it
anywhere you need a structured comparison:

```php
use ElPandaPe\Sentinel\Diff\Diff;
use ElPandaPe\Sentinel\Diff\DiffException;

$diff = Diff::between($payloadWeSent, $payloadTheyReturned);

if (! $diff->isEmpty()) {
    report(new PayloadDivergence($diff->toArray()));
}
```

Three constructors and what each is for:

| Constructor | Input | Validates |
|---|---|---|
| `Diff::between($before, $after)` | any two structures | normalises both, then compares |
| `Diff::fromEntries($entries)` | the `{path, op, old?, new}` list a column stores | yes — `DiffException::malformedEntry` naming the index |
| `Diff::fromChanges($changes)` | a `list<Change>` you built yourself | no |

The only failure the component raises is `ElPandaPe\Sentinel\Diff\DiffException`, and it lives
inside `Diff/` for the same isolation reason as everything else there:

| Factory | Raised when |
|---|---|
| `unsupportedType` | A value no contract reaches — a resource, a plain object. Names the path. |
| `tooDeep` | The structure is deeper than 64 levels. Names where it gave up. |
| `malformedEntry` | A stored entry is not a `{path, op, old, new}` entry. Names the index. |
| `malformedPatch` | A JSON Patch operation is not an array, has no `op`/`path`, or is an `add`/`replace` with no `value`. Names the index. |
| `unsupportedOperation` | A patch carries `move`, `copy` or anything outside `add`/`remove`/`replace`/`test`. Names the operation. |

---

## RFC 6902 interoperability

### Exporting

```php
$diff = Diff::between(['a' => 1, 'b' => 2], ['a' => 9, 'c' => 3]);

$diff->toJsonPatch();
// [
//   ['op' => 'test',    'path' => '/a', 'value' => 1],
//   ['op' => 'replace', 'path' => '/a', 'value' => 9],
//   ['op' => 'test',    'path' => '/b', 'value' => 2],
//   ['op' => 'remove',  'path' => '/b'],
//   ['op' => 'add',     'path' => '/c', 'value' => 3],
// ]

$diff->toJsonPatch(tests: false);
// the same, without the two `test` operations
```

The `test` operations are what make the patch **verifiable against the document it came from**: RFC
6902 has nowhere to put the old value, so it travels as a guard. The rules:

- A `test` precedes every `replace` and `remove` whose old value is known.
- Never before an `add` — there was no previous value to test.
- A `remove` never carries a `value`, because the RFC does not give it one.
- A change whose `oldKnown` is false emits no `test`, even with `tests: true`.

### Importing

```php
Diff::fromJsonPatch($patch);
```

| Patch input | Becomes | `old` |
|---|---|---|
| `test` + `replace` on the same path | one `replace` | restored from the `test` |
| `test` + `remove` on the same path | one `remove` | restored from the `test` |
| bare `replace` | one `replace` | **absent** — `oldKnown` false, and `toArray()` omits the key |
| bare `remove` | one `remove` | absent |
| `add` | one `add` | `null`, and *known*: there genuinely was none |
| a `test` guarding nothing, or guarding another path | dropped | — |
| `move`, `copy` | — | `DiffException::unsupportedOperation` |

A `test` guards **only the operation immediately after it, and only on the same path**. Anything
else is ignored rather than misapplied.

```php
// With the tests, the round trip is lossless.
Diff::fromJsonPatch($diff->toJsonPatch())->toArray() === $diff->toArray();   // true

// Without them, the loss is reported rather than papered over.
Diff::fromJsonPatch($diff->toJsonPatch(tests: false))->toArray();
// [
//   ['path' => '/a', 'op' => 'replace', 'new' => 9],              // no 'old' key at all
//   ['path' => '/b', 'op' => 'remove',  'new' => null],
//   ['path' => '/c', 'op' => 'add', 'old' => null, 'new' => 3],   // an addition rebuilds
// ]
```

> ⚠️ **Warning.** `toJsonPatch()` produces a **verifiable** patch, not necessarily an **applicable**
> one. It emits operations in comparison order and does not reorder them. Over an identified list
> with a simultaneous insertion and removal, an `add` indexed on the destination document and a
> `remove` indexed on the source can land on the same index, and applying them in that order shifts
> the indices under each other. Putting a record back is
> [restore](../06-reading/08-restoring-state.md)'s job, and it is the one that decides how.

---

## The diff in the pipeline and the chain

`changes` is inside the canonical payload that the chain hashes
(`Integrity\CanonicalPayload::COLUMNS`), so three things about it are load-bearing:

- **The order of entries is fixed at capture and never re-sorted downstream.** The `NormalizeData`
  pipeline stage sorts `before`, `after`, `metadata` and `context` and deliberately leaves `changes`
  alone: for relation entries the line order set at capture is the only thing that makes two runs of
  the same `sync()` hash alike.
- **The diff duplicates every value it reports.** What is in `before` and `after` is also in
  `changes`. The pipeline handles that: `Security\Fields` walks `changes` alongside the two
  snapshots, transforming `old` and `new` for a redacted, hashed or encrypted field while leaving
  the `path` intact — so the entry proves *something changed at this field* without saying what.
  See [Protecting sensitive data](../05-pipeline-and-security/02-protecting-sensitive-data.md).
- **Changing what a diff entry looks like is a `payload_version` change**, not a formatting choice.

---

## Engine notes

> 🐘 **Engine.** MySQL's binary JSON and PostgreSQL's `jsonb` both **reorder the keys inside every
> object**, so a stored entry can come back as `{op, new, old, path}` instead of
> `{path, op, old, new}`. The **values** survive intact and so does the **order of the entries** —
> only the key order inside an entry does not. Nothing in the package depends on it: entries are
> read by key, `Audit::toArray()` re-imposes the package order, and the chain hashes the canonical
> form (RFC 8785 sorts members before hashing), never the stored text. SQLite stores JSON as text
> and preserves insertion order, so this never shows there.

Anything that compares the `changes` column **as text** — a signature over the raw column, a diff of
two database dumps — will disagree across engines. Compare the parsed structure, or the entry's
hash.

> 🧪 **Verify it.** `make test ARGS=tests/Diff` covers the comparator, pointers, list matching and
> the patch round trip; `make test-dbs` runs the round-trip suites against MySQL 9 and PostgreSQL 16.

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `diffFor('a.b')` finds nothing for a key literally named `a.b` | Dot notation splits on `.` and escapes each segment, producing `/a/b`. | Pass the literal pointer: `diffFor('/a.b')`. |
| A whole list shows as changed after inserting one element | Identity matching was not available — an element without `id`/`uuid`, a repeated id, a non-scalar id, or scalar elements. The list fell back to positional comparison. | Give every element a unique scalar `id` or `uuid` on both sides, or accept positional diffs for that field. |
| Changing an id from `7` to `'7'` yields an add plus a remove | The identity key renders the type (`var_export`), so `7` and `'7'` are different elements. | Keep id types stable, or expect the pair. |
| `old` is missing from an entry | The value was never recoverable — typically a JSON Patch imported without its `test` operations. `Change::oldKnown` is false and `toArray()` omits the key. | Export with `toJsonPatch()` default `tests: true`; treat a missing `old` as *unknown*, never as `null`. |
| Applying `toJsonPatch()` output to a document corrupts a list | Adds are indexed on the destination and removes on the source; the exporter does not reorder them. | Use the patch to verify, not to apply. Use `$audit->restore()` to put a record back. |
| An `updated` produced no entry at all | Its diff was `[]` — the save moved only excluded columns — and `FilterUnchanged` discarded it before the ledger. | Expected. Remove the stage from `sentinel.pipeline` if you want those entries. |
| `$this->changes` is the wrong thing inside an `Audit` subclass | Eloquent declares a protected `$changes` holding the dirty set of the last `save()`, and it wins over the cast column. | Read `$this->getAttribute('changes')`. Same hole for any column sharing a name with an Eloquent property. |
| `whereFieldChanged()` never matches a relation entry | A relation line carries `relation`/`operation`, not `path`. | Use `whereRelation()` / `whereOperation()` for relation entries. |
| `DiffException: the structure is deeper than the 64 levels…` on save | A cast returned an attribute nested past `Normalizer::MAX_DEPTH`. The snapshot builder has no such limit, so it succeeds and the diff then fails. | Flatten the structure, or exclude the attribute. |
| A raw text comparison of `changes` differs between two databases | MySQL and PostgreSQL reorder object keys inside the column. | Compare the parsed array, or the entry hash. |

---

## ✅ Best practices

✅ **Do** — reach for `$audit->diff()` instead of comparing `before` and `after` by hand. It answers
with the same `Diff` type for an entry with stored changes, an entry with only snapshots, an entry
with neither, and a relation entry.

```php
foreach ($invoice->audits as $audit) {
    foreach ($audit->diff() as $entry) {
        logger()->info("{$entry['path']} {$entry['op']}");
    }
}
```

❌ **Don't** — hand-roll the comparison off the two snapshots. You will re-implement identity
matching, pointer escaping and the `null`-versus-absent distinction, and you will get an error on
entries that carry no snapshots at all.

```php
$moved = array_diff_assoc($audit->after ?? [], $audit->before ?? []);   // wrong on every count
```

---

✅ **Do** — pass a literal JSON Pointer whenever a key might contain a dot. The pointer form is the
one that is never ambiguous.

```php
$audit->diffFor('/metrics/p95.latency');
```

❌ **Don't** — assume dot notation and pointers are interchangeable. They are for the common case
only, and the failure is silent: an empty diff that reads exactly like *nothing changed*.

```php
$audit->diffFor('metrics.p95.latency');   // looks for /metrics/p95/latency — finds nothing
```

---

✅ **Do** — export with the default `tests: true` when the patch has to be checkable against the
document it came from. The `test` operations carry the old value RFC 6902 has no room for.

```php
$patch = $audit->diff()->toJsonPatch();
Diff::fromJsonPatch($patch)->toArray() === $audit->diff()->toArray();   // true
```

❌ **Don't** — treat a missing `old` key as `old = null`. Those are different statements: *there was
no previous value* versus *the previous value was not recoverable from this patch*.

```php
$old = $entry['old'] ?? null;   // erases the distinction the format went out of its way to keep
```

---

✅ **Do** — give the elements of an audited JSON list a stable scalar `id` or `uuid`, so an
insertion in the middle reads as one addition rather than a cascade of replacements.

```php
$invoice->lines = [
    ['id' => 1, 'sku' => 'A-1', 'qty' => 2],
    ['id' => 2, 'sku' => 'B-7', 'qty' => 1],
];
```

❌ **Don't** — rely on positional lists for anything an auditor will read. Reordering a positional
list produces one `replace` per position, and the trail says everything changed when nothing did.

```php
$invoice->tags = ['urgent', 'export'];   // reordering these is two replaces
```

---

✅ **Do** — use `Diff::between()` outside auditing when you need a structured comparison. It is a
self-contained component with no Eloquent dependency, and an isolated-process test proves it.

```php
$divergence = Diff::between($expected, $actual);
```

❌ **Don't** — add anything to the `ElPandaPe\Sentinel\Diff` namespace that imports
`Illuminate\Database` or another Sentinel namespace. An architecture test enumerates the forbidden
list and a second test boots a PHP process without the container to catch what it misses.

```php
use Illuminate\Database\Eloquent\Model;   // fails the arch test
```

---

**See also:** [Snapshots](02-snapshots.md) · [What gets audited](01-what-gets-audited.md) · [Relationship auditing](04-relationships.md) · [Field history and comparing versions](../06-reading/04-field-history.md) · [Protecting sensitive data](../05-pipeline-and-security/02-protecting-sensitive-data.md) · [Discarding entries](../05-pipeline-and-security/06-discarding-entries.md) · [Serialization](../99-reference/08-serialization.md)
