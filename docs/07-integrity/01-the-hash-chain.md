# 🔐 The hash chain

> How Sentinel seals an entry, links it to the one before it, and exactly which kinds of tampering
> that link catches — and which it does not.

**On this page:** [Chaining has no switch](#chaining-has-no-switch) · [The formula](#the-formula) · [What the payload covers](#what-the-payload-covers) · [The algorithm comes off the row](#the-algorithm-comes-off-the-row) · [The link and the genesis entry](#the-link-and-the-genesis-entry) · [Sequence assignment](#sequence-assignment) · [payload_version](#payload_version) · [What the chain detects](#what-the-chain-detects-and-what-it-does-not) · [Computing a hash by hand](#computing-a-hash-by-hand) · [⚠️ Pitfalls](#️-pitfalls) · [✅ Best practices](#-best-practices)

---

## Chaining has no switch

Every entry Sentinel writes gets a `stream`, a `sequence`, a `previous_hash` and a `hash`. There is
no configuration key that turns that off, no mode in which an entry is written unlinked, and no
driver that is allowed to skip it — `Testing\LedgerContractTestCase` asserts a first entry with
`sequence` 1 and a null `previous_hash`, and every subsequent entry carrying the hash of the one
before it, for any implementation of the `Ledger` contract.

What *is* optional, and ships off, is everything built on top of the chain:

| Mechanism | Config key | Default | Covered elsewhere |
|---|---|---|---|
| Hash chain | — | always on | this page |
| Stream scoping | `integrity.stream` | `tenant` | [Streams](02-streams.md) |
| Signatures | `integrity.signature.enabled` | `false` | [Signing the chain](04-signing.md) |
| Anchors (checkpoints) | `integrity.checkpoints.enabled` | `false` | [Checkpoints and anchors](05-checkpoints-and-anchors.md) |

> 📌 **Note.** The chain is a *tamper-evidence* mechanism, not an access control. It proves that a
> row is the row that was written. It does not stop anyone from writing to the table, and it does
> not prove that what the row says was true when it was captured.

---

## The formula

`Integrity\Hasher::hash()` builds one string and digests it:

```
hash = algorithm(
    payload_version ␟ stream ␟ sequence ␟ (previous_hash ?? '') ␟ canonical(core)
)
```

`␟` is `Hasher::SEPARATOR`, the ASCII unit separator `\x1f`. It is there so the parts of the prefix
cannot run into one another: without it, `("a", 11)` and `("a1", 1)` would produce the same bytes
and therefore the same link. There is a test for exactly that pair in `tests/Integrity/HasherTest.php`.

The five parts, and where each comes from:

| Part | Column | Assigned by | Why it is in the prefix and not in the payload |
|---|---|---|---|
| `payload_version` | `payload_version` | `Ledger\EntryBuilder::PAYLOAD_VERSION` | It names the format the rest of the string is in. A format identifier inside the format it identifies is circular. |
| `stream` | `stream` | `Integrity\Stream::resolve()` | Two chains must not be able to produce the same hash. It is also why a stream is never renamed in place. |
| `sequence` | `sequence` | the ledger, under the write gate | Position is part of the fact. Without it two identical entries would be interchangeable. |
| `previous_hash` | `previous_hash` | the tail of the stream, or `null` | This is the link. `null` is rendered as the empty string, never as the literal `null`. |
| `canonical(core)` | 27 columns | the pipeline | RFC 8785 canonical JSON of the frozen column list. See [Canonicalization](03-canonicalization.md). |

The `hash` column itself is obviously not in the input, and neither is `signature`,
`signature_key_id`, `algorithm`, `created_at`, `capture_id`, `redacted_at`, `redaction_reason` or
`redacted_hash`. `tests/Integrity/CanonicalPayloadTest.php` asserts that absence directly, under the
name *"leaves out the columns that would make the hash circular or unstable"*.

The same `Hasher::hash()` runs on write (`Ledger\EntryBuilder::build()`) and on verification
(`Integrity\Content::of()`). There is one implementation, so there is nothing for a write path and a
verify path to disagree about.

---

## What the payload covers

`Integrity\CanonicalPayload::COLUMNS` is the frozen list of twenty-seven columns, and it is the only
enumeration of them anywhere in the package. A second list would be a second payload format, and the
two would agree right up until the day one of them did not.

| Group | Columns |
|---|---|
| Identity | `id`, `audit_type`, `event`, `severity` |
| Subject | `subject_type`, `subject_id`, `version` |
| Who | `actor_type`, `actor_id`, `impersonator_type`, `impersonator_id`, `tenant_id` |
| Correlation | `transaction_id`, `request_id`, `trace_id`, `span_id`, `source`, `source_audit_id` |
| Content | `context`, `before`, `after`, `changes`, `metadata`, `criteria`, `affected_rows` |
| Protection | `encryption` |
| Clock | `occurred_at` |

Two consequences of that list that surprise people:

- **`encryption` is inside it.** The hash is taken over the ciphertext, not the plaintext, which is
  what lets an auditor holding no encryption key reproduce it. Altering the `key_id` of a stored row
  breaks its hash, so a forged rotation is not something that can be done quietly. See
  [Encryption and the keyring](../05-pipeline-and-security/03-encryption-and-the-keyring.md).
- **`occurred_at` is inside it and `created_at` is not.** `occurred_at` is when the fact happened and
  never moves; `created_at` is when the entry settled and is not part of what the chain proves.

And what is deliberately outside:

| Outside the hash | Consequence |
|---|---|
| Labels (`sentinel_audit_tags`) | Classifying an old entry does not break its hash — and relabelling leaves no trace any verification can find. What has to be provable goes in `metadata`. See [Labels](../06-reading/06-labels.md). |
| Relation projection (`sentinel_audit_relations`) | Editing it leaves the chain perfectly intact. It is reported separately as `ProjectionMismatch`, never as a chain break. |
| `signature`, `signature_key_id` | A signature is taken *over* the hash, so it cannot be inside it. Writing them costs no `payload_version`. |
| `capture_id` | Idempotency metadata, not a fact about the audited event. |
| `redacted_at`, `redaction_reason`, `redacted_hash` | A tombstone has to be writable without invalidating the chain. See [Redaction and tombstones](../08-lifecycle/04-redaction-and-tombstones.md). |
| `created_at` | The ledger's own clock. |

---

## The algorithm comes off the row

`Hasher::digest()` is handed `$audit->algorithm` — the value stored in the row — and never
`config('sentinel.integrity.algorithm')`. Configuration governs what a **new** entry is stamped with
(`EntryBuilder::build()` reads `Config::integrityAlgorithm()` once, at write time) and nothing else.

That is the whole reason changing `integrity.algorithm` on a live installation does not invalidate
history: yesterday's rows keep verifying under the digest they recorded, today's use the new one.

An algorithm the runtime does not know throws rather than silently returning something:

```php
use ElPandaPe\Sentinel\Exceptions\ConfigurationException;

// Hasher::digest() — thrown on verification of a row whose `algorithm` is not in hash_algos()
ConfigurationException::unknown('integrity.algorithm', 'nonesuch', /* the accepted list */);
```

> ⚠️ **Warning.** `Config::integrityAlgorithm()` validates membership in `hash_algos()` and nothing
> else. It does **not** validate digest width, and `hash`, `previous_hash` and `root_hash` are all
> `char(64)` — sized for the 64 hex characters of sha256. Set `sha512` (128 characters) and the
> hasher computes it correctly and the column cannot hold it: truncated on a lax MySQL, refused on
> PostgreSQL. Use a 64-hex-character digest. The `algorithm` column is `varchar(16)`, so the name
> has a ceiling too.

---

## The link and the genesis entry

`previous_hash` holds the `hash` of the entry at `sequence - 1` **of the same stream**. The first
entry of a stream is the genesis entry: `sequence` 1, `previous_hash` `null`, rendered into the
prefix as the empty string.

The tail is read, not computed. `Ledger\StreamGate::tail()` issues one query — the highest
`sequence` of the stream, with its `hash`, `ORDER BY sequence DESC LIMIT 1 FOR UPDATE` — and returns
a `Ledger\StreamTail`. A stream nobody has written to yields `StreamTail::empty()`, which is
`(sequence: 0, hash: null)`, so the next entry takes 1 and links to nothing. No INSERT can compute
its own link, which is precisely why the tail has to be read before the row is built.

Because `previous_hash` is inside the prefix, it is covered by the row's own hash. Rewriting it on a
stored row is therefore caught on that row as a `HashMismatch`, not downstream as a broken link —
`tests/Integrity/ReferenceChainTest.php` calls this *"catches a rewritten link on the row that
carries it, because the hash covers it"*.

Verification holds one extra rule: an entry at `sequence` 1 that carries a non-null `previous_hash`
is a `LinkMismatch` with `checked` at 0. It claims to open a chain it does not open.

---

## Sequence assignment

Sequences are assigned by the ledger, inside the sealing transaction, per stream. They are dense and
monotonic: `writeMany()` consumes consecutive numbers for a batch, and the entry the batch produces
is already linked in memory before a single row is inserted.

The order in `Ledger\DatabaseLedger::chain()` is fixed:

1. Open a transaction.
2. Group the incoming `AuditData` by resolved stream.
3. For each stream, take the gate and read the tail.
4. Build every entry: assign `++$sequence`, set `previous_hash` to the running hash, seal, sign.
5. Insert all rows, then labels, then the relation projection.
6. Commit.

Anchoring, when enabled, runs **after** that transaction commits — folding a window means reading
it, and holding the writer's lock across that read would serialize every other writer of the stream.

### What serialises it, per engine

| Engine | What holds the stream | What happens under contention |
|---|---|---|
| PostgreSQL 16 | `select pg_advisory_xact_lock(hashtext(?))` on the stream name, taken before the tail read, plus `FOR UPDATE` | An advisory lock serialises whoever *asks* for it. A writer that inserts without asking can slip in between the tail read and the insert; the unique index on `(stream, sequence)` then rejects the loser, which re-reads the tail and takes the next number. |
| MySQL 9 (InnoDB) | The gap lock the `FOR UPDATE` takes over `(stream, sequence)` | The gap lock covers the first write of a stream too, so no advisory lock is needed, and it also blocks an outside writer that would land in the gap. It does not spill onto a neighbouring stream. |
| SQLite | Nothing — `lockForUpdate()` is discarded without error | The engine serialises writes for the whole database on its own. No `busy_timeout` tuning was needed. |

> 🐘 **Engine.** PostgreSQL takes no row lock on a row that is not there yet, which is why an empty
> stream has to be locked by name. That is the single reason `StreamGate` carries an engine branch
> at all.

### The retry, and its ceiling

`DatabaseLedger::attempt()` catches `UniqueConstraintViolationException` and replays the batch, at
most `MAX_ATTEMPTS` (3) times. Between attempts it drops whatever has settled since the last one, by
`capture_id` — because a repeated capture is not a lost race for a position, it is a fact already
recorded, and replaying it would seal the same chain three times before giving up. A batch with
nothing left is handed back its own violation rather than a silence the caller would read as success.

### What is *not* under the gate

`version`, the per-subject counter, is read with `max('version')` and no lock. Two concurrent writes
about the same subject can therefore reach the same number. A repeated number resolves to the newest
entry carrying it. `version` is inside the canonical payload, so a duplicate is sealed into both
hashes and neither entry is "wrong" — the chain is unaffected.

### A discard costs no sequence

The pipeline runs before the ledger, so a stage that discards an entry never reaches sequence
assignment and leaves no gap. Calling `Discard::because()` outside the pipeline throws
`Exceptions\DiscardException::outsideThePipeline`, whose message says why: past that point a discard
would leave a gap that verification reports as tampering. See
[Discarding entries](../05-pipeline-and-security/06-discarding-entries.md).

### Batches larger than one statement

`DatabaseLedger` caps a statement at 32 766 placeholders — SQLite's `SQLITE_MAX_VARIABLE_NUMBER`,
the narrowest of the three engines rather than the widest, with the arithmetic on
[SQLite](../10-database-engines/04-sqlite.md#the-placeholder-ceiling). What matters to the chain is
that it is built *before* the split and is not what gets divided: every statement runs inside the one
transaction, so a batch divided into four still lands whole or not at all.

---

## `payload_version`

`payload_version` is a `smallint` on every row, stamped from `Ledger\EntryBuilder::PAYLOAD_VERSION`,
which is `1`. `php artisan about` reports it under the Sentinel section.

It is the first field of the hash prefix, so it is not a label — bumping it changes every hash the
package computes from that moment on.

**Nothing in the package branches on it today.** There is one payload format, `CanonicalPayload`
describes it, and there is no dispatcher that would route a version-2 row to a different builder.
The column exists so that a format change is *expressible* and *detectable*, not because a second
format ships.

The rule the project holds itself to is: any change to the twenty-seven columns, to how their values
are rendered (`CanonicalPayload::normalize()`), or to the link formula bumps `payload_version` and
ships a backwards-compatibility test alongside it. What that test looks like is already in the
suite: `tests/Fixtures/GoldenLedger.php` freezes entries together with the canonical string each one
produces and the hash of that string, and `tests/Ledger/GoldenDatasetTest.php` reproduces those
hashes three ways — through the canonicalizer, through the hasher, and with a bare
`hash('sha256', …)` that does not go through the package at all.
`tests/Fixtures/ReferenceChain.php` freezes the other half, the linkage: one dense stream where
every `previous_hash` is literally the hash of the row before it. While those reproduce,
`payload_version` 1 still means what it meant.

What a bump costs, concretely:

| Cost | Detail |
|---|---|
| Old rows must keep verifying | Which is why the version is read off the row, exactly as `algorithm` is. |
| A second frozen vector set | The existing golden entries stay; new ones are added for the new version. |
| Every external re-implementation | Anyone reproducing hashes outside PHP has two formats to implement, forever. |
| Nothing is rewritten | Rehashing existing rows in a migration would destroy the one property the package sells. |

> ⚠️ **Warning.** Never rehash stored entries — not in a migration, not in a repair script, not to
> "fix" a row someone edited. A trail whose hashes can be recomputed by whoever holds the database
> proves nothing.

---

## What the chain detects, and what it does not

The chain alone — no signatures, no anchors — walked by
`Sentinel::verifyIntegrity($stream)`:

| Attack | Detected? | Reported as | Why |
|---|---|---|---|
| Edit a canonical column (`before`, `event`, `context`, …) | **Yes** | `HashMismatch` at that entry | The row no longer reproduces its own hash. |
| Edit `previous_hash` on a stored row | **Yes** | `HashMismatch` at that entry | `previous_hash` is inside the prefix the hash covers. |
| Overwrite the `hash` column | **Yes** | `HashMismatch` at that entry | Rehashing the row disagrees with what the column now says. |
| Edit a column and recompute that row's `hash` | **Yes** | `LinkMismatch` at the *next* entry | The next row's `previous_hash` still points at the old hash. |
| Reorder two entries | **Yes** | `HashMismatch`, or `LinkMismatch` once the hashes are recomputed | `sequence` is inside the hash, so a swapped row cannot both keep its hash and hold its new position. |
| Delete an entry from the middle | **Yes** | `SequenceGap` at the missing number | The walk expects a dense sequence. |
| Delete an entry and renumber the rest | **Yes** | `HashMismatch`, or `LinkMismatch` once the hashes are recomputed | Renumbering changes each row's hash input, so a renumbered row that kept its hash no longer reproduces it; recompute them and each row links to a hash that is no longer there. |
| Rewrite a whole range and recompute every hash after it | **No** (chain alone) | — | The result is a self-consistent chain. This is what [signatures](04-signing.md) and [anchors](05-checkpoints-and-anchors.md) exist for. |
| Append a fabricated entry at the tail | **No** (chain alone) | — | A correctly linked new tail is indistinguishable from a real write. A signature the attacker cannot forge is what stops it. |
| Delete entries from the tail | **No** | — | The walk simply ends earlier and reports intact, correctly, over a shorter chain. Once the range has been anchored, `--depth=roots` catches it: the range no longer folds to its recorded root. |
| Relabel an entry | **No**, by design | — | Labels are outside the hash in both directions. |
| Edit `sentinel_audit_relations` | Only with `--projections` | `ProjectionMismatch` | The projection is an index over the evidence, not the evidence. |
| An entry that was never written | **No** | — | The chain proves that what settled was not tampered with. It never proves that everything that happened settled — see [The buffered mode](../09-operations/02-the-buffered-mode.md). |

Two more limits worth stating plainly:

> 🔒 **Security.** The chain proves append-time integrity. Someone with application access at capture
> time produces a perfectly intact chain of false statements, and no hash catches that. The honest
> claim is "this row is the row that was written", never "this row is true".

> ⚠️ **Warning.** The immutability guard on `Models\Audit` (`ImmutableAuditException` on `updating`
> and `deleting`) runs on Eloquent model events, so it only sees changes that go through the model.
> `Audit::query()->where(...)->update([...])` bypasses it entirely. What catches that is
> verification, after the fact.

---

## Computing a hash by hand

Reproducing an entry's hash outside the package is the point of freezing the formula, and the recipe
for doing it — the prefix, the twenty-seven columns of `CanonicalPayload::COLUMNS`, how each value is
rendered, and the one deviation from strict RFC 8785 — is written out once, in
[Canonicalization](03-canonicalization.md).

What this page adds is the vector to check your implementation against.

### End to end, with a frozen vector

The test suite ships an entry whose canonical string and hash are frozen. It opens a chain, so
`previous_hash` is `null` and the fourth prefix field is empty:

```
payload_version  1
stream           global
sequence         1
previous_hash    (null → "")
algorithm        sha256
```

Its canonical payload, in full, is:

```json
{"actor_id":null,"actor_type":null,"affected_rows":null,"after":null,"audit_type":"model","before":null,"changes":null,"context":[],"criteria":null,"encryption":null,"event":"created","id":"01JGOLDEN000000000000000A1","impersonator_id":null,"impersonator_type":null,"metadata":null,"occurred_at":"2026-08-26 10:00:00.000000","request_id":null,"severity":"info","source":"system","source_audit_id":null,"span_id":null,"subject_id":null,"subject_type":null,"tenant_id":null,"trace_id":null,"transaction_id":null,"version":null}
```

Note what the canonicalization did: keys sorted, `null` written as `null` rather than omitted, and
`context` kept as `[]` — an empty list and an empty object are different answers.

> 🧪 **Verify it.** Save that JSON to `canonical.json` with no trailing newline, then:
>
> ```bash
> { printf '1\x1fglobal\x1f1\x1f\x1f'; cat canonical.json; } | sha256sum
> # 752d2a1b0ededff42112fde4b1429d2d5e11ffe85ff664a41fe0e9730d5adbfe
> ```
>
> The four `\x1f` bytes are: after `payload_version`, after `stream`, after `sequence`, and the one
> that joins the prefix to the payload. The last two are adjacent because `previous_hash` is empty
> for a genesis entry. That digest is the `hash` the fixture freezes, so if the command prints
> something else, one of the two sides moved.

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| Every entry written after a config change verifies, older ones do not | You rehashed old rows, or changed a canonical column's meaning without bumping `payload_version` | Nothing repairs a rehashed row. Restore from backup; never rewrite stored entries. |
| `hash` column contains a truncated digest, or PostgreSQL refuses the insert | `integrity.algorithm` set to a digest wider than 64 hex characters, e.g. `sha512` | Use a 64-hex-character digest. Width is not validated anywhere. |
| `ConfigurationException` naming `integrity.algorithm` during verification, not during writing | A stored row's `algorithm` column names a digest this PHP build does not have | Install the extension providing it, or accept that those rows cannot be verified on this host. |
| `sequence` restarts at 1 and the old entries stop growing | `integrity.stream` ships as `tenant`; the first tenant that resolves moves entries to a `tenant:<id>` stream | Decide the stream strategy before data exists. See [Streams](02-streams.md). |
| `LinkMismatch` at `sequence` 1 with `checked` = 0 | An entry that claims to open a chain carries a non-null `previous_hash` | The genesis entry links to nothing. Investigate as tampering or as a bad import. |
| Two entries about one subject share a `version` | `version` is assigned with `max()` and no lock, unlike `sequence` | Expected. A repeated number resolves to the newest entry carrying it; the chain is unaffected. |
| `Audit::query()->update()` succeeded and no exception was raised | The immutability guard runs on model events, which the query builder does not fire | Never write through the query builder. `verifyIntegrity()` reports it afterwards as `HashMismatch`. |
| Verification reports intact but entries you expected are missing | The chain proves what settled, not that everything settled; a buffered-mode process died holding entries | Listen for `BufferFlushFailed`, or use the sync or queue mode. |
| Entries hydrated by a custom driver verify as `HashMismatch`, straight out of storage | The row was filled with `forceFill()` while its JSON columns were already encoded strings, so the set casts encoded them a second time | Hydrate an already-encoded row with `setRawAttributes()`. `forceFill()` is right only when the JSON columns arrive as decoded arrays. See [Writing a ledger driver](../11-extending/03-writing-a-ledger-driver.md). |
| A relabelled entry still verifies | Labels are outside the hash, deliberately | Put anything that has to be provable in `metadata`, which is inside the canonical payload. |

---

## ✅ Best practices

✅ **Do** — read the algorithm off the entry when reproducing a hash yourself. It is the only value
that keeps working after `integrity.algorithm` changes.

```php
$recomputed = hash($audit->algorithm, $prefix."\x1f".$canonical);
```

❌ **Don't** — read it from configuration. Every entry written before the change now fails a check
that is wrong, and the report says "tampering".

```php
$recomputed = hash(config('sentinel.integrity.algorithm'), $prefix."\x1f".$canonical);
```

---

✅ **Do** — settle `integrity.stream` at install, before any entry exists, and treat it as frozen.
The stream name is inside the hash prefix, so changing it forks the history into two independent
chains rather than continuing one.

```php
// config/sentinel.php — decided once
'integrity' => ['stream' => 'global'],
```

❌ **Don't** — switch it on a live installation and expect the history to follow. Nothing is
rewritten; the old chain keeps verifying under its own name and stops growing.

```php
// after six months of tenant:* entries
'integrity' => ['stream' => 'global'],   // sequence restarts at 1, under a second chain
```

---

✅ **Do** — build the canonical payload through `CanonicalPayload`, which is the one enumeration of
the twenty-seven columns.

```php
use ElPandaPe\Sentinel\Integrity\CanonicalPayload;

$payload = CanonicalPayload::from($audit);
```

❌ **Don't** — hand-roll the column list in an exporter, a report or a verification job. A second
list is a second payload format, and the two agree until the day one of them does not.

```php
$payload = $audit->only(['id', 'event', 'before', 'after', /* … and the ones you forgot */]);
```

---

✅ **Do** — correct the record by appending a new entry that says so. History is append-only, and a
new entry takes the next sequence and links to the one before it.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

Sentinel::event('invoice.export.corrected')
    ->subject($invoice)
    ->metadata(['supersedes' => $audit->id])
    ->record();
```

❌ **Don't** — reach for the query builder to "fix" a stored entry. The immutability guard never
sees it, the row's hash stops matching, and the next verification reports it as tampering — correctly.

```php
use ElPandaPe\Sentinel\Models\Audit;

Audit::query()->where('id', $id)->update(['event' => 'fixed']);
```

---

✅ **Do** — put anything that must be provable inside `metadata`, which is one of the twenty-seven
canonical columns the hash covers.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

Sentinel::event('invoice.approved')
    ->subject($invoice)
    ->metadata(['approval_ref' => $approval->id])
    ->record();
```

❌ **Don't** — encode it as a label and assume the chain protects it. Labels are outside the hash in
both directions: relabelling an entry leaves no trace any verification can find.

```php
Sentinel::event('invoice.approved')
    ->subject($invoice)
    ->tags(['approval:'.$approval->id])   // classification, not evidence
    ->record();
```

---

✅ **Do** — treat a `HashMismatch` on one entry and a `LinkMismatch` on the next as one event, not
two. Editing a row and recomputing its hash moves the finding to the following entry.

```php
$result = Sentinel::verifyIntegrity('global');

$result->reason;     // IntegrityBreak::LinkMismatch
$result->sequence;   // the entry that no longer links — look at the one before it
```

❌ **Don't** — read `sequence` in the report as "the entry somebody edited". It is where the chain
stops being followable, which is not always the same row.

---

**See also:** [Streams](02-streams.md) · [Canonicalization](03-canonicalization.md) · [Signing the chain](04-signing.md) · [Checkpoints and anchors](05-checkpoints-and-anchors.md) · [Verification](06-verification.md) · [The integrity model](../01-concepts/04-the-integrity-model.md) · [Schema](../99-reference/03-schema.md) · [Enums](../99-reference/04-enums.md)
