# Upgrade guide

Every version that changes a published contract, a driver's behaviour or the schema is documented
here, with the before and the after. Versions that only add are covered by the
[CHANGELOG](CHANGELOG.md).

Sentinel is in its `0.x` cycle: only the last minor receives fixes, and there are no backports
before `1.0.0`.

---

## v0.11.0 → v0.11.1

One published contract changes. Nothing to migrate.

### `Contracts\Auditable` gains `auditParents()`

**Before:** ten methods.

**After:** eleven. `auditParents(): array` returns the `belongsTo` relations whose parent gets a
relation entry when this model changes hands, as a map of *relation on this model* to *the name the
parent gives that collection*.

Nothing to do if your model uses the `Concerns\Auditable` trait: the trait implements it, and
declaring `protected array $auditParents = ['author' => 'articles'];` on the model is all it reads.
A model that declares nothing keeps auditing exactly as before.

If you implement the interface by hand, add the method:

```php
/**
 * @return array<string, string>
 */
public function auditParents(): array
{
    return [];
}
```

---

## v0.10.1 → v0.11.0

Two published contracts change, and there is a migration.

### `Contracts\Auditable` gains `relationHistory()`

**Before:** nine methods.

**After:** ten. A class that implements the interface by hand rather than using the trait needs:

```php
public function relationHistory(string $relation): AuditQuery
{
    return app(Sentinel::class)->audits()->for($this)->whereRelation($relation);
}
```

Models using `Concerns\Auditable` get it for nothing.

### A driver must declare the three new filters to answer them

`Filter` gains `Relation`, `Related` and `Operation`. As with every filter published after `v0.9.0`,
`Filter::assumed()` does **not** grow: a driver that does not name them refuses them rather than
ignoring a criterion it cannot translate.

**If you ship a `DeclaresFilters` driver** and want to answer `whereRelation()`, `whereRelated()` and
`whereOperation()`, add the three cases to `supportedFilters()` and translate them. The criterion
arrives as one `Query\RelationCriteria` holding all three parts, and they must narrow the **same
line** — an entry matches when one of its lines satisfies every part at once. Compiling them into
separate conditions is a subtly wrong answer, not a slower one.

A driver over arrays can hand the whole thing to `Ledger\ArrayQuery`, which already answers them.

**If you do nothing**, your driver keeps working and refuses the three by name.

### The trait now overrides two relation factories

`Concerns\Auditable` overrides `newBelongsToMany()` and `newMorphToMany()` so every many-to-many a
model declares is wrapped. Nothing in your application changes — `$team->members()->sync([...])` is
written and behaves exactly as before, and every return value is untouched.

**If your model already overrides either factory**, one of the two overrides wins and the other is
lost. Call the trait's version from yours, or the relation is not audited.

### Migration

```bash
php artisan migrate
```

`sentinel_audit_relations` is created. It is additive and reversible, and `sentinel_audits` is not
touched: no new column, no `ALTER`, no rewritten hash. `payload_version` stays at `1` and every
entry frozen before this version reproduces its hash byte for byte.

If you publish migrations, publish again to pick up the new one — the package loads its own for any
file you have not published.

---

## v0.9.0 → v0.10.0

Four published contracts change. Three of them are one edit each; the fourth is a read that used to
answer and now refuses, on purpose.

### `Contracts\Auditable` gains `auditTags()`

**Before:** eight methods. **After:** nine — `auditTags(): array` returns the labels every entry of
that model is born with.

Nothing to do if your model uses the `Concerns\Auditable` trait: the trait implements it, and
declaring `protected array $auditTags = ['billing'];` on the model is all it reads.

If you implement the interface by hand, add the method:

```php
/**
 * @return list<string>
 */
public function auditTags(): array
{
    return [];
}
```

### A new pipeline stage, which a published configuration will not pick up on its own

**Before:** six stages. **After:** seven — `Pipeline\Stages\ResolveTags` gathers what an entry is
classified as, and it sits between `ResolveContext` and `NormalizeData`.

The published `config/sentinel.php` names every stage and is taken verbatim, so **an installation
that published its configuration keeps running the six it published** — labels would silently stay
empty. If you published it, add the stage:

```php
// config/sentinel.php
'pipeline' => [
    ElPandaPe\Sentinel\Pipeline\Stages\FilterUnchanged::class,
    ElPandaPe\Sentinel\Pipeline\Stages\ResolveContext::class,
    ElPandaPe\Sentinel\Pipeline\Stages\ResolveTags::class,      // <- new
    ElPandaPe\Sentinel\Pipeline\Stages\NormalizeData::class,
    ElPandaPe\Sentinel\Pipeline\Stages\MaskSensitiveData::class,
    ElPandaPe\Sentinel\Pipeline\Stages\EncryptSensitiveData::class,
    ElPandaPe\Sentinel\Pipeline\Stages\EnforcePolicies::class,
],
```

### A driver no longer answers a filter it never named

**Before:** a driver that does not implement `Contracts\DeclaresFilters` was assumed to translate
every case of `Enums\Filter`, whatever that enum grew to hold.

**After:** it is assumed to translate `Filter::assumed()` — the nine filters published with the
contract in `v0.9.0` — and nothing else. `whereTag()`, `whereFieldChanged()` and `whereVersion()`
are answered only by a driver that names them.

This is the safe direction: a driver written against `v0.9.0` never knew about the new filters, and
`ArrayQuery`-style resolution ignores a criterion it does not recognise, so the old assumption
would have had such a driver answering with entries nobody asked for. If your driver does translate
them, say so:

```php
final class RedisLedger implements DeclaresFilters, Ledger
{
    public function supportedFilters(): array
    {
        return Filter::cases();
    }
}
```

The contract suite changed with it. `publishedFilters()` now yields the `Filter` beside the closure,
and every filter expectation holds a driver to one of two answers — it translates the filter, or it
refuses it — so a driver declaring the narrower set still runs the suite unchanged.

### `get()` refuses a read it would have to truncate

**Before:** `get()` handed back at most `AuditQuery::DEFAULT_LIMIT` entries and said nothing about
having cut. A filter matching six hundred entries answered with five hundred, in exactly the shape
of a complete answer.

**After:** it asks for one more than the bound and throws `QueryException` when that one arrives.

```php
// was: five hundred entries, silently a prefix
$entries = Sentinel::audits()->get();

// now, pick one:
$entries = Sentinel::audits()->whereTag('billing')->get();        // narrow it
$entries = Sentinel::audits()->take(500)->get();                  // a prefix, on purpose
$page    = Sentinel::audits()->paginate(100);                     // walk all of it
```

### Two migrations, both additive

`sentinel_audit_tags` is new. The second one adds two indexes to `sentinel_audits` and no columns:
`(occurred_at, id)` and `(subject_type, subject_id, occurred_at, id)`, which are what keep the
timeline from sorting outside every index.

```bash
php artisan migrate
```

Existing entries come out unlabelled, and `whereTag()` does not return them. There is no backfill:
nobody knows which label an entry written before labels existed would have carried.

`payload_version` stays at `1`, labels are not part of the hashed payload, and every entry frozen
before this version reproduces its hash byte for byte.

**If you published the migrations** with `vendor:publish --tag=sentinel-migrations`, publish again
to pick up the two new files. Until `v0.10.0` the package loaded its migration directory only when
the audits migration was absent from yours — all or nothing on one filename — so a second migration
would never have reached you. That check is now made per file.

---

## v0.8.0 → v0.9.0

`v0.9.0` gives `Ledger::query()` something to answer. The signature has been on the contract since
`v0.2.0` and every driver in this package threw from it; now they all answer, and the published
contract suite holds them to answering the same thing.

**Nothing to migrate.** No new tables, no new indexes, no altered columns, no rewritten hashes.
`payload_version` stays at `1`, and every entry frozen before this version comes back through the
new surface reproducing the hash it was frozen with. The query plan of each published filter was
measured on MySQL 9 and PostgreSQL 16, and none of them needed an index that did not already exist.

If you do not implement `Contracts\Ledger` yourself, there is nothing here for you: the rest of this
section is about drivers.

### `query()` has to work now

**Before:** the method was on the interface and no driver in this package implemented it. A driver of
your own could throw from it and nothing noticed.

**After:** it takes an `AuditQuery` and returns the entries that match it, ordered by `created_at`
with the entry's identifier behind it — oldest first, or newest first when `newestFirst` is set —
bounded by `limit` and `offset` when they are set.

```php
public function query(AuditQuery $query): AuditCollection;
```

The criteria are plain readable properties: `subject` and `actor` (a `Support\Reference` with a type
and a key), `event`, `severity`, `source`, `tenantId`, `transactionId`, `traceId`, `period` (a
`Query\Period` with `from` and `to`, both ends inclusive, over `created_at`), `newestFirst`, `limit`
and `offset`.

If your store keeps entries in memory or in anything you can iterate, `Ledger\ArrayQuery` resolves
the whole thing for you:

```php
public function query(AuditQuery $query): AuditCollection
{
    return new AuditCollection($this->queries->resolve($this->entries, $query));
}
```

Extend `Testing\LedgerContractTestCase` and the fifteen new expectations run against your driver
without you writing any of them.

### A filter you cannot translate is declared, never dropped

If your backend cannot answer one of the filters, say so. The query then refuses it as it is added,
which is a readable exception at the call site instead of a result that quietly answers a different
question:

```php
use ElPandaPe\Sentinel\Contracts\DeclaresFilters;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Enums\Filter;

final class RedisLedger implements DeclaresFilters, Ledger
{
    public function supportedFilters(): array
    {
        return [Filter::Subject, Filter::Tenant];
    }
}
```

A driver that does not implement `DeclaresFilters` is taken to answer all nine. Dropping a filter you
cannot translate would hand back entries nobody asked for, and a trail that shows the wrong history
is worse than one that refuses to answer.

### `LedgerException::queryNotImplemented()` is gone

It existed to say the Query API had not arrived. It has.

---

## v0.7.0 → v0.8.0

`v0.8.0` ran the `Ledger` contract against a store with no transactions, no joins and no
autoincrement, and published the contract that survived it. Three of the changes below are the
corrections that came out of that; the rest is the shape a second and a third driver forced.

### `Contracts\Ledger` gains `append()`

Every implementation of the contract has to add one method. If you do not implement `Ledger`
yourself, nothing here applies.

```php
public function append(Audit $audit): Audit;
```

It stores an entry that arrives already sealed, exactly as it is: **no sequence is assigned and no
hash is recomputed**. It is how a fanout hands a secondary destination what the primary sealed, and
how an archive or a replica takes an entry it did not write. An implementation that reseals what it
is appended is wrong: two ledgers each numbering their own chain produce two different truths about
one fact.

A driver over a store that cannot store the entry verbatim should throw rather than adapt it.

### The contract states three guarantees it does not make

Nothing in your code has to change for these, but what your code is entitled to assume does.

- **`writeMany()` is not atomic.** It either returns everything that settled, or it throws having
  made a best effort to leave nothing behind. On a store with no rollback that effort is
  compensation, and compensation can be interrupted. `DatabaseLedger` still wraps the batch in a
  transaction and is still all-or-nothing — the contract simply stops requiring every driver to be.
- **No read is promised to see a write that just returned.** `find()` and `stream()` may not show an
  entry `write()` handed back a moment ago. `DatabaseLedger` and `MemoryLedger` both do; a driver
  over a search index does not, and it is still a valid driver.
- **Idempotency by `capture_id` belongs to the caller.** A ledger cannot deduplicate what it cannot
  reliably look up, and the lookup is exactly the read with no promise attached.

### `NullLedger` keeps nothing

**Before:** the `null` driver kept every entry it wrote on the instance, so `find()` and `stream()`
answered from memory for the rest of the request.

**After:** it still builds, seals and chains every entry — the code path an application measures is
unchanged, and `write()` still returns a real sealed entry — but it retains only the tail of each
stream and a version counter per subject, which are the two things the next entry is sealed with.
`find()` returns `null` and `stream()` walks nothing.

If you were reading entries back out of the `null` driver, switch to the new `memory` driver, which
is what that behaviour now is:

```php
// config/sentinel.php
'ledger' => [
    'default' => 'memory',   // was 'null'
],
```

Turning auditing off is not a reason to grow with the traffic it is refusing to record, which is
what the old behaviour did in a long-lived worker.

### The contract test suite moved into the package

**Before:** `ElPandaPe\Sentinel\Tests\Testing\LedgerContractTestCase`, inside this package's test
folder and unreachable from anywhere else.

**After:** `ElPandaPe\Sentinel\Testing\LedgerContractTestCase`, shipped in `src/`. Extend it, return
your driver from `ledger()`, and your driver is held to the same chain the ones in this package are.
PHPUnit and Testbench stay development dependencies and are declared in `suggest`.

Two expectations that queried the `sentinel_audits` table were dropped: they were about one driver,
not about the contract. Two hooks replaced them — `retains()` for a driver that keeps nothing, and
`settle()` for one whose reads are eventually consistent.

### `--tag=sentinel-factories` is gone

**Before:** `php artisan vendor:publish --tag=sentinel-factories` copied `AuditFactory` into
`database/factories`.

**After:** the tag does not exist. The copy declared a class in this package's namespace inside a
directory an application maps to its own, so what it produced was either never loaded or a
collision. `AuditFactory` ships with the package and is autoloaded from it: `Audit::factory()` works
with nothing published. To change what it builds, point `models.audit` at your own subclass.

If you published it, delete the copy.

### Nothing to migrate

No new tables, no altered columns, no rewritten hashes. `payload_version` stays at `1` and every
entry frozen before this version reproduces its hash byte for byte.
