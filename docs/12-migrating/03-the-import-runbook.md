# 🔄 The import runbook

> The operational procedure for `sentinel:import`, whichever package you are coming from: what to
> back up and freeze, how to read the report, what to do when a run stops halfway, and what
> verification does and does not prove about what came across.

**On this page:** [What an import actually does](#what-an-import-actually-does) ·
[Before you run it](#before-you-run-it) · [The dry run](#the-dry-run) ·
[The real run](#the-real-run) · [Reading the report](#reading-the-report) ·
[When it stops halfway](#when-it-stops-halfway) · [Verifying afterwards](#verifying-afterwards) ·
[Rolling back](#rolling-back) · [Running old and new side by side](#running-old-and-new-side-by-side) ·
[A timeline for a live system](#a-timeline-for-a-live-system) · [Pitfalls](#️-pitfalls) ·
[Best practices](#-best-practices)

---

This page is the procedure. The per-column mapping — which source column becomes which Sentinel
field, and what each package never recorded in the first place — lives in
[From owen-it/laravel-auditing](01-from-owen-it.md) and [From altek/accountant](02-from-altek.md).
Read your origin's page first; come back here to run it.

Nothing on this page differs by database engine on the *destination* side. Where the source engine
matters — it matters exactly once, for the resume cursor — the difference is called out.

---

## What an import actually does

`sentinel:import` is a one-way copy of another package's table into Sentinel's ledger. It is not an
adapter and not a live bridge. In order, per run:

| Step | Class | What happens |
|---|---|---|
| 1 | `Import\Shape` | Asks the schema builder whether the table exists and carries every column the origin requires. A missing column aborts before a single row is read. |
| 2 | `Import\Importer` | Forces `sentinel.mode` to `sync` and removes `Pipeline\Stages\ResolveContext` from `sentinel.pipeline`, restoring both in a `finally`. |
| 3 | `Import\Importer` | Reads the source forward by its `id` column, `--size` rows at a time, `where('id', '>', $cursor)`. |
| 4 | `Import\Origins\OwenIt` / `Altek` | Turns each raw row into either an `AuditData` or a refusal carrying its reason. |
| 5 | `Support\DerivedIdentity` | Gives each mapped entry a `capture_id` derived from `sha256(origin \x1f source row key)` — 26 Crockford characters, the same on every run. |
| 6 | `Capture\Recorder` | Settles the batch through the ordinary write pipeline and the ledger. No transaction of its own: `DatabaseLedger::writeMany()` already wraps the batch. |
| 7 | `Import\Report` | Counts the batch into four buckets and hands the command the last source key it read. |

Two consequences fall straight out of that list and govern everything below.

**The chain starts at the import.** Imported entries receive `sequence`, `hash` and `previous_hash`
from the ledger exactly like native ones, and link onto whatever chain was already there — a test in
the package plants three entries, imports on top, and asserts the fourth entry's `previous_hash` is
the third's `hash` and that the first three keep the hashes they had. What no part of this proves is
the period *before* the import: nobody hashed those source rows as they were written, and fabricating
a `previous_hash` backwards would be a proof that nobody touched data Sentinel never saw. See
[The hash chain](../07-integrity/01-the-hash-chain.md).

**Re-running is the recovery mechanism.** Because the identity is derived rather than minted, a
second run offers the same `capture_id` values, the ledger says it already holds them, and they are
dropped before a hash is computed for any of them. The `unique` index on `sentinel_audits.capture_id`
is the backstop under that. Nothing needs a bookmark to be *correct*; `--after` only makes it *fast*.

> 📌 **Note.** Imported entries are written under `payload_version = 1`, the same format everything
> else uses, and carry `source = import` **inside** the 27 canonical columns the hash covers. That is
> deliberate: an imported entry cannot be relabelled as a native one without breaking its own hash.
> It is also why no second payload version was minted for imports — changing the canonical payload,
> `sequence`, `hash` or `previous_hash` costs a `payload_version` bump and a backwards-compatibility
> test, and marking a `source` value cost neither.

---

## Before you run it

### What to back up

**A database backup or a snapshot of the destination, taken immediately before the real run.** This
is the whole rollback plan; see [Rolling back](#rolling-back) for why there is no second one. Back up
the *destination* — the source table is only read, never written, never truncated and never marked.

If you can, take a copy of the source table too. Not because the import touches it, but because a
mapping question three months later ("why is this entry's `before` empty?") is answerable against a
frozen copy and unanswerable against a table the old package kept writing to and then dropped.

### What to freeze

| Freeze | Until | Why |
|---|---|---|
| The old package's writing | The import has finished | There is no `--before`/`--until` option. A run always reads to the end of the table, so rows the old package writes mid-run may or may not be picked up depending on where the cursor is. Re-running is safe, but the accounting is only stable over a table that stopped moving. |
| Model class names and the morph map | The import has finished | `$auditRedact`, `$auditEncrypt` and `$auditHash` are resolved from the class named in the source's `auditable_type` / `recordable_type`. If that class no longer exists, `Support\PolicyRegistry` returns an empty policy and the per-model protections **silently do not apply**. |
| `sentinel.severity.*`, the security field lists, `integrity.stream`, `integrity.algorithm` | Forever, for these entries | All of them are sealed into the entry at write time and all of them are covered by the hash — `severity` inside the canonical payload, `stream` in the prefix the hash is taken over. Changing them afterwards does not change what was imported. |
| Deployments of the app that runs the import | The run | The importer mutates `sentinel.mode` and `sentinel.pipeline` in the config repository of its own process. A deploy that kills the process mid-run leaves committed batches and no report. |

> ⚠️ **Warning.** Import **before** you rename or move models, not after. The migration that renames
> `App\Invoice` to `App\Models\Invoice` is the same migration that quietly turns off redaction and
> encryption for every imported row of that model. If the rename already happened, add a morph map
> alias for the old name — `Relation::getMorphedModel()` is consulted first — or fall back to the
> global rules under `sentinel.security.redaction.fields`, `.encryption.fields` and `.hashing.fields`,
> which are unioned in regardless of whether the class resolves.
> See [Protecting sensitive data](../05-pipeline-and-security/02-protecting-sensitive-data.md).

### What to set before the first row

Anything in this list is part of the import, not a decision you can revisit afterwards:

| Setting | Effect on the import | If you get it wrong |
|---|---|---|
| `sentinel.severity.events` | `Origin::map()` calls `Config::defaultSeverity($event)` with the event string **exactly as the source wrote it** | The entry carries the wrong severity, inside the hash. `altek` writes `forceDeleted`, which does not match the shipped `force_deleted` key, so those rows get `severity.default` unless you add the camelCase key first. |
| `sentinel.security.*.fields` and the model's `$auditRedact` / `$auditEncrypt` / `$auditHash` | Applied by the pipeline to imported values, however plainly the source held them | A secret the old package stored in the clear stays in the clear, permanently. |
| `sentinel.integrity.signature.enabled` | Entries are signed at write time by `Ledger\EntryBuilder` and only then | Nothing signs an entry after it is written. The migrated history stays unsigned forever. Verification still exits `0` on it, reporting the count as unsigned. |
| `sentinel.integrity.stream` | Decides which chain the imported entries join | Imported entries carry no `tenant_id`, so under the default `tenant` strategy they all land in the `global` stream. See below. |
| `sentinel.compliance` | When on, deleting a range of the trail is refused without archiving first | Turning it on before the import means the only route back from a bad import is an archive-then-prune cycle. Turn it on *after* you have verified. |

> 🐘 **Engine.** Under `integrity.stream = 'subject_type'` the stream name is
> `'type:' . morphAlias($subject_type)` built from the raw type string the *old* application wrote,
> and `Integrity\Stream::guard()` refuses any name over 64 characters. A legacy FQCN longer than 59
> characters throws `ConfigurationException` at write time and takes the batch with it. Check the
> distinct `auditable_type` / `recordable_type` values before choosing that strategy.

> 📌 **Note.** Under the factory default `integrity.stream = 'tenant'`, every imported entry lands in
> the `global` stream, because `ResolveContext` is the stage that would have set `tenant_id` and it
> is the one stage the importer removes. That is coherent and it verifies — but in a multi-tenant
> installation the migrated history sits in a different stream from everything written since, which
> changes what `sentinel:verify --stream=` covers. See [Streams](../07-integrity/02-streams.md) and
> [Multi-tenancy](../04-context/04-multi-tenancy.md).

### What to size

There is no published throughput figure for the importer, and you should not assume one. What you
can size is the shape of the work:

- **Row count.** `select count(*)` on the source table. That is the number the report's first row
  must eventually match.
- **Batch memory.** `--size` rows are materialised as objects, mapped to `AuditData`, transformed by
  the pipeline and handed to the ledger as one batch. Lower it when the source rows carry large
  `new_values` / `properties` blobs; raise it on a wide, fast connection. Non-numeric input falls
  back to `500`, and the importer clamps to `max(1, $size)`, so `--size=0` is a batch of one.
- **Per-batch cost.** One `SELECT` of `--size` rows, two `settled()` lookups when the ledger
  implements `Contracts\Deduplicates` — one for the report's count, one in the write path —
  pipeline work per row, one wrapped ledger write.
- **Anchoring, if it is on.** With `integrity.checkpoints.enabled = true`, `DatabaseLedger` issues a
  anchor per stream after **every** batch commit. On a large import that is a fold-and-anchor
  pass per batch. See [Checkpoints and anchors](../07-integrity/05-checkpoints-and-anchors.md).
- **Storage.** One `sentinel_audits` row per readable source row, plus one `sentinel_audit_tags` row
  per label (`owen-it` labels come across; `altek` has none).

To time a real run without importing the whole table, copy a slice of the source into another table
or connection and point at it: `--table=audits_sample`, `--connection=legacy_copy`. There is no
`--limit`, so that copy is the only way to bound a *real* run.

---

## The dry run

```bash
php artisan sentinel:import --from=owenit --connection=legacy --dry-run
```

A dry run is the documented first step, not a suggestion — because an import cannot be undone.

**What it does:** verifies the table's shape, reads every row, maps every row, groups the refusals by
reason, asks the ledger which identities it already holds, and prints the same five-row table a real
run prints. It writes nothing.

**What it does not do — read this before you trust the numbers:** it does not run the write pipeline.
In rehearse mode `Importer::walk()` computes `written = count(offered) - already` and never calls
`Recorder::recordMany()`, which is where `Pipeline::process()` lives. Two consequences:

- **`Written as entries` is an upper bound**, not a prediction. It counts rows the real run's
  pipeline would refuse.
- **`Refused by the pipeline` is structurally always `0` in a dry run.** It is not "we found none";
  it is "the question was never asked".

The gap is not hypothetical. An `owen-it` `updated` row whose `old_values` and `new_values` are both
`[]` maps to `changes === []`, and `Pipeline\Stages\FilterUnchanged` discards exactly that: an
`updated` model entry with no changes. Any policy registered through `Sentinel::filter()` behaves the
same way, through `EnforcePolicies`. So a dry run over a source full of such rows counts them all as
written and exits `0`, while the real run counts them as refused and exits `1`.

> 💡 **Tip.** If you need a true rehearsal including the pipeline, restore the destination backup onto
> a scratch database and do a *real* run against it. The dry run tells you about the **source**; only
> a real run tells you about the **pipeline**.

A dry run **can** still exit `1` — on unreadable rows, which are counted the same way in both modes.
Its summary line is `Would import N entries from M source rows. Nothing was written.`

---

## The real run

```bash
php artisan sentinel:import --from=owenit --connection=legacy --size=1000
```

| Option | Default | Notes |
|---|---|---|
| `--from=` | none, required | `owenit` or `altek`. Anything else exits `2` and names the two it accepts. |
| `--table=` | `audits` (owenit), `ledgers` (altek) | Only if the source application moved it. |
| `--connection=` | the default connection | Where the source table lives. |
| `--actor=` | `user` | The prefix of the source's two actor columns — `audit.user.morph_prefix` in owen-it, `accountant.user.prefix` in altek. **Never detected.** Read it out of the old application's config. |
| `--size=` | `500` | Source rows per batch. Non-numeric falls back to `500`; clamped to at least `1`. |
| `--after=` | none | Skip every source row up to **and including** this key. Exclusive, and it is the **source's** `id`, not a Sentinel identifier. |
| `--dry-run` | off | Read and map everything, write nothing. |

### What the run does to your configuration

For the length of the run, in the importing process only:

- **`sentinel.mode` becomes `sync`.** Under `queue` the importer would push a job per batch and
  return having written nothing, so the report would be a lie and a resumed run would find no work
  done; under `buffered` it would fill a store nobody sized for a backfill. See
  [Performance modes](../09-operations/01-performance-modes.md).
- **`Pipeline\Stages\ResolveContext` is removed from `sentinel.pipeline`.** The context an imported
  entry needs travels on the row. Resolving it in a terminal would sign every historical action with
  the name of whoever ran the migration. See [Execution context](../04-context/01-execution-context.md).

Both are restored in a `finally`. Your web workers are untouched — this is one process's config
repository, not a shared setting. But a listener, policy or custom stage invoked *during* the import
reads `sync`, whatever the application is configured for.

**Every other stage runs whole.** That is what makes redaction, encryption, hashing, label resolution
and your own policies apply to imported data — and what makes `FilterUnchanged` and `EnforcePolicies`
able to discard it. See [The write pipeline](../05-pipeline-and-security/01-the-write-pipeline.md).

### Where to run it from

A plain terminal, or a deploy step. Not from inside `DB::transaction()`, not from inside a migration.

```php
use Illuminate\Support\Facades\Artisan;

$code = Artisan::call('sentinel:import', ['--from' => 'owenit', '--connection' => 'legacy', '--size' => '1000']);
$report = Artisan::output();   // the printed table; there is no programmatic Report object
```

`Import\Importer` and `Import\Report` are both `@internal`. `Artisan::output()` is the only way to
get the counters, and it is text.

---

## Reading the report

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
Imported 3 entries from 4 source rows. The chain starts here: what the other package recorded before this has no link, because it never had one.
The last source row read was 4. Pass --after=4 to carry on from there.
1 rows could not be read and 0 were refused by the pipeline, so they are not in the trail.
```

| Row | Means | What to do about it |
|---|---|---|
| **Read from the source** | Rows the forward scan returned | On a run with no `--after`, compare against `count(*)` on the source; anything less means the scan stopped early — see [When it stops halfway](#when-it-stops-halfway). |
| **Written as entries** | Rows that became entries in the ledger | In a **dry run**, an upper bound (see above). In a real run, exact. |
| **Already imported** | Identities the ledger said it already held | Expected to be non-zero on every re-run. Does **not** make the command exit `1`. |
| **Refused by the pipeline** | Mapped rows a stage or a policy discarded | Non-zero exits `1`. Carries **no reasons** — see below. |
| **Could not be read** | Rows the origin refused | Non-zero exits `1`. Grouped by reason, one line per group. |

**The four buckets account for every row read.** `Report::balances()` asserts
`read == written + repeated + discarded + unreadable`, and the command prints all five numbers so you
can do the same arithmetic by eye. Numbers that do not add up are a defect, not a finding.

### The three refusal reasons

Both origins refuse for exactly the same three reasons, in this order, and none of them is repairable
from Sentinel's side:

| Printed reason | The row |
|---|---|
| `the row has no key of its own to be identified by` | has a null or empty `id` — there is nothing to derive an identity from |
| `the row does not say when it happened, and an invented instant is worse than no entry` | has a null or unparseable `created_at` |
| `the row does not say what it is about` | has a null `auditable_type`/`recordable_type` or a null `auditable_id`/`recordable_id` |

Decide about every non-zero group **before** the real run. Each one is a fact about the old table.

### Pipeline discards carry no reasons

`Refused by the pipeline` is a bare count. The stage and the reason travel in the `AuditDiscarded`
event that `Pipeline::process()` emits per discard, and nothing joins the two. Listen for it while
importing if you want to know:

```php
use ElPandaPe\Sentinel\Events\AuditDiscarded;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

Event::listen(function (AuditDiscarded $discarded): void {
    Log::info('Import discard', [
        'stage' => $discarded->stage,
        'reason' => $discarded->reason,
        'message' => $discarded->message(),
        'subject' => $discarded->subjectType.':'.$discarded->subjectId,
        'event' => $discarded->event,
    ]);
});
```

The event carries identity only — no `before`, `after`, `changes` or `metadata` — because
`FilterUnchanged` runs before redaction and encryption, and an event carrying payload would be the
route by which plaintext escaped the pipeline. See
[Discarding entries](../05-pipeline-and-security/06-discarding-entries.md) and
[Events and listeners](../09-operations/04-events-and-listeners.md).

### Exit codes

| Code | Meaning here |
|---|---|
| `0` | Every row read became an entry, or already was one |
| `1` | The run **happened** and something did not come across — unreadable rows or pipeline discards. What did come across is written and chained. Repeated rows do not trigger it. |
| `2` | The run could not happen: unknown `--from`, no `--from` at all, an absent or mis-shaped table — **or any exception thrown mid-run**, in which case earlier batches are already committed |

> ⚠️ **Warning.** Exit `1` is not a rollback and not a failed run. Treating it as one — a deploy step
> that halts and retries from zero — costs a full re-read and changes nothing, because the entries
> that landed stay landed. See [Exit codes](../99-reference/07-exit-codes.md).

---

## When it stops halfway

A lost connection, a killed process, a `ConfigurationException` from an over-long label or stream
name: any `Throwable` from the run is caught by `ImportCommand` and printed as
`Nothing was imported: <reason>`, exit `2`.

> ⚠️ **Warning.** That message is wrong about a mid-run failure. **Batches settled before the failure
> are already committed.** The importer opens no transaction across the run, and each batch is
> committed by the ledger's own. A run that died at batch 500 of 2 000 has roughly a quarter of the
> trail on disk and told you nothing was imported.

The recovery is always the same: **run the same command again.**

```bash
# Correct, and needs no bookkeeping. Re-reading costs one SELECT per batch and one
# settled() lookup; nothing is re-hashed and nothing duplicates.
php artisan sentinel:import --from=owenit --connection=legacy --size=1000
```

On a source of tens of millions of rows, re-reading from zero is a full scan you may not want to pay.
The cursor is recoverable from the trail, because every imported entry carries the key of the row it
came from:

```php
use ElPandaPe\Sentinel\Enums\Source;
use ElPandaPe\Sentinel\Facades\Sentinel;

$last = Sentinel::audits()
    ->whereSource(Source::Import)
    ->latest()
    ->take(1)
    ->get()
    ->first();

$key = $last?->metadata['import']['row'] ?? null;  // the source row's own id
```

```bash
php artisan sentinel:import --from=owenit --connection=legacy --size=1000 --after=418923
```

`--after` is **exclusive**: the row you name is skipped. Two things to know before using a recovered
key rather than re-running from zero:

- It is the last row that **wrote an entry**, not the last row that was **read**. Rows after it in the
  interrupted batch that the origin could not read will never be reported. If the accounting matters
  more than the minutes, re-run without `--after`.
- It is the **source's** key. Pasting a `capture_id` or an audit `id` there silently re-reads or skips
  the whole table.

> 🐘 **Engine.** The cursor is bound as a string — `->orderBy('id')->where('id', '>', $after)` — so
> against a `bigIncrements` source key every engine casts and compares numerically, while against a
> string key (a source table re-keyed to UUID or ULID) the walk is lexicographic. Both resume
> correctly; the two orders are different, so do not hand a numeric `--after` to a string-keyed
> source. This is the only place a *source* engine difference shows.

---

## Verifying afterwards

```bash
php artisan sentinel:verify
php artisan sentinel:verify --stream=global   # where imported entries land under the default strategy
php artisan sentinel:show --subject="App\Models\Invoice:77"
```

Expect `sentinel:verify` to come back intact. A frozen test in the package asserts exactly this end
to end: entries written before the import keep their original hashes, imported entries each reproduce
their own, and the whole report is intact.

**What verification proves about an imported entry:**

- It reproduces its own hash over the 27 canonical columns.
- It links to the entry before it, and the entry after it links to it.
- Its sequence has no gap.
- Its signature verifies, if it was signed at write time.

**What verification cannot tell you, and never will:**

| It cannot say | Because |
|---|---|
| Whether the mapping was faithful to the source row | The hash is computed over what was written, not over what it was written from |
| Whether rows were missed | Nothing links the source table to the trail. That is what the report's arithmetic is for |
| Whether the source table itself had been tampered with | Nobody hashed those rows as they were written |
| Anything about the period before the import | Same reason. This is the honest limit of a migrated history, and it belongs in whatever you tell an auditor |

So the verification step answers *"is the trail intact since the import?"*. The **report** answers
*"did everything come across?"*. Neither answers the other's question. See
[Verification](../07-integrity/06-verification.md) and
[The verification playbook](../07-integrity/07-the-verification-playbook.md).

### Spot-checking the mapping

The only way to check fidelity is to read entries back and compare them with source rows by eye. Do
it on a handful of subjects, chosen to cover every event the source used:

```php
use ElPandaPe\Sentinel\Enums\Source;
use ElPandaPe\Sentinel\Facades\Sentinel;

// Order by the clock of the fact, never by sequence: imported entries sit at the tail
// of the chain, so sequence order and occurrence order disagree after an import.
$entries = Sentinel::audits()
    ->for('App\Models\Invoice', 77)
    ->whereSource(Source::Import)
    ->byOccurrence()
    ->take(50)
    ->get();

foreach ($entries as $entry) {
    $entry->metadata['import']['origin'];  // 'owenit' | 'altek'
    $entry->metadata['import']['row'];     // the source row's own key
    $entry->verifyIntegrity();             // true
}
```

`Reference::to()` takes that string verbatim, so type the subject exactly as the **source** wrote it
into `auditable_type` / `recordable_type` — `Invoice::class` only works while the class name has not
moved. See
[The Query API](../06-reading/01-the-query-api.md) and
[Order, paging and walking the trail](../06-reading/03-order-paging-and-walking.md).

> 📌 **Note.** A whole-record `restore()` on an imported entry is **refused**, with
> `Omission::EntryImported`, whatever the origin and whatever the event — including `owen-it`
> `created` and `deleted` rows that do carry complete snapshots. An imported `before`/`after` is not
> guaranteed to be a whole snapshot, and a rule that has to be reasoned about per event is one
> somebody will reason about wrongly. Naming the fields works:
> `$entry->restore(['status', 'total'])`. See [Restoring state](../06-reading/08-restoring-state.md).

---

## Rolling back

**The backup is the rollback.** There is no second plan, and this is the reason the dry run is the
documented first step.

| What you might reach for | Why it is not a rollback |
|---|---|
| `sentinel:prune` | Retires whole **anchored windows** released by a retention policy, per stream. It cannot be pointed at "everything one import wrote". It offers nothing at all on a stream with no anchors, and under `sentinel.compliance = true` it refuses to delete a range that has not been archived. |
| `sentinel:redact` | Empties **one entry's** contents and writes a new entry describing the destruction. The entry stays in the chain. It is evidence destruction with a tombstone, not removal. |
| `DELETE` in SQL | Leaves a sequence gap, which `sentinel:verify` will correctly report as a broken chain from then on. Never do this. |
| Re-importing "correctly" | The identity is derived from `(origin, source row key)`. Re-running writes nothing new. The wrong entries stay. |

Also note that retention will not quietly clean it up for you either: `Retention\RetainedPredicate`
measures age against `created_at`, and an imported entry's `created_at` is the instant of the
**import**, not of the fact. A ten-year-old fact imported today is one day old to retention. See
[Retention and pruning](../08-lifecycle/01-retention-and-pruning.md).

> 🐘 **Engine.** The same column explains a partitioning surprise: Sentinel's shipped range
> partitioning divides on `created_at`, so an entire migrated history lands in the **current** partition
> however old the facts are. Correct — `created_at` is the entry's own date — and startling if you
> partitioned expecting historical spread. See [Partitioning](../10-database-engines/06-partitioning.md).

So: snapshot before the run, keep the snapshot until you have verified and spot-checked, and only
then turn `sentinel.compliance` on. See [Compliance mode](../08-lifecycle/05-compliance-mode.md).

---

## Running old and new side by side

Most teams cannot cut over in one deploy. The usual shape is: install Sentinel, let both packages
record for a transition window, then remove the old one and import its history.

That works, with one thing to understand: **during the overlap, the same fact is recorded twice** —
once natively by Sentinel and once by the old package. When you later import, the old package's row
for that fact becomes a second entry. Nothing deduplicates across the two, because the native entry's
`capture_id` is a minted ULID and the imported one's is derived from the source row.

There is no `--before` option, so you cannot bound the import to "only rows older than the cutover".
Your choices are:

1. **Stop the old package writing at cutover, then import.** The overlap is empty and this section
   stops mattering. This is the recommended shape.
2. **Accept the duplicates**, and tell the two trails apart afterwards.

Telling them apart is straightforward, because `Source::Import` is the only `Source` case no resolver
ever produces — it is written by the importer and by nothing else. Everything Sentinel witnessed
itself carries a resolved source instead: `http`, `api`, `cli`, `queue`, `job`, `scheduler`,
`console` or `system`.

```php
use ElPandaPe\Sentinel\Enums\Source;
use ElPandaPe\Sentinel\Facades\Sentinel;

$migrated = Sentinel::audits()->whereSource(Source::Import)->byOccurrence()->take(500)->get();
$native = Sentinel::audits()->whereSource(Source::Http)->byOccurrence()->take(500)->get();
```

`whereSource()` is backed by `Filter::Source`, which `DatabaseLedger` declares as answerable; a ledger
that does not declare it throws `LedgerException` rather than answering approximately. See
[Enums](../99-reference/04-enums.md) and [The Ledger contract](../11-extending/01-the-ledger-contract.md).

> ⚠️ **Warning.** The overlap is visible to readers, not just to queries. A
> [timeline](../06-reading/05-the-timeline.md) or a field history over the transition window will
> show each change twice, once from each trail. If you accepted the duplicates, say so in whatever
> UI reads the trail, or filter by source there.

---

## A timeline for a live system

A shape that has the decisions in the right order. Adapt the durations; do not reorder the steps.

| # | Phase | Do | Gate before moving on |
|---|---|---|---|
| 1 | Read the source's config | `audit.table` / `accountant.table`, `audit.user.morph_prefix` / `accountant.user.prefix`, and for altek `accountant.contexts` and `accountant.ledger.threshold` | You know your `--table` and `--actor` values |
| 2 | Install Sentinel | `composer require`, `sentinel:install`, `migrate` | `sentinel_audits` and its companions exist ([Installation](../02-getting-started/01-installation.md)) |
| 3 | Decide the sealed settings | Severity map (including `forceDeleted` for altek), security field lists, stream strategy, signing | Written into `config/sentinel.php` and deployed |
| 4 | Dry run against production data | `--dry-run`, real `--table` and `--connection` | Every `Could not be read` group is understood and accepted |
| 5 | Rehearse the pipeline | Restore the destination backup to a scratch database, run a **real** import there | You know the true `Refused by the pipeline` count |
| 6 | Freeze the source | Stop the old package recording | The source table has stopped growing |
| 7 | Back up the destination | Snapshot immediately before the run | You can get back to this exact state |
| 8 | Real run | `--size` tuned to the row width; not inside a transaction; not inside a migration | Exit `0`, or exit `1` you have already accounted for |
| 9 | Reconcile | `read` from the report against `count(*)` on the source; check the four buckets add up | The numbers match |
| 10 | Verify | `sentinel:verify`, then `--stream=global` if you are multi-tenant | Intact |
| 11 | Spot-check | `sentinel:show --subject=…` across every event the source used | The mapping reads correctly |
| 12 | Remove the old package | Uninstall it; the Rector stubs in `stubs/rector/` are a dry-run starting point for renaming FQCNs, never a migration | The app boots and records natively |
| 13 | Turn compliance on, if you use it | `sentinel.compliance = true` | Only now — it makes step 7's snapshot the only way back |

Steps 4, 5 and 7 are the ones teams skip and the ones that cost the most when skipped.

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `Nothing was imported: …` after several minutes of running | Any `Throwable` mid-run is reported with the same message the pre-flight refusals use. Batches already settled **are committed** | Re-run the same command. Nothing duplicates. Do not restore the backup on this message alone |
| The dry run exits `0` with everything written; the real run writes fewer, reports pipeline refusals and exits `1` | The dry run never calls the pipeline, so `Refused by the pipeline` is structurally `0` and `Written as entries` is an upper bound | Rehearse a real run against a restored copy when the exact figure matters |
| Report says `written = 0` and everything is `Refused by the pipeline`, yet rows appear later | The command ran with a transaction open on the default connection and `sentinel.transactions.after_commit = true`, so `Dispatcher::dispatchMany()` deferred the write and returned an empty collection | Never run the import inside `DB::transaction()` or a migration |
| Every imported `occurred_at` is shifted by a fixed number of hours | `Row::instant()` calls `CarbonImmutable::parse()` with **no timezone**, so a naive source timestamp is read in the importing application's `app.timezone` | Check both timezones before the run. There is no `--timezone` option and `occurred_at` is inside the hash — the only correction is restoring the backup and re-importing |
| `$auditRedact` / `$auditEncrypt` did not apply to imported rows | The class named in the source's `auditable_type` no longer exists, so `PolicyRegistry` resolved an empty policy | Import before renaming models, add a morph map alias, or use the global `security.*.fields` lists |
| `whereEvent(AuditEvent::ForceDeleted)` matches no imported altek rows | altek writes `forceDeleted`; Sentinel's vocabulary is `force_deleted`, and the event string is carried verbatim into a plain `string(64)` column | Query `whereEvent('forceDeleted')`, and add that key to `severity.events` **before** importing |
| `The table [x] is not shaped like [owenit] history: it has no …` | `Shape::verify()` found a missing required column — often a wrong `--actor` prefix, which changes two of the required column names | Fix `--actor`/`--table`. Do not work around it: this refusal is the feature |
| Everything imports, but `before` is null on every altek entry | altek never recorded earlier values. `changes` is built with `oldKnown = false`, so there is no `old` key at all | Correct, not a bug. See [From altek/accountant](02-from-altek.md) |
| A run never finishes and `Read from the source` climbs forever | `walk()` advances with `$cursor = $row->text('id') ?? $cursor`. A batch where no row yields a key leaves the cursor unmoved and the same batch is read again | Only possible on a source whose `id` column is null or empty — `Shape` checks names, never nullability. Fix the source table |
| A migrated history reads out of order | An import appends to the tail, so `sequence` order and `occurred_at` order disagree by design | Read with `byOccurrence()`, always, after an import |
| `Already imported` is always `0` on re-runs, and re-runs are slow | The configured ledger does not implement `Contracts\Deduplicates` — `ArchiveLedger` deliberately does not | Import against a deduplicating ledger. For a fanout, the primary is the one that must answer |

---

## ✅ Best practices

✅ **Do** — dry-run against the real source table and connection, and read every `Could not be read`
line before the real run. The three refusal reasons are facts about the old table that no option fixes.

```bash
php artisan sentinel:import --from=owenit --table=audits --connection=legacy --actor=user --dry-run
```

❌ **Don't** — treat the dry run's `Refused by the pipeline: 0` as a prediction. It is the answer to a
question that was never asked, and the real run can refuse rows on `FilterUnchanged` alone.

```bash
# Not a rehearsal of the pipeline. Only of the source.
php artisan sentinel:import --from=owenit --dry-run && php artisan sentinel:import --from=owenit
```

---

✅ **Do** — re-run the same command after any interruption. The derived `capture_id` plus the unique
index make a repeated row cost one read and no hash.

```bash
php artisan sentinel:import --from=owenit --connection=legacy --size=1000
```

❌ **Don't** — restore the backup because the command printed `Nothing was imported`. On a mid-run
failure that message is false: earlier batches are committed, and re-running finishes the job.

```bash
# Throws away work that is already on disk, for no reason.
psql sentinel < backup.sql && php artisan sentinel:import --from=owenit
```

---

✅ **Do** — run the import from a plain console process, with nothing open around it.

```php
// A deploy step, a terminal, an artisan call at the top level. Nothing wrapping it.
Artisan::call('sentinel:import', ['--from' => 'owenit', '--connection' => 'legacy']);
```

❌ **Don't** — run it inside a transaction or a migration. With `sentinel.transactions.after_commit = true` the
batch is deferred to the commit, `recordMany()` returns an empty collection, and the report claims
zero entries were written while attributing every mapped row to the pipeline.

```php
use Illuminate\Support\Facades\DB;

DB::transaction(function (): void {
    Artisan::call('sentinel:import', ['--from' => 'owenit']);  // report will be a lie
});
```

---

✅ **Do** — settle the severity map, the security field lists and the signing key **before** the first
row, because all of them are sealed into the entry and most sit inside the hashed payload.

```php
// config/sentinel.php — before the run, for an altek source
'severity' => [
    'default' => 'info',
    'events' => [
        'deleted' => 'notice',
        'force_deleted' => 'warning',
        'forceDeleted' => 'warning',  // the string altek actually writes
    ],
],
```

❌ **Don't** — plan to "fix the severities afterwards with an UPDATE". `severity` is one of the 27
canonical columns; changing it in place makes the entry stop reproducing its own hash, and
`sentinel:verify` will report that, correctly, as tampering.

```sql
-- Breaks the chain. There is no way back from this but the backup.
UPDATE sentinel_audits SET severity = 'warning' WHERE event = 'forceDeleted';
```

---

✅ **Do** — read a migrated history by the clock of the fact, and isolate what the import wrote when
you need to.

```php
use ElPandaPe\Sentinel\Enums\Source;
use ElPandaPe\Sentinel\Facades\Sentinel;

Sentinel::audits()->whereSource(Source::Import)->byOccurrence()->take(200)->get();
```

❌ **Don't** — order a migrated history by sequence and present it as a timeline. An import appends to
the tail, so the oldest facts carry the newest sequence numbers.

```php
// Ten-year-old facts appear as the most recent thing that happened.
Sentinel::audits()->for($invoice)->latest()->take(200)->get();
```

---

✅ **Do** — state the honest boundary when you hand a migrated trail to an auditor: provable from the
import forward, carried over faithfully but unprovable before it.

```bash
php artisan sentinel:verify   # answers "intact since the import", and only that
```

❌ **Don't** — describe an imported period as proven, or copy a foreign digest into
`sentinel_audits.signature` to make it look signed. altek's `signature` is a per-row digest over the
row's own attributes and chains nothing; it is kept as data at `metadata.import.signature`.

```php
// Would make sentinel:verify report an uncheckable signature as a checkable one.
$audit->signature = $row->signature;
```

---

**See also:** [From owen-it/laravel-auditing](01-from-owen-it.md) ·
[From altek/accountant](02-from-altek.md) ·
[Artisan commands](../09-operations/06-artisan-commands.md) ·
[Verification](../07-integrity/06-verification.md) ·
[The hash chain](../07-integrity/01-the-hash-chain.md) ·
[The write pipeline](../05-pipeline-and-security/01-the-write-pipeline.md) ·
[Restoring state](../06-reading/08-restoring-state.md) ·
[Retention and pruning](../08-lifecycle/01-retention-and-pruning.md) ·
[Exit codes](../99-reference/07-exit-codes.md) ·
[Configuration](../99-reference/02-configuration.md)
