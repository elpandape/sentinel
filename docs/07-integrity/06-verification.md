# 🔐 Verification

> The three depths of a verification, exactly what each one proves and what it leaves unproved, how
> to read the three result shapes field by field, and what `sentinel:verify` hands back to a cron.

**On this page:** [The four entry points](#the-four-entry-points) · [The three depths](#the-three-depths) · [Verifying the whole trail](#verifying-the-whole-trail) · [Reading a VerificationResult](#reading-a-verificationresult) · [Reading a StreamVerification](#reading-a-streamverification) · [Reading an IntegrityReport](#reading-an-integrityreport) · [Every break, and what it means](#every-break-and-what-it-means) · [The projection check](#the-projection-check) · [sentinel:verify](#sentinelverify) · [Verifying without the keys](#verifying-without-the-keys) · [⚠️ Pitfalls](#️-pitfalls) · [✅ Best practices](#-best-practices)

---

## The four entry points

Everything a verification can do from application code is on the facade. All four **report**; none of
them throws because the chain is broken.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

Sentinel::verifyIntegrity('tenant:acme');          // VerificationResult
Sentinel::verifyIntegrity('tenant:acme', 1, 5000); // the same, bounded by sequence
Sentinel::verifyAnchors('tenant:acme');            // StreamVerification
Sentinel::verifyRoots('tenant:acme');              // StreamVerification
Sentinel::verifyEverything();                      // IntegrityReport
```

| Method | Scope | Returns | Reads | Throws |
|---|---|---|---|---|
| `verifyIntegrity($stream, $from, $to)` | one stream, optionally a range | `Integrity\VerificationResult` | every entry of the range, in full | `ConfigurationException` if a row's `algorithm` is not in `hash_algos()`; `CanonicalizationException` on a payload value that cannot be encoded |
| `verifyAnchors($stream)` | one stream | `Integrity\StreamVerification` | the anchor rows, plus every entry of the tail no anchor covers | — |
| `verifyRoots($stream)` | one stream | `Integrity\StreamVerification` | the above, plus `(sequence, hash)` of every anchored entry | — |
| `verifyEverything()` | every stream the ledger can name | `Integrity\IntegrityReport` | every entry of every stream | `QueryException::cannotEnumerateStreams` when the bound ledger does not implement `Contracts\EnumeratesStreams` |

> ⚠️ **Warning.** `verifyIntegrity()` hands back **the chain result only**. The walk behind it checks
> every entry's signature — the event fires, and `sentinel:verify` reports it — but a forged
> signature does not appear in the `VerificationResult` you get back, so `$result->isIntact()` is
> `true` for a stream whose only defect is a forged signature. For a signature verdict from
> application code, read the per-stream `StreamVerification` out of `verifyEverything()`, listen for
> `Events\IntegrityVerificationFailed`, or ask one entry with `$audit->verifySignature()`.

A bounded range is a question about **one** stream: the same sequence numbers mean different entries
in different chains, which is why `verifyEverything()` takes no range at all. When `$from` is greater
than 1 the walk reads the entry at `$from - 1` and hangs the first link off it, rather than taking
that link on faith; when that entry is no longer there, the first link is not checked and not
invented either.

---

## The three depths

The depths are not three speeds of the same check. They prove three different things, and the
difference is the whole point of publishing three of them.

| Depth | What it reads per stream | What it proves | What it does **not** catch |
|---|---|---|---|
| `verifyAnchors()` | the anchor rows, plus the tail no anchor covers | the anchors run contiguously from sequence 1, every anchor's signature holds, and the tail links and rehashes | anything at all about an anchored entry — not one of those rows is opened |
| `verifyRoots()` | the same, plus two columns (`sequence`, `hash`) of every anchored entry | additionally that the stored hashes still fold to each recorded root: a hash rewritten, removed or reordered, located down to the entry | a canonical column edited while the `hash` column was left alone — it rehashes nothing |
| `verifyIntegrity()` | every entry of the range, in full | every entry still reproduces its own hash, links to its predecessor, and sits at the sequence it claims | whether the statement was true when it was captured |

### Anchors report a range as anchored, never as intact

`verifyAnchors()` never opens an anchored entry. What it can say about a range is that an anchor
covers it, that the anchor is signed by a key the ring resolves, and that the anchor chain has no
hole in it. That is reported as `anchored` — and `intact` is reserved for what was read.

`isIntact()` on the result means *nothing came back wrong*. It does not mean *everything was read*.

A stream nobody has anchored is not reported as a failure: `verifyAnchors()` falls through to the
entry walk and returns `['absent' => 1]` in the range tally, which is the same answer the deep walk
gives, having paid for it.

### Roots folds stored hashes, not content

`verifyRoots()` recomputes each root out of the `hash` column the entries carry now. That catches a
hash rewritten, an entry removed from the middle of a window, or two entries swapped. It does not
catch this:

```sql
-- The chain breaks. verifyIntegrity() reports HashMismatch at that sequence.
update sentinel_audits set event = 'moved' where id = '01JB9Z8Q0000000000000000AB';

-- Under an anchor, verifyRoots() and verifyAnchors() both still pass:
-- the fold is over `hash`, and `hash` was not touched.
```

There is a test named exactly for this — *takes an anchored range on trust that the walk of every
entry refuses*. It is why a range the shallow walks agree with is still only `anchored`, and why the
depth to reach for whenever the question is about **content** is `entries`.

> 📌 **Note.** Switching anchoring on never makes an installation verify less. `verifyIntegrity()` is
> deliberately unchanged by anchors: it reads and rehashes the same rows it did the day before.

### Cost

`verifyAnchors()` reads rows in proportion to *history ÷ window* plus the tail; the deep walk reads
the history. A test pins the relation — *walks the anchors in fewer statements than the entries they
stand for* — by counting statements, not by asserting a ratio. There is no published measurement of
how much cheaper it is on your data, and this page will not invent one; measure it on the trail you
have. See [Checkpoints and anchors](05-checkpoints-and-anchors.md) for the window size that governs
the trade.

---

## Verifying the whole trail

```php
use ElPandaPe\Sentinel\Enums\SignatureState;
use ElPandaPe\Sentinel\Facades\Sentinel;

$report = Sentinel::verifyEverything();

if (! $report->isIntact()) {
    logger()->critical($report->firstBreak()?->message() ?? '');
}

$forged = $report->signatures()[SignatureState::Invalid->value] ?? 0;
```

Listing the chains is a **capability**, not an assumption: `Contracts\EnumeratesStreams` is declared
by `DatabaseLedger`, `MemoryLedger`, `ArchiveLedger` and `FanoutLedger`, and not by `NullLedger`. A ledger that cannot
name its streams is refused with `QueryException::cannotEnumerateStreams` rather than answered with
an empty report — "nothing is broken" about a list nobody could build reads as reassurance and means
nothing. See [The Ledger contract](../11-extending/01-the-ledger-contract.md).

---

## Reading a VerificationResult

`Integrity\VerificationResult` is what one walk of one chain found. Its constructor is private; the
package builds it through `::intact()` and `::broken()`.

| Member | Type | What it holds |
|---|---|---|
| `$stream` | `string` | the chain that was walked |
| `$checked` | `int` | entries **read and rehashed** before the walk ended |
| `$reason` | `?IntegrityBreak` | what was found wrong, or `null` |
| `$sequence` | `?int` | where it was found — for `CheckpointMismatch`, the anchor's `sequence_from` |
| `$auditId` | `?string` | the entry it was found at — for `CheckpointMismatch`, the anchor's **root hash** |
| `$archived` | `int` | entries the walk **stepped over** because they are no longer in the ledger and something accounts for them |
| `isIntact()` | `bool` | `! $reason instanceof IntegrityBreak` |
| `message()` | `string` | the translated sentence, empty when nothing was found |

`$checked` and `$archived` are published side by side and are **never summed**. An entry nobody read
is not an entry that verified.

```php
$result = Sentinel::verifyIntegrity('tenant:acme');

if (! $result->isIntact()) {
    $result->reason;    // IntegrityBreak::HashMismatch
    $result->sequence;  // 2981 — where the chain stops being followable
    $result->auditId;   // '01JB9Z8Q0000000000000000AB'
    $result->checked;   // how many entries were read before it
    $result->message(); // the sentence, from the same source the event uses
}
```

An absence in the sequence is stepped over only when **two** independent things account for it at
once: the archive manifest says the range was retired, **and** the anchors reach past it. The
manifest alone will not do — nothing in `sentinel_archives` is hashed or signed, so on its own it
would make "delete the rows, then insert one row" a way of laundering a gap. See
[Retention and pruning](../08-lifecycle/01-retention-and-pruning.md).

---

## Reading a StreamVerification

`Integrity\StreamVerification` is what the shallow depths return, and what each element of an
`IntegrityReport` is.

| Member | Type | What it holds |
|---|---|---|
| `$chain` | `VerificationResult` | the chain finding for what was read |
| `$signatures` | `array<string, int>` | how many **entries** landed in each signature state, keyed by `SignatureState`'s string values |
| `$signature` | `?VerificationResult` | the **first** forged signature located, or `null` |
| `$anchors` | `array<string, int>` | how many **ranges** landed in each state: `'anchored'`, `'archived'`, `'absent'` |
| `$covered` | `int` | **entries** an anchor answered for — entries nobody read |
| `$content` | `array<string, int>` | how many entries landed in each `ContentState`: `'sealed'`, `'redacted'`, `'altered'` |
| `$anchorSignatures` | `array<string, int>` | how many **anchors** landed in each signature state |
| `isIntact()` | `bool` | the chain is intact **and** no forged signature was located |
| `break()` | `?VerificationResult` | the first thing wrong, chain before signature |
| `stream()` | `string` | the chain's name |
| `redacted()` | `int` | declared redactions among the entries that were read |
| `archived()` | `int` | read off `$chain->archived`, never carried twice |

Two pairs of names are easy to confuse and mean genuinely different things:

- `$anchors` counts **ranges**; `archived()` counts **entries**. Both render as the word "retired" in
  the command's output, in two different columns.
- `$signatures` is about entries somebody read; `$anchorSignatures` is about the anchors standing in
  for entries nobody read. They are kept apart because merging them lost counts as well as blurring
  them — string keys overwrite on collision, so a state both sides had came out as the tail's count
  alone. "Two unsigned" is not an answer until you know which.

```php
$anchors = Sentinel::verifyAnchors('global');

$anchors->chain->checked;   // entries actually read: the tail
$anchors->covered;          // entries taken on an anchor's word
$anchors->anchors;          // ['anchored' => 41] — or ['absent' => 1] for a stream nobody anchored
$anchors->anchorSignatures; // ['signed' => 41]
$anchors->isIntact();       // nothing came back wrong — not "everything was read"
$anchors->break()?->reason; // IntegrityBreak::CheckpointMismatch, say
```

> 📌 **Note.** `$anchors`, `$signatures`, `$content` and `$anchorSignatures` are keyed by **string
> values**. `Enums\CheckpointState` is `@internal`: read the keys `'anchored'`, `'archived'` and
> `'absent'` as strings and do not reference the enum class. `SignatureState` and `ContentState` are
> public — see [Enums](../99-reference/04-enums.md).

A **declared redaction is counted, never announced**. It does not stop the walk, does not fill
`reason` and does not invert `isIntact()` — an act somebody performed on purpose and left a trail for
is not a finding. A real tampering standing next to a tombstone still wins, otherwise a redaction
would be a place to hide one. See
[Redaction and tombstones](../08-lifecycle/04-redaction-and-tombstones.md).

At a shallow depth, `$content` and `$signatures` describe **the tail only** — the walk never opened
the anchored entries, so a redaction inside an anchored range is not in the count.

---

## Reading an IntegrityReport

`Integrity\IntegrityReport` is every stream that was walked, in the order the ledger named them. A
single-stream verification through the command is a report with one element, so there is one shape to
read and not two.

| Member | Returns | What it says |
|---|---|---|
| `$streams` | `list<StreamVerification>` | one element per chain walked |
| `isIntact()` | `bool` | every stream is intact |
| `checked()` | `int` | entries read and rehashed, across all streams |
| `covered()` | `int` | entries an anchor answered for — **never** added to `checked()` |
| `archived()` | `int` | entries stepped over, no longer in the ledger |
| `redacted()` | `int` | declared redactions, a count and never a break |
| `firstBreak()` | `?VerificationResult` | the first thing wrong; it carries its own stream |
| `signatures()` | `array<string, int>` | entry signature states, summed across streams |
| `anchorSignatures()` | `array<string, int>` | anchor signature states, summed across streams |
| `anchors()` | `array<string, int>` | range states, summed across streams |
| `content()` | `array<string, int>` | content states, summed across streams |

```php
foreach (Sentinel::verifyEverything()->streams as $verification) {
    if (! $verification->isIntact()) {
        report(new RuntimeException($verification->break()?->message() ?? $verification->stream()));
    }
}
```

---

## Every break, and what it means

`Enums\IntegrityBreak` holds **only** what is wrong. Every case makes a verification fail and
dispatches `Events\IntegrityVerificationFailed` at the point of rupture. States that coexist with a
healthy chain — unsigned, unknown key, redacted, unanchored — live in other enums on purpose:
putting one here would invert `isIntact()` in silence.

| Case | Value | Raised when | `sequence` / `auditId` carry | What to do |
|---|---|---|---|---|
| `HashMismatch` | `hash_mismatch` | an entry reproduces neither its own hash nor, if `redacted_at` is set, its `redacted_hash` | the entry's sequence and id | the row was written to outside the model. Compare it against a backup and against the archive |
| `LinkMismatch` | `link_mismatch` | `previous_hash` is not the hash of the entry before it — including an entry at sequence 1 that carries a `previous_hash` at all | the entry's sequence and id | an entry was inserted, removed or re-pointed. The walk stops: past a broken link nothing can be said |
| `SequenceGap` | `sequence_gap` | a sequence is missing and nothing accounts for it, or an entry repeats a sequence already passed | the missing or repeated sequence, and the id of the entry the walk was reading | rows were deleted without an archive record, or a sequence was planted |
| `SignatureMismatch` | `signature_mismatch` | a signature its own key refuses — `SignatureState::Invalid`, never `Unsigned` and never `UnknownKey` | the entry's sequence and id, or an anchor's `sequence_from` and root hash | the key that signed it is on the ring and disagrees. Treat as forgery until a transport explanation is proved |
| `CheckpointMismatch` | `checkpoint_mismatch` | an anchor chain has a hole, or a root no longer folds back and the range walk found nothing wrong | the anchor's `sequence_from`, and **the anchor's root hash in `auditId`** | the anchors were tampered with or reissued. Reissuing one obliges reissuing every anchor after it |
| `ProjectionMismatch` | `projection_mismatch` | `sentinel_audit_relations` no longer matches the lines an entry sealed | the entry's sequence and id | the chain is **intact**. The index is not — see below |

Two behaviours are worth stating plainly because they change how an alert should be written:

- **A broken link stops the walk. A broken signature does not.** Past a broken link nothing can be
  said, so the walk returns there. A forged signature leaves the chain holding, so the walk carries
  on and only the **first** forged signature is located — the rest are counted in the tally and not
  named.
- **`CheckpointMismatch` does not name an entry.** `auditId` carries the anchor's root hash, and the
  `:id` placeholder in the translated sentence renders that. A consumer that looks the value up in
  `sentinel_audits` will find nothing.

```php
use ElPandaPe\Sentinel\Enums\IntegrityBreak;
use ElPandaPe\Sentinel\Events\IntegrityVerificationFailed;
use Illuminate\Support\Facades\Event;

Event::listen(function (IntegrityVerificationFailed $failure): void {
    $page = $failure->reason !== IntegrityBreak::ProjectionMismatch;

    logger()->log($page ? 'critical' : 'warning', $failure->message(), [
        'stream'   => $failure->stream,
        'sequence' => $failure->sequence,
        'audit_id' => $failure->auditId,
    ]);
});
```

`IntegrityVerificationFailed` is an **event**, never an exception — no class in the package carries
that name as an exception. See [Events and listeners](../09-operations/04-events-and-listeners.md).

---

## The projection check

`sentinel_audit_relations` is an index over the relation lines inside `changes`, not the evidence. It
is outside the canonical payload, so the hash does not cover it: someone who edits it leaves the
chain perfectly intact and every relation query answering a different question.

`Integrity\Projections` closes that gap, and reports it as **its own kind of defect**. Calling it a
broken chain would be a lie about what the hash covers, and the only thing that makes the hash worth
anything is that it never claims more than it covers.

What the check does, precisely:

- It re-derives each entry's rows through `Ledger\RelationProjection` — the same mapping the ledger
  wrote through, so the two cannot drift — and compares them against what the table holds.
- It compares as a **multiset**, keyed by relation, operation, related type, related id and both
  pivot maps, counting repeats. The table carries no key of its own and no order worth trusting, so
  position cannot be part of the comparison and a count has to be: two identical lines are two rows.
- A row that belongs to no line is as much a divergence as a line with no row.
- It **skips redacted entries** rather than comparing them. A tombstone's lines were destroyed with
  the rest of its content and its projection rows went with them; comparing would report every
  redacted relation entry as divergent forever, over an act the package itself performed.
- It holds 500 entries before asking for their rows, which is what bounds one `where in`, and stops
  at the first batch that disagrees.

> 🐘 **Engine.** On PostgreSQL, `changes` is `jsonb` — which sorts object keys on the way in — while
> `pivot_before` and `pivot_after` are `json`, which keeps the text exactly as it arrived. The same
> map therefore comes back from the two in different orders. The check decodes and deep-sorts both
> sides before comparing, so what it reports belongs to the data and not to the engine. See
> [PostgreSQL](../10-database-engines/02-postgresql.md).

The supported route is `sentinel:verify --projections`; `Integrity\Projections` itself is
`@internal`. **Detection ships; repair does not** — there is no command that rebuilds the projection,
so a divergence is something you investigate against a backup, not something you clear.

See [Relationship auditing](../03-capture/04-relationships.md) for what the projection is for.

---

## `sentinel:verify`

```bash
php artisan sentinel:verify
php artisan sentinel:verify --stream=tenant:acme --from=1 --to=5000
php artisan sentinel:verify --depth=anchors
php artisan sentinel:verify --depth=roots --projections
```

| Option | Default | What it does | Constraints |
|---|---|---|---|
| `--stream=` | every stream the ledger can name | verify one chain | required by `--from` / `--to` |
| `--from=` | none | first sequence to verify | needs `--stream` **and** `--depth=entries` |
| `--to=` | none | last sequence to verify | same |
| `--depth=` | `entries` | `entries`, `roots` or `anchors` | anything else exits 2 and names the accepted values |
| `--projections` | off | also check the relation index, over the same streams | run **after** the chain, never instead of it |

A numeric option whose value is not numeric is treated as **absent**, not as zero. So
`--from=yesterday` is no bound at all: the command verifies the whole stream, exits 0, and says
nothing about the typo.

### Exit codes

| Code | Constant | Meaning here |
|---|---|---|
| `0` | `SUCCESS` | intact — **including** a trail nobody has signed, and one whose only finding is declared redactions |
| `1` | `FAILURE` | a real break from a run that happened: a hash that does not reproduce, a broken link, a sequence gap nothing accounts for, a signature its own key refuses, an anchor that no longer folds, or a divergent relation index under `--projections` |
| `2` | `INVALID` | a run that could not happen: `--from`/`--to` without `--stream`, a range at a depth that takes none, an unknown `--depth`, a ledger that cannot enumerate its streams, or any thrown exception |

A watchdog that cannot tell 1 from 2 will eventually treat one as the other: 1 is a finding a human
must look at, 2 is retry-or-page-the-operator. See [Exit codes](../99-reference/07-exit-codes.md).

### Reading the report

Five columns — Stream, Entries, Chain, Anchors, Signatures — then one summary line. Composed from the
shipped English strings, `--depth=anchors` over two streams, one of which nobody has anchored:

```
 Stream       Entries  Chain   Anchors                                        Signatures
 global       4210     intact  1 none (covering 0 entries nobody read)        4210 signed
 tenant:acme  210      intact  4 anchored (covering 4000 entries nobody read) 210 signed, 4 signed on the anchors

Read 4420 entries and took 4000 on the word of their anchors, across 2 streams.
Nothing came back wrong, which is not the same as every entry having been read.
```

Three things to read off that:

- The Anchors column is a dash and the anchor signature tally is absent at `--depth=entries`, which
  reads no anchors at all — so an installation that has never anchored does not look like one whose
  anchors failed.
- `global` shows `1 none`: nobody anchored it, so the walk fell through to the entry walk and read
  all 4210. Its 4210 are in `checked`, not in `covered`.
- `tenant:acme` read 210 entries — the tail — and took 4000 on the anchors' word.

Two counts share the word "retired" in different columns. `(+N retired)` in the Entries column counts
**entries** the walk stepped over because they are no longer in the ledger; `N retired` in the Anchors
column counts **ranges** the manifest and the anchors together accounted for.

The full command surface is in [Artisan commands](../09-operations/06-artisan-commands.md), and the
cadence to run these at is in [The verification playbook](07-the-verification-playbook.md) and
[Scheduling](../09-operations/07-scheduling.md).

---

## Verifying without the keys

The hash covers the **ciphertext**, not the plaintext. `CanonicalPayload::from()` decrypts nothing —
the `Audit` model has no decrypting cast, so the protected columns go into the digest exactly as the
database holds them. That is what lets a verification run on a machine holding no encryption key at
all and still prove the row is the one that was written. The trade is stated rather than hidden: the
chain proves the row was not touched, not what the value said. See
[Encryption and the keyring](../05-pipeline-and-security/03-encryption-and-the-keyring.md).

An external auditor is handed three things and nothing else:

1. the rows — a replica, a dump, or an export;
2. `integrity.signature.keys`, the **verifying** half of the ring;
3. the formula.

The formula is written out once, in [Canonicalization](03-canonicalization.md) — prefix, the
twenty-seven columns of `CanonicalPayload::COLUMNS`, the rendering rules, and the number policy an
outside implementation has to copy. The one thing worth repeating here is that the digest name comes
off **the row**, never from configuration, so entries written under an older `integrity.algorithm`
keep verifying under their own. See also [API stability](../99-reference/09-api-stability.md).

> 🔒 **Security.** Under `openssl`, `keys` holds public halves and `private_key` holds what the
> current identifier signs with — so a verification node can be given the whole ring and still be
> unable to sign anything. Under `hmac`, one secret does both: whoever can verify can forge. Handing
> an auditor an HMAC secret hands them the ability to write signatures. If the point of the exercise
> is proving something to a third party, sign with `openssl` — see [Signing the chain](04-signing.md).

> 🧪 **Verify it.** On a replica with the keyring configured and `private_key` unset:
> `php artisan sentinel:verify --depth=entries` — exit 0 with the signature tally showing `signed`
> is the strongest statement the package makes about a trail.

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `Sentinel::verifyIntegrity()` reports intact while `sentinel:verify` exits 1 on the same stream | the facade method returns only the chain result; the forged signature lives in the `StreamVerification` it discards | read the per-stream `StreamVerification` out of `verifyEverything()`, or listen for `IntegrityVerificationFailed` |
| "Entries verified" drops sharply after the first anchoring run | the shallow depths read only the tail; `checked` counts what was **read** | publish `checked`, `covered` and `archived` as three numbers and never a total |
| A row an operator knows was edited passes `--depth=roots` | the fold is over the stored `hash` column, and the edit left `hash` alone | any question about content is `--depth=entries`; the shallow depths never rehash |
| `$audit->verifyIntegrity()` returns `false` on an entry you redacted yourself | a tombstone no longer reproduces the hash it carries, which is what that method has always meant | ask `$audit->verifyContent()`; `ContentState::Redacted` is a declared act, `Altered` is a finding |
| A scheduled `sentinel:verify` exits 2 on a host where auditing was "turned off" with `ledger.default = null` | `NullLedger` does not implement `EnumeratesStreams`, so a run with no `--stream` is refused | name a `--stream`, or do not schedule the maintenance commands on that host |
| `--from=yesterday` verifies the whole stream and exits 0 | a non-numeric numeric option reads as **absent**, not as zero | check the values you pass; a bound that silently disappears answers a wider question than the one asked |
| `sentinel:verify --stream=x --from=1 --depth=roots` exits 2 with a warning | a range is what the entries depth answers; the shallow walks cover whatever the anchors cover | drop the range, or drop back to `--depth=entries` |
| `IntegrityVerificationFailed` fires again on every scheduled run for the same old break | the event is dispatched at the point of rupture on each walk; nothing deduplicates it | deduplicate in the listener on `(stream, sequence, reason)` |
| The `audit_id` in a `checkpoint_mismatch` alert matches no row in `sentinel_audits` | for that case `auditId` carries the anchor's **root hash** and `sequence` its `sequence_from` | look it up in `sentinel_checkpoints`, not in the trail |
| Deleting the first anchor of a stream reports `CheckpointMismatch` at the *second* anchor's start, with `covered = 0` | the anchor walk requires the first anchor to start at sequence 1 and each next to start where the previous ended; a hole aborts before any range is credited | read it as "the anchor chain has a hole", not as "this anchor is bad" |
| A stream's `redacted()` reads 0 at `--depth=anchors` but non-zero at `--depth=entries` | the shallow walk counts only the tail; it never opened the anchored entries | count redactions from the deep walk |
| `--projections` reports nothing on a relation entry you know was tampered with | the entry is redacted, and redacted entries are skipped rather than compared | compare against a backup; the projection check cannot answer for a tombstone |

---

## ✅ Best practices

✅ **Do** — branch a cron on the exit code, not on the text. Three codes mean one thing each, and
they are the frozen part of the surface.

```bash
php artisan sentinel:verify --depth=roots
case $? in
  0) exit 0 ;;              # sound, including an unsigned trail and declared redactions
  1) notify "integrity finding — a human must look" ;;
  2) notify "verification could not run — retry or page the operator" ;;
esac
```

❌ **Don't** — grep the output for "BROKEN". The strings come out of `resources/lang`, so they
change with the application's locale and a Spanish-locale worker silently stops matching.

```bash
php artisan sentinel:verify | grep -q BROKEN && notify "broken"   # false negative under es
```

✅ **Do** — read `checked`, `covered` and `archived` as three separate facts. They are kept apart
precisely so a reader can see which of the two a number came from.

```php
$report = Sentinel::verifyEverything();

$metrics->gauge('sentinel.checked',  $report->checked());   // read and rehashed
$metrics->gauge('sentinel.covered',  $report->covered());   // taken on an anchor's word
$metrics->gauge('sentinel.archived', $report->archived());  // stepped over, no longer here
```

❌ **Don't** — sum them into one "entries verified" number. It hides the only distinction the report
exists to draw.

```php
$metrics->gauge('sentinel.verified', $report->checked() + $report->covered()); // a lie
```

✅ **Do** — use the deep walk whenever the question is about content, and treat a range reported
`anchored` as unread.

```php
$roots = Sentinel::verifyRoots('tenant:acme');

// The keys are strings: 'anchored', 'archived', 'absent'. CheckpointState is @internal.
$anchoredRanges = $roots->anchors['anchored'] ?? 0;

if ($anchoredRanges > 0) {
    // $roots->covered entries were never opened. If the question is about what an entry
    // says, the deep walk is the only depth that rehashes.
    $deep = Sentinel::verifyIntegrity('tenant:acme');
}
```

❌ **Don't** — treat a clean `verifyAnchors()` as proof that the entries are unchanged. Not one
anchored row was opened.

```php
if (Sentinel::verifyAnchors('tenant:acme')->isIntact()) {
    $auditor->certify('every entry is unmodified'); // false: nothing under an anchor was read
}
```

✅ **Do** — read the two signature tallies apart, and alert only on `Invalid`.

```php
use ElPandaPe\Sentinel\Enums\SignatureState;

$entries = $report->signatures();        // are the entries somebody read attested?
$anchors = $report->anchorSignatures();  // are the anchors standing in for the rest?

$forged = ($entries[SignatureState::Invalid->value] ?? 0)
        + ($anchors[SignatureState::Invalid->value] ?? 0);
```

❌ **Don't** — page on `Unsigned` or `UnknownKey`. A trail written before signing was switched on is
sound, and a key nobody holds is a verdict the verifier is not entitled to give.

```php
$broken = ($entries[SignatureState::Signed->value] ?? 0) !== $report->checked(); // pages on a
                                                                                // sound trail
```

✅ **Do** — get a single stream's signature verdict from the report or the event, since
`verifyIntegrity()` will not give you one.

```php
use ElPandaPe\Sentinel\Integrity\StreamVerification;

$verification = collect(Sentinel::verifyEverything()->streams)
    ->first(fn (StreamVerification $stream): bool => $stream->stream() === 'tenant:acme');

$verification?->break()?->reason;   // SignatureMismatch shows up here
```

❌ **Don't** — build a signature alert on the facade's deep walk.

```php
if (Sentinel::verifyIntegrity('tenant:acme')->isIntact()) {
    // true even when an entry in that stream carries a forged signature
}
```

✅ **Do** — run `--projections` on its own cadence and treat a divergence as an index problem.

```bash
php artisan sentinel:verify --depth=anchors --projections
```

❌ **Don't** — read a `projection_mismatch` as a broken chain, or restore the whole trail from
backup because of one. The chain is intact; `sentinel_audit_relations` is outside the hash.

---

**See also:** [The hash chain](01-the-hash-chain.md) · [Streams](02-streams.md) · [Canonicalization](03-canonicalization.md) · [Signing the chain](04-signing.md) · [Checkpoints and anchors](05-checkpoints-and-anchors.md) · [The verification playbook](07-the-verification-playbook.md) · [The integrity model](../01-concepts/04-the-integrity-model.md) · [Artisan commands](../09-operations/06-artisan-commands.md) · [Exit codes](../99-reference/07-exit-codes.md) · [Enums](../99-reference/04-enums.md) · [Monitoring and troubleshooting](../09-operations/08-monitoring-and-troubleshooting.md)
