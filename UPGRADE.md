# Upgrade guide

Every version that changes a published contract, a driver's behaviour or the schema is documented
here, with the before and the after. Versions that only add are covered by the
[CHANGELOG](CHANGELOG.md).

Sentinel is in its `0.x` cycle: only the last minor receives fixes, and there are no backports
before `1.0.0`.

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
