# ♻️ Rehydration

> Bringing an archived range back into the hot table exactly as it left — sequences, hashes, links, labels and operation headers included.

**On this page:** [No command](#there-is-no-artisan-command-for-this) · [The call](#the-call) · [What the range selects](#what-the-range-argument-actually-selects) · [What it checks](#what-it-checks-before-it-writes-anything) · [The order of a restore](#the-order-of-a-restore) · [Why `append()`](#why-append-and-not-write) · [Interruptions](#idempotent-not-atomic-and-single-writer) · [The manifest row](#why-the-manifest-row-stays) · [Verifying](#verifying-after-a-rehydration) · [When to reach for it](#when-rehydration-is-the-right-answer) · [What it cannot restore](#what-rehydration-cannot-restore) · [Pitfalls](#️-pitfalls) · [Best practices](#-best-practices)

---

Cold archiving writes an anchor window out as NDJSON, proves the file, and only then removes the
rows — see [Cold archiving](02-cold-archiving.md). Rehydration is the other direction: it reads one
of those batches back and re-inserts every entry into `sentinel_audits` under the sequence, hash and
link it left with.

It is not an undo. Nothing about a rehydration removes the file from the disk, removes the manifest
row, or stops the next scheduled prune from taking the same range straight back out.

## There is no artisan command for this

Sentinel ships no `sentinel:rehydrate`. Rehydration is a container call and nothing else:

```php
use ElPandaPe\Sentinel\Archive\Rehydrator;

$done = app(Rehydrator::class)->restore('tenant:acme', 5001, 6000);
```

That is deliberate rather than an omission — a restore is a single-writer operation with no
progress reporting and no dry run, and putting it behind a schedulable command would invite both.
Wrap it in your own command when an operator needs to run it.

> 📌 **Note.** `Archive\Rehydrator` and `Archive\Rehydration` are part of the frozen public surface
> (`tests/SurfaceTest.php` lists them among the declarations a reader may reach). Everything else
> under `ElPandaPe\Sentinel\Archive` — `BatchReader`, `BatchWriter`, `Manifest`, `Line`, `Batch`,
> `BatchPath`, `ArchiveBatch`, `Claim` — is marked `@internal` and may change in a minor. See
> [API stability](../99-reference/09-api-stability.md).

Do not confuse this with `php artisan sentinel:import`, which reads a *foreign* trail out of
`owen-it/laravel-auditing` or `altek/accountant` and writes fresh Sentinel entries with fresh
hashes. Rehydration reads Sentinel's own bytes and re-inserts the original entries verbatim. The two
share no code. See [The import runbook](../12-migrating/03-the-import-runbook.md).

## The call

`ElPandaPe\Sentinel\Archive\Rehydrator::restore(string $stream, int $from, int $to): Rehydration`

```php
use ElPandaPe\Sentinel\Archive\Rehydration;
use ElPandaPe\Sentinel\Archive\Rehydrator;

/** @var Rehydration $done */
$done = app(Rehydrator::class)->restore('tenant:acme', 5001, 6000);
```

`Rehydration` is a readonly value object with four public counters and a `plus()` that folds two of
them together:

| Property | Counts |
|---|---|
| `restored` | Entries this pass inserted into `sentinel_audits`. |
| `skipped` | Entries the pass found already present and left alone. |
| `operations` | Operation header rows the ignore-on-conflict insert actually created. A header already in `sentinel_transactions` counts zero, so this is not "headers in the batch". |
| `batches` | Files read. `0` means no batch covered the range — see below. |

The counters are kept apart on purpose: a second pass over the same range reports
`restored: 0, skipped: 4`, and one total could not tell that from "there was nothing to do".

```php
$done->restored;   // 4
$done->skipped;    // 0
$done->operations; // 1
$done->batches;    // 1
```

## What the range argument actually selects

`$from` and `$to` select **batches**, not entries. `Archive\Manifest::batchesIn()` returns every
manifest row whose range overlaps `[$from, $to]`, ordered by where it starts, and
`Rehydrator::batch()` then restores each of those files **whole**.

Ask for sequences 5001–5002 of a batch covering 5001–6000 and you get all thousand entries back.
There is no clipping and no partial read of a file.

> ⚠️ **Warning.** `restore()` returning `batches: 0` is a **silent no-op, not an error**. Two
> different situations produce it: no manifest row overlaps the range at all, or the row that does
> is a range retired with `--action=delete`, whose `disk`, `path`, `checksum` and `compressed`
> columns are all null. `Manifest::batchOf()` returns null for such a row and `batchesIn()` skips
> it. Nothing throws, because a row with no cold columns is a truthful record that the entries were
> removed and written nowhere. Always check `$done->batches` before reporting success.

Find out which it was by reading the manifest yourself:

```php
use ElPandaPe\Sentinel\Models\AuditArchive;

$row = AuditArchive::query()
    ->where('stream', 'tenant:acme')
    ->where('sequence_from', '<=', 5200)
    ->where('sequence_to', '>=', 5200)
    ->first();

match (true) {
    $row === null       => 'Nothing accounts for this sequence at all.',
    $row->disk === null => 'Retired without a copy. There is nothing to bring back.',
    default             => "Batch on [{$row->disk}] at [{$row->path}], {$row->records} entries.",
};
```

## What it checks before it writes anything

The archive refuses a batch on the way back the same way it refuses one on the way out. Every check
below happens inside `Archive\BatchReader` or `Rehydrator` and every one of them throws
`ElPandaPe\Sentinel\Exceptions\ArchiveException`:

| Check | What it catches | Refusal |
|---|---|---|
| The disk returns bytes | A file deleted, a lifecycle rule that expired it, a bucket the app can no longer read | `ArchiveException::unreadable` |
| The bytes digest to `sentinel_archives.checksum` | The file changed since it was written | `ArchiveException::corrupt` |
| The header line's `format` equals this build's `Line::FORMAT` | A batch written by a newer container format | `ArchiveException::unknownFormat` |
| Every operation line names all nine header columns | A truncated or hand-edited operation line | `ArchiveException::incompleteOperation` |
| Entry sequences run contiguously from `sequence_from` | A line removed from the middle of the file | `ArchiveException::discontiguous` |
| The entry count equals the manifest's `records` | A manifest row that no longer describes the file it points at | `ArchiveException::miscounted` |
| No entry's sequence is held by a **different** hash | A second chain already occupying those positions | `ArchiveException::occupied` |
| Every entry reproduces the hash it is entitled to | An entry altered inside the batch | `ArchiveException::unverifiable` |

Two of those need spelling out.

**The digest and the count are different questions.** A checksum over the bytes cannot see a
manifest row that has drifted away from the file it names, which is exactly what the count and the
contiguity check are for.

**"The hash it is entitled to reproduce"** is not always the original one.
`Integrity\Content::holds()` returns true for `ContentState::Sealed` *and* for
`ContentState::Redacted` — a tombstone reproduces its `redacted_hash`, never its `hash`, and asking
only for the first would make every redacted range permanently unrestorable. See
[Redaction and tombstones](04-redaction-and-tombstones.md).

> ⚠️ **Warning.** `ArchiveException::unreadable`'s message reads *"could not be read back after
> being written"*. The same factory serves the write path and the read path, so on a rehydration
> years later that phrasing is misleading — what it means here is simply that the disk did not hand
> the file over.

> 🐘 **Engine.** `sentinel_archives.sequence_from` and `sequence_to` are `bigint`. PDO returns them
> as strings on MySQL and PostgreSQL and as ints on SQLite, so `Models\AuditArchive` casts both to
> integer. The arithmetic that decides which batch covers a sequence has to mean the same thing on
> all three engines.

## The order of a restore

For each batch, in this order:

1. **Read the operation headers** — `BatchReader::operations()` fetches the file, verifies the
   checksum and the format, and rebuilds one `AuditTransaction` per operation line.
2. **Insert the headers** with `insertOrIgnore`. Several batches of one long-running operation carry
   the same header, and its identifier is its table's primary key.
3. **Read the entries** — `BatchReader::read()` fetches the file *again*, verifies contiguity and
   the count.
4. **Ask the hot table what it already holds** — one query for the hashes at each sequence of the
   range, one for the capture identifiers this batch carries.
5. **Insert each missing entry** through `Ledger\DatabaseLedger::append()`, one transaction per
   entry.

Headers go back *before* entries, and that ordering is load-bearing. `append()` commits per entry,
so a pass that started with the entries and died would leave a range that the next pass reads as
already restored — and therefore permanently detached from the operation it belonged to. See
[Business transactions](../03-capture/06-business-transactions.md).

> 📌 **Note.** Step 1 and step 3 each fetch the whole object and digest it. A batch is therefore
> read from the disk **twice** per restore. On a local disk that is free; on S3 or R2 it is two GETs
> and two full-body SHA-256 passes for every batch you bring back.

Rehydration always writes through `DatabaseLedger` by name and never through the configured
`ledger.default`. Under the hot-plus-cold fanout composition, writing through the resolved ledger
would hand every entry to the cold destination, which would write a fresh batch at the same
deterministic key — a batch path is a pure function of `(root, stream, range, codec)` — overwriting
the very file being read, and without its operation lines. There is a test pinning that the file on
disk is byte-identical after a restore with `archive` configured as a fanout destination. See
[The shipped drivers](../11-extending/02-shipped-drivers.md).

## Why `append()` and not `write()`

`DatabaseLedger::write()` is the write path for a *new* entry: it takes the stream's tail, assigns
the next `sequence`, links `previous_hash`, seals a `hash`, and derives a fresh `version` from
`max(version)` for the subject.

`append()` does none of that. It inserts the row it was handed, verbatim, inside one transaction —
plus the label rows and the relation projection. Everything the entry arrived with survives:

| Column | After a rehydration |
|---|---|
| `sequence` | The original. Nothing is renumbered. |
| `hash`, `previous_hash`, `signature`, `signature_key_id` | The originals. The chain reads through the restored range unbroken. |
| `payload_version`, `algorithm` | The originals — a batch written under an older payload version comes back under it. |
| `created_at`, `occurred_at` | The originals, to the microsecond. |
| `version` | **The original.** This is the counter `write()` would have left behind. |
| `capture_id` | The original, which is what makes the skip test possible. |
| `redacted_at`, `redaction_reason`, `redacted_hash` | The originals — a tombstone comes back a tombstone. |
| `encryption` | The original `{fields, key_id}`. The ciphertext is unchanged. |

Labels travel in the batch line (`tags`) and are re-inserted. Relation lines do **not** travel: they
are re-derived from the entry's own `changes` by `append()`, because they are a projection of
something inside the canonical payload rather than a second copy of it. See
[Labels](../06-reading/06-labels.md) and [Relationship auditing](../03-capture/04-relationships.md).

### The cost of keeping `version`

`version` sits inside `Integrity\CanonicalPayload::COLUMNS`. Renumbering it would stop the entry
reproducing its own hash, break the next entry's link, and undo the fold its anchor recorded. So it
comes back as it was — and a subject whose whole history was pruned and then written to again ends
up with **two entries claiming version 1**:

```php
use App\Models\Patient;
use ElPandaPe\Sentinel\Archive\Rehydrator;
use ElPandaPe\Sentinel\Facades\Sentinel;

// Sequences 5..8 were this patient's versions 1..4. They were archived and pruned.
// The next audited write started counting again, because max(version) was gone:
$patient->update(['ward' => 'B3']);   // that entry carries version 1

app(Rehydrator::class)->restore('global', 5, 8);

Sentinel::audits()->for($patient)->get()->pluck('version')->all();
// [1, 2, 3, 4, 1]
```

Two consequences you have to design around:

- `AuditQuery::whereVersion()` may legitimately return several entries for one number.
- `AuditQuery::compare(int $from, int $to)` resolves a repeated number **to the newest entry
  carrying it** — it applies `latest()`, which orders by `created_at`. A restored entry carries its
  original, older `created_at`, so `compare()` will pair the post-prune era, not the archived one.

See [Field history and comparing versions](../06-reading/04-field-history.md).

> 📌 **Note.** A further write after the restore continues from the highest number the table now
> holds: with `[1, 2, 3, 4, 1]` present, the next entry for that patient is version 5.

## Idempotent, not atomic, and single-writer

Three separate properties. Getting one of them confused for another is how a restore goes wrong.

**Idempotent.** Running the same restore twice is safe. For each entry, `Rehydrator::alreadyThere()`
asks two questions: does something already hold this sequence, and is this entry's `capture_id`
already in the table? A sequence held by the **same** hash is skipped. A sequence held by a
**different** hash throws `ArchiveException::occupied` — two entries cannot occupy one position of a
chain. A capture identifier already present is skipped even when the sequence looks free, which is
what stops a capture that was archived, pruned and then replayed from colliding on the unique index.

**Not atomic.** The `Ledger` contract exposes no transaction, and `append()` commits its own per
entry. There is no rollback across a batch. An interrupted run leaves a prefix.

**Single-writer.** The "is it already there?" check happens *before* the write and not inside it.
Two passes over the same range at the same time can still collide on the unique index. Do not run
two restores of one range concurrently, and do not run one while a `sentinel:prune` is walking the
same stream.

### What an interruption leaves, and how to resume

| Interrupted at | What is on disk / in the database |
|---|---|
| Before the headers went in | Nothing changed. |
| After the headers, before any entry | Header rows present, pointing at no entries. Harmless: the prune only removes a header once nothing anywhere carries its identifier. |
| Mid-batch | A prefix of the batch's entries, all with their original hashes. |
| Between batches | Earlier batches complete, later ones untouched. |

Resume by calling `restore()` again with the same arguments. Nothing else is needed — the second
pass skips what is there and finishes the rest:

```php
$first  = app(Rehydrator::class)->restore('tenant:acme', 5001, 6000);
// ... process dies ...
$second = app(Rehydrator::class)->restore('tenant:acme', 5001, 6000);

$second->restored + $second->skipped === $first->restored + $first->skipped; // true
```

If the headers were deleted between passes, the second pass puts them back too — it re-reads the
operation lines every time and re-issues the ignore-on-conflict insert.

## Why the manifest row stays

A rehydration never touches `sentinel_archives`. That looks like sloppiness and is not:

- **The row is the only place `disk`, `path`, `checksum` and `compressed` exist.** Not even the
  file's own header line carries them — `Archive\Line::header()` writes `kind`, `format`, `stream`,
  `sequence_from`, `sequence_to`, `records` and `written_at`, and nothing else. Delete the row and
  the batch becomes an object nobody can find, verify or inflate.
- **The prune reads it as licence.** `Retention\Pruner::tampered()` will excuse a window whose root
  cannot be recomputed *at all* only when the manifest holds that range. Removing the row turns the
  next prune of that window into a `CheckpointMismatch` stop.
- **The verifier reads it too**, and only ever about an absence — see below.
- **A re-archive updates it in place.** `Manifest::write()` looks for the row already describing
  exactly this `(stream, sequence_from, sequence_to)` and reuses it, so the round trip
  restore → redact → prune leaves one row for the range, not two. Two rows would be two answers to
  `batchesIn()` with no tiebreak, and the next restore would read the range out of both files.

> ⚠️ **Warning.** The manifest row keeps covering the whole range even while only part of it is
> back. A half-finished restore therefore still verifies as intact — the walk steps over the
> sequences that are still missing, because the manifest explains them and the anchors reach past
> them. The `(+N retired)` count in `sentinel:verify` is the only thing that says work is
> outstanding.

## Verifying after a rehydration

`sentinel:verify` crosses an absence only when **two** things account for it at once: the manifest
says the range was retired, and the anchors reach past it. Nothing in `sentinel_archives` is hashed
or signed, so on its own it would make "delete the rows, then insert one row" a supported way of
laundering a gap. Once a range is back, there is no absence and the walk simply reads it.

```bash
# Just the range you brought back — the seam is checked, because the walk reads
# the entry before $from to get the hash it must link to.
php artisan sentinel:verify --stream=tenant:acme --from=5001 --to=6000

# The whole stream. After a complete restore the "(+N retired)" note is gone for
# this range and N entries have moved from "stepped over" to "read".
php artisan sentinel:verify --stream=tenant:acme
```

From application code:

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

$result = Sentinel::verifyIntegrity('tenant:acme', 5001, 6000);

$result->isIntact();  // true — every entry rehashed and every link followed
$result->checked;     // 1000
$result->archived;    // 0 — nothing was stepped over
```

The tests pin the strong form of this: a range that goes out through the prune and comes back
through the rehydrator reproduces every hash it was frozen with, folds to the same anchor roots, and
verifies at every depth (`tests/Archive/RoundTripTest.php`). See
[Verification](../07-integrity/06-verification.md) and
[Checkpoints and anchors](../07-integrity/05-checkpoints-and-anchors.md).

> 🧪 **Verify it.** Compare the archived object against its manifest row before you trust it:
> download the file and run `sha256sum` on it, then compare with the hex after the colon in
> `sentinel_archives.checksum`. The digest is over the bytes as written, compression included.

## When rehydration is the right answer

**Reach for it when:**

- An auditor, a regulator or a court asks for a retired range and wants it queryable rather than as
  a file. Bring it back, answer the question, and let the schedule take it out again.
- An erasure request lands on content inside an already-archived range. `Redaction\Redactor` refuses
  an entry that is not in the hot table, naming the disk and path holding it. The supported answer
  is the round trip: restore, redact, re-prune. See
  [Redaction and tombstones](04-redaction-and-tombstones.md).
- You are moving to `--action=delete` under compliance mode and a window has no batch. Compliance
  mode refuses to delete a range with no archive row; a restore plus an `--action=archive` pass
  gives it one.

**Do not reach for it when:**

- Somebody wants to read one old entry. Read the batch file — it is NDJSON, one JSON object per
  line, and `grep` answers a single-entry question without touching the hot table.
- A dashboard or a report wants "all history". Cold is where retired ranges are supposed to live. A
  restore puts a thousand rows back into the table the retention policy exists to keep small, and
  the next scheduled prune has to do the work of removing them all over again.
- You want to undo a prune you regret. Nothing about a restore is permanent — see the pitfall below.

## What rehydration cannot restore

| Not restored | Why |
|---|---|
| A range retired with `--action=delete` | Nothing was ever written. The manifest row has null cold columns; `batches` comes back `0` and nothing throws. |
| A batch whose file is gone | The disk is the operator's. Sentinel never deletes from the archive disk — its only two operations there are `get` and `put` — so an expired lifecycle rule or a manual delete is unrecoverable. `ArchiveException::unreadable`. |
| Redacted content | The batch carries the tombstone, not what the tombstone destroyed. A redacted entry comes back redacted, with `verifyContent()` answering `ContentState::Redacted`. |
| Values under a retired encryption key | The ciphertext and the original `key_id` come back untouched. If that key left `security.encryption.keys`, the values stay unreadable. See [Encryption and the keyring](../05-pipeline-and-security/03-encryption-and-the-keyring.md). |
| `sentinel_access_log` rows | Only the chained `access` *entries* are in the batch — they are ordinary audit entries. The searchable projection rows are not, and nothing here writes them. See [Compliance mode](05-compliance-mode.md). |
| Anchors | `append()` never anchors, whatever `integrity.checkpoints.enabled` says. It does not need to: the prune does not remove `sentinel_checkpoints` rows, so the anchors covering the range were never gone. |
| Events | No `AuditCreated` and no `Audited` fires. The first comes from `Dispatch\Settlement` and the second from `Dispatch\Dispatcher`, both on the capture path, which a restore does not go through. See [Events and listeners](../09-operations/04-events-and-listeners.md). |
| Your business rows | Sentinel restores audit entries, not the models they describe. Putting a subject's state back is a different feature — see [Restoring state](../06-reading/08-restoring-state.md). |

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `restore()` returns all zeros and throws nothing | No manifest row overlaps the range, or the only row covering it was retired with `--action=delete` and has null `disk`/`path`/`checksum` | Check `$done->batches` before reporting success, then read the `AuditArchive` row to see which of the two it was |
| You asked for 20 sequences and got 1000 back | `$from`/`$to` select whole batches, not entries; `Manifest::batchesIn()` returns every overlapping file and each is restored entire | Expected. Size your expectations by `integrity.checkpoints.every`, which is the window a prune writes per file |
| The range is gone again by morning | A restored entry keeps its original `created_at`, which is the retention clock, so the very next `sentinel:prune` releases the window again and re-archives it | Do the work between scheduled runs, or suspend the prune schedule. There is no flag that pins a range |
| `Sequence N of stream X is already held by an entry with a different hash` | Something else has written into those positions since the prune — usually a second chain started under the same stream name | Do not force it. Find out what wrote those entries before deciding which chain is the real one |
| `The entry at sequence N does not reproduce its own hash when read back` | The batch's bytes still digest correctly but that entry's content does not rehash to its sealed `hash` (nor to its `redacted_hash`) | The file has been edited past its own checksum, or the row was tampered with before it was archived. Nothing was inserted; investigate rather than retry |
| `The archive batch at [...] declares container format N and this build reads 1` | The file was written by a newer version of the package | Restore with a build that reads that format. A file it cannot read is a refusal, not something to parse and hope |
| `whereVersion(1)` returns two entries for one subject | A rehydrated range brought its original `version` numbers back, and a write after the prune had already claimed 1 | Expected and unfixable — `version` is inside the canonical payload. Disambiguate by `sequence` or `created_at` |
| `operations` is `0` on a second pass that clearly restored entries | It counts rows the ignore-on-conflict insert actually created, and the headers were already present | Not an error. Check `sentinel_transactions` directly if you need to confirm a header is there |
| The restore is slow against S3 | Each batch is fetched and digested twice — once for the operation lines, once for the entries | Expected. Restore the narrowest range that answers the question |
| Two restores at once fail on a unique index | The already-present check runs before the write, not inside it; rehydration is single-writer | Serialise them. One restore of one range at a time |

## ✅ Best practices

✅ **Do** — check `batches` before you tell anyone the range is back. It is the only counter that
separates "nothing needed doing" from "there was nothing there to do".

```php
$done = app(Rehydrator::class)->restore('tenant:acme', 5001, 6000);

if ($done->batches === 0) {
    throw new RuntimeException('No archive batch covers 5001-6000 of tenant:acme.');
}
```

❌ **Don't** — treat a zero-valued `Rehydration` as success. A range retired with `--action=delete`
produces exactly the same object as a completed restore of an already-present range, and no
exception at all.

```php
app(Rehydrator::class)->restore('tenant:acme', 5001, 6000);

$auditor->notify('Your range is available.'); // it may not be, and nothing said so
```

---

✅ **Do** — verify the range you brought back, bounded to it, before handing it to anyone. The walk
reads the entry before `$from` to get the hash the first restored entry must link to, so the seam is
checked rather than assumed.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

$result = Sentinel::verifyIntegrity('tenant:acme', 5001, 6000);

if (! $result->isIntact()) {
    throw new RuntimeException(
        "Restored range broken at sequence {$result->sequence}: {$result->reason?->value}",
    );
}
```

❌ **Don't** — rely on `sentinel:verify` alone to tell you the restore finished. The manifest row
still covers the whole range, so a half-restored range verifies as intact with the missing entries
counted as retired.

```bash
php artisan sentinel:verify --stream=tenant:acme   # says "intact" either way
```

---

✅ **Do** — restore, redact and re-archive in one sitting when an erasure request reaches archived
content. The batch holds a tombstone perfectly well, `Manifest::write()` reuses the same row, and
the same object key is rewritten.

```php
use ElPandaPe\Sentinel\Archive\Rehydrator;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Redaction\Redactor;
use ElPandaPe\Sentinel\Support\Reference;

app(Rehydrator::class)->restore('tenant:acme', 5001, 6000);

Audit::query()
    ->where('stream', 'tenant:acme')
    ->whereBetween('sequence', [5001, 6000])
    ->where('subject_type', 'patient')
    ->where('subject_id', '77')
    ->cursor()
    ->each(fn (Audit $entry) => app(Redactor::class)->redact($entry, 'Erasure request', Reference::to($officer)));

// then: php artisan sentinel:prune --action=archive --stream=tenant:acme
```

❌ **Don't** — restore a range and leave it sitting in the hot table with the prune schedule
running. The restored entries carry their original `created_at`, so the next run releases the same
window and writes it straight back out — with whatever state it is in at that moment.

```php
app(Rehydrator::class)->restore('tenant:acme', 5001, 6000);
// nightly sentinel:prune runs at 03:10 and takes it away again, mid-redaction
```

---

✅ **Do** — serialise restores. One range, one process, no concurrent prune on the same stream.

```php
use ElPandaPe\Sentinel\Archive\Rehydrator;
use Illuminate\Support\Facades\Cache;

Cache::lock('sentinel:rehydrate:tenant:acme', 600)->block(30, function (): void {
    app(Rehydrator::class)->restore('tenant:acme', 5001, 6000);
});
```

❌ **Don't** — fan a restore out across queue workers by splitting the range. Each worker will pull
whole overlapping batches, the already-present check runs before the insert, and the collisions land
on the chain's unique index.

```php
foreach (array_chunk(range(5001, 6000), 100) as $slice) {
    RestoreChunk::dispatch('tenant:acme', $slice[0], end($slice)); // all reading the same file
}
```

---

✅ **Do** — leave `sentinel_archives` alone. A rehydration deliberately does not touch it, and the
row is the only record of where the bytes are, what they digest to, and which codec inflates them.

```php
// Read it. Never delete it.
AuditArchive::query()->where('stream', 'tenant:acme')->orderBy('sequence_from')->get();
```

❌ **Don't** — "clean up" the manifest row after a successful restore. You lose the batch's address
and checksum, and the next prune of that window can no longer be excused when its root cannot be
recomputed.

```php
AuditArchive::query()->where('sequence_from', 5001)->delete(); // irreversible
```

---

✅ **Do** — restore the narrowest range that answers the question, and let it go cold again
afterwards. A batch is fetched and digested twice per restore, and every entry you bring back is
work the next prune has to redo.

❌ **Don't** — use rehydration as a query engine. To read one entry out of cold storage, read the
batch file — one JSON object per line, keyed by the entry's own columns — instead of putting a
thousand rows back into the hot table. With the default `codec => 'gzip'` the object ends in
`.ndjson.gz`, so inflate it first.

```bash
zcat 00000000000000005001-00000000000000006000.ndjson.gz \
  | jq -c 'select(.kind == "entry" and .sequence == 5137)'
```

---

**See also:** [Cold archiving](02-cold-archiving.md) · [Retention and pruning](01-retention-and-pruning.md) · [Redaction and tombstones](04-redaction-and-tombstones.md) · [Compliance mode](05-compliance-mode.md) · [Verification](../07-integrity/06-verification.md) · [Checkpoints and anchors](../07-integrity/05-checkpoints-and-anchors.md) · [The hash chain](../07-integrity/01-the-hash-chain.md) · [Field history and comparing versions](../06-reading/04-field-history.md) · [The shipped drivers](../11-extending/02-shipped-drivers.md) · [Exceptions](../99-reference/06-exceptions.md) · [Schema](../99-reference/03-schema.md) · [Configuration](../99-reference/02-configuration.md)
