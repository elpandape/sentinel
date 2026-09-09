# 📥 State transitions

> How Sentinel records that a record moved from one state to the next — as an entry of its own kind,
> with a lifeline you read instead of a diff you have to mine.

**On this page:** [What a transition is](#what-a-transition-is) · [Stating one outright](#stating-a-transition-outright) · [Declaring the column](#declaring-the-state-column) · [Which column moved](#which-column-moved) · [Refusing an illegal move](#refusing-an-illegal-move) · [Reading the lifeline](#reading-the-lifeline)

---

## What a transition is

A transition is an audit entry whose `audit_type` is `transition`. It says that a named column of a
named record moved from one state to another, at a stated moment, on someone's say-so, optionally
with a reason.

**Sentinel does not perform the move.** It writes down that it happened. `Sentinel::transition()`
takes a model, a `from` and a `to`, and records the fact; it never touches the column, never saves
the model, and never calls a state-machine package on your behalf. Executing the transition is the
application's job — giving that away would make an audit engine into a workflow engine.

There are two ways an entry of this kind comes into existence, and they produce the same
`audit_type`, so a lifeline read does not care which was used:

| | `Sentinel::transition(…)->record()` | `$auditTransitions = ['status']` |
|---|---|---|
| What fires it | your explicit call | an Eloquent `update()` that moves a declared column |
| Which column | `->on()`, else a single declared column, else `transitions.attribute` | the declared column the diff shows moved |
| `changes` | one line: the column that moved | the whole diff of that save |
| `before` / `after` | absent — nothing was snapshotted | the snapshot pair, unless the model sets `$auditSnapshots = false` |
| `reason` | `->reason('…')` | **never** — the automatic route records no reason |
| Actor | `->actor()`, else the resolved actor | the resolved actor |
| Severity | `->severity()`, else `severity.events.transition`, else `severity.default` | the model's `$auditSeverity`, else `severity.events.transition`, else `severity.default` |
| Governed by `DeclaresTransitions` | yes, inside `record()` | yes, on Eloquent's `updating` — the **save itself** is abandoned |

Both routes set `event` to `transition` as well as `audit_type`. See
[Which column moved](#which-column-moved) for how the column is resolved, and
[What the entry holds](#what-the-entry-holds) for the columns each route fills in.

> 📌 **Note.** A transition is chained, hashed and signed exactly like every other entry. Rewriting
> its `changes` or its `metadata.transition.reason` in the database breaks `verifyIntegrity()` —
> the reason is inside the canonical payload, not beside it. See
> [The hash chain](../07-integrity/01-the-hash-chain.md).

---

## Stating a transition outright

`Sentinel::transition()` returns a `TransitionBuilder`. Every method on it chains; `record()` is the
terminal and it returns `void`.

```php
use App\Models\Invoice;
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Facades\Sentinel;

Sentinel::transition($invoice, from: 'pending', to: 'approved')
    ->on('status')
    ->reason('Budget confirmed')
    ->actor($approver)
    ->severity(Severity::Notice)
    ->tags(['billing'])
    ->metadata(['approved_by' => 'finance'])
    ->record();
```

`record()` returns nothing on purpose. When `transactions.after_commit` is on — it is, by default —
the write is deferred to the commit of the transaction on the subject's connection, so the entry
does not exist yet when the call comes back and there is no `Audit` to hand you.

| Method | Argument | What it does |
|---|---|---|
| `on()` | `string $attribute` | Names the column that moved. Becomes the JSON Pointer path of the change line and `metadata.transition.attribute`. |
| `reason()` | `string $reason` | Why it moved. Lands under `metadata.transition.reason`. |
| `actor()` | `object\|string $actor, int\|string\|null $id = null` | Attributes the move to someone other than the resolved actor. A class-string needs the second argument. |
| `severity()` | `Severity $severity` | Overrides the configured severity for this one entry. |
| `tags()` | `list<string> $tags` | Labels the entry, findable with `whereTag()`. |
| `metadata()` | `array<string, mixed>` | Your own metadata. The `transition` key is added on top of it. |
| `record()` | — | Resolves the column, asks the machine, records. Returns `void`. |

### `from` and `to` accept enums

`bool`, `float`, `int`, `string`, `UnitEnum` or `null` — normalised the same way
`Snapshot\SnapshotBuilder` normalises a column, so a transition and the snapshot of the same column
never disagree:

| Given | Recorded |
|---|---|
| A **backed** enum | its backing value — `Status::Draft` becomes `'draft'` |
| A **pure** enum | its case **name** — `PureStatus::Draft` becomes `'Draft'` |
| A scalar or `null` | itself |

> ⚠️ **Warning.** `'draft'` and `'Draft'` are two different states in the same lifeline. Pick one
> form of the state per column across the whole application; a codebase that passes a backed enum in
> one place and a pure enum in another produces a lifeline with two spellings of the same state and
> no way to reconcile them after the fact.

### What it refuses

`Sentinel::transition()` throws `QueryException::unsavedModel()` **from the constructor**, before any
modifier runs, when the subject has no key yet — no entry can point at a record that does not exist.
Everything else waits for `record()`, and a chain that ends at `->reason(…)` builds an object and
discards it silently, because building a builder is not an error.

```php
Sentinel::transition(new Invoice, from: 'draft', to: 'pending');
// ElPandaPe\Sentinel\Exceptions\QueryException:
// Cannot query for a App\Models\Invoice that has no key yet: no entry can reference it.
```

---

## Declaring the state column

`$auditTransitions` names the columns whose movement is a state change rather than an edit. An
`update()` that moves one of them is written as a transition instead of a `model` / `updated` entry.

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

```php
$invoice->update(['status' => 'pending']);   // audit_type = transition, event = transition
$invoice->update(['total' => 9000]);         // audit_type = model,      event = updated
$invoice->update(['status' => 'pending']);   // no entry at all — nothing moved
```

**One save is one entry.** An update that moves the state *and* three other columns writes a single
transition carrying the whole diff; the two states are read off the line naming the declared column.
Splitting the save into a transition plus an update would invent a second fact where there was one.

```php
$invoice->update(['status' => 'published', 'name' => 'Renamed']);

$audit = Sentinel::audits()->for($invoice)->take(1)->latest()->get()->first();

$audit->audit_type;             // 'transition'
$audit->diff()->toArray();
// [
//   ['path' => '/name',       'op' => 'replace', 'old' => 'invoice', 'new' => 'Renamed'],
//   ['path' => '/status',     'op' => 'replace', 'old' => 'draft',   'new' => 'published'],
//   ['path' => '/updated_at', 'op' => 'replace', 'old' => …,         'new' => …],
// ]
$audit->diffFor('status')->toArray()[0]['old'];   // 'draft'
```

### What the automatic route will not do

- **A creation is not a transition.** A record acquiring its first state through `create()` is a
  `model` / `created` entry. Only `updated` is examined.
- **A deletion is not a transition**, nor is a force-delete, nor a soft-delete revival — see
  [A restoration is not a transition](#a-restoration-is-not-a-transition).
- **An edit inside a structured column is not a state change.** `ModelCapture::moved()` matches the
  declared column's own JSON Pointer against the diff paths, and a change inside a JSON column is
  reported at a deeper path — `/options/a`, not `/options` — so the save comes out as an ordinary
  `model` / `updated` entry. It fails quietly rather than loudly: nothing a person would call
  *draft* or *approved* is an array.
- **It records no reason.** `metadata` on an automatic transition is exactly
  `['transition' => ['attribute' => '<column>']]`. If the move needs a reason, state it with the
  builder instead.

---

## Which column moved

`record()` resolves the column through three rungs, in this order:

1. `->on('phase')` — what the call named.
2. The model's `$auditTransitions`, **if it declares exactly one column**.
3. `config('sentinel.transitions.attribute')`, which ships as `'status'`.

A model that declares **two or more** state columns and a call that names none is refused rather
than guessed:

```php
final class Invoice extends Model
{
    use Auditable;

    /** @var list<string> */
    protected array $auditTransitions = ['status', 'billing_state'];
}

Sentinel::transition($invoice, from: 'draft', to: 'pending')->record();
// ElPandaPe\Sentinel\Exceptions\ConfigurationException:
// Sentinel cannot tell which column [App\Models\Invoice] moved: it declares status and
// billing_state as state columns, so the call has to name one with ->on(). Guessing would
// file the change under a column that did not move.
```

`->on('status')` resolves it. The exception is a developer error, not a user-facing one, so it is not
translated.

> ⚠️ **Warning.** Rung 3 is unchecked. A model that declares **no** `$auditTransitions` and a call
> with no `->on()` files the change under `transitions.attribute` — `'status'` as shipped — even when
> that model has no `status` column at all. Nothing verifies that the column exists. Name it with
> `->on()` on any model that has not declared one.

| Config key | Default | What it does | When to change |
|---|---|---|---|
| `transitions.attribute` | `'status'` | The column a stated transition is filed under when neither `->on()` nor a single declared column names one. Becomes the change line's pointer and `metadata.transition.attribute`. | When your convention for the state column is not `status` and most models do not declare `$auditTransitions`. |
| `severity.events.transition` | *not in the shipped file* — falls to `severity.default` (`info`) | The severity of a transition entry, on both routes. `->severity()` beats it. | When a state change is operationally louder in your system than an ordinary edit. |

---

## What the entry holds

```php
Sentinel::transition($invoice, from: 'pending', to: 'approved')
    ->reason('Budget confirmed')
    ->metadata(['reason' => 'this one is yours'])
    ->record();
```

| Column | Value |
|---|---|
| `audit_type` | `'transition'` |
| `event` | `'transition'` |
| `subject_type` / `subject_id` | the model's morph class and its key, as a string |
| `changes` | `[['path' => '/status', 'op' => 'replace', 'old' => 'pending', 'new' => 'approved']]` |
| `metadata` | `['reason' => 'this one is yours', 'transition' => ['attribute' => 'status', 'reason' => 'Budget confirmed']]` |
| `before` / `after` | `null` on the explicit route; the snapshot pair on the automatic one |
| `occurred_at` | when the fact happened, stamped at capture |

The column and the reason travel together under one `transition` key rather than loose at the top
level, so your own `metadata(['reason' => …])` stays yours. `reason` is omitted entirely when none
was given — it is not written as `null`.

Filing the two states as a diff line is what makes a transition findable with the ordinary filters,
with no new index and no new query surface:

```php
Sentinel::audits()->for($invoice)->whereFieldChanged('status')->get();   // reaches the transition
Sentinel::audits()->whereType('transition')->take(50)->get();            // every transition
```

> 📌 **Note.** `whereType('transition')` and `whereEvent('transition')` both match these entries, but
> they are different questions. Only `audit_type` is the kind of entry, and only it carries an index
> as `(audit_type, created_at)`. Your application is free to call a custom event `'transition'`, and
> `whereEvent()` would then match that too. See
> [Filters reference](../06-reading/02-filters-reference.md).

---

## Keeping the state column readable

A column named in `$auditTransitions` must remain readable in the entry. It cannot also appear in
`$auditExclude`, `$auditRedact`, `$auditEncrypt` or `$auditHash`, and a declared `$auditInclude`
cannot leave it out. The combination throws a `ConfigurationException` **the first time the model is
audited** — which for a fresh model is its `create()`:

```php
final class Invoice extends Model
{
    use Auditable;

    protected array $auditTransitions = ['status'];
    protected array $auditRedact = ['status'];
}

Invoice::query()->create(['status' => 'draft']);
// ElPandaPe\Sentinel\Exceptions\ConfigurationException:
// Sentinel cannot record [status] as a state transition and keep it out of the entry at the
// same time: it is also declared in $auditRedact. A lifeline the entry cannot show is not a
// lifeline.
```

This check reads the model's declarations, and it only knows about columns you declared as
transitions. A column named with `->on()` that is redacted, hashed or encrypted but **not** listed in
`$auditTransitions` slips past it — the entry is written, and its `old` and `new` come out masked.
Field protection matches by key name wherever the name appears, `changes` included. See
[Protecting sensitive data](../05-pipeline-and-security/02-protecting-sensitive-data.md).

---

## Refusing an illegal move

`Contracts\DeclaresTransitions` is optional. It has one method:

```php
namespace ElPandaPe\Sentinel\Contracts;

interface DeclaresTransitions
{
    public function allowsTransition(string $attribute, bool|float|int|string|null $from, bool|float|int|string|null $to): bool;
}
```

It is a contract rather than a property array because a real state machine is logic: whether an
invoice may go from *pending* to *paid* can depend on the invoice. It has one method rather than the
two exceptions a workflow package raises, because Sentinel asks and does not execute — "not
registered" and "not allowed right now" reach it as the same boolean.

```php
use ElPandaPe\Sentinel\Concerns\Auditable;
use ElPandaPe\Sentinel\Contracts\DeclaresTransitions;
use Illuminate\Database\Eloquent\Model;

final class Invoice extends Model implements DeclaresTransitions
{
    use Auditable;

    /** @var list<string> */
    protected array $auditTransitions = ['status'];

    public function allowsTransition(string $attribute, bool|float|int|string|null $from, bool|float|int|string|null $to): bool
    {
        return in_array([$attribute, $from, $to], [
            ['status', null, 'draft'],
            ['status', 'draft', 'pending'],
            ['status', 'pending', 'approved'],
        ], true);
    }
}
```

An already-integrated state-machine package is one delegation away — the method is the whole adapter:

```php
public function allowsTransition(string $attribute, bool|float|int|string|null $from, bool|float|int|string|null $to): bool
{
    return $attribute === 'status' && $this->workflow()->can((string) $from, (string) $to);
}
```

A refused move raises `Transitions\IllegalTransition`, and **nothing is written**: the exception is
raised before the entry reaches the pipeline, so the chain spends no sequence number on a fact that
was refused. It is user-facing, so it is translated through `resources/lang/{en,es}`:

```
Invoice #41 cannot move its status from pending to paid.
```

A column that held nothing is rendered as *nothing*, not as an empty string — a record has a state
before it has any, and `from ""` would read as a state named blank.

### Where the refusal happens

On the automatic route the check runs on Eloquent's `updating` event, so **the save itself is
abandoned**: the row is not written and the trail stays empty.

```php
try {
    $invoice->update(['status' => 'archived']);
} catch (\ElPandaPe\Sentinel\Transitions\IllegalTransition) {
    // the row still reads 'pending', and no entry exists
}
```

Validating on `updated` would leave the record holding a state the trail says never happened — worse
than not validating at all.

### What the machine does not govern

- **A model that implements nothing consents to everything.** Sentinel records the transition; it
  does not govern the workflow.
- **Creations are not vetted.** Only `updating` calls the machine, so a `create()` that sets the
  state to anything at all goes through.
- **Non-state values are skipped.** If either side of the column is an array or an object,
  `ModelCapture::vet()` skips that column and the machine is never asked about it.
- **Paused auditing governs nothing.** Inside `Sentinel::withoutAuditing()` the machine is never
  asked and the move goes through, because Sentinel refusing a move it would not have recorded would
  be governing the workflow. See
  [Turning auditing off](../02-getting-started/04-turning-auditing-off.md).

---

## Reading the lifeline

`Sentinel::transitions()` is an audit query with `whereType('transition')` already pinned, wrapped so
only the criteria that mean something about a sequence of states are published, and always ordered by
`occurred_at` — the clock of the fact, not the clock of the ledger.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Transitions\Transition;

$lifeline = Sentinel::transitions()->for($invoice)->take(200)->get();

foreach ($lifeline as $step) {
    /** @var Transition $step */
    $step->attribute;            // 'status'
    $step->from;                 // 'pending'
    $step->to;                   // 'approved'
    $step->reason;               // 'Budget confirmed', or null
    $step->actor?->id;           // a Support\Reference, or null
    $step->occurredAt;           // CarbonImmutable
    $step->since?->totalHours;   // 2.0 — how long it had been in the state it just left
    $step->entry;                // the Audit itself
}
```

| Method | What it narrows |
|---|---|
| `for($subject, $id = null)` | one record's lifeline |
| `by($actor, $id = null)` | who moved it |
| `between($from, $to)` | a window; throws `QueryException::backwardsPeriod()` when `$to < $from` |
| `latest()` | newest step first |
| `take($limit)` | a prefix, asked for on purpose; throws for a limit under 1 |
| `entries()` | drops back to the underlying `AuditQuery` |
| `get()` | `Collection<int, Transition>` |

### Dwell time is computed, never stored

`since` is the distance from the previous step, worked out in PHP at read time and persisted nowhere.
It is a fact about two entries rather than about either of them, and an entry that carried it would
be wrong the moment an earlier one was archived away.

Two consequences:

- `latest()` reverses the reading, not the arithmetic. The newest step still reports how long the
  record had been in the state it left, and the **last** row of a newest-first read is the one with
  `since === null`.
- **`since === null` means "first in what was read", not "first that ever happened".** With `take()`
  or `between()`, the earliest row of the result has a null interval that is indistinguishable from a
  genuine first transition.

### There is no `paginate()` on a lifeline

The interval of a page's first row is the distance to an entry the page does not hold, so a paged
lifeline would hand back a number that is either wrong or missing. `entries()` pages like any other
query — and it is a **different order**: it hands the query back before `get()` puts `byOccurrence()`
in front of it, so a paged read through `entries()` is ordered by `created_at`.

```php
Sentinel::transitions()->for($invoice)->entries()->paginate(20);          // by the ledger clock
Sentinel::audits()->whereType('transition')->for($invoice)->byOccurrence()->paginate(20);
```

> ⚠️ **Warning.** `get()` refuses rather than truncates: a lifeline of **more than** 500 entries read
> with no `take()` throws `QueryException::unbounded(500)`, while one of exactly 500 comes back
> whole. Put `->take(n)` on any lifeline for a long-running document — and see
> [Order, paging and walking the trail](../06-reading/03-order-paging-and-walking.md#how-much-comes-back)
> for the probe and why a refusal beats a prefix.

---

## Transitions start when you declare them

**Adopting `$auditTransitions` rewrites nothing.** The `updated` entries already in the trail keep
their `audit_type` of `model` and their `event` of `updated`, and `Sentinel::transitions()` sees only
the part of history that came after adoption. A lifeline that starts late is exactly that — not a gap
in the chain, and not a sign that anything was lost.

There is no backfill command and there will not be one. Rewriting the type of an entry that has
already been hashed and chained would break `verifyIntegrity()` for that entry and every entry after
it, and writing new transitions for old updates would put facts in the ledger that nobody stated at
the time. The entries are still there and still readable:

```php
// The moves the trail recorded as ordinary edits, before the column was declared:
Sentinel::audits()->for($invoice)->whereType('model')->whereFieldChanged('status')->take(100)->get();

// The moves recorded as transitions, from adoption onwards:
Sentinel::transitions()->for($invoice)->take(100)->get();
```

If you need one list, read both and concatenate them in the application. Sentinel will not pretend
the first set was something it was not.

---

## A restoration is not a transition

A `restore` entry never appears in `Sentinel::transitions()`, even when the restoration moves a
declared state column, and `DeclaresTransitions` does not govern it. A lifeline answers which states
the *workflow* moved through; an operator putting a record back is not one of them. Sentinel applies
a restoration with auditing paused and writes one entry of type `restore` describing what it put
back — so the machine is never asked, and the state change never surfaces as a step.

Two words that sound alike and are not:

| | Meaning |
|---|---|
| `event = 'restored'` | Eloquent's soft-delete revival — the row's `deleted_at` was cleared. `audit_type` is `model`, and it is never a transition even if the same save moved the state column, because only `updated` is examined. |
| `audit_type = 'restore'` | Sentinel put earlier state back into the record from an entry. |

See [Restoring state](../06-reading/08-restoring-state.md).

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `ConfigurationException: Sentinel cannot tell which column […] moved` | The model declares two or more `$auditTransitions` and the call named none. | Add `->on('status')`. Rung 2 only fires for exactly one declared column. |
| `ConfigurationException: … and keep it out of the entry at the same time` on a plain `create()` | The state column is also in `$auditExclude` / `$auditRedact` / `$auditEncrypt` / `$auditHash`, or missing from a declared `$auditInclude`. | Remove it from the protection list, or stop declaring it as a transition. A masked lifeline answers nothing. |
| A transition's `old` and `new` come back as a mask | The column was named with `->on()` and is protected on the model, but is not in `$auditTransitions`, so the readable-column check never saw it. | Declare it in `$auditTransitions` — the check will then refuse the combination up front — or stop protecting it. |
| A transition's `reason` comes back as a mask | Field protection matches by key name at any depth, and `metadata.transition.reason` uses the key `reason`. A model that protects a field literally called `reason` masks it. | Rename the model's field, or put the explanation in your own `metadata()` under a different key. |
| `QueryException: This filter matches at least 500 entries` from a lifeline | `get()` refuses rather than truncating, and the lifeline outgrew `AuditQuery::DEFAULT_LIMIT`. | `->take(n)`, or `->entries()->paginate(n)` — which loses the interval arithmetic. |
| `since` is `null` on a step that is obviously not the first | The interval is the distance to the previous step **in what was read**, and `take()` / `between()` cut it off. | Read from the start of the lifeline, or treat the first row of any bounded read as having no interval. |
| The lifeline order disagrees with `->entries()` | `get()` forces `byOccurrence()`; `entries()` hands back the query before that, ordered by `created_at`. The two clocks agree only while writing is synchronous. | Call `->byOccurrence()` yourself on the query `entries()` returns. |
| `'draft'` and `'Draft'` appear as separate states | A pure enum lands as its case name, a backed enum as its value. | Use one form for the column everywhere. Pure enums are legal but unforgiving. |
| Declaring a JSON column in `$auditTransitions` does nothing | An edit inside a JSON column is reported at a deeper diff path (`/options/a`), so the declared column's own pointer never matches and the save comes out as `model` / `updated`. | Nothing to fix — pick a scalar column. Sentinel fails quietly here by design. |
| `Sentinel::transition($x, from: 'draft', to: 'draft')` writes an entry | Nothing on the explicit route refutes a move that is not a move; only a `DeclaresTransitions` implementation can. | Guard it in the caller, or refuse `$from === $to` in `allowsTransition()`. The automatic route cannot have this problem — no diff, no entry. |
| An update moved two declared columns, and the entry names only one | `metadata.transition.attribute` is the **first** column of `$auditTransitions` that the diff shows moved. The whole diff is still on the entry. | Read the diff, not just the attribute, when a model declares more than one state column. |
| The machine let an illegal move through | Governance is skipped while auditing is paused or disabled, and on `create()`. | Do the workflow check in the application too. `DeclaresTransitions` guards the trail, not the domain. |
| Nothing was written and no exception was raised | The chain ended without `record()`, or auditing was paused. `record()` returns early when Sentinel is not recording. | Call `record()`. Check `Sentinel::isRecording()` if you expected an entry. |

---

## ✅ Best practices

✅ **Do** — name the column explicitly on any model that has not declared one. Rung 3 falls through
to `transitions.attribute` without checking that the column exists.

```php
Sentinel::transition($order, from: 'packing', to: 'shipped')->on('fulfilment_state')->record();
```

❌ **Don't** — rely on the configured default for a model whose state column is called something
else. The entry is filed under `/status`, `whereFieldChanged('fulfilment_state')` will not find it,
and nothing warns you.

```php
Sentinel::transition($order, from: 'packing', to: 'shipped')->record();   // filed under /status
```

---

✅ **Do** — bound every lifeline read. A document that lives long enough will pass 500 steps, and the
query refuses rather than truncating.

```php
$lifeline = Sentinel::transitions()->for($invoice)->take(200)->get();
```

❌ **Don't** — read an unbounded lifeline and discover the limit in production. The failure is a
thrown `QueryException`, not a short list.

```php
$lifeline = Sentinel::transitions()->for($invoice)->get();   // throws once it outgrows 500
```

---

✅ **Do** — keep `DeclaresTransitions` as a thin delegation to whatever already owns the workflow, and
keep enforcing that workflow in the domain as well.

```php
public function allowsTransition(string $attribute, bool|float|int|string|null $from, bool|float|int|string|null $to): bool
{
    return $attribute === 'status' && $this->workflow()->can((string) $from, (string) $to);
}
```

❌ **Don't** — treat the contract as your only guard. It is skipped inside `withoutAuditing()`, on
`create()`, and for any column holding a structure — an audit engine is not a workflow engine.

```php
Sentinel::withoutAuditing(fn () => $invoice->update(['status' => 'archived']));   // moves anyway
```

---

✅ **Do** — state a reason when the move needs one, and let it live where Sentinel puts it.

```php
Sentinel::transition($invoice, from: 'pending', to: 'rejected')
    ->reason('Missing purchase order')
    ->record();
```

❌ **Don't** — expect the automatic route to carry a reason. `$auditTransitions` records the column
and nothing else; a reason you want on the entry has to be stated.

```php
$invoice->update(['status' => 'rejected']);
// metadata is exactly ['transition' => ['attribute' => 'status']]
```

---

✅ **Do** — use one representation of a state per column, and prefer a backed enum so the recorded
value is the one the column holds.

```php
enum Status: string
{
    case Draft = 'draft';
    case Pending = 'pending';
}

Sentinel::transition($invoice, from: Status::Draft, to: Status::Pending)->record();   // 'draft' → 'pending'
```

❌ **Don't** — mix a pure enum, a backed enum and a raw string for the same column. The pure enum
lands as `'Draft'` and will never line up with the `'draft'` the other two recorded.

```php
Sentinel::transition($invoice, from: PureStatus::Draft, to: 'pending')->record();   // 'Draft' → 'pending'
```

---

✅ **Do** — say out loud, in your own runbooks, that the lifeline starts at adoption. Read the older
moves as what they are when you need them.

```php
Sentinel::audits()->for($invoice)->whereType('model')->whereFieldChanged('status')->take(100)->get();
```

❌ **Don't** — rewrite old entries to backfill a lifeline. Changing `audit_type` on a chained entry
breaks its hash and every link after it, and `verifyIntegrity()` will report it for the rest of the
stream's life.

```php
DB::table('sentinel_audits')->where('event', 'updated')->update(['audit_type' => 'transition']);
```

---

**See also:** [What gets audited](01-what-gets-audited.md) · [Diffs](03-diffs.md) · [Snapshots](02-snapshots.md) · [Business transactions](06-business-transactions.md) · [Restoring state](../06-reading/08-restoring-state.md) · [Filters reference](../06-reading/02-filters-reference.md) · [What a model declares](../02-getting-started/03-what-a-model-declares.md) · [Exceptions](../99-reference/06-exceptions.md)
