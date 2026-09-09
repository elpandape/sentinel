# 🔐 The verification playbook

> The operational runbook: what to run and how often, how to read a run, and — when one comes back
> broken — one triage block per reason code, ending in what you can honestly tell an auditor.

**On this page:** [The cadence](#the-cadence) · [Reading a run](#reading-a-run) · [Before you touch anything](#before-you-touch-anything) · [`hash_mismatch`](#hash_mismatch) · [`link_mismatch`](#link_mismatch) · [`sequence_gap` — a sequence that is not there](#sequence_gap--a-sequence-that-is-not-there) · [`sequence_gap` — a sequence that is not the one expected](#sequence_gap--a-sequence-that-is-not-the-one-expected) · [`signature_mismatch`](#signature_mismatch) · [`checkpoint_mismatch`](#checkpoint_mismatch) · [`projection_mismatch`](#projection_mismatch) · [A pruned gap needs two witnesses](#a-pruned-gap-needs-two-witnesses) · [What a tombstone does to verification](#what-a-tombstone-does-to-verification) · [When verification fails in CI](#when-verification-fails-in-ci) · [Telling an auditor](#telling-an-auditor) · [⚠️ Pitfalls](#️-pitfalls) · [✅ Best practices](#-best-practices)

---

## The cadence

Sentinel registers commands and never schedules itself. Everything below is something your
application writes in `routes/console.php`.

| Cadence | Command | What it answers | What it does **not** answer |
|---|---|---|---|
| Hourly | `sentinel:checkpoint` | Nothing — it *emits* anchors. Without it the shallow depths have nothing to read and `sentinel:prune` releases nothing | It is not a verification and never reports one |
| Daily | `sentinel:verify --depth=anchors` | Are the anchors a contiguous, signed chain from sequence 1, and does the unanchored tail link? | Nothing about the content of an anchored range. It opens no anchored entry |
| Weekly | `sentinel:verify --depth=roots` | The same, plus: does each range still fold to the root its anchor recorded, from the hashes the rows carry now? | A canonical column edited while the `hash` column was left alone. The fold reads `hash`, not content |
| Monthly, and before an audit | `sentinel:verify` (`--depth=entries`) | Does every entry still reproduce its own hash, link to the one before it, and carry a signature its own key verifies? | Whether an entry is *true*. It proves nobody touched the row after it was written |
| Before an audit, when relation queries are evidence | `sentinel:verify --projections` | Does `sentinel_audit_relations` still say what the entries sealed? | Nothing extra about the chain; the projection is checked **after** the chain, never instead of it |

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

// Anchor first: retention's unit is the anchored window, and the shallow depths read anchors.
Schedule::command('sentinel:checkpoint')->hourly();

Schedule::command('sentinel:verify --depth=anchors')->dailyAt('04:00');
Schedule::command('sentinel:verify --depth=roots')->weeklyOn(7, '04:20');
Schedule::command('sentinel:verify')->monthlyOn(1, '05:00');
```

> ⚠️ **Warning.** Run the *first* `sentinel:checkpoint` by hand, off the schedule. On a trail that
> predates anchoring it anchors every complete window each stream owes, in one pass, with no
> `--limit` — which is one read of the whole trail. See
> [Checkpoints and anchors](05-checkpoints-and-anchors.md).

### Before an audit

1. `php artisan sentinel:checkpoint` — so the anchors reach as far as the entries do.
2. `php artisan sentinel:verify --depth=entries` — the only depth that rehashes.
3. `php artisan sentinel:verify --projections` if relation queries are part of what you are handing over.
4. `php artisan sentinel:export --format=ndjson --disk=… --path=…` — the body and the
   `<path>.manifest.json` beside it. See [Export and rekey](../08-lifecycle/06-export-and-rekey.md).
5. `php artisan about` — version, mode, ledger driver, `payload_version`, compliance and telemetry.
   It carries no key, no key identifier and no signer configuration, so it is safe to paste anywhere.

---

## Reading a run

Three exit codes, one meaning each, and a watchdog must branch on all three:

| Code | Meaning | What a cron should do |
|---|---|---|
| `0` | The run happened and nothing came back wrong — **including** a wholly unsigned trail, and one whose only finding is declared redactions | Nothing |
| `1` | The run happened and found a real defect: a hash that does not reproduce, a broken link, an unaccounted gap, a forged signature, an anchor that no longer folds, or a divergent relation index under `--projections` | Page a human. Do not retry |
| `2` | The run could not happen: `--from`/`--to` without `--stream`, an unknown `--depth`, a range on a depth that takes none, a ledger that cannot enumerate its streams, or any thrown exception | Retry, then page the operator. Nothing was checked |

The report prints five columns — Stream, Entries, Chain, Anchors, Signatures — and one summary line.
Read the numbers apart:

| Number | Where | Means |
|---|---|---|
| `checked()` | Entries column, summary | Entries **read and rehashed** |
| `covered()` | Anchors column (`covering N entries nobody read`) | Entries an anchor answered for. Nobody opened them |
| `archived()` | Entries column (`+N retired`) | Entries the walk **stepped over**: they are no longer in the ledger and two things account for them |
| `redacted()` | Entries column (`N redacted`) | Declared redactions. A count, never a break |

Never add `checked` to `covered`. The report keeps them apart precisely so a reader can see which of
the two a number came from, and `isIntact()` means "nothing came back wrong", not "everything was
read".

The Signatures column carries two tallies when the walk read anchors: the entries' and, after
`on the anchors`, the anchors'. They answer different questions — whether what somebody read is
attested, and whether the anchors standing in for what nobody read are.

```php
use ElPandaPe\Sentinel\Enums\SignatureState;
use ElPandaPe\Sentinel\Facades\Sentinel;

$report = Sentinel::verifyEverything();

$report->isIntact();            // bool
$report->checked();             // read and rehashed
$report->covered();             // taken on an anchor's word — never added to checked()
$report->archived();            // stepped over, no longer in the ledger
$report->redacted();            // declared redactions
$report->signatures();          // ['signed' => 41208, 'unsigned' => 4000]
$report->anchorSignatures();    // ['signed' => 41]
$report->firstBreak();          // ?VerificationResult, carrying its own stream

$forged = $report->signatures()[SignatureState::Invalid->value] ?? 0;
```

> 📌 **Note.** Only `SignatureState::Invalid` is a defect. `Unsigned` is a trail written before
> signing was switched on — signing is not retroactive. `UnknownKey` is a verdict the verifier is
> not entitled to give, because it never held the key that could have made the signature good.

---

## Before you touch anything

Every repair rewrites the evidence. Nothing in the package repairs a chain, and an entry re-sealed
after the fact proves only that somebody with write access passed through.

Collect, in this order, before running anything else:

1. The **full command output**, the **exit code**, the timestamp and the host.
2. `php artisan about`.
3. The **whole row** named by the break and its two neighbours — every column, not a summary.
4. The anchors of that stream: `select id, sequence_from, sequence_to, root_hash, algorithm,
   signature, key_id, created_at from sentinel_checkpoints where stream = ? order by sequence_from`.
5. The manifest rows: `select * from sentinel_archives where stream = ? order by sequence_from`.
6. A database-side snapshot or backup of `sentinel_audits`, `sentinel_checkpoints` and
   `sentinel_archives` taken **together**. Restoring them from different points in time
   manufactures breaks that were never there.

Do not run `sentinel:redact`, `sentinel:prune`, `sentinel:partitions --retire=<age>` or a rehydration
until that is captured.

---

## `hash_mismatch`

> *Audit :id no longer reproduces its own hash at sequence :sequence of stream :stream.*

**What it means.** The entry does not reproduce the hash it is entitled to reproduce — the original
`hash` for a sealed entry, or `redacted_hash` once `redacted_at` is set. The reported `sequence` is
the entry's own and `auditId` is its ULID. **The walk stops here**: `checked` is what was read before
it, and everything past it is unexamined.

**The three innocent causes, in order of likelihood.**

1. A data migration or backfill that wrote one of the twenty-seven canonical columns through the
   query builder. `Models\Audit` refuses `update()` and `delete()` on model events, and
   `Audit::query()->update(...)` walks straight past that guard.
2. A migration that rewrote `algorithm` or `payload_version` on existing rows "to match the config".
   Verification reads the digest name off the row and puts `payload_version` in the hash prefix, so
   normalising either column re-breaks every row it touches.
3. A hand-rolled redaction: `redacted_at` written without a `redacted_hash` that reproduces.
   `Integrity\Content` asks `redacted_at` first, so such a row is `Altered`, not `Redacted`.

**The guilty cause.** Someone changed what the entry says and could not recompute its hash. This is
the case the chain exists for.

**What to collect.** `verifyContent()` and `verifySignature()` on the row; `redacted_at`,
`redaction_reason`, `redacted_hash`, `algorithm`, `payload_version`; and any trail entry pointing at
it (`source_audit_id`).

```php
use ElPandaPe\Sentinel\Enums\ContentState;
use ElPandaPe\Sentinel\Enums\SignatureState;
use ElPandaPe\Sentinel\Models\Audit;

$entry = Audit::query()->findOrFail('01JB9Z8Q0000000000000000AB');

$entry->verifyContent();    // ContentState::Altered — the finding
$entry->verifySignature();  // the second half of the diagnosis
```

**What to do next.** Read the pair together. A signature is taken over the 64-character `hash`
string and never over the payload, so:

- `Altered` + `Signed` — the content changed while the `hash` column was left alone. Whoever did it
  could not produce a signature for a new hash, so they did not try. This is the strongest evidence
  of tampering the package produces.
- `Altered` + `Invalid`/`Unsigned` — consistent with the `hash` column having been rewritten too.

Then bound a second walk past the break to find out whether the damage is one entry or many:

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

$rest = Sentinel::verifyIntegrity('tenant:acme', from: 2_982);
```

A bounded walk reads the `hash` column of the entry before the range and hangs the first link off it
— it does not rehash that entry, and it does not take the first link on faith.

---

## `link_mismatch`

> *Audit :id does not link to the entry before it at sequence :sequence of stream :stream.*

**What it means.** The entry's own content is sound and its sequence is the expected one, but its
`previous_hash` is not the `hash` of the entry the walk just read. The checks run in that order —
sequence, then content, then link — so a `link_mismatch` says something precise: **the entry at
`sequence - 1` is not the entry that was there when this one was written.** The walk stops here.

An entry with no readable predecessor is held to one thing only: sequence 1 must link to nothing.
That covers the first entry of a stream, the first entry of a bounded range, and the first entry
after a range the walk stepped over.

**The three innocent causes.**

1. A partial restore. `sentinel_audits` recovered from a snapshot in which the predecessor is an
   *older, self-consistent* version of itself — intact on its own, and not the row this one links to.
2. An entry deleted and re-inserted by hand at the same sequence, rather than through
   `Archive\Rehydrator` (which refuses a sequence held by a different hash).
3. Two histories merged under one stream name — `integrity.stream` changed and rows moved by hand.
   The stream name is inside the hash prefix, so changing it forks a chain; it never renames one.
   See [Streams](02-streams.md).

**The guilty cause.** Somebody rewrote an entry, recomputed its hash correctly, and could not
rewrite the `previous_hash` of the entry after it. The link is exactly what catches a re-sealed
forgery.

**What to collect.** Rows `N-1`, `N` and `N+1` in full; the `hash` of `N-1` next to the
`previous_hash` of `N`; `verifyContent()` and `verifySignature()` on both; and the anchor covering
`N-1`.

**What to do next.** Run `--depth=roots` on that stream. If the range containing `N-1` was anchored
before the substitution, the fold is over the hashes as they were then — and it will disagree,
naming the range. That is the second, independent witness.

---

## `sequence_gap` — a sequence that is not there

> *Stream :stream is missing sequence :sequence, so its chain cannot be followed past it.*

**What it means.** The walk expected sequence `N`, the next row it read had a higher one, and
nothing accounted for the absence. The reported `sequence` is the **first** missing one. The
reported `auditId` is the id of the entry *after* the gap — the translated sentence does not render
it, so do not read the message as naming a culprit. The walk stops here.

**The three innocent causes.**

1. A prune whose two witnesses do not both hold — see
   [A pruned gap needs two witnesses](#a-pruned-gap-needs-two-witnesses). This is by far the most
   common.
2. A partition retired with `sentinel:partitions --retire='12 months' --force`. That is a catalogue-level drop:
   nothing is archived and **no `sentinel_archives` row is written**, because the prune is the only
   writer of that table. The gap it leaves is permanent and unaccountable.
3. A cleanup script, a restore that skipped rows, or a `delete` run against the table by hand.

**The guilty cause.** Entries removed to make an action disappear.

**What to collect.** The manifest rows and the anchors' reach, exactly as the verifier asks for them:

```sql
select sequence_from, sequence_to, records, disk, path, checksum, compressed
from sentinel_archives where stream = ? order by sequence_from;

select max(sequence_to) from sentinel_checkpoints where stream = ?;
```

Plus the entry after the gap, and the batch on the archive disk if a manifest row names one.

**What to do next.** Work the two-witness rule below. If neither witness can be produced, the gap is
a finding and stays one; there is no way to declare an absence retrospectively that would mean
anything.

---

## `sequence_gap` — a sequence that is not the one expected

The same reason code covers a second, rarer shape: a row whose sequence is **lower** than the one
the walk expected, which can only happen where two rows share a `(stream, sequence)`. It is reported
at `min(sequence, expected)`.

The flat migration forbids it with `unique(stream, sequence)`. The **date-range partitioned**
migrations cannot: both MySQL and PostgreSQL require every unique key of a partitioned table to
carry the partitioning column, so the key becomes `(stream, sequence, created_at)` and stops being
enforced across partitions. See [Partitioning](../10-database-engines/06-partitioning.md).

**The three innocent causes.** A re-import or a hand-run insert on a partitioned table; two
rehydrations of the same range running at once (the already-restored check happens before the write,
not inside it, and rehydration is single-writer); rows copied between environments.

**The guilty cause.** A forged entry inserted alongside the real one, so a query answers with the
attacker's version and the real row is still there to be produced if anyone asks.

**What to collect.**

```sql
select id, sequence, hash, previous_hash, created_at, capture_id
from sentinel_audits where stream = ? and sequence = ?;
```

**What to do next.** Delete nothing. A `--depth=roots` run over the range is the tiebreak: a range
with a duplicate no longer has the exact number of hashes its anchor's window declares, so the root
cannot be recomputed and the verifier walks the range entry by entry and names it.

---

## `signature_mismatch`

> *Audit :id carries a signature that its own key does not verify, at sequence :sequence of stream :stream.*

**What it means.** The signature stored on the entry — or on an **anchor** — does not verify against
the key its own `signature_key_id` names. The chain is untouched, so this does **not** stop the
walk: only the *first* forged signature is located, and the rest are counted in the tally.

At `--depth=anchors` and `--depth=roots`, an anchor's forged signature is announced with the anchor's
`sequence_from` and its **root hash** in the id slot — while the sentence still says "Audit :id". A
64-character value there is a root hash; look it up in `sentinel_checkpoints.root_hash`, not in
`sentinel_audits.id` (entry ids are 26-character ULIDs).

**The three innocent causes.**

1. **The HMAC secret changed.** With `integrity.signature.keys.default` left null, the secret is
   derived from `APP_KEY`. Rotate the application key — or point a second environment's `.env` at
   the same database — and every entry signed under `default` reports **`Invalid`**, not
   `UnknownKey`, because the ring still resolves the identifier.
2. **A signature that went through a text pipeline.** The OpenSSL signature is stored base64-encoded
   and `verify()` requires strict base64: whitespace padding or a re-encoding in transit verifies as
   `Invalid`, which reads as forgery when it was transport.
3. **Crossed key material.** `keys['v2']` resolves but holds the wrong environment's public half.

**The guilty cause.** The `signature` column was altered, or the entry was re-signed with a key that
is not the one it names.

**What to collect.** The full signature tally from the report; the `signature_key_id` of the
offending rows; whether the invalid ones share a key id or a date window; and your `APP_KEY`
rotation history.

**What to do next.** Count them.

- If **every** signature under one identifier is invalid, it is a key problem. A forger cannot
  invalidate signatures they never touched.
- If a handful are invalid inside a sea of valid ones under the **same** key, it is not a key problem.

> ⚠️ **Warning.** Under `openssl`, a PEM the process cannot parse makes `verify()` throw, and the
> command turns any throwable into **exit 2** with *"The chain could not be verified …"*. That is
> unreadable key material, not a forgery — and exit 2 is the retryable code.

---

## `checkpoint_mismatch`

> *Anchor :id no longer folds to the root it recorded, over the range of stream :stream that begins at sequence :sequence.*

**What it means.** Two different things, and the depth tells you which:

- At `--depth=anchors` nothing is refolded, so the only way to get this is a **hole in the chain of
  anchors**: the first anchor does not start at sequence 1, or one does not start where the previous
  ended. Deleting the first anchor of a stream therefore reports a mismatch at the `sequence_from`
  of whatever anchor now comes first, with `covered` at 0.
- At `--depth=roots`, additionally: a range that no longer folds to its recorded root. Before
  blaming the anchor the verifier walks that range entry by entry and reports the **entry** if the
  walk finds anything — so a `checkpoint_mismatch` at this depth means the range walked clean and
  the root still disagrees.

The reported `sequence` is the anchor's `sequence_from` and `auditId` is the anchor's **root hash**.

**The three innocent causes.**

1. An anchor row deleted to "clean up". After a prune the anchors are the only thing standing behind
   entries that are gone.
2. `sentinel_checkpoints` restored from a different backup than `sentinel_audits`.
3. An anchor written by a build whose construction this one does not know — an `algorithm` not
   prefixed `fold-`. It cannot be recomputed at all, and unless the manifest accounts for the range
   that reads as a mismatch rather than as "not checked".

**The guilty cause.** A range was rewritten and its anchor reissued to match. The fold carries the
**previous anchor's root**, so reissuing one anchor obliges reissuing every anchor after it —
reissue one and the next one stops folding.

**What to collect.** Every anchor of the stream, ordered by `sequence_from`, with `created_at`.
Anchors are emitted in ascending window order, one transaction each, so `created_at` should rise
with `sequence_from`. An anchor created *after* the anchor that follows it is a reissue.

```php
use ElPandaPe\Sentinel\Models\AuditCheckpoint;

$anchors = AuditCheckpoint::query()
    ->where('stream', 'tenant:acme')
    ->orderBy('sequence_from')
    ->get(['id', 'sequence_from', 'sequence_to', 'root_hash', 'algorithm', 'key_id', 'created_at']);
```

**What to do next.** Only the deep walk says anything about the entries:

```bash
php artisan sentinel:verify --stream=tenant:acme --from=5001 --to=6000
```

`--from`/`--to` are accepted only with `--stream` and only at `--depth=entries`; the shallow walks
cover whatever the anchors cover, and both violations exit 2.

---

## `projection_mismatch`

> *The indexed relations of audit :id no longer match the lines it sealed … The chain is intact: the projection is not part of it.*

Reported only under `--projections`. `sentinel_audit_relations` is a reconstructible projection of
the relation lines inside `changes`; it is **not** in the canonical payload, so the hash does not
cover it. Somebody who edits it leaves the chain perfectly intact and every relation query answering
a different question.

Read it as its own kind of defect. It exits 1 like the others, but the chain is sound, and calling
it a chain break would be a lie about what the hash covers. Detection ships; repair does not.

Redacted entries are skipped rather than compared — their lines were destroyed with the rest of
their content, and comparing would report every redacted relation entry as divergent forever.

---

## A pruned gap needs two witnesses

The rule, in one sentence: **an absence is stepped over only when the manifest accounts for the
range *and* the anchors reach past it.** Neither alone is enough.

- The **anchors** are the evidence. `sentinel_checkpoints` rows carry a signed root over a fixed
  window, and they are what still stands behind entries that are gone.
- The **manifest** only says which absence they are being asked about. `sentinel_archives` is
  neither hashed nor signed, and it has exactly one writer — the prune. On its own it would make
  "delete the rows, then insert one row" a supported way of laundering a gap.

When a legitimate prune is reported as a `sequence_gap`, check both, in this order:

| Check | Query | Must hold |
|---|---|---|
| The anchors reach past the gap | `select max(sequence_to) from sentinel_checkpoints where stream = ?` | `>=` the **last** missing sequence |
| The manifest starts at or before the gap | `select sequence_from, sequence_to from sentinel_archives where stream = ? and sequence_to >= <first missing> order by sequence_from` | The first row's `sequence_from` is `<=` the first missing sequence |
| The manifest is contiguous across the gap | the same rows, walked forward | Each next row starts no later than one past where the walk has reached |

The manifest walk stops at the first gap it cannot bridge. A row that starts beyond where the walk
has reached is a *different* absence with something unexplained in front of it, so it is not jumped
to.

The usual reasons a legitimate gap is refused:

- The anchors were deleted, or restored from an older backup than the entries.
- `sentinel_archives` was dropped, excluded from the backup, or restored separately. It is not
  derivable again: the entries it accounts for are gone.
- Rows were removed beyond what a manifest row claims — a hand-run delete plus a hand-written row
  naming a narrower range.
- A partition was force-retired. That writes no manifest row at all.

> 🔒 **Security.** Do not insert a `sentinel_archives` row to silence a gap. It disarms the prune's
> tamper guard and the verifier's gap-crossing for that range, permanently, and it is precisely the
> move the two-witness rule exists to refuse. It also cannot work on its own: without an anchor
> reaching past the range the verifier still refuses.

A range whose root folds to a *different* root is never excused by the manifest. Only a root that
cannot be recomputed **at all** — the entries are gone, or the construction is unknown — qualifies
as retired.

---

## What a tombstone does to verification

A redaction empties six columns of the canonical payload in place (`context` to `[]`; `before`,
`after`, `changes`, `metadata`, `criteria` to null), keeps `sequence`, `hash` and `previous_hash`,
seals a second hash into `redacted_hash`, and writes a **new chained entry** recording who ordered
it. See [Redaction and tombstones](../08-lifecycle/04-redaction-and-tombstones.md).

| Question | Answer for a tombstone |
|---|---|
| `$audit->verifyIntegrity()` | **`false`** — unchanged meaning, and it errs towards the alarm. Answering true would rest on `redacted_hash`, which no signature and no fold covers |
| `$audit->verifyContent()` | `ContentState::Redacted` — this is how you tell a tombstone from a tampering |
| `--depth=entries` | Counted, never announced. It does not stop the walk, does not fill `reason` and does not invert `isIntact()`. The Entries column shows `(N redacted)` and the run exits **0** |
| `--depth=anchors` / `--depth=roots` | A tombstone inside a **closed** anchor window is invisible — neither depth opens an entry, and the fold is over `hash`, which a tombstone keeps. One in the **unanchored tail** is counted, because the tail is walked |
| `--projections` | Redacted entries are skipped, not compared |
| A real `Altered` standing next to a tombstone | Wins. It is `hash_mismatch`, it stops the walk, and it exits 1 — otherwise a redaction would be a place to hide one |

All three depths agree on the thing that matters: none of them calls a tombstone a tampering.

> 📌 **Note.** `redacted_at`, `redaction_reason` and `redacted_hash` are outside the canonical
> payload, outside the signature and outside the fold. The second hash catches a later write into a
> redacted row and nothing more. What separates a declared redaction from an attack is the trail
> entry the redaction wrote — chained and signed like any other, and an attacker does not write one.

---

## When verification fails in CI

A verification job is read-only and safe to run concurrently. What breaks in CI is usually the way
the job is wired, not the chain.

**Branch on the code, not on the text.** A job that treats any non-zero exit as "the chain is
broken" will treat an unreachable database exactly like a rewritten entry.

```bash
php artisan sentinel:verify --stream=global --depth=roots
case $? in
  0) ;;
  1) echo "Integrity finding. Stop the deploy and page." ; exit 1 ;;
  2) echo "The check could not run. Retry, then page the operator." ; exit 75 ;;
esac
```

**A green run on an empty database has checked nothing.** With no `--stream`, the walk covers the
streams the ledger can name, and `Ledger\DatabaseLedger::streams()` is `select distinct stream from
sentinel_audits`. An empty table names no streams, so the report holds zero streams, `isIntact()` is
true, and the command prints *"Verified 0 entries across 0 streams"* and exits 0. Name a stream you
seeded, and assert a floor on `checked()`.

**The same mechanism means a wiped stream is never reported.** A stream whose rows are all gone
stops being named, so nothing walks it and nothing complains — even though its anchors are still
sitting in `sentinel_checkpoints`. If that matters to you, compare the stream list against a known
set rather than trusting a green exit.

**`ledger.default = 'null'` turns the job red at exit 2.** `Ledger\NullLedger` does not implement
`Contracts\EnumeratesStreams`, so a run with no `--stream` is refused rather than reported empty:
answering "nothing is broken" about a list nobody could produce reads as reassurance and means
nothing. `Ledger\MemoryLedger` *does* implement it — but it only knows what the current process
wrote, so verifying it proves nothing about a database.

**Restore the three tables together.** A CI job that verifies a database restored from a production
dump will manufacture `checkpoint_mismatch` and `sequence_gap` out of nothing if `sentinel_audits`,
`sentinel_checkpoints` and `sentinel_archives` come from different points in time.

**Put the gate after migrations and before traffic.** Exit 1 means stop; exit 2 means retry.

---

## Telling an auditor

When a run comes back non-zero for a reason you understand, say so in this shape.

**Lead with the run, not the conclusion.** The exact command, the depth, the host, the timestamp,
the exit code and the reason string verbatim. Then say what that depth proved: quote `checked`,
`covered` and `archived` as three numbers and never as a sum, and state plainly that a range under
an anchor was not read.

**For a declared redaction**, produce the trail entry — that, and not the second hash, is the proof:

```php
use ElPandaPe\Sentinel\Models\Audit;

$trail = Audit::query()
    ->where('source_audit_id', '01JB9Z8Q0000000000000000AB')
    ->where('audit_type', 'security')
    ->where('event', 'redacted')
    ->first();

$trail?->metadata; // ['redaction' => ['audit_id' => …, 'stream' => …, 'sequence' => …, 'reason' => …]]
$trail?->actor_type;
$trail?->actor_id;
```

Find it by `source_audit_id`. The trail entry goes through the pipeline and takes the **run's**
context, so under the default `tenant` stream strategy a redaction ordered from a console with no
tenancy resolver lands in `global` while the entry it redacts lives in `tenant:acme`. There is no
Query API filter on `source_audit_id`; this is an Eloquent read.

Say what it proves and what it does not: the tombstone's second hash proves the remains are the ones
the redaction left, and nothing against someone who can write the row. The chained, signed trail
entry is what makes the destruction declared rather than clandestine.

**For a retired range**, produce the `sentinel_archives` row (`records`, `disk`, `path`, `checksum`,
`compressed`) and the anchors that reach past it. Say that the manifest row is a map and not
evidence — nothing in it is hashed or signed — and that what stands behind the absence is the
anchor. If a batch exists, they can verify the object themselves: the checksum is `sha256:<hex>` over
the exact bytes written, compression included.

**For an unsigned stretch**, give the date signing was switched on and say that signing is not
retroactive and deliberately never will be. `Unsigned` is not a defect; a mass update over immutable
rows would produce a signature proving only that somebody with write access passed through.

**Hand them what they can check without you.** The rows, or a `sentinel:export` body with its
`.manifest.json`, plus the verifying half of `integrity.signature.keys`. `CanonicalPayload::from()`
decrypts nothing, so a third party reproduces every hash holding no encryption key — and under
`openssl`, no private key either. See
[Verification](06-verification.md) and [Canonicalization](03-canonicalization.md).

> ⚠️ **Warning.** An export manifest is signed by whatever the current signer is. With
> `integrity.signature.enabled` off that is the null signer, and the manifest ships an empty
> `signature` with `signature_key_id` of `"null"` — it proves nothing. Check before you send it.

**Never present a repaired chain.** If you rehashed rows to make a report green, say that instead;
it is the one action that destroys the property the trail is being audited for.

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `sentinel:verify` exits 0 having read nothing | With no `--stream` the walk covers the streams `DatabaseLedger::streams()` names — `select distinct stream from sentinel_audits`. An empty table names none | Name `--stream`, and assert a floor on the entries the run reports |
| A stream that was wiped is never reported broken | Same mechanism: a stream with no rows is not named, so nothing walks it | Compare the stream list against a known set; back up `sentinel_checkpoints` alongside `sentinel_audits` |
| `signature_mismatch` names a 64-character id | At the shallow depths an anchor's forged signature is announced with the anchor's root hash in the `:id` slot, and the sentence still says "Audit :id" | Look it up in `sentinel_checkpoints.root_hash`. Entry ids are 26-character ULIDs |
| `checkpoint_mismatch` names an id that is in no table you know | For an anchor mismatch, `auditId` carries the root hash and `sequence` the anchor's `sequence_from` | Query `sentinel_checkpoints`, not `sentinel_audits` |
| Every signature suddenly `Invalid` after a deploy | `integrity.signature.keys.default` left null derives the HMAC secret from `APP_KEY`; rotating the app key changes the secret while the ring still resolves the identifier | Set the secret explicitly in `keys`; a rotation is moving `key_id` and leaving the old key on the ring |
| Exit 2 with "The chain could not be verified" on a signed trail | Under `openssl`, a public key the process cannot parse makes `verify()` throw, and the command turns any throwable into exit 2 | Fix the key material. Exit 2 is the retryable code; exit 1 is not |
| A pruned trail passes `--depth=anchors` and fails `--depth=entries` | The anchor walk refolds nothing and opens no anchored entry, so it cannot see that a covered range's entries are gone or changed; `covered` counts them anyway | Use `--depth=roots` for the routine sweep on any trail that is pruned |
| A gap appears right after a partition retirement | `sentinel:partitions --retire=<age> --force` drops a range at the catalogue level and writes no `sentinel_archives` row — the prune is the manifest's only writer | Archive with `sentinel:prune` first. A forced drop leaves a gap nothing can account for |
| A redaction trail is not in the stream you searched | The trail entry takes the run's context, so a console redaction of a `tenant:acme` entry lands in `global` under the default stream strategy | Find it by `source_audit_id`, never by stream or tenant |
| A duplicate `(stream, sequence)` the database accepted | On the date-range partitioned migrations the unique key carries `created_at` and is local to each partition | Check for duplicates explicitly; the flat table's unique key is a safety net partitioning gives up |

---

## ✅ Best practices

✅ **Do** — branch the watchdog on all three exit codes. A broken chain and an unreachable database
are different facts, and a watchdog that cannot tell them apart will eventually treat one as the other.

```bash
php artisan sentinel:verify --depth=roots
# 0 sound · 1 a real finding, stop · 2 the run could not happen, retry
```

❌ **Don't** — treat any non-zero exit as "the chain is broken". Exit 2 means nothing was checked,
which is not the same as nothing being wrong.

```bash
php artisan sentinel:verify || page_the_security_team   # pages for a dropped connection
```

✅ **Do** — read `checked`, `covered` and `archived` as three separate facts. They are published side
by side precisely so a reader can see which of the three a number came from.

```php
$report->checked();   // read and rehashed
$report->covered();   // taken on an anchor's word
$report->archived();  // stepped over; not in the ledger any more
```

❌ **Don't** — sum them into one "entries verified" figure for a dashboard. Two of the three were
never opened, and the total quietly claims they were.

```php
$verified = $report->checked() + $report->covered(); // claims what nobody read
```

✅ **Do** — capture the evidence, then bound a second walk past the break to see how far the damage
goes. The first walk stops at the first break, so everything past it is unexamined.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

$rest = Sentinel::verifyIntegrity('tenant:acme', from: 2_982);
```

❌ **Don't** — rehash the offending row, or rehash rows in a migration for any reason. It destroys
the exact property the package sells, and a format change bumps `payload_version` instead.

```php
$audit->forceFill(['hash' => $recomputed])->save(); // the report goes green and means nothing
```

✅ **Do** — require both witnesses before believing a pruned range is a break: the anchors reach past
the last missing sequence, and the manifest starts at or before the first one.

```sql
select max(sequence_to) from sentinel_checkpoints where stream = 'tenant:acme';
select sequence_from, sequence_to from sentinel_archives
where stream = 'tenant:acme' and sequence_to >= 5001 order by sequence_from;
```

❌ **Don't** — insert a `sentinel_archives` row to make a gap go away. The prune is that table's only
writer; a hand-written row disarms the prune's tamper guard and the verifier's gap-crossing for that
range, and without an anchor reaching past it the gap is still reported anyway.

✅ **Do** — tell a tombstone from a tampering with `verifyContent()`, and treat the count as a count.

```php
use ElPandaPe\Sentinel\Enums\ContentState;

$declared = $entry->verifyContent() === ContentState::Redacted;
```

❌ **Don't** — alert on `$audit->verifyIntegrity()` returning `false`. It has always meant "does this
row reproduce its own hash", and a tombstone does not — on purpose.

```php
if (! $entry->verifyIntegrity()) {
    alert('tampering');   // fires on every legitimate redaction you ever performed
}
```

✅ **Do** — verify a stream you actually seeded in CI, and assert a floor on the entries read.

```bash
php artisan sentinel:verify --stream=global --depth=entries
```

❌ **Don't** — schedule `sentinel:verify` with no `--stream` against an installation running the null
ledger. It cannot name its chains, so every run exits 2 rather than quietly passing.

---

**See also:** [The hash chain](01-the-hash-chain.md) · [Streams](02-streams.md) · [Signing the chain](04-signing.md) · [Checkpoints and anchors](05-checkpoints-and-anchors.md) · [Verification](06-verification.md) · [Retention and pruning](../08-lifecycle/01-retention-and-pruning.md) · [Redaction and tombstones](../08-lifecycle/04-redaction-and-tombstones.md) · [Artisan commands](../09-operations/06-artisan-commands.md) · [Scheduling](../09-operations/07-scheduling.md) · [Monitoring and troubleshooting](../09-operations/08-monitoring-and-troubleshooting.md) · [Exit codes](../99-reference/07-exit-codes.md) · [Enums](../99-reference/04-enums.md)
