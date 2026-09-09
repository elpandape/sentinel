# 🔐 Checkpoints and anchors

> An anchor is a signed root over a fixed window of one stream, chained to the anchor before it. This
> page is what one is, how the fold is built, how to emit them without reading ten million rows by
> accident, and the moment they stop being an optimisation and become the only evidence you have.

**On this page:** [What an anchor is](#what-an-anchor-is) · [The fold](#the-fold) ·
[The window](#the-window) · [Emitting anchors](#emitting-anchors) ·
[The first run over an existing trail](#-the-first-run-over-an-existing-trail-is-unbounded) ·
[What anchors buy](#what-anchors-buy) · [When an anchor becomes the evidence](#when-an-anchor-becomes-the-evidence) ·
[Anchors fold, they do not tree](#anchors-fold-they-do-not-tree) · [Reading and exporting anchors](#reading-and-exporting-anchors) ·
[Engine notes](#-engine-notes) · [Pitfalls](#-pitfalls) · [Best practices](#-best-practices)

---

## What an anchor is

Verifying a chain means walking it. A stream that has been running for two years is a stream whose
verification reads two years of rows. An anchor is the way out of that: one row that records the root
a fixed range of entries folds to, signed, so a verification can walk *anchors* instead of *entries*
and still say something about the ranges it never opened.

Anchors live in `sentinel_checkpoints` (`tables.prefix` + `tables.checkpoints`), one row per range:

| Column | Type | What it holds |
|---|---|---|
| `id` | `char(26)` | ULID. An anchor is meant to survive being moved between databases. |
| `stream` | `string(64)` | The chain this range belongs to. Anchors never span two streams. |
| `sequence_from` | `unsignedBigInteger` | First sequence of the range, inclusive. |
| `sequence_to` | `unsignedBigInteger` | Last sequence of the range, inclusive. |
| `root_hash` | `char(64)` | What the range folds to. |
| `algorithm` | `string(32)` | The construction that produced the root — `fold-sha256` for everything this build writes. |
| `signature` | `text`, nullable | The signature over `root_hash`. `NULL` when nobody was signing. |
| `key_id` | `string(64)`, nullable | Which key made that signature. `NULL` alongside a `NULL` signature. |
| `created_at` | `dateTime(6)` | Microsecond precision, so two anchors of the same millisecond still order. |

Two indexes: a unique one on `(stream, sequence_from)`, and one on `(stream, sequence_to)` — both
reads that matter come off the far end of a stream's anchors.

What the row does **not** contain is as important as what it does:

- **No copy of any entry.** Only the root. An anchor cannot be used to reconstruct what an entry said.
- **No column for the previous anchor.** The previous root goes *into* the fold; which anchor it was
  is derived from the range, because the ranges are contiguous windows — the previous anchor is the
  row whose `sequence_to` is this one's `sequence_from` minus one.
- **No link back from `sentinel_audits`.** Nothing in the entry table references an anchor. Anchors
  stand outside the chain they attest, which is why `AuditCheckpoint` carries none of the immutability
  guards `Audit` does. What stands in for a guard is the signature.

> 📌 **Note.** Anchoring is optional and ships off. Chaining is not: every entry is linked whatever
> your configuration says. See [The hash chain](01-the-hash-chain.md).

---

## The fold

A root is built by digesting a domain-separating prefix and then folding the hash of every entry of
the range into it, one at a time, in ascending sequence. `\x1f` (ASCII unit separator) joins the
prefix parts, the same separator the entry hash uses.

```
root₀ = H( "fold-<algorithm>" ⑴ stream ⑴ sequence_from ⑴ sequence_to ⑴ (previous root ?? "") )
rootᵢ = H( rootᵢ₋₁ ⑴ hashᵢ )
```

`H` is `integrity.algorithm` at the time the anchor was written, recorded in the row as
`fold-<algorithm>`. `hashᵢ` is the `hash` column of the entry — folding reads two columns per row,
canonicalizes nothing and decrypts nothing, however heavy the entries are.

Three constraints shaped that formula, and each one is a specific attack it refuses:

| Constraint | What goes into the prefix | What it stops |
|---|---|---|
| Domain separation | the construction name, the stream, both ends of the range | A range of one colliding with the entry it contains, and the same entries under another span or another stream landing on the same root. It is why RFC 6962 §2.1 prefixes leaves and nodes — the ambiguity is unfixable once the first anchor is written. |
| Linkage | the previous anchor's root | Reissuing one anchor to cover a rewritten range. Contiguous integers are not linkage; without the previous root, a rewritten range plus a reissued anchor is a history that agrees with itself. With it, reissuing one anchor obliges reissuing every anchor after it. |
| Order | the chained fold itself, rather than a sum or a set | The same entries reordered folding to the same root. A root cannot be reproduced from part of its range either. |

### Reproducing a root outside the package

An anchor is checkable with `hash()` and nothing else. This reproduces exactly what
`Integrity\Fold::root()` writes:

```php
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Models\AuditCheckpoint;

$anchor = AuditCheckpoint::query()
    ->where('stream', 'tenant:acme')
    ->where('sequence_from', 1)
    ->firstOrFail();

$previous = AuditCheckpoint::query()
    ->where('stream', $anchor->stream)
    ->where('sequence_to', $anchor->sequence_from - 1)
    ->first()?->root_hash;

$digest = substr($anchor->algorithm, strlen('fold-'));   // 'sha256'

$root = hash($digest, implode("\x1f", [
    $anchor->algorithm,
    $anchor->stream,
    $anchor->sequence_from,
    $anchor->sequence_to,
    $previous ?? '',
]));

foreach (
    Audit::query()
        ->where('stream', $anchor->stream)
        ->whereBetween('sequence', [$anchor->sequence_from, $anchor->sequence_to])
        ->orderBy('sequence')
        ->pluck('hash') as $hash
) {
    $root = hash($digest, $root."\x1f".$hash);
}

hash_equals($anchor->root_hash, $root);   // true
```

> 🧪 **Verify it.** The package holds a frozen reference chain and asserts its roots are reproducible
> *without going through the package at all* — `tests/Integrity/FoldTest.php`, "reproduces the frozen
> root without going through the package". The snippet above is that test, spelled with real models.

> ⚠️ **Warning.** `root_hash` is `char(64)`, sized for a 64-hex-character digest. Nothing validates
> the width of `integrity.algorithm`: setting `sha512` produces a 128-character root that MySQL may
> truncate and PostgreSQL will refuse. Stay on digests that fit — `sha256` and friends.

---

## The window

A window is a **fixed** `integrity.checkpoints.every` entries. It is not "whatever is pending":

- The next window is derived as `max(sequence_to) + 1 .. + every`, under the emission lock.
- It is emitted only once the stream already holds the entry that *ends* it. `Checkpoints::pending()`
  is one indexed seek asking whether the entry at that sequence exists.
- The range is read before anything is written and the count has to fill the window exactly. A short
  read — the entries are not there yet, or there is a hole inside the range — anchors nothing.
- The trailing incomplete window is never anchored. A verification walks it entry by entry, which is
  what it would have done for the whole stream anyway.

The reason is reproducibility. Under a "whatever is pending" rule the two ends of a range would
depend on *when* the emission happened to run, and a root that a second run cannot reproduce is not
evidence of anything.

### Changing `every` later

Existing anchors keep the width they were written with — `sequence_to` records it — and the
derivation stays contiguous either way, so old and new widths sit end to end without a hole.

But `pending()` probes at `reach() + every`, using the **current** `every`:

- **Lowering it** takes effect on the next window. Narrower windows locate a break faster and cost
  more anchor rows.
- **Raising it** stalls emission until the wider window fills. A stream whose unanchored tail is
  shorter than the new `every` quietly anchors nothing until it grows — `sentinel:checkpoint` will
  keep reporting "Nothing left to anchor" and exiting 0, which is easy to read as "up to date".

`every` is floored at 1 in code: a window of zero would anchor nothing, forever, while reporting that
anchoring was on.

---

## Emitting anchors

There are exactly two routes, and they are not equivalent.

### The command (recommended)

```bash
php artisan sentinel:checkpoint              # every stream the ledger can name
php artisan sentinel:checkpoint --stream=tenant:acme
```

It anchors every complete window each stream still owes, in order, printing stream / from / to / root
for each, then `Anchored N ranges.`

| Exit | Meaning |
|---|---|
| `0` | Anchored something — or there was nothing to anchor, which is the ordinary outcome on a schedule and is not a failure. |
| `2` | The run could not happen. Any `Throwable` becomes `Nothing was anchored: <reason>`. |

There is no exit 1: this command has no "bad finding" to report.

> 📌 **Note.** `sentinel:checkpoint` anchors regardless of `integrity.checkpoints.enabled`. That flag
> governs the threshold route only. The command works on a default installation with anchoring
> switched off.

With no `--stream`, the command asks the resolved ledger to name its chains and refuses one that
cannot: `NullLedger` does not implement `Contracts\EnumeratesStreams`, so a null-ledger installation
exits 2 rather than reporting an empty success.

Each window is its own transaction. An emission interrupted halfway leaves anchors that are contiguous
as far as they go, which is the only state the next run can carry on from.

### Scheduling it

The package registers commands and puts nothing on your scheduler. The schedule belongs to the
application:

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('sentinel:checkpoint')->hourly();
Schedule::command('sentinel:verify --depth=roots')->dailyAt('03:00');
```

Pick the cadence from `every` and your write rate: anchoring hourly on a stream that writes fifty
entries an hour, at the default `every` of 1000, emits one anchor about every twenty hours and the
schedule costs one indexed seek per stream per run in between. See
[Scheduling](../09-operations/07-scheduling.md).

### ⚠️ The first run over an existing trail is unbounded

Every command in this package that walks the trail is bounded per pass except this one. There is no
`--limit`, and there is deliberately no `--range` — a hand-named `sequence_from` can leave a hole or
an overlap that the unique index on `(stream, sequence_from)` cannot see, and contiguity is the one
property `verifyAnchors()` depends on.

`Checkpoints::issue()` loops until there is nothing left to anchor. On a stream that already holds ten
million entries, at `every = 1000`, that is **ten thousand anchors in one invocation**, each a fold
over its window — so the first pass reads the whole trail once.

> ⚠️ **Warning.** Do not let the scheduler discover this for you. A first run started by `->hourly()`
> can hold a worker for as long as it takes to read the whole trail, and the run after it will start
> before the first one finishes.

The safe procedure:

**1. Find out what it will emit.** A stream that has never been pruned starts at sequence 1, so the
count is arithmetic:

```php
use ElPandaPe\Sentinel\Models\Audit;

$every = config('sentinel.integrity.checkpoints.every');

foreach (Audit::query()->distinct()->orderBy('stream')->pluck('stream') as $stream) {
    $tail = (int) Audit::query()->where('stream', $stream)->max('sequence');

    printf("%s: %d entries, ~%d anchors on the first run%s", $stream, $tail, intdiv($tail, $every), PHP_EOL);
}
```

**2. Run it by hand, one stream at a time, off the schedule.** `--stream=` bounds the work to a chain
you have just sized:

```bash
php artisan sentinel:checkpoint --stream=tenant:acme
```

**3. Check the result before moving on:**

```bash
php artisan sentinel:verify --stream=tenant:acme --depth=anchors
```

**4. Only then add `sentinel:checkpoint` to the scheduler.** From that point every run has at most
`writes since the last run ÷ every` windows to emit.

### The threshold route

Setting `integrity.checkpoints.enabled` to `true` makes `DatabaseLedger` anchor every stream it just
wrote to, **after** the sealing transaction commits — never inside it. Folding a window means reading
it, and holding the writer's lock of a stream across that read would serialize every other writer of
it behind whoever happened to cross the threshold.

This is the route the package steers away from, and the cost is why:

| Anchoring variant | Writes | Per write (µs) | Δ vs unanchored |
|---|---|---|---|
| not anchored | 1000 | 2204.6 | — |
| every 1000 entries | 1000 | 2428.8 | +10.2 % |
| every 100 entries | 1000 | 2448.3 | +11.1 % |
| every 100 entries, HMAC signed | 1000 | 2468.1 | +12.0 % |

Measured by `benchmarks/bench.php` (the `ANCHOR_ITERATIONS` block), one thousand writes per variant,
on one machine, against the same pass with anchoring off. Re-run it on your own hardware before you
plan around it: the unanchored baseline itself moves several percent between passes, which is the
resolution these deltas are readable at.

Other things the threshold route does:

- It anchors **per stream written**, once per `writeMany()` call, over the unique streams in the batch.
- An emission that fails is **not swallowed**: the entries are already sealed and are not at risk, but
  an installation that believes it is anchoring and is not would have no way to find out. Under
  [failure policy](../09-operations/05-failure-policy.md) rules, on a deferred write that becomes an
  announced-and-logged failure rather than a propagated one.
- In fanout, only the sequencing primary anchors. A secondary destination takes an entry the primary
  already sealed and does not emit. See [Fanout](../11-extending/05-fanout.md).
- Inside an application's own transaction there is nothing it can do about ordering: the commit
  belongs to whoever opened it.

> 📌 **Note.** [Compliance mode](../08-lifecycle/05-compliance-mode.md) requires
> `integrity.checkpoints.enabled` to be `true` — `Compliance\Requirements::enforce()` refuses to boot
> without it. That switches the threshold route on. Scheduling `sentinel:checkpoint` as well is still
> worth doing: the threshold only fires on a write, so a stream that goes quiet mid-window stays
> unanchored until the next write arrives.

### Concurrency

Two emitters on one stream cannot produce a hole or an overlap:

- `Integrity\CheckpointGate` serializes them before they read where the anchors end. It locks the
  *anchors* of a stream (`checkpoint:<stream>` on PostgreSQL), never the stream itself — taking the
  lock the writers take would put emission straight back inside the write path it was moved out of.
- The unique index on `(stream, sequence_from)` is the final arbiter. A loser re-reads the tail and
  takes the window after the one it lost.
- It makes at most **three** attempts and then rethrows the `UniqueConstraintViolationException`. Three
  consecutive losses on one stream surface as an exception — exit 2 on the command route, and on the
  threshold route it reaches the caller of the write.

The index does not enforce contiguity, and cannot: it rejects two anchors starting in the same place,
and sees neither a hole (`[1,200]` then `[301,400]`) nor an overlap between two anchors that start in
different places. Contiguity is derived in code under the lock and **proved after the fact** by
`verifyAnchors()`.

---

## What anchors buy

### Cheap verification

Anchors add two shallower verification depths. They do not change what the deep one proves — an
installation that starts anchoring never verifies less than it did the day before.

| Depth | Reads | Proves | Does not prove |
|---|---|---|---|
| `verifyAnchors()` · `--depth=anchors` | the anchors, plus the tail no anchor covers | the anchors are a contiguous, signed chain from sequence 1, and the tail links | anything about the current content of an anchored entry — no entry of an anchored range is opened |
| `verifyRoots()` · `--depth=roots` | the same, plus `(sequence, hash)` of every anchored range | no hash was rewritten or reordered, and *where* — a range that no longer folds back is then walked entry by entry so the report names the entry | that an entry's canonical columns did not change while its `hash` column stayed put |
| `verifyIntegrity()` · `--depth=entries` | every entry | everything the chain covers: content, link, order | — |

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

$anchors = Sentinel::verifyAnchors('tenant:acme');

$anchors->chain->checked;   // entries actually read — the unanchored tail
$anchors->covered;          // entries taken on an anchor's word
$anchors->anchors;          // ['anchored' => 41]
$anchors->anchorSignatures; // ['signed' => 41] — kept apart from entry signatures
$anchors->isIntact();       // nothing came back wrong. NOT "everything was read"
```

`checked` and `covered` are published side by side and are never summed. A range under a valid anchor
is reported **anchored**, never **intact** — `intact` is reserved for what was read.

A stream nobody has anchored comes back as `['absent' => 1]` and is walked whole: the same answer the
deep walk gives, having paid for it. Those keys are the string values `'anchored'`, `'archived'` and
`'absent'`; the enum behind them is internal, so read the strings.

Full treatment in [Verification](06-verification.md) and
[The verification playbook](07-the-verification-playbook.md).

### Evidence that survives pruning

`sentinel:prune` retires **anchored windows**, never loose entries, because a window is folded whole
and a partly emptied one could never reproduce its root again. A stream with no anchors reports
`RetentionHold::Unanchored` and releases nothing:

> Stream `tenant:acme` has no anchors. A range is only retired while an anchor still answers for it,
> so anchor the history before pruning it.

Every window is refolded against its recorded root **before** a single row is touched. A range that no
longer folds stops the run on that stream and leaves every row where it is, so a prune can never be
the thing that destroys the evidence of a tampering. A prune removes entries, labels, relation lines
and orphaned transaction headers — it never removes an anchor.

See [Retention and pruning](../08-lifecycle/01-retention-and-pruning.md).

---

## When an anchor becomes the evidence

This is the sentence to take away from the page.

**Before you have ever retired a range**, an anchor is a shortcut and nothing more. Its root is
derivable from the entries again, because they are all still there. Losing `sentinel_checkpoints`
costs you speed:

- `verifyAnchors()` and `verifyRoots()` report `['absent' => 1]` and walk the stream whole.
- `verifyIntegrity()` is completely unaffected.
- `sentinel:prune` stops offering anything, with `RetentionHold::Unanchored`.
- `sentinel:checkpoint` will happily re-emit the whole trail from sequence 1 again.

**After the first retirement**, the same table is the only evidence for every range that has left.
A verification steps over an absence only when **two** things account for it at once: the manifest in
`sentinel_archives` says the range was retired, *and* the anchors reach past it. Neither alone will
do — nothing in `sentinel_archives` is hashed or signed, so on its own it would make "delete the rows,
then insert one row" a supported way of laundering a gap.

So with the anchors gone after a prune, `Checkpoints::reach()` answers 0, the absence is never
accounted for, and the walk stops at the first missing sequence with `IntegrityBreak::SequenceGap`.
`sentinel:verify` exits 1. **Permanently** — the entries the anchors folded over are gone, so nothing
can recompute them, and no command in the package rebuilds an anchor over a range that is not there.

| | Losing `sentinel_checkpoints` costs | Recoverable? |
|---|---|---|
| Before the first retirement | the shortcut: shallow depths degrade to a full walk; pruning holds | Yes — re-run `sentinel:checkpoint` |
| After the first retirement | the only account of what is missing; every retired range reads as `sequence_gap` | No |

> ⚠️ **Warning.** Once you have pruned, back up `sentinel_checkpoints` with the same seriousness as
> `sentinel_audits`. A backup policy that covers the entries and skips the anchors is a policy that
> silently stops covering the trail the first time a range leaves.

Two more consequences of the same rule:

- **Anchor before you prune, not after.** An anchor is written from the entries; after they leave
  there is nothing to fold.
- **A range that folds to a *different* root is never excused by the manifest.** Only a root that
  cannot be recomputed *at all* qualifies as retired. A range whose entries are right there and have
  moved is a break, and one manifest insert must not be the price of hiding it.

---

## Anchors fold, they do not tree

An anchor is a chained fold, not a Merkle tree, and that is a decision rather than an omission.

**What you cannot do with one:** hand a third party a single entry plus a short proof that it belongs
to an anchored range. There are no inclusion proofs and no consistency proofs. To check one entry
against its anchor you need every hash in that window — `every` values, in ascending sequence — and
you refold the whole window.

**Why:** nothing in the package consumes an inclusion proof. A tree turns up exactly where a *remote
client that does not trust the log* asks about one record — Certificate Transparency, Trillian,
immudb, QLDB — and every one of those keeps a chain underneath the tree anyway. Git and RFC 5848 fold
for the same reason Sentinel does: a fold is O(1) in memory, one digest per row, and reproducible with
`hash()` alone by an auditor with no library.

**How the door stays open:** the construction travels in the prefix and in the `algorithm` column.
`Fold::digestOf()` recognises only names starting `fold-`; a future `merkle-sha256` would be written
under its own name without touching a row already written, and this build would report an anchor it
does not recognise as one it *cannot recompute* rather than one that failed.

That last part has a sharp edge today. An anchor whose `algorithm` this build does not know refolds to
`null`, and `null` is treated as benign **only** when the manifest agrees the range was retired. Over
live entries it is reported as `CheckpointMismatch` — a break, not a "not checked".

---

## Reading and exporting anchors

The value object `Integrity\Checkpoint` and the emitter `Integrity\Checkpoints` are both `@internal`
and outside the 1.0 freeze — do not reach for them from application code. What *is* public surface is
the Eloquent model:

```php
use ElPandaPe\Sentinel\Models\AuditCheckpoint;

$anchors = AuditCheckpoint::query()
    ->where('stream', 'tenant:acme')
    ->orderBy('sequence_from')
    ->get(['stream', 'sequence_from', 'sequence_to', 'root_hash', 'algorithm', 'signature', 'key_id', 'created_at']);
```

Those eight columns are everything a verifier needs and nothing it does not. Whoever holds the
verifying half of `key_id` can check the signature over `root_hash` without reaching the trail at all,
which is the point of publishing one — to another service, to WORM storage, to a third-party
timestamper.

> 📌 **Note.** `sentinel:export` and `Compliance\Export` carry **entries**, not anchors. There is no
> shipped command that emits anchors in an interchange format; publishing them is a query away, and
> the query above is it. See [Export and rekey](../08-lifecycle/06-export-and-rekey.md).

### Signing anchors

`Checkpoints::write()` signs the root with `Signers::current()` — the same signer, the same key ring,
the same `key_id` recording as an entry's hash. A signer that returns the empty string leaves **both**
columns `NULL`: an anchor nobody signed says so, rather than storing a signature that attests to
nothing.

An unsigned anchor is a row anyone with write access can reissue. Anchoring without signing buys
verification speed and no trust at all. And signing is not retroactive: switching
`integrity.signature.enabled` on signs what is written from that moment, and nothing re-signs an
anchor that already exists. See [Signing the chain](04-signing.md).

### Configuration

| Key | Default | Effect | When to change |
|---|---|---|---|
| `integrity.checkpoints.enabled` | `false` | Threshold emission on the write path. Does **not** gate `sentinel:checkpoint`. Read as strictly `=== true`, so a config published before the key existed reports "off" instead of crashing. | Leave off and anchor from the scheduler. Compliance mode requires it on. |
| `integrity.checkpoints.every` | `1000` | The fixed window one anchor covers. Floored at 1. Must be an `int` or `null` — anything else throws `ConfigurationException`. | Smaller locates a break faster and costs more rows; larger, the reverse. |
| `integrity.algorithm` | `sha256` | The digest a new anchor folds with, recorded as `fold-<algorithm>`. Read back off the anchor row on verification, never from config. | Almost never, and never to a digest wider than 64 hex characters. |
| `tables.checkpoints` | `checkpoints` | The table name, after `tables.prefix`. | Only to fit an existing convention, and before the migration runs. |

Full list in [Configuration](../99-reference/02-configuration.md); columns in
[Schema](../99-reference/03-schema.md).

---

## 🐘 Engine notes

- **PostgreSQL** — emission takes `pg_advisory_xact_lock(hashtext('checkpoint:'||stream))`, a
  deliberately *different* lock name from the one the chain's writers take. PostgreSQL takes no row
  lock on a row that is not there yet, so a stream with no anchors has to be locked by name.
- **MySQL** — no advisory lock is issued: `lockForUpdate()` on the last anchor of the stream is enough,
  and InnoDB covers the case of a stream that has no anchors yet. A second emitter of the same stream
  blocks on the first, which is asserted against a real second connection.
- **SQLite** — `lockForUpdate()` is discarded without error; the engine serializes writes for the whole
  database on its own. An in-memory database cannot have a second connection, so the concurrent
  emission tests skip under `make ci` and run only under `make test-dbs`.
- **All three** — anchor emission, anchor reading, concurrent emission without holes or overlaps, and
  the golden dataset reproducing its frozen roots are all verified on SQLite, MySQL 9 and
  PostgreSQL 16. See [Choosing an engine](../10-database-engines/01-choosing-an-engine.md).

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `sentinel:checkpoint` says "Nothing left to anchor" on a stream with thousands of entries | The window is only emitted once the stream holds the entry that *ends* it. The unanchored tail is shorter than `every`. | Lower `integrity.checkpoints.every`, or wait. Raising `every` mid-life stalls emission the same way, silently. |
| The first scheduled run hangs a worker for hours | The first pass over an existing trail anchors every complete window at once and reads the whole trail. There is no `--limit`. | Run it by hand per `--stream` first; add it to the schedule only afterwards. |
| `sentinel:verify --depth=anchors` reports `checkpoint_mismatch` at a sequence far from 1, with `covered = 0` | An anchor was deleted. Contiguity from sequence 1 is required, and emission derives the next window from `max(sequence_to)` — it never backfills a hole behind it. | Restore the anchor from a backup. Nothing in the package rebuilds one in the middle of a chain. |
| On `checkpoint_mismatch`, looking the reported `auditId` up in `sentinel_audits` finds nothing | For that break, `auditId` carries the anchor's **root hash** and `sequence` its `sequence_from`. | Look it up in `sentinel_checkpoints` by `root_hash`. |
| A previously clean pruned stream now reports `sequence_gap` and exits 1 | `sentinel_checkpoints` was lost or truncated after a retirement. An absence needs the manifest *and* `reach() >= to`. | Restore the table from a backup. There is no recomputation path once the entries are gone. |
| `--depth=roots` reports a range `archived` where `--depth=anchors` reported it `anchored` | `verifyAnchors()` does not refold, so it cannot tell a retired range from a live one. | Read the range tally off `--depth=roots` when the distinction matters. |
| A retired range shows up in `covered` on the shallow depths but in `archived` on the deep one | The shallow walks credit everything under an anchor to `covered`; only the entry walk meets the absence and counts it as `archived`. | Never sum the two, and say which depth a dashboard number came from. |
| Anchor rows have `signature` and `key_id` `NULL` | Signing was off (or `signer: 'null'`) when they were emitted. An empty signature is stored as `NULL`, never as a claim. | Turn signing on before anchoring. Signing is not retroactive and existing anchors are never re-signed. |
| `UniqueConstraintViolationException` out of a write, or exit 2 from the command | Three consecutive lost emission races on one stream. The unique index on `(stream, sequence_from)` is the arbiter and the retry gives up at three. | Run one emitter per stream. Do not run the threshold route and a tight schedule against the same hot stream. |
| `ConfigurationException` at boot naming `integrity.checkpoints.every` | The key was given a string — typically `env()` without a cast. | Cast it: `(int) env('SENTINEL_CHECKPOINT_EVERY', 1000)`. |
| A `root_hash` looks truncated, or PostgreSQL refuses the insert | `integrity.algorithm` names a digest wider than 64 hex characters; `root_hash` is `char(64)`. | Stay on 64-hex digests. Nothing validates the width. |
| `sentinel:checkpoint` with no `--stream` exits 2, "Nothing was anchored" | The resolved ledger cannot name its chains — `NullLedger` does not implement `Contracts\EnumeratesStreams`. | Pass `--stream=`, or point the configuration at a ledger that enumerates. |

---

## ✅ Best practices

✅ **Do** — run the very first `sentinel:checkpoint` by hand, per stream, before it ever reaches the
scheduler. It is the one command in the package with no per-pass bound.

```bash
php artisan sentinel:checkpoint --stream=tenant:acme
php artisan sentinel:verify --stream=tenant:acme --depth=anchors
# ...then, and only then, add it to routes/console.php
```

❌ **Don't** — add it to `->hourly()` on an installation that already has history. At `every = 1000`
a ten-million-entry stream emits ten thousand anchors on that first run and reads the whole trail,
and the next scheduled run starts before it has finished.

```php
// routes/console.php — on day one of a trail that is already two years old
Schedule::command('sentinel:checkpoint')->hourly();
```

---

✅ **Do** — sign your anchors. Turn signing on *before* anchoring, so every anchor you emit carries an
attestation from the start.

```php
'integrity' => [
    'signature'   => ['enabled' => true, 'signer' => 'hmac', 'key_id' => 'default'],
    'checkpoints' => ['enabled' => false, 'every' => 1000],
],
```

❌ **Don't** — anchor first and plan to sign later. Nothing re-signs an existing anchor, and an
unsigned anchor is a row anybody with write access can reissue — it buys verification speed and no
trust whatsoever.

---

✅ **Do** — treat `sentinel_checkpoints` as part of the evidence in your backup policy the moment you
start pruning, and prove it restores.

```bash
# after any restore drill, on a stream that has retired ranges
php artisan sentinel:verify --stream=tenant:acme --depth=roots   # expect exit 0
```

❌ **Don't** — assume the entry table is the trail. After the first retirement the anchors are the only
thing that can tell a range you retired from rows somebody deleted; without them every retired range
reports `sequence_gap` and there is no way back.

```bash
# a backup that covers the entries and skips the anchors
pg_dump --table=sentinel_audits --table=sentinel_archives app > trail.sql
```

---

✅ **Do** — read `checked`, `covered` and `archived` as three different facts, and say which depth
produced them.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

$report = Sentinel::verifyEverything();

$report->checked();   // entries read and rehashed
$report->covered();   // entries an anchor answered for — nobody read these
$report->archived();  // entries stepped over: no longer in the ledger
```

❌ **Don't** — add `covered` to `checked` and call the result "entries verified". A range under a valid
anchor was not read; `verifyRoots()` folds the stored `hash` column, which somebody who edits a
canonical column and leaves `hash` alone does not touch.

```php
$verified = $report->checked() + $report->covered();   // a number that means nothing
```

---

✅ **Do** — anchor before you prune, and let `sentinel:prune` refuse when you have not. The refusal is
the design working.

```bash
php artisan sentinel:checkpoint --stream=tenant:acme
php artisan sentinel:prune --stream=tenant:acme --dry-run
```

❌ **Don't** — reach for the internals to force an anchor over a range on your own terms. There is no
`--range` on purpose: a hand-named `sequence_from` can leave a hole or an overlap the unique index
cannot see, and contiguity is exactly what `verifyAnchors()` relies on.

```php
use ElPandaPe\Sentinel\Integrity\Checkpoints;   // @internal, outside the 1.0 freeze

app(Checkpoints::class)->issue('tenant:acme');
```

---

✅ **Do** — pick `every` from how precisely you want a break located, then leave it alone. Existing
anchors keep their own width, so the two sit end to end without a hole.

```php
'checkpoints' => ['enabled' => false, 'every' => 500],   // a break is located to within 500 entries
```

❌ **Don't** — raise `every` on a live installation and assume anchoring continues. `pending()` probes
at `reach() + every` with the *current* value, so a stream whose tail is shorter than the new window
anchors nothing and keeps reporting exit 0.

```php
'checkpoints' => ['enabled' => true, 'every' => 50_000],   // silence, for the next 50k entries
```

---

**See also:** [The hash chain](01-the-hash-chain.md) · [Streams](02-streams.md) ·
[Signing the chain](04-signing.md) · [Verification](06-verification.md) ·
[The verification playbook](07-the-verification-playbook.md) ·
[Retention and pruning](../08-lifecycle/01-retention-and-pruning.md) ·
[Compliance mode](../08-lifecycle/05-compliance-mode.md) ·
[Artisan commands](../09-operations/06-artisan-commands.md) ·
[Scheduling](../09-operations/07-scheduling.md) · [Schema](../99-reference/03-schema.md) ·
[Configuration](../99-reference/02-configuration.md)
