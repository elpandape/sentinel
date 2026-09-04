# Migrating from `altek/accountant`

> **Written against `altek/accountant` v5.0.0**, released April 2026 and requiring PHP 8.3 and
> Illuminate 12.8 or 13. Its `ledgers` table has not moved a column since v3.0.0, so the mapping
> below holds for v3, v4 and v5. On v1 and v2 it does not: the `pivot` column did not exist and
> several others were nullable. The package lives on GitLab, not GitHub.

Accountant is not Laravel Auditing with different column names, and the difference is the whole
reason this page is separate. It records a **full snapshot on every row** and, beside it, a bare
list of the attribute *names* that changed. It stores no earlier values anywhere.

Read [What it guarantees and what it does not](#what-it-guarantees-and-what-it-does-not) before you
run anything.

---

## 1. Equivalences

| Accountant | Sentinel |
|---|---|
| `use Altek\Accountant\Recordable;` | `use ElPandaPe\Sentinel\Concerns\Auditable;` |
| `implements Contracts\Recordable` | Nothing to implement |
| `$model->ledgers()` | `Sentinel::audits()->for($model)->get()` |
| `$recordableEvents` | `$auditEvents` |
| `$ciphers = ['card' => Base64::class]` | `$auditEncrypt = ['card']` — reversible, and with a key ring |
| `$ciphers = ['card' => Bleach::class]` | `$auditRedact = ['card']`, or `$auditHash` to keep it comparable |
| `Contracts\Cipher` | The write pipeline, per field, with rotation |
| `Ledger::isTainted()` | `$audit->verifyIntegrity()` |
| `Recordable::isCurrentStateReachable()` | `sentinel:verify`, over the chain rather than one record |
| `Ledger::extract()` | `$audit->restore()` — see the caveat in §4 |
| `config('accountant.contexts')` bitmask | No equivalent, and none is wanted. Sentinel records the source of a write and never uses it to decide whether to record |
| `config('accountant.ledger.threshold')` | `sentinel:prune` with a retention policy — which archives before it removes, and leaves an anchor answering for what went |
| `Notary::sign()` | `Integrity\Signer` over the hash, with a key ring that rotates and retires |
| `altek/eventually` for pivot events | Built in. Six pivot operations, and the parent side too |

The two concepts Accountant has that Laravel Auditing does not are the ones worth naming, because
Sentinel answers both differently:

**Its ledger is signed per row and chains nothing.** `Notary::sign()` hashes a row over its own
attributes. Altering row N leaves row N+1's signature intact, so what it proves is that one row was
not edited — not that none were removed, reordered or inserted. Sentinel's chain proves the
sequence, and its signature sits over the chain hash.

**It can rebuild a record from a snapshot.** `Ledger::extract()` and
`isCurrentStateReachable()` are the record-and-restore model, and they are good. Sentinel's restore
is the same idea over `before`/`after`, with a plan that says per field what it will and will not
put back and why.

---

## 2. What each column becomes

| `ledgers` | `sentinel_audits` | Note |
|---|---|---|
| `id` | `metadata.import.row` | What makes a repeated import cost nothing |
| `recordable_type` / `recordable_id` | `subject_type` / `subject_id` | `audit_type` becomes `model` |
| `event` | `event` | `created`, `updated`, `restored`, `deleted`, `forceDeleted`, carried as written |
| `properties` | `after` | A **complete** snapshot. This one is whole |
| — | `before` | **Left empty. See below** |
| `modified` + `properties` | `changes` | The attributes that moved, with the value they moved to and no `old` key at all |
| `<prefix>_type` / `<prefix>_id` | `actor_type` / `actor_id` | Prefix from `accountant.user.prefix`, `user` by default. Pass `--actor=` if you changed it |
| `url` | `context.url` | |
| `ip_address` | `context.ip` | |
| `user_agent` | `context.user_agent` | |
| `context` | `metadata.import.context` | The execution bitmask, kept as the number it is — `TEST=1`, `CLI=2`, `WEB=4` |
| `signature` | `metadata.import.signature` | **Kept as data.** See below |
| `extra` | `metadata.import.extra` | Whatever `supplyExtra()` returned, unreinterpreted |
| `pivot` | `metadata.import.pivot` | Ídem |
| `created_at` | `occurred_at` | The entry's own `created_at` is the instant of the import |
| `updated_at` | — | Dropped |

### Why `before` is empty

Because nobody wrote one. `modified` holds the *names* of the attributes that were dirty and
nothing else; the values they held before that event exist nowhere in the table.

They could be guessed at — pair every row with the previous row of the same record and call the
difference a change — and that is a deduction, not a record. An audit engine that fills in a value
nobody wrote down has stopped being one. So `after` is real and complete, `changes` says which
attributes ended up holding which values, and neither claims anything about what came before.

### Why the signature is not carried into `signature`

`sentinel_audits.signature` means "signed by Sentinel's chain, with a key on Sentinel's key ring".
Putting somebody else's digest there would make `sentinel:verify` report a signature it cannot
check as one it can. It is kept in `metadata` where it is a fact about the source, which is what it
is.

### Four ways the source may already be incomplete

None of these can be detected from this side, and all four are worth checking before you conclude
something is missing:

- **The context bitmask.** `accountant.contexts` defaults to the web context alone. Anything the
  application did from the console, from a queue worker or in a test recorded **nothing at all**,
  silently — the observer was never registered.
- **The ledger threshold.** `accountant.ledger.threshold` above zero hard-deletes the oldest rows
  of a record after every successful write, leaving no trace that they existed.
- **The one-way cipher.** An attribute under `Bleach` was redacted when it was written and cannot
  be recovered. It comes across as it is stored, which is to say redacted.
- **Pivot events.** They need `altek/eventually` installed **and** an explicit `$recordableEvents`
  on the model. Their absence does not mean nothing happened.

---

## 3. The procedure

**Start with a dry run.**

```bash
php artisan sentinel:import --from=altek --dry-run
```

It reads every row, maps every one, puts each through the write pipeline that would refuse it, and
writes nothing. What it prints is what a real run would do. Then:

```bash
php artisan sentinel:import --from=altek
```

**Options.** `--table=` if the application moved it, `--connection=` if it lives elsewhere,
`--actor=` for a changed prefix, `--size=` for the batch size, `--after=` to carry on from a row.

**If it is interrupted, run it again.** Every entry carries an identity derived from its source
row, so the second run finds its own work done and writes none of it twice. `--after=` the last row
the command printed to skip straight back to where it stopped.

**Exit codes.** `0` when every row read became an entry or already was one. `1` when something did
not come across. `2` for a package it does not read, a table shaped like something else, or a
connection it cannot reach.

### What to check afterwards

```bash
php artisan sentinel:verify
php artisan sentinel:show --subject="App\Models\Invoice:77"
```

---

## 4. What it guarantees and what it does not

**It guarantees:**

- Every source row produces at most one entry, however many times you run it.
- The mapping is the one on this page, and a frozen test in the package holds it to that.
- The chain verifies from the point of import onwards.
- Your `$auditRedact`, `$auditEncrypt` and `$auditHash` apply to what comes in.

**It does not guarantee:**

- **Anything about what happened before the import.** The rows carried over were signed one by one
  and linked to nothing, so there is no sequence to prove. Sentinel could fabricate one and will
  not: a chain manufactured backwards is a proof that nobody touched data this package never saw.
- **That the source captured everything.** See the four ways above.
- **That a record can be rebuilt from an imported entry.** There is no `before` to go back to, so a
  whole-record `restore()` on an imported entry is **refused** — `Omission::EntryImported`. Naming
  the fields works, and puts back what the snapshot holds.
- **That an event means the same thing in both.**

### Two things that will look wrong and are not

**The order.** Imported entries sit at the end of the chain even though they are the oldest facts
in it. Order by `occurred_at` to read a history.

**The partition.** If you partitioned by `created_at`, everything imported lands in the current
partition whatever year it is from.

### It cannot be undone

Undoing an import means deleting a range of the trail, and with compliance mode on that is refused
without archiving it first.

---

## 5. The Rector stub

[`stubs/rector/altek.php`](stubs/rector/altek.php) renames the imports in **your** application. It
is a starting point in dry run and never a migration:

```bash
cp vendor/elpandape/sentinel/stubs/rector/altek.php rector-sentinel.php
vendor/bin/rector process --config=rector-sentinel.php --dry-run
```

It touches fully-qualified names and imports and nothing else. Ciphers, the context bitmask and the
ledger threshold have no automatic equivalent and are yours to decide.
