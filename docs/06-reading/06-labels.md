# 🔎 Labels

> The classification layer of the trail: one word hung off an entry so you can find it again — and
> the one part of an entry that the hash does not cover.

**On this page:** [What a label is](#what-a-label-is) · [A label is not evidence](#a-label-is-not-evidence) · [The three places a label comes from](#the-three-places-a-label-comes-from) · [What each capture path can label](#what-each-capture-path-can-label) · [The length cap](#the-length-cap) · [Querying by label](#querying-by-label) · [Reading the labels off an entry](#reading-the-labels-off-an-entry) · [The table, its two indexes and its missing foreign key](#the-table-its-two-indexes-and-its-missing-foreign-key) · [Label, metadata, or a custom event?](#label-metadata-or-a-custom-event) · [Naming that survives three years](#naming-that-survives-three-years) · [Engine notes](#engine-notes) · [⚠️ Pitfalls](#️-pitfalls) · [✅ Best practices](#-best-practices)

---

## What a label is

A label is a short string attached to one audit entry. `billing`. `gdpr`. `region:eu-west`. Nothing
more: there is no value half, no type, no hierarchy, no nesting.

Labels live in their own table — `sentinel_audit_tags` by default — as pairs of `audit_id` and
`tag`. `ElPandaPe\Sentinel\Models\AuditTag` is the model over it, and its docblock states what the
row is: no key of its own, because the pair *is* the key, and no clock, because a label is
classification rather than a fact and the entry it hangs off already knows when it happened.

```php
namespace ElPandaPe\Sentinel\Models;

class AuditTag extends Model
{
    public $incrementing = false;
    public $timestamps = false;
    protected $primaryKey = 'audit_id';
    protected $keyType = 'string';
}
```

Three consequences follow from those four lines, and all three matter:

| Declaration | Consequence |
|---|---|
| `$timestamps = false` | No row records *when* a label was applied. A label that appeared yesterday looks exactly like one written with the entry. |
| `$primaryKey = 'audit_id'` (not unique) | Eloquent compiles `$tag->delete()` on a single instance as `where audit_id = ?` — it removes **every** label of that entry. Delete through the relation query instead. |
| No key column, no clock | There is nothing on the row that a prune or an audit of the audit trail can use to reconstruct which entry it belonged to once that entry is gone. |

Labels are written **inside the same database transaction that seals the chain**.
`Ledger\DatabaseLedger::chain()` inserts the entries, then calls `label()`, then `project()`, all
within one `transaction()`. `append()` — the path a secondary fanout destination takes — does the
same. An entry stored without the labels it arrived with is not the entry that arrived, and the
transaction is what makes that an all-or-nothing statement.

The insert uses `insertOrIgnore` and leans on the unique pair: a repeated label is dropped by the
index rather than surfacing as a constraint violation that would replay a whole sealed batch.

---

## A label is not evidence

`Integrity\CanonicalPayload::COLUMNS` is the frozen definition of what the hash covers for
`payload_version` 1. It lists twenty-seven columns. `tags` is not one of them, and it cannot be one:
labels are not a column at all. `Ledger\EntryBuilder` says so directly — labels ride the built entry
as a **loaded relation, never as attributes**, which is what keeps them out of `getAttributes()`,
which is both what the ledger inserts and what the hash is taken over.

> 🔒 **Security.** Anyone with write access to `sentinel_audit_tags` can add, remove or change a
> label and nothing will say so. `verifyIntegrity()` will keep returning intact. There is no
> timestamp on the row, no actor, and no entry in the trail describing the change. **A label is a
> convenience for finding entries. It is never evidence.**

This cuts both ways, and the other direction is the reason for the design: reclassifying a
three-year-old entry does not break its hash, so your taxonomy is free to change without turning
into an integrity problem. You can add `pci` to ten thousand historical entries at four in the
morning and every chain still verifies.

What has to be provable goes in `metadata`, which **is** in `CanonicalPayload::COLUMNS`. Rewrite a
metadata key and the entry stops reproducing its own hash.

> 📌 **Note.** Labels are content, though, even if they are not evidence — a label can name a person.
> `Redaction\Redactor::forgetSatellites()` deletes an entry's labels and its relation lines when the
> entry is tombstoned, for exactly that reason. See
> [Redaction and tombstones](../08-lifecycle/04-redaction-and-tombstones.md).

---

## The three places a label comes from

`Pipeline\Stages\ResolveTags` is the only place labels are assembled. It runs on the write path for
every entry, and it does one thing:

```php
$tags = $this->config->tags([
    ...$this->policies->for($audit->subject_type)->tags,   // 1. what the model declared
    ...$audit->tags,                                        // 2. what the caller put there
]);                                                         // 3. + tags.default, appended
```

| Source | Where you write it | Applies to |
|---|---|---|
| Model declaration | `protected array $auditTags = [...]` on the model | Every entry whose `subject_type` resolves to that model class |
| Per entry | `->tags([...])` on `Sentinel::event()` or `Sentinel::transition()` | That one entry |
| Configuration | `sentinel.tags.default` | Every entry the pipeline touches, without exception |

They are a **union, in that order, de-duplicated keeping the first occurrence**. There is no way for
one to cancel another: `tags.default` cannot remove a model's declaration, and a model cannot opt
out of `tags.default`.

```php
use ElPandaPe\Sentinel\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

final class Invoice extends Model
{
    use Auditable;

    /** @var list<string> */
    protected array $auditTags = ['billing', 'finance'];
}
```

```php
// config/sentinel.php
'tags' => [
    'enabled' => true,
    'default' => ['env:production'],
],
```

An update to that `Invoice` is written with `['billing', 'finance', 'env:production']`.

`tags.enabled = false` makes `ResolveTags` skip the assembly entirely — the model declaration and
`tags.default` both stop applying, silently, and the entry is written with whatever the caller had
already put on it (which for a model event is nothing).

> ⚠️ **Warning.** `$auditTags` is read on the pipeline path through
> `Support\PolicyRegistry`, which builds the model with `newInstanceWithoutConstructor()` and reads
> the property with `property_exists()`. A label list computed in a constructor, or exposed only
> through `__get()`, is invisible — and nothing warns you. Declare it as a real property.

Two more things about the pipeline path worth knowing:

- **A subjectless entry gets no model labels.** `PolicyRegistry::for(null)` returns the empty policy,
  so a `Sentinel::event()` with no `->subject()` is classified by `tags.default` and by its own
  `->tags()` call, and by nothing else.
- **A morph alias resolves.** `PolicyRegistry` runs the subject type through
  `Relation::getMorphedModel()` before looking for the class, so a registered morph map does not
  break model-declared labels.

---

## What each capture path can label

| Path | Model `$auditTags` | Per-entry `->tags()` | `tags.default` |
|---|---|---|---|
| Model events (`created`, `updated`, `deleted`, …) | Yes — subject is the model | No API | Yes |
| Relation changes (`attach`, `detach`, `sync`) | Yes — subject is the **parent** | No API | Yes |
| Mass operations (`->auditing()`) | Yes — subject is the model | No API | Yes |
| `Sentinel::event('…')` | Only when `->subject()` names a model | `->tags([...])` | Yes |
| `Sentinel::transition(…)` | Yes — subject is the model | `->tags([...])` | Yes |
| Restore entries | Yes — subject is the restored model | No API | Yes |
| Redaction trail entries | Yes — carries the redacted entry's `subject_type` | No API | Yes |
| Authentication events | Only when the actor resolved to a model type | No API | Yes |

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

Sentinel::event('invoice.approved')
    ->subject($invoice)
    ->tags(['manual-review'])
    ->metadata(['approver_note' => $note])
    ->record();

Sentinel::transition($order, 'pending', 'shipped')
    ->on('status')
    ->tags(['fulfilment'])
    ->record();
```

> ⚠️ **Warning.** `->tags()` **assigns**; it does not merge. Both `Capture\PendingEvent::tags()` and
> `Transitions\TransitionBuilder::tags()` do `$this->tags = $tags;`, so calling it twice keeps only
> the second list. Pass one list.

---

## The length cap

`Pipeline\Stages\ResolveTags::MAX_LENGTH` is `64`, matching `string('tag', 64)` in the migration. A
longer label throws `ConfigurationException::tagTooLong` **at the pipeline**, not at the ledger —
the message quotes the first 80 characters of the offending label and says why nothing is truncated:
a label is a whole word or it is not the label that was meant.

The measurement is `mb_strlen`, so the cap is 64 **characters**, not bytes: a 64-character label made
of `ñ` is accepted.

> ⚠️ **Warning.** Because the refusal happens in a stage rather than at the write, an over-long
> `tags.default` value fails **every entry the application writes**, of every type, from the moment
> the config is deployed. Under `on_write_failure = throw` (the default, and forced under compliance
> mode) that is a failed request per write.

---

## Querying by label

Two methods, one `Enums\Filter` case (`Filter::Tag`), one accumulating criterion
(`Query\TagCriteria`, reachable as `$query->tags`).

| Method | Asks | Compiles to |
|---|---|---|
| `whereTag(array\|string $tag)` | Every label named, on the same entry | One `where exists` into `sentinel_audit_tags` **per label** |
| `whereAnyTag(array\|string $tag)` | At least one of the labels named | One `where exists` with a `whereIn` over the list |

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

// Entries carrying BOTH labels.
Sentinel::audits()->whereTag(['billing', 'refund'])->take(100)->get();

// The same question, spelled across two calls — TagCriteria accumulates.
Sentinel::audits()->whereTag('billing')->whereTag('refund')->take(100)->get();

// Entries carrying EITHER label.
Sentinel::audits()->whereAnyTag(['refund', 'chargeback'])->take(100)->get();

// The two lists are kept apart and both are applied:
// carries 'billing' AND at least one of 'refund'/'chargeback'.
Sentinel::audits()
    ->whereTag('billing')
    ->whereAnyTag(['refund', 'chargeback'])
    ->paginate(50);
```

Accumulation is deliberate. Every other criterion on the query surface holds a single value and a
repeated call overwrites; here that would be a filter that quietly stopped narrowing, so
`TagCriteria::requiring()` and `including()` append and de-duplicate instead.

**An empty list is refused.** `whereTag([])` and `whereAnyTag([])` both throw
`QueryException::noLabels` — narrowing by no label asks nothing of an entry and would hand back the
whole trail.

Labels compose with everything else, `Sentinel::timeline()` included, and the query stays immutable:
`$query->whereTag('billing')` returns a new query and leaves `$query` unnarrowed.

### Drivers must declare the filter

`Filter::Tag` was published after the nine filters in `Filter::assumed()`, so a ledger driver that
does not implement `Contracts\DeclaresFilters` **refuses** it:

```
LedgerException: … cannot filter by tag, so whereTag() is not part of the query it answers.
```

The refusal lands as the method is called, not when the query runs. Note that
`Filter::Tag->method()` returns `'whereTag'` — the message names `whereTag()` even when you called
`whereAnyTag()`, because both reach the same filter case. See
[The Ledger contract](../11-extending/01-the-ledger-contract.md).

---

## Reading the labels off an entry

```php
use ElPandaPe\Sentinel\Models\AuditTag;

$audit->tags;                              // Collection<int, AuditTag>
$audit->tags->pluck('tag')->all();         // ['billing', 'refund']
$audit->toArray()['tags'];                 // ['billing', 'refund'] — list<string>
```

`Models\Audit::tags()` is a `HasMany` on `audit_id` with `->orderBy('tag')`. `$model->audits()` and
`DatabaseLedger::find()` and `DatabaseLedger::query()` all eager-load it, because `Audit::toArray()`
reads labels unconditionally: without the eager load the frozen serializer would be an N+1, and a
`LazyLoadingViolationException` under `Model::preventLazyLoading()`.

> 📌 **Note.** There are **two** orders here, and they are not the same one.
> The relation's `orderBy('tag')` is sorted by the database, so it follows the column's collation.
> `toArray()` re-sorts in PHP, so it is byte order and identical on all three engines — `['Audit',
> 'Refund', 'billing']`, uppercase before lowercase. Neither is your declaration order: that is used
> only to decide which duplicate survives. See
> [Presenting and serializing](07-presenting-and-serializing.md).

On an array-backed driver (`MemoryLedger`, `ArchiveLedger`, any third-party driver over
`Ledger\ArrayQuery`), `labelsOf()` returns `[]` unless the `tags` relation is **loaded**. An entry
handed to `append()` without it reads as an entry with no labels rather than as unknown, and is left
out of every label query.

---

## The table, its two indexes and its missing foreign key

```php
$table->char('audit_id', 26);
$table->string('tag', 64);

$table->unique(['audit_id', 'tag']);
$table->index(['tag', 'audit_id']);
```

| Index | Answers | Also does |
|---|---|---|
| `unique(audit_id, tag)` | "What is this entry labelled?" — seek on the leading `audit_id` | Makes a labelled entry unable to repeat a label, and makes `insertOrIgnore` idempotent on a retry |
| `index(tag, audit_id)` | "Which entries carry this label?" — seek on the leading `tag` | Covers the `select 1 … where audit_id = … and tag = ?` subquery entirely, so the exists never touches the table |

There is **no foreign key to `sentinel_audits`**, on purpose. The migration says why: date
partitioning and batched pruning both live badly with a cascade, so cleaning up after a removed
entry is the explicit job of whoever removes it.

Two things in the package do that job:

- **`sentinel:prune`**, through `Retention\Cascade`. One slice is one transaction across the three
  tables — labels first, then relation lines, then entries — with the child rows named by a subquery
  over `(stream, sequence)` rather than by a list of identifiers. The docblock is explicit about the
  ordering: interrupted between the labels and the entries, the labels of an entry that is gone
  would be rows nothing surviving could ever name again.
- **`sentinel:redact`**, through `Redactor::forgetSatellites()`, which deletes the labels and the
  relation lines of the tombstoned entry.

> ⚠️ **Warning.** Anything else that removes rows from `sentinel_audits` — a hand-written `DELETE`, a
> dropped partition, a table truncation during a data migration — leaves the labels behind as
> **orphans that nothing can identify**. They carry an identifier and no clock; once the entry is
> gone, nothing maps a label row back to a stream or a sequence. Remove entries through
> `sentinel:prune`. See [Retention and pruning](../08-lifecycle/01-retention-and-pruning.md).

Labels survive the round trip to cold storage: `Archive\Line::entry()` writes a `tags` key beside
the entry's columns, and `Line::toAudit()` rebuilds them as a loaded relation on rehydration. See
[Cold archiving](../08-lifecycle/02-cold-archiving.md).

---

## Label, metadata, or a custom event?

The three are not interchangeable. Pick by what you will need later.

| You need to… | Use | Why that one |
|---|---|---|
| Find entries later by a coarse class ("everything billing touched") | **Label** | `whereTag()` is the only filter that reads it, and `index(tag, audit_id)` is built for exactly that lookup |
| Have the classification still be true after someone with database access got creative | **`metadata`** | `metadata` is inside `CanonicalPayload::COLUMNS`; change it and the entry stops reproducing its hash. A label change leaves no trace at all |
| Record something with structure — a reason, an amount, an external reference | **`metadata`** | A label is one string of at most 64 characters with no value half. `metadata` is a JSON map |
| Say that something happened that no model change describes | **`Sentinel::event('name')`** | It writes its own entry with `audit_type` `custom` and a queryable `event` name; a label on an unrelated entry is not the fact |
| Slice the trail by kind of entry | **`whereType()`** | `audit_type` is a real column with a `(audit_type, created_at)` index behind it. Do not reinvent it as a label |
| Attach the same word to every entry of a deployment, region or product line | **`tags.default`** | It is unioned onto every entry the pipeline touches, and it is the only lever that reaches entries with no model subject |

> 💡 **Tip.** The honest test: *if this word were quietly changed, would anyone be misled about what
> happened?* If yes, it is not a label. Labels answer "which entries do I want to look at"; they do
> not answer "what happened".

There is one more thing a label is not: a substitute for `metadata` on a subjectless entry. A
`Sentinel::event()` with no `->subject()` gets nothing from any model, so a label you rely on there
has to be spelled out at the call site or set globally.

---

## Naming that survives three years

The code imposes exactly one rule — 64 characters — and no others. No case folding, no trimming, no
character-set restriction, no normalisation of any kind. `' billing'` and `'billing'` are two
different labels and both are valid. That freedom is what makes a convention worth writing down
before the first entry, because you cannot rename a label across a trail without writing to a table
nothing tamper-checks.

| Convention | Why it holds up |
|---|---|
| **Lowercase, always** | `whereTag()` is a plain equality against the column's collation. MySQL's default collation folds case; PostgreSQL and SQLite do not. Lowercase is the only spelling that answers the same on all three |
| **`prefix:value` for anything with a dimension** | `region:eu-west`, `env:production`, `retention:7y`. `whereAnyTag(['region:eu-west', 'region:eu-central'])` then reads as one question, and the prefix is greppable in your own code |
| **Hyphens, never spaces** | Nothing trims; a stray space produces a label that looks identical in a console and never matches |
| **Name a category, not an instance** | `billing`, not `invoice-4471`. An identifier belongs in `subject_id` or in `metadata`; a label whose cardinality tracks your row count makes the reverse index useless |
| **Stay well inside 64 characters** | The cap is a hard refusal on the write path, not a truncation, and it fires for every entry once it is in `tags.default` |
| **Write the list down** | There is no enum, no registry and no validation beyond the length. A typo is a new label that silently matches nothing |

> 💡 **Tip.** A label most entries carry is not useless, but it is not a filter either. The plan for
> a label filter seeks the labels table; whether the *whole read* is cheap depends on how many
> entries carry the label. Put a narrowing filter — `for()`, `forTenant()`, `between()` — in front of
> a universal one. See [Filters reference](02-filters-reference.md).

---

## Engine notes

> 🐘 **Engine.** The `tag` column is created as a plain `string(64)` with **no explicit collation**,
> and `DatabaseLedger::narrowByLabel()` compares it with a plain `where('tag', ?)` — no binary
> recheck, unlike `Ledger\ContextPredicate`, which adds `collate utf8mb4_bin` for `whereIp()` and
> `whereRoute()` precisely because MySQL's default collation is case- and accent-insensitive. So
> `whereTag('Billing')` can match an entry labelled `billing` on MySQL and match nothing on
> PostgreSQL and SQLite. Keep labels lowercase and the difference never arises.

> 🐘 **Engine.** The serialized order is engine-independent by construction: `Audit::toArray()` sorts
> the labels in PHP rather than trusting the `orderBy('tag')` the relation used. The test that pins
> it asks for `['Refund', 'billing', 'Audit']` back and expects `['Audit', 'Refund', 'billing']` —
> byte order, on all three engines.

> 🧪 **Verify it.** `tests/Query/QueryPlanTest.php` asserts that an intersection (`whereTag`) reaches
> the labels table through an index on every engine the package supports. The union form is
> deliberately not pinned — over a small labels table, scanning it is the cheaper plan, and asserting
> otherwise would be a gate that moves with the fixture:
>
> ```bash
> make test-dbs ARGS=tests/Query/QueryPlanTest.php
> ```

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `whereAnyTag()` throws a message naming `whereTag()` | Both spellings reach `Filter::Tag`, and `Filter::Tag->method()` returns `'whereTag'` | Read it as "the label filter". Declare `Filter::Tag` in your driver's `supportedFilters()` |
| Labels come back in a different order than declared | The relation sorts by `tag` in SQL; `toArray()` re-sorts in PHP byte order. Declaration order only decides which duplicate survives | Do not depend on the order; sort in your own code if you need a specific one |
| `whereTag('Billing')` answers on MySQL and answers nothing on PostgreSQL | Plain equality against the column's default collation, with no binary recheck | Normalise labels to lowercase when you write them |
| A `Sentinel::event()` entry has none of the subject's model labels | No `->subject()` was named, so `PolicyRegistry::for(null)` returned the empty policy | Call `->subject($model)`, or spell the label at the call site with `->tags()` |
| `$auditTags` is ignored on some entries and honoured on others | The property is computed in a constructor or served by `__get()`; the pipeline reads it via `newInstanceWithoutConstructor()` + `property_exists()` | Declare `$auditTags` as a real property with a literal list |
| Every write in the application starts failing after a config deploy | A `tags.default` value longer than 64 characters — `ResolveTags` throws `ConfigurationException::tagTooLong` for every entry | Shorten the label. The cap is a refusal, never a truncation |
| Model labels stopped applying entirely, with no error | `sentinel.tags.enabled` is `false`; `ResolveTags` skips the assembly | Turn it back on. Labels dropped at write time are not recoverable afterwards |
| `sentinel_audit_tags` keeps growing after entries were deleted | No foreign key. Only `Retention\Cascade` (via `sentinel:prune`) and `Redactor` remove label rows | Remove entries through `sentinel:prune`. Orphans left by a manual `DELETE` cannot be matched back to anything |
| Deleting one `AuditTag` removed all of that entry's labels | `$primaryKey = 'audit_id'`, which is not unique, so `delete()` compiles to `where audit_id = ?` | Delete through the relation query: `$audit->tags()->where('tag', $label)->delete()` |
| `->tags()` called twice, only the second list survives | `PendingEvent::tags()` and `TransitionBuilder::tags()` assign rather than merge | Pass one list |
| An entry written through a memory or archive driver matches no label query | `ArrayQuery::labelsOf()` reads only a **loaded** `tags` relation and otherwise reports no labels | Load the relation before handing the entry to `append()` |
| `verifyIntegrity()` still says intact after someone relabelled an entry | Labels are outside `CanonicalPayload::COLUMNS` — by design | Nothing to fix. Anything that must be provable belongs in `metadata` |

---

## ✅ Best practices

✅ **Do** — declare the coarse, stable classification on the model, where it applies to every entry
about that subject without anyone remembering to add it.

```php
final class Invoice extends Model
{
    use Auditable;

    /** @var list<string> */
    protected array $auditTags = ['billing', 'finance'];
}
```

❌ **Don't** — put a per-entry fact in `$auditTags`. It applies to *every* entry of that model
forever, including entries where it is false, and there is no per-entry escape hatch on the model
capture path.

```php
protected array $auditTags = ['approved-by-finance'];   // now on every create, update and delete
```

✅ **Do** — put anything that has to survive an argument in `metadata`. It is one of the twenty-seven
columns the hash covers, so a later edit is detectable.

```php
Sentinel::event('invoice.approved')
    ->subject($invoice)
    ->metadata(['approved_by' => $user->id, 'limit_applied' => 50000])
    ->tags(['billing'])          // the label is for finding it, not for proving it
    ->record();
```

❌ **Don't** — treat a label as an attestation. Nothing records who applied it, when, or that it
changed, and `verifyIntegrity()` is untouched by labelling in either direction.

```php
Sentinel::event('invoice.approved')->tags(['approved-by:'.$user->id])->record();
```

✅ **Do** — lowercase every label and give a dimension a `prefix:value` shape, so the same query
answers identically on SQLite, MySQL and PostgreSQL.

```php
'tags' => ['enabled' => true, 'default' => ['env:production', 'region:eu-west']],
```

❌ **Don't** — mix case or leave spaces in. Nothing normalises, nothing trims, and MySQL's default
collation will hide the mistake right up until you run the same query on PostgreSQL.

```php
'default' => ['Env: Production'],   // matches 'env: production' on MySQL only
```

✅ **Do** — put a narrowing filter in front of a label that most of the trail carries, and bound the
read.

```php
Sentinel::audits()
    ->forTenant('acme')
    ->between($from, $to)
    ->whereTag('billing')
    ->paginate(50);
```

❌ **Don't** — read the whole trail by a universal label. `get()` refuses once more than
`AuditQuery::DEFAULT_LIMIT` (500) entries match, rather than handing back a prefix shaped like a
complete answer, so this throws `QueryException::unbounded` the moment it matters.

```php
Sentinel::audits()->whereTag('env:production')->get();   // QueryException::unbounded
```

✅ **Do** — remove entries through `sentinel:prune`, which deletes labels, relation lines and entries
in one transaction per slice.

```bash
php artisan sentinel:prune --dry-run
php artisan sentinel:prune
```

❌ **Don't** — delete from `sentinel_audits` by hand or drop a partition without clearing the label
rows first. There is no foreign key and no cascade; what is left cannot be matched back to a stream,
a sequence or a subject.

```sql
DELETE FROM sentinel_audits WHERE created_at < '2024-01-01';   -- labels stay, forever
```

✅ **Do** — declare `Filter::Tag` in `supportedFilters()` on any ledger driver you write that can
answer it, and leave it out when it cannot.

```php
public function supportedFilters(): array
{
    return [Filter::Subject, Filter::Tenant, Filter::Period, Filter::Tag];
}
```

❌ **Don't** — let a driver accept a label filter it will ignore. A driver that quietly drops the
criterion answers with entries nobody asked for, and a trail showing the wrong history is worse than
one that refuses.

```php
// No DeclaresFilters, and query() ignores $query->tags: every label question answers wrongly.
```

---

**See also:** [The Query API](01-the-query-api.md) · [Filters reference](02-filters-reference.md) · [Presenting and serializing](07-presenting-and-serializing.md) · [What a model declares](../02-getting-started/03-what-a-model-declares.md) · [The write pipeline](../05-pipeline-and-security/01-the-write-pipeline.md) · [Custom and authentication events](../03-capture/07-custom-and-authentication-events.md) · [Canonicalization](../07-integrity/03-canonicalization.md) · [Retention and pruning](../08-lifecycle/01-retention-and-pruning.md) · [Redaction and tombstones](../08-lifecycle/04-redaction-and-tombstones.md) · [The Ledger contract](../11-extending/01-the-ledger-contract.md) · [Schema](../99-reference/03-schema.md) · [Configuration](../99-reference/02-configuration.md)
