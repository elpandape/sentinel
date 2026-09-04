# Migrating from `owen-it/laravel-auditing`

> **Written against `owen-it/laravel-auditing` v14.0.6.** Its `audits` table has not moved a column
> since v10.0.0, so the mapping below holds for v10 through v14. On v6 to v9 it does not: the actor
> was a single `user_id` with no `user_type` until v8, and `id` was a plain `increments` until v10.
> Check your own migration before you trust this page.

Sentinel and Laravel Auditing answer the same question and disagree about almost everything else.
This is what the two have in common, what they do not, and how to bring a history across.

Read [What it guarantees and what it does not](#what-it-guarantees-and-what-it-does-not) before you
run anything. It is short and it is the part that matters.

---

## 1. Equivalences

| Laravel Auditing | Sentinel |
|---|---|
| `use OwenIt\Auditing\Auditable;` | `use ElPandaPe\Sentinel\Concerns\Auditable;` |
| `implements OwenIt\Auditing\Contracts\Auditable` | Nothing to implement |
| `$model->audits()` | `Sentinel::audits()->for($model)->get()` |
| `$auditInclude` | `$auditInclude` — same idea, same name |
| `$auditExclude` | `$auditExclude` — same idea, same name |
| `$auditStrict` | No equivalent. Hidden attributes are governed by `snapshots.include_hidden` |
| `$auditTimestamps` | No equivalent. Sentinel snapshots the record and lets the diff decide what moved |
| `$auditEvents` | `$auditEvents` |
| `generateTags(): array` | `$auditTags`, or `Sentinel::event()->tag()` — see *Labels* in the README |
| No equivalent | `$auditRedact`, `$auditEncrypt`, `$auditHash` — see *The write pipeline* |
| `config('audit.resolvers')` + `config('audit.user.resolver')` | `config('sentinel.resolvers')`, one slot per resolved thing |
| `Contracts\AuditDriver` | `Contracts\Ledger` — a wider contract, with a published test suite |
| `Drivers\Database` | `ledger.default = 'database'` |

Two differences worth knowing before you plan the work, because neither is a setting:

**Sentinel records what Laravel Auditing cannot.** Pivot changes, mass `Builder::update()` and
`Builder::delete()`, business transactions, state transitions and authentication events all leave
entries. Laravel Auditing's own troubleshooting page names the mass-operation gap as its most
common support request.

**Sentinel's history is a chain.** Every entry links to the one before it in its stream and can be
proven untampered without the key that encrypted half of it. That is what the rest of this page is
careful about.

---

## 2. What each column becomes

| `audits` | `sentinel_audits` | Note |
|---|---|---|
| `id` | `metadata.import.row` | Where the entry came from. It is what makes a repeated import cost nothing |
| `auditable_type` / `auditable_id` | `subject_type` / `subject_id` | `audit_type` becomes `model` |
| `event` | `event` | Carried as written. Sentinel invents no vocabulary for it |
| `old_values` | `before` | **See below** |
| `new_values` | `after` | **See below** |
| `old_values` + `new_values` | `changes` | Recomputed by Sentinel's diff engine, not copied. The source never wrote one |
| `<prefix>_type` / `<prefix>_id` | `actor_type` / `actor_id` | The prefix is `audit.user.morph_prefix` in your source config, `user` by default. Pass `--actor=` if you changed it |
| — | `impersonator_type` / `impersonator_id` | Left empty. Laravel Auditing has no impersonation concept |
| `url` | `context.url` | |
| `ip_address` | `context.ip` | |
| `user_agent` | `context.user_agent` | |
| `tags` | `sentinel_audit_tags` | One row per label. The source keeps them comma-joined in one column |
| `created_at` | `occurred_at` | The entry's own `created_at` is the instant of the import |
| `updated_at` | — | Dropped. An audit row that was updated is a fact about that table, not about the record |

### What is already missing before Sentinel sees it

Three gaps live in the source rows. None of them can be closed from this side, and none of them is
closed by pretending:

- **An `updated` row holds only what Eloquent called dirty.** Laravel Auditing writes
  `getDirty()`, so the `old_values` and `new_values` of an update are the fields that moved and not
  the record. A `created` and a `deleted` row do carry the whole thing.
- **An attribute whose value was an array was dropped.** `audit.allowed_array_values` is `false` by
  default, and anything array-shaped never reached the table.
- **A label containing a comma was already two labels.** The `tags` column is one string joined by
  commas with no escaping, so the split happened when it was written, not when it is read.

---

## 3. The procedure

**Start with a dry run. It is the documented way in, not a suggestion.**

```bash
php artisan sentinel:import --from=owenit --dry-run
```

It reads every row, maps every one, puts each through the write pipeline that would refuse it, and
writes nothing. What it prints is what a real run would do.

```
+-------------------------+------+
| Outcome                 | Rows |
+-------------------------+------+
| Read from the source    | 4    |
| Written as entries      | 3    |
| Already imported        | 0    |
| Refused by the pipeline | 0    |
| Could not be read       | 1    |
+-------------------------+------+
1 could not be read because the row does not say when it happened, and an invented instant is worse than no entry
Would import 3 entries from 4 source rows. Nothing was written.
```

Then run it:

```bash
php artisan sentinel:import --from=owenit
```

**Options you may need.** `--table=` if your application moved the table, `--connection=` if the
source lives on another one, `--actor=` if you changed the morph prefix, `--size=` for the batch
size, and `--after=` to carry on from a row.

**If it is interrupted, run it again.** Nothing is duplicated: every entry carries an identity
derived from the source row it came from, so a second run offers the same identities, the ledger
says it already has them, and they are dropped before anything is hashed. The command prints the
last row it read; `--after=` that key to skip straight back to where it stopped.

**Exit codes.** `0` when every row read became an entry or already was one. `1` when something did
not come across — the run happened, and the trail is short of what the source held. `2` for a
package it does not read, a table shaped like something else, or a connection it cannot reach.

### What to check afterwards

```bash
php artisan sentinel:verify
php artisan sentinel:show --subject="App\Models\Invoice:77"
```

The verification should come back intact. If you had entries before the import, theirs are
untouched and the imported ones sit behind them in the chain.

---

## 4. What it guarantees and what it does not

**It guarantees:**

- Every source row produces at most one entry, however many times you run it.
- The mapping is the one on this page, and a frozen test in the package holds it to that.
- The chain verifies from the point of import onwards.
- Your model's `$auditRedact`, `$auditEncrypt` and `$auditHash` apply to what comes in, however
  plainly the source held it.

**It does not guarantee:**

- **Anything about what happened before the import.** The entries carried over have no link to each
  other, because nobody hashed them as they were written. Sentinel could fabricate one and will
  not: a chain manufactured backwards is a proof that nobody touched data this package never saw,
  which is exactly the claim an audit engine must never make. Your trail is provable from the
  import forward, and honest about the part before it.
- **That the source captured everything.** Laravel Auditing records no mass operations, no pivot
  changes and nothing outside a model event. What it never wrote cannot be brought over.
- **That `before` and `after` are complete.** See §2. Sentinel refuses a whole-record
  `restore()` on an imported entry for this reason, and allows one that names its fields.
- **That an event or a label means the same thing in both.** They are your strings and they come
  across as written.

### Two things that will look wrong and are not

**The order.** Imported entries sit at the end of the chain even though they are the oldest facts
in it. `sequence` is the order entries were written and `occurred_at` is the order things happened,
and after an import the two disagree — the same divergence the `queue` and `buffered` modes already
document. Order by `occurred_at` to read a history.

**The partition.** If you partitioned by `created_at`, everything imported lands in the current
partition whatever year it is from. `created_at` is the date of the entry, not of the fact.

### It cannot be undone

Undoing an import means deleting a range of the trail, and with compliance mode on that is refused
without archiving it first. This is the reason the dry run comes first.

---

## 5. The Rector stub

[`stubs/rector/owen-it.php`](stubs/rector/owen-it.php) renames the imports in **your** application.
It is a starting point in dry run and never a migration:

```bash
cp vendor/elpandape/sentinel/stubs/rector/owen-it.php rector-sentinel.php
vendor/bin/rector process --config=rector-sentinel.php --dry-run
```

It touches fully-qualified names and imports and nothing else. Every behavioural difference on this
page is yours to make by hand — a tool that tried would be a tool guessing at what your audit trail
is supposed to mean.
