# 🔄 From altek/accountant

> How `sentinel:import --from=altek` reads an `accountant` ledger, what the mapping gives you, and
> why a whole-record restore on anything it wrote is refused before it starts.

**On this page:** [The sentence that changes your plan](#the-sentence-that-changes-your-plan) ·
[What accountant wrote](#what-accountant-wrote-and-what-it-never-did) ·
[Column by column](#column-by-column) · [The API equivalence](#the-api-equivalence) ·
[Restoring from an imported entry](#restoring-from-an-imported-entry) · [Running it](#running-it) ·
[Pitfalls](#-pitfalls)

---

## The sentence that changes your plan

**`altek/accountant` never wrote a `before`, so a whole-record `restore()` on an imported entry is
refused by design.** The call comes back with `refused === Omission::EntryImported` and nothing is
written — not to the record, not to the trail.

```php
use ElPandaPe\Sentinel\Enums\Omission;
use ElPandaPe\Sentinel\Models\Audit;

$entry = Audit::query()->findOrFail($id);   // an entry sentinel:import brought over

$entry->restore()->refused;                 // Omission::EntryImported
$entry->restore()->applied;                 // []
$entry->restore()->entry;                   // null — no restoration entry was written
```

The guard is in `Restore\Planner::for()` and reads exactly one thing: `$audit->source ===
Source::Import` with no field list given. It does not look at the origin, the event, or whether the
snapshot happens to be complete. That uniformity is deliberate — a rule that has to be reasoned
about per row is a rule somebody will reason about wrongly — and it means the refusal also lands on
entries imported from `owen-it/laravel-auditing`, including its `created` and `deleted` rows that
*do* carry whole snapshots. See [From owen-it/laravel-auditing](01-from-owen-it.md).

What you keep is the granular form. Name the fields and the plan is built normally:

```php
$result = $entry->restore(['status', 'total']);

$result->refused;             // null
$result->applied;             // ['status'] — 'total' already held that value
$result->reason('total');     // Omission::Unchanged
```

> 📌 **Note.** For accountant specifically the refusal is not a shame. `properties` holds a
> **complete** snapshot of the record on every row, so what an imported entry portrays is real —
> it is `after`, and it is whole. The refusal is a package-wide rule about the class of entry, not
> a statement that this particular snapshot is thin.

Read [Restoring state](../06-reading/08-restoring-state.md) for the rest of the engine; everything
below is what changes when the entry came from accountant.

---

## What accountant wrote, and what it never did

Accountant is not Laravel Auditing with different column names, and the whole reason this page is
separate from the owen-it one is the shape of what it recorded:

| | `altek/accountant` | Sentinel after the import |
|---|---|---|
| State before an event | **Nowhere in the table** | `before` is `null` |
| State after an event | `properties`, a whole snapshot on every row | `after`, complete |
| What moved | `modified`, a bare list of attribute *names* | `changes`, with `new` and **no `old` key** |
| Row-level integrity | `signature`, a digest of the row over itself | Kept as data in `metadata.import.signature` |
| Chain | None. Altering row N leaves row N+1's signature valid | `sequence` / `previous_hash` / `hash` from the import forward |

`Import\Origins\Altek::changes()` builds every entry with `Diff\Change(..., oldKnown: false)`, and
`Change::toArray()` omits the `old` key entirely when `oldKnown` is false. So the diff on an
imported accountant entry says *these attributes ended up holding these values* and asserts nothing
about what came before:

```php
// From the frozen mapping fixture: an `updated` row whose `modified` was ["status"].
$entry->changes;
// [['path' => '/status', 'op' => 'replace', 'new' => 'sent']]
//   ↑ no 'old' key at all — not null, absent.
```

**Why the `before` is not reconstructed.** It could be guessed at, by pairing every row with the
previous row of the same recordable and calling the difference a change. That is a deduction, not a
record. An audit engine that writes down a value nobody observed has stopped being one, so the
importer does not, and neither should an application reading the result.

**Why the signature is not carried into `signature`.** `sentinel_audits.signature` means *signed by
Sentinel's chain, with a key on Sentinel's key ring*. A foreign per-row digest put there would make
`sentinel:verify` report a signature it cannot check as one it can. It is kept in `metadata` where
it is a fact about the source. See [Signing the chain](../07-integrity/04-signing.md).

---

## Column by column

`Import\Origins\Altek::columns()` is also the shape check: its sixteen names — every column below
except `before`, which has no source column, and `updated_at`, which is not required — must exist by
name on the source table or `sentinel:import` refuses before reading a single row.

| `ledgers` column | Lands in | Note |
|---|---|---|
| `id` | `metadata.import.row` | Also the input to the derived `capture_id` |
| `recordable_type` | `subject_type` | Carried as the source wrote it, not resolved to a class |
| `recordable_id` | `subject_id` | Cast to string |
| `event` | `event` | Verbatim. `audit_type` is set to `model` |
| `properties` | `after` | Complete snapshot. Must decode to a JSON **object** |
| — | `before` | **Always `null`.** Nobody wrote one |
| `modified` + `properties` | `changes` | Names ∩ snapshot, with `new` and no `old` |
| `<prefix>_type` / `<prefix>_id` | `actor_type` / `actor_id` | Prefix from `accountant.user.prefix`; pass `--actor=` |
| `url` | `context.url` | Absent when null, never an empty key |
| `ip_address` | `context.ip` | |
| `user_agent` | `context.user_agent` | |
| `context` | `metadata.import.context` | The bitmask as the integer it is: `TEST=1`, `CLI=2`, `WEB=4` |
| `signature` | `metadata.import.signature` | Data about the source, never Sentinel's own signature |
| `extra` | `metadata.import.extra` | Whatever `supplyExtra()` returned, unreinterpreted |
| `pivot` | `metadata.import.pivot` | Ditto — **not** turned into relation lines |
| `created_at` | `occurred_at` | The entry's own `created_at` is the instant of the import |
| `updated_at` | — | Dropped |

Nothing else on the mapped entry is populated by the origin: `impersonator_*`, `tenant_id`,
`transaction_id`, `request_id`, `trace_id`, `span_id`, `criteria`, `affected_rows`,
`source_audit_id` and `tags` all stay empty, because accountant records no answer to any of them.

### How `changes` is built

Three rules, all in `Altek::changes()`:

1. `op` is `add` when the source event is exactly `created`, and `replace` for everything else —
   including `deleted`. The source's own word for the column is `modified`, and that is all it
   claims.
2. A name in `modified` that the `properties` snapshot does not carry is **dropped**, not written
   as a null. A column dropped between the write and the dump simply does not appear.
3. Order follows `modified`, not the alphabet. (The owen-it origin builds its diff with
   `Diff::between()`, which sorts by path — so the two origins produce differently ordered
   `changes` for the same record. Neither order is part of the canonical payload's meaning.)

| `properties` | `modified` | Resulting `changes` |
|---|---|---|
| `{"status":"sent"}` | `["status"]` | `[{path:/status, op:replace, new:"sent"}]` |
| `{"status":"sent"}` | `["status","gone_since"]` | `[{path:/status, …}]` — the unknown name is dropped |
| `{"status":"sent"}` | `[]` | `[]` — the source listed nothing |
| absent or not an object | anything | `null` — nothing to compare against |

### Events: the one that does not line up

The event string is carried verbatim, and `sentinel_audits.event` is a plain `string(64)` that is
never cast to `AuditEvent`. Four of accountant's five events happen to match Sentinel's vocabulary.
One does not.

| accountant `event` | Matches an `AuditEvent` case | Severity it receives |
|---|---|---|
| `created` | `AuditEvent::Created` | `info` (the default) |
| `updated` | `AuditEvent::Updated` | `info` |
| `restored` | `AuditEvent::Restored` | `info` |
| `deleted` | `AuditEvent::Deleted` | `notice`, from `severity.events.deleted` |
| `forceDeleted` | **No.** Sentinel's case is `force_deleted` | `info` — no override key matches |

> ⚠️ **Warning.** `whereEvent(AuditEvent::ForceDeleted)` will not match an imported accountant hard
> delete: it filters on the string `force_deleted` and the row holds `forceDeleted`. Use
> `whereEvent('forceDeleted')`. And if you want those rows to carry the severity a native force
> delete gets, add `'forceDeleted' => 'warning'` to `sentinel.severity.events` **before** you run
> the import — `occurred_at`, `event` and `severity` are all inside the hashed canonical payload,
> so none of them can be corrected afterwards without deleting the range and re-importing.

---

## The API equivalence

For a team that knows accountant, this is the shortest route into the package.

| `altek/accountant` | Sentinel |
|---|---|
| `use Altek\Accountant\Recordable;` | `use ElPandaPe\Sentinel\Concerns\Auditable;` |
| `implements Contracts\Recordable` | Nothing to implement — the trait is the whole declaration |
| `$model->ledgers()` | `Sentinel::audits()->for($model)->get()` |
| `$recordableEvents` | **No equivalent.** The trait always observes `created`, `updated`, `deleted` and `forceDeleted`; narrow with `$auditExclude`, a `Sentinel::filter()` policy, or `Sentinel::withoutAuditing()` |
| `$ciphers = ['card' => Base64::class]` | `$auditEncrypt = ['card']` — reversible, with a key ring |
| `$ciphers = ['card' => Bleach::class]` | `$auditRedact = ['card']`, or `$auditHash` to keep it comparable |
| `Contracts\Cipher` | Not a class you name: per-field configuration on the write pipeline |
| `Ledger::isTainted()` | `$audit->verifyIntegrity()` |
| `Recordable::isCurrentStateReachable()` | `php artisan sentinel:verify`, over the chain rather than one record |
| `Ledger::extract()` | `$audit->restore()` — **but see the guard above for imported entries** |
| `Notary::sign()` | A `Contracts\Signer` over the chain hash, with a key ring that rotates and retires |
| `config('accountant.contexts')` bitmask | No equivalent, and none is wanted (see below) |
| `config('accountant.ledger.threshold')` | `sentinel:prune` with a retention policy |
| `altek/eventually` for pivot events | Built in — [Relationship auditing](../03-capture/04-relationships.md) |

Two of those are not really equivalences. **The context bitmask has no counterpart on purpose:**
Sentinel records *where* a write came from — `Enums\Source`, resolved per entry — and never uses it
to decide *whether* to record ([Execution context](../04-context/01-execution-context.md)). And
**`ledger.threshold` is not `sentinel:prune`:** the threshold hard-deleted the oldest rows of a
record after every successful write, leaving nothing behind, where pruning archives before it
removes and leaves an anchor answering for what went
([Retention and pruning](../08-lifecycle/01-retention-and-pruning.md)).

### The Rector stub

`stubs/rector/altek.php` ships in the package. It renames five fully-qualified names and their
imports in **your** application and does nothing else:

```bash
cp vendor/elpandape/sentinel/stubs/rector/altek.php rector-sentinel.php
vendor/bin/rector process --config=rector-sentinel.php --dry-run
```

It deliberately refuses to rename `Contracts\Recordable` (delete the `implements` clause by hand),
`Contracts\Cipher` and the two shipped ciphers (they become per-field settings, not classes),
`Accountant\Context` and `Accountant\Notary` — there is nothing to rename any of those to. Read
every hunk in dry run before you let it write.

---

## Restoring from an imported entry

Accountant's record-and-restore model — `Ledger::extract()` and `isCurrentStateReachable()` — is
the closest prior art to Sentinel's restore engine, and the two agree on the idea: an entry is a
photograph, and restoring is going back to that moment. They differ in what they will assert.

| | `Ledger::extract()` | `Audit::restore()` on an imported entry |
|---|---|---|
| Whole record | Rebuilds it from the snapshot | **Refused** — `Omission::EntryImported` |
| Named fields | n/a | Planned normally, per field |
| Result | A model instance | `RestoreResult` — `applied`, `skipped`, `refused`, `entry` |
| What it writes | Nothing until you save | One new `audit_type = restore` entry pointing back |
| Fields it declines | n/a | Redacted, hashed, identity, unknown, unrecorded, unchanged |

Four consequences worth knowing before you plan a data-recovery workflow on top of an import.

**It never lifts a soft delete.** `Planner::revived()` only adds `deleted_at => null` when the whole
state was asked for, and the whole state is exactly what an imported entry refuses. An imported
accountant `deleted` row will put its other fields back if you name them; the record stays in the
bin.

**`restoreRelationship()` answers `EntryStateless`, always.** `RelationPlanner` reads relation lines
out of the entry's own `changes`, looking for a `relation` key on each line. Accountant's `pivot`
column lands in `metadata.import.pivot` and never becomes a line, so no imported entry carries one —
`$entry->restoreRelationship('members')->refused` is `Omission::EntryStateless` whatever the
relation is named.

**A row with no snapshot refuses earlier, and for a different reason.** The planner checks in order:
subject missing → redacted → tampered → **stateless** → imported. An accountant row whose
`properties` did not decode to a JSON object maps to `after === null` and `before === null`, so its
whole-record restore comes back `Omission::EntryStateless`, not `EntryImported`.

**A field the snapshot does not carry is `UnrecordedField`.** There is no old-name-to-new-name
mapping anywhere in the engine, so a column renamed since the source wrote the row cannot be
restored under either name.

```php
use ElPandaPe\Sentinel\Enums\Omission;

$result = $entry->restore(['status', 'card_number', 'id', 'legacy_ref']);

$result->applied;                     // ['status']
$result->reason('card_number');       // Omission::RedactedField  — masked on the way in, gone
$result->reason('id');                // Omission::IdentityField  — never written back
$result->reason('legacy_ref');        // Omission::UnrecordedField — not in the snapshot
$result->entry?->id;                  // the ULID of the entry that recorded this restoration
```

Every `Omission` has a translated message: `Omission::EntryImported->message()` reads *"This entry
was imported from another package, which may not have recorded the whole record. Name the fields to
put back and they will be."* English and Spanish ship with the package.

> 🔒 **Security.** A field under accountant's one-way `Bleach` cipher was redacted when it was
> written. It comes across as it is stored — redacted — and there is nothing to recover. If you
> want a field restorable in Sentinel, declare it in `$auditEncrypt`, never `$auditRedact` or
> `$auditHash`. See [Protecting sensitive data](../05-pipeline-and-security/02-protecting-sensitive-data.md).

---

## Running it

The full procedure, including backups and verification, is
[The import runbook](03-the-import-runbook.md). The accountant-specific parts are these.

```bash
# The two source settings the importer cannot guess. Read them first.
#   config('accountant.table')       default 'ledgers'  -> --table= if it moved
#   config('accountant.user.prefix') default 'user'     -> --actor= if it changed

php artisan sentinel:import --from=altek --dry-run
php artisan sentinel:import --from=altek --size=1000

# Interrupted? Run the same command again. Nothing duplicates.
# --after= takes the SOURCE row id the command printed, and is exclusive.
php artisan sentinel:import --from=altek --size=1000 --after=90210
```

| Option | Default | What it does |
|---|---|---|
| `--from` | none | `altek` selects this origin. Anything unknown exits `2` |
| `--table` | `ledgers` | The source table, if the application moved it |
| `--connection` | the app default | The connection the `ledgers` table lives on |
| `--actor` | `user` | The prefix of the two actor columns, from `accountant.user.prefix` |
| `--size` | `500` | Source rows read per batch; a non-numeric value falls back to 500, and the importer clamps to at least 1 |
| `--after` | none | Skip every row up to **and including** this source key |
| `--dry-run` | off | Read and map everything, write nothing |

**Exit codes**, from `Console\ImportCommand` — `0` and `1` from its `report()`, `2` from `handle()`:

| Code | Meaning |
|---|---|
| `0` | Every row read became an entry or already was one |
| `1` | The run happened and something did not come across — unreadable rows, or pipeline discards |
| `2` | Unknown `--from`, absent or mis-shaped table, **or an exception mid-run** |

> ⚠️ **Warning.** Exit `2` from a mid-run exception prints *"Nothing was imported"*, and that
> message is wrong: every batch settled before the failure is already committed. Do not act on it.
> Run the same command again — the derived identity makes already-settled rows repeat rather than
> duplicate.

### The three rows the origin refuses

`Altek::map()` returns a refusal with its reason instead of an entry, and the report groups by
reason. None of the three is repairable from this side:

| Reason printed | Cause |
|---|---|
| `the row has no key of its own to be identified by` | `id` is null or empty — nothing to derive an identity from |
| `the row does not say when it happened, and an invented instant is worse than no entry` | `created_at` is null or unparseable |
| `the row does not say what it is about` | `recordable_type` or `recordable_id` is null or empty |

### Idempotence

Every mapped entry gets `capture_id = DerivedIdentity::of('altek', $sourceId)` — a 26-character
Crockford digest of `sha256('altek' . "\x1f" . $id)`. A second run offers the same identities, the
ledger recognises them through `Contracts\Deduplicates::settled()`, and they are dropped before a
hash is computed for any of them. The unique index on `sentinel_audits.capture_id` has the last
word if the ledger cannot answer.

The source name is inside the digest and not a prefix, so the same row key in an owen-it dump and an
accountant dump produce different identities — a frozen test asserts it. The corollary: **a re-keyed
or renumbered dump of the same source imports everything again as new entries**, and there is no way
to remove the duplicates except by deleting a range of the trail.

---

## Four ways the source is already short

None of these can be detected from Sentinel's side, and all four are worth checking before you
conclude the import lost something.

| What | Where to look | What it means |
|---|---|---|
| The context bitmask | `config('accountant.contexts')` | It defaults to the web context alone. Anything the application did from the console, a queue worker or a test recorded **nothing at all**, silently — the observer was never registered |
| The ledger threshold | `config('accountant.ledger.threshold')` | Above zero, it hard-deleted the oldest rows of every record after each successful write, with no trace that they existed |
| The one-way cipher | `$ciphers` on each model | An attribute under `Bleach` was redacted at write time and cannot be recovered |
| Pivot events | `altek/eventually` + `$recordableEvents` | They needed both. Their absence in the dump does not mean nothing happened |

The bitmask is why the importer keeps `context` as the integer it is rather than translating it:
its *absence* is the story. If `metadata.import.context` is `4` on every entry and never `2`, the
source recorded nothing at all from the console — and a gap in the migrated period is that, not a
failure of the import.

---

## Reading a migrated trail

```php
use ElPandaPe\Sentinel\Enums\Source;
use ElPandaPe\Sentinel\Facades\Sentinel;

$imported = Sentinel::audits()
    ->whereSource(Source::Import)
    ->byOccurrence()          // never by sequence — see below
    ->take(50)
    ->get();

foreach ($imported as $entry) {
    $entry->metadata['import']['origin'];      // 'altek'
    $entry->metadata['import']['row'];         // the source ledgers.id
    $entry->metadata['import']['signature'];   // accountant's own per-row digest, as data
    $entry->before;                            // null, always
    $entry->verifyIntegrity();                 // true — it reproduces its own hash
}
```

**Order.** An import appends to the tail of the stream, so imported entries hold the newest
`sequence` values and the oldest `occurred_at` values. The two orders disagree by design. Read a
migrated history with `byOccurrence()`. See
[Order, paging and walking the trail](../06-reading/03-order-paging-and-walking.md).

**Stream.** Imported entries carry no `tenant_id`, and under the shipped `integrity.stream =
'tenant'` a null tenant maps to the `global` stream. In a multi-tenant installation the migrated
history therefore sits in a different chain from everything written since, which changes what
`sentinel:verify --stream=` covers. It is coherent and it verifies — it is just not where you might
look for it. See [Streams](../07-integrity/02-streams.md).

**Partition.** If you partitioned by `created_at`, everything imported lands in the current
partition whatever year the fact is from: `created_at` is the date of the entry, not of the fact.
See [Partitioning](../10-database-engines/06-partitioning.md).

> 📌 **Note.** `payload_version` stays `1` for imported entries. There is no separate format for
> them; the mark is `source = 'import'`, and it sits **inside** the hashed canonical payload — so an
> imported entry cannot be relabelled as a native one without breaking its own hash.

### What the import does not prove

The chain starts at the import. Imported entries get a `sequence`, a `previous_hash` and a `hash`
like any other, and they link onto whatever chain was already there — but nothing links the source
rows to each other, because nobody hashed them as they were written. Sentinel could fabricate a
chain backwards and does not: that would be a proof that nobody touched data this package never saw,
which is the one claim an audit engine must never make.

Say that plainly to whoever asks. See [The hash chain](../07-integrity/01-the-hash-chain.md).

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `restore()` returns `refused: entry_imported` and nothing moves | Whole-record restore is refused on every imported entry, regardless of origin or event | Name the fields: `$entry->restore(['status', 'total'])` |
| A soft-deleted record stays in the bin after restoring from an imported `deleted` entry | `deleted_at => null` is only added on a whole restore, which imported entries refuse | Clear the column in your own code, inside an `AuditRestoring`-gated action |
| `restoreRelationship()` answers `entry_stateless` on a row you know had pivot data | Accountant's `pivot` column lands in `metadata.import.pivot` and never becomes a relation line | Read `metadata.import.pivot` as data; there is no relation restore for imported entries |
| `whereEvent(AuditEvent::ForceDeleted)` finds no imported hard deletes | The source wrote `forceDeleted`; the enum's value is `force_deleted`, and `event` is an uncast string column | Filter with `whereEvent('forceDeleted')` |
| Imported hard deletes carry severity `info` where native ones carry `warning` | `severity.events` has no `forceDeleted` key, so the default applies | Add `'forceDeleted' => 'warning'` to `sentinel.severity.events` **before** the run — severity is inside the hash |
| The dry run reported zero refusals; the real run refused rows and exited `1` | `--dry-run` never calls the pipeline. `Importer::walk()` computes `written = offered − already` in rehearse mode, so `discarded` is structurally always `0` | Treat the dry run's "Written as entries" as an upper bound; it proves the shape and the mapping, not the pipeline |
| The report says `written = 0` and everything was "Refused by the pipeline" | The command ran inside an open transaction on the application's default connection; with `sentinel.transactions.after_commit` on, the batch is deferred to the commit and `recordMany()` returns nothing to count | Never run it inside `DB::transaction()` or a migration |
| Exit `2` with "Nothing was imported", but rows are in the trail | A mid-run exception. The message is wrong: batches settled before the failure are committed | Re-run the same command; already-settled rows repeat rather than duplicate |
| Every `occurred_at` is off by a fixed number of hours | `Row::instant()` parses the source timestamp with `CarbonImmutable::parse()` and no timezone, so a naive value is read in the importing app's `app.timezone` | Set `app.timezone` to the source database's zone for the run. There is no `--timezone` option, and `occurred_at` cannot be corrected after it is hashed |
| `metadata.import.pivot` or `.extra` is missing on rows you know had one | `Row::json()` answers `null` for anything that is not a JSON **object** at the top level, and the origin drops null and `[]` keys | Expected when the source stored a JSON list there; the data is not recoverable through the importer |
| `$auditRedact` / `$auditEncrypt` / `$auditHash` did not apply to imported values | The policy is resolved from the class named in `recordable_type`. If the migration renamed the model first, `PolicyRegistry::resolve()` finds nothing | Import **before** renaming models, or add a morph map alias. The global `security.*.fields` lists apply either way |
| `The table [ledgers] is not shaped like [altek] history` | The shape check compares column **names** against `Altek::columns()` and refuses on any miss | Check `accountant.table` and `accountant.user.prefix`, then pass `--table=` / `--actor=`. On accountant v1/v2 the `pivot` column does not exist and this mapping does not hold |
| A second run wrote everything again | The source was re-keyed or a different dump was pointed at the same key space. The identity is `DerivedIdentity::of('altek', $id)` | Nothing removes the duplicates but deleting a range of the trail. Always dry-run against the dump you will import |
| A whole restore refuses with `entry_stateless`, not `entry_imported` | `properties` did not decode to a JSON object, so `after` and `before` are both null and the stateless check runs first | The row carries no state to put back. Nothing to fix on this side |

---

## ✅ Best practices

✅ **Do** — read `RestoreResult` in three steps on imported entries: `refused`, then
`applied === []`, then `skipped`. An imported entry can be refused, be a no-op, or apply a subset,
and only the three-way read tells them apart.

```php
$result = $entry->restore(['status', 'total']);

$message = match (true) {
    $result->refused instanceof Omission => $result->refused->message(),
    $result->applied === []              => 'The record already holds what this entry recorded.',
    default                              => 'Restored: '.implode(', ', $result->applied),
};
```

❌ **Don't** — treat a refusal as an exception to catch. There is no `RestoreException`; the engine
answers with a result, and a `try` block around `restore()` silently catches only what the subject's
own `save()` throws.

```php
try {
    $entry->restore();            // never throws EntryImported — it returns it
} catch (Throwable) {
    // this block never runs for the case you wrote it for
}
```

---

✅ **Do** — set the severity override for accountant's camelCase hard delete **before** the run.
`severity` is inside the canonical payload the hash seals, so it is not correctable afterwards.

```php
// config/sentinel.php
'severity' => [
    'default' => 'info',
    'events' => [
        'deleted' => 'notice',
        'force_deleted' => 'warning',
        'forceDeleted' => 'warning',   // what altek actually wrote
    ],
],
```

❌ **Don't** — plan to fix it later with an update. Changing `event`, `severity` or `occurred_at`
on a settled entry breaks its hash and `sentinel:verify` will report the row as tampered — which is
exactly what it is.

```php
Audit::query()->where('event', 'forceDeleted')->update(['severity' => 'warning']);
// the chain now fails verification from this entry forward
```

---

✅ **Do** — import before you rename or move models, so the per-model protections resolve.

```php
// 1. php artisan sentinel:import --from=altek
// 2. THEN rename App\Models\Invoice, and add a morph map alias for the old name.
```

❌ **Don't** — rename first and rely on the guarantee that your `$auditRedact` applies to what
comes in. It is resolved from the string in `recordable_type`; when that class is gone the policy
comes back empty and the values land in the trail as the source held them.

```php
// recordable_type = 'App\Models\Legacy\Invoice', which no longer exists.
// $auditRedact on the new class is never consulted. Nothing errors.
```

---

✅ **Do** — read a migrated trail by occurrence, and isolate it with `whereSource`.

```php
Sentinel::audits()
    ->for('App\Models\Invoice', '77')
    ->whereSource(Source::Import)
    ->byOccurrence()
    ->get();
```

❌ **Don't** — order a migrated history by `sequence` and present it as a timeline. Imported
entries hold the newest sequence numbers and the oldest facts; the reader sees 2024 at the bottom
of a list headed "most recent".

```php
Sentinel::audits()->for($invoice)->get();   // ledger order, not history order
```

---

✅ **Do** — check `metadata.import.context` before you report a gap in the migrated period. A
bitmask that never included `CLI` (2) means the source recorded nothing outside the web context, and
that is a fact about accountant's configuration rather than about the import.

```php
$entry->metadata['import']['context'];   // 4 = WEB only
```

❌ **Don't** — copy `metadata.import.signature` into `sentinel_audits.signature` to "keep the
proof". Accountant's digest covers one row over itself and chains nothing; putting it in that column
would make `sentinel:verify` report an uncheckable signature as a checkable one.

```php
$entry->signature = $entry->metadata['import']['signature'];   // a lie, and it breaks the hash
```

---

✅ **Do** — verify after the run and spot-check a subject, typing the type exactly as accountant
wrote it into `recordable_type`.

```bash
php artisan sentinel:verify
php artisan sentinel:show --subject="App\Models\Invoice:77"
```

❌ **Don't** — turn compliance mode on before the import is verified. With it on, deleting a range
of the trail is refused without archiving first, and deleting a range is the only way back from a
bad import.

```php
// config/sentinel.php — leave this false until you have read the verify output.
'compliance' => true,
```

---

**See also:** [From owen-it/laravel-auditing](01-from-owen-it.md) ·
[The import runbook](03-the-import-runbook.md) ·
[Restoring state](../06-reading/08-restoring-state.md) ·
[The hash chain](../07-integrity/01-the-hash-chain.md) ·
[Enums](../99-reference/04-enums.md) · [Exit codes](../99-reference/07-exit-codes.md) ·
[Artisan commands](../09-operations/06-artisan-commands.md)
