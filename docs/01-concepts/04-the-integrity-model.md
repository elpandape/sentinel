# 🧠 The integrity model

> What Sentinel means by *tamper-evident*, what each of the three layers adds, and exactly what a
> verification result proves — and does not prove — at each depth.

**On this page:** [Evident, not prevented](#evident-not-prevented) · [The three layers](#the-three-layers) · [Layer 1: the chain](#layer-1-the-chain) · [Layer 2: the signature](#layer-2-the-signature) · [Layer 3: the anchor](#layer-3-the-anchor) · [The threat model](#the-threat-model) · [What a verification proves](#what-a-verification-proves) · [Why the states live in four enums](#why-the-states-live-in-four-enums) · [⚠️ Pitfalls](#️-pitfalls) · [✅ Best practices](#-best-practices)

---

## Evident, not prevented

Sentinel does not stop anyone from editing `sentinel_audits`. A database user with `UPDATE` rights
can rewrite any row in it, and the package has no mechanism that prevents that — no trigger, no
database-level immutability, no write-once storage.

What the package does is make the edit **fail a later check that names it**: the stream, the
sequence, the entry id, and which of six things went wrong. That is the whole claim. Everything on
this page is a refinement of it.

The in-process guard is narrower than people expect. `Models\Audit::booted()` registers `updating`
and `deleting` listeners that throw `Exceptions\ImmutableAuditException`, so the model refuses to
save over itself. Those are **Eloquent model events**, which means a query-builder write walks past
them:

```php
use ElPandaPe\Sentinel\Models\Audit;

$audit->update(['event' => 'created']);                            // throws ImmutableAuditException
Audit::query()->whereKey($id)->update(['event' => 'created']);     // no exception; the row changes
```

The second line is not a hole in the design — it is the case the hash chain exists for. Nothing
catches it at write time; `Sentinel::verifyIntegrity()` catches it afterwards and reports
`IntegrityBreak::HashMismatch` at that entry's sequence.

> 🔒 **Security.** "Tamper-evident" is a detection property with a *latency*: an edit is invisible
> until something verifies. How short that latency is, is a scheduling decision you make — see
> [Scheduling](../09-operations/07-scheduling.md).

---

## The three layers

| Layer | Default | Configuration | What it adds | Where the how-to is |
|---|---|---|---|---|
| **Chain** | Always on | None. There is no off switch | Detects an edit, an insertion, a deletion and a reorder within a stream | [The hash chain](../07-integrity/01-the-hash-chain.md) |
| **Signature** | Off | `integrity.signature.enabled` | Binds the chain to whoever holds a key, so recomputing the chain is not enough to forge it | [Signing the chain](../07-integrity/04-signing.md) |
| **Anchor** | Off | `integrity.checkpoints.enabled` | Makes verification cost rows in proportion to *history ÷ window*, and lets a pruned range still be accounted for | [Checkpoints and anchors](../07-integrity/05-checkpoints-and-anchors.md) |

Two consequences follow from that table and both surprise people.

**Chaining needs no configuration.** `Ledger\DatabaseLedger::chain()` seals every entry it writes,
on every installation, whatever `config/sentinel.php` says. A page that tells you to "enable
integrity" is describing a different package.

**The optional layers are optional in one direction only.** Switching signing on signs entries
written *from that moment*; it does not reach back. Prior history stays
`SignatureState::Unsigned` forever, and that is correct rather than a defect —
see [What a verification proves](#what-a-verification-proves).

> 📌 **Note.** [Compliance mode](../08-lifecycle/05-compliance-mode.md) is the one setting that makes
> the optional layers mandatory: `Compliance\Requirements::enforce()` refuses to boot the application
> unless both `integrity.signature.enabled` and `integrity.checkpoints.enabled` are true, naming
> whichever is missing. It fails at boot rather than at the first write, because the first write may
> be a year away.

---

## Layer 1: the chain

Every entry belongs to exactly one **stream** — a named chain — and inside that stream carries a
monotonic `sequence` starting at 1. `Integrity\Hasher::hash()` seals it:

```
hash = digest(
    payload_version  ⟨SEP⟩
    stream           ⟨SEP⟩
    sequence         ⟨SEP⟩
    previous_hash ?? ''  ⟨SEP⟩
    canonical(payload)
)
```

`⟨SEP⟩` is the ASCII unit separator, `\x1f` (`Hasher::SEPARATOR`). It is there so the prefix parts
cannot run into each other: without it, `("a", 11)` and `("a1", 1)` would produce the same bytes.

`digest` is named by the row's own `algorithm` column, never by the current configuration. That is
what lets `integrity.algorithm` change without invalidating history: yesterday's rows keep verifying
under yesterday's digest. Editing the `algorithm` column of an existing row does not help an
attacker — the rehash then runs under a different digest and stops matching.

`canonical(payload)` is the RFC 8785 canonical JSON of exactly the twenty-seven columns frozen in
`Integrity\CanonicalPayload::COLUMNS`. The full list and the encoding rules are in
[Canonicalization](../07-integrity/03-canonicalization.md); what matters conceptually is the split:

| Position | Columns | Covered? |
|---|---|---|
| Hash prefix | `payload_version`, `stream`, `sequence`, `previous_hash` | Yes — a reorder or a restream changes the digest |
| Canonical payload | The twenty-seven in `CanonicalPayload::COLUMNS`, including `context`, `before`, `after`, `changes`, `metadata`, `encryption` and `occurred_at` | Yes |
| Outside both | `hash`, `algorithm`, `signature`, `signature_key_id`, `capture_id`, `created_at`, `redacted_at`, `redaction_reason`, `redacted_hash` | No |
| Other tables | `sentinel_audit_tags` (labels), `sentinel_audit_relations` (the relation projection) | No |

Three things fall out of the last two rows and each is worth knowing before you design around them:

- **Labels are outside the hash in both directions.** Classifying an old entry when a new category
  appears does not break its hash — making it would turn a taxonomy into an integrity incident — and
  equally, relabelling leaves no trace any verification can find. Anything that must be provable
  goes in `metadata`, which *is* inside the payload. See [Labels](../06-reading/06-labels.md).
- **The relation projection is outside the hash**, so a divergence there is reported as
  `IntegrityBreak::ProjectionMismatch` and never as a broken chain. Saying otherwise would be a lie
  about what the hash covers.
- **`occurred_at` is sealed, `created_at` is not.** The instant the entry claims for the event is
  inside the payload; the instant the row landed in the table is not.

> ⚠️ **Warning.** `stream` is part of the hash prefix, so **a stream is never renamed in place**.
> Changing `integrity.stream` on an installation that already holds data does not rewrite the old
> rows under the new name — it starts a second chain and leaves the history split across two.
> See [Streams](../07-integrity/02-streams.md).

### What the chain alone detects

| Attack | Detected as |
|---|---|
| A column of one entry edited | `HashMismatch` at that sequence |
| An entry deleted | `SequenceGap`, unless a retired range accounts for it |
| An entry inserted between two others | `SequenceGap` or `LinkMismatch` |
| Two entries swapped | `LinkMismatch` |
| A whole range rewritten *and rehashed forward* | Nothing — this is what layer 2 is for |

---

## Layer 2: the signature

The chain proves internal consistency. It does not survive somebody who can rewrite the rows **and
recompute every hash after them**: the result is a self-consistent chain of statements that were
never made. A signature closes that by binding each hash to something the database does not hold.

`Ledger\EntryBuilder::seal()` signs the 64-character `hash` string — never the payload:

```php
$signature = $signer->sign($audit->hash);
```

Signing the hash rather than the payload is what makes the layer cheap enough to pay per write, and
what lets a third party verify **without recomposing the entry or decrypting anything**. Both
signature columns sit outside `CanonicalPayload::COLUMNS`, so filling them costs no
`payload_version` bump.

The key ring splits on purpose:

| Config key | Holds | Hand to an auditor? |
|---|---|---|
| `integrity.signature.keys` | What **verifies**: the shared secret under `hmac`, the public key under `openssl` | Yes — this is the half an external verifier needs |
| `integrity.signature.private_key` | Under `openssl` only: what the **current** `key_id` signs with | No — keep it off the machine the entries live on |
| `integrity.signature.key_id` | The identifier every new signature records into `signature_key_id` | — |

Rotation is moving `key_id` and **leaving the old key on the ring**. Every row records the key that
signed it, so yesterday's entries keep verifying with yesterday's key. Removing a retired key does
not make its history invalid — it makes it *undecidable*, reported as `SignatureState::UnknownKey`.

---

## Layer 3: the anchor

Verifying a chain means walking it, and a trail that only grows eventually means reading everything.
An **anchor** (`sentinel_checkpoints`) is a signed root over a fixed window of entries, so a range
can be verified without being reread and a bad range can be found without walking the good ones.

`Integrity\Fold::root()` folds the window — it chains, it is not a Merkle tree:

```
root₀ = digest("fold-<algorithm>" ⟨SEP⟩ stream ⟨SEP⟩ from ⟨SEP⟩ to ⟨SEP⟩ previous_root ?? '')
rootₙ = digest(rootₙ₋₁ ⟨SEP⟩ hashₙ)          for each entry hash in ascending sequence
```

Three properties are load-bearing:

- **The window is fixed at `integrity.checkpoints.every`** (default 1000): `[1,1000]`, `[1001,2000]`,
  and the trailing incomplete window is left alone. A "whatever is pending" window would make a
  root's ends depend on when emission ran, and a root that is not reproducible proves nothing.
- **The previous anchor's root goes into the prefix.** Contiguous integers are not linkage; without
  it, rewriting a range and reissuing its anchor produces a history that agrees with itself. With
  it, reissuing one anchor obliges reissuing every anchor after it.
- **The construction name travels in the prefix and in the `algorithm` column** (`fold-sha256`), so
  an anchor written by a construction this build does not know is reported as one it *cannot
  recompute*, never as one that failed to.

An anchor also survives what the entries do not. When [pruning](../08-lifecycle/01-retention-and-pruning.md)
removes a range from the hot table, the walk steps over the absence only when **two** things agree:
the archive manifest says the range was retired *and* the anchors reach past it. Either alone is
insufficient — the manifest is an unsigned, unhashed row, so on its own it would turn "delete the
rows, then insert one row" into a supported way of laundering a gap. It is also why the unit of
pruning is the anchored window: a stream nobody has anchored releases nothing, and the run reports
`Enums\RetentionHold::Unanchored` as the reason rather than silently removing nothing.

> ⚠️ **Warning.** An unsigned anchor is a row anyone with write access can reissue. Anchoring
> without signing buys speed and no trust.

---

## The threat model

Stated in tiers, by who each one stops.

| Tier | Configuration | Stops | Does not stop |
|---|---|---|---|
| **Chain only** (default) | `signature.enabled = false` → `Integrity\NullSigner`, both signature columns stay `NULL` | Accidental corruption; an edit by anyone who does not also recompute the chain forward | Anyone who can rewrite rows *and* rehash — which is anyone holding the database and the algorithm, and the algorithm is public |
| **HMAC** | `signer = 'hmac'` | Someone with database access and no application access: a stolen backup, a replica, a console, a SQL-injection sink | Anyone who can read `APP_KEY` or the configured secret. Under HMAC, whoever can verify can forge |
| **OpenSSL** | `signer = 'openssl'`, `private_key` kept off the box | All of the above, **and the machine's own administrator**, when the private key lives elsewhere | Whoever holds the private key. RFC 5848 §8.3 acknowledges this rather than mitigating it, and so does Sentinel |

### The fourth tier, and why it is out of scope

Two things sit deliberately outside all three tiers, and neither is a bug to be filed:

**Forward secrecy.** A forward-secure MAC evolves its key and erases the predecessor, so entries
written *before* a compromise stay provable after it. `systemd-journald` ships one. Sentinel does
not, and says so rather than implying the HMAC tier is more than it is.

**Truth at capture time.** No signature, no chain and no anchor proves the content is **true**. They
prove nobody touched the row after it was written. An attacker with application access at capture
time — a compromised controller, a malicious job, a developer with `tinker` on production —
produces a **perfectly intact, perfectly signed chain of false statements**, and every verification
in this package will report it sound. That is append-time integrity, and it is the honest limit of
it.

> 🔒 **Security.** If the threat you are modelling is "the application lied", the answer is not a
> deeper verification depth. It is somewhere else entirely: least privilege on who can write, and a
> second observer that is not the application. Sentinel does not claim to be that observer.

### One more limit worth stating: the hash covers ciphertext

When [field encryption](../05-pipeline-and-security/03-encryption-and-the-keyring.md) is on, the
hash is taken over the stored ciphertext, because `CanonicalPayload::from()` decrypts nothing. That
is a requirement, not an oversight: `verifyIntegrity()` has to run in an environment that holds **no
key at all** and still prove the row untouched.

The trade is stated rather than hidden — the chain proves the row is the one that was written, not
what the value said. The compensation is that the `encryption` column is *inside* the canonical
payload, so forging a `key_id` rotation on a stored row breaks its hash.

---

## What a verification proves

Three depths, three different claims. All three are on the facade; none of them throws on a broken
chain, they *report* it.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

$anchors = Sentinel::verifyAnchors('tenant:acme');   // cheapest
$roots   = Sentinel::verifyRoots('tenant:acme');     // middle
$deep    = Sentinel::verifyIntegrity('tenant:acme'); // only one that rehashes
```

| Depth | What it reads | What it proves | What it does **not** catch |
|---|---|---|---|
| `verifyAnchors()` | The anchor rows, plus every entry in the tail no anchor covers | The anchors are contiguous from sequence 1, each anchor's signature holds, and the tail links and rehashes | Anything at all about an anchored entry — not one of those rows is opened |
| `verifyRoots()` | The above, plus `(sequence, hash)` of every anchored entry | Additionally that the stored hashes still fold to each recorded root: a hash rewritten, removed or reordered | A canonical column edited while the `hash` column was left alone. It rehashes nothing |
| `verifyIntegrity()` | Every entry of the range, in full | Every entry still reproduces its own hash and links to its predecessor | Whether the statement was true when it was captured |

The vocabulary is deliberate and the report never blurs it:

- A range under a valid anchor is reported **anchored**, never **intact**. `intact` is reserved for
  what was read.
- `checked`, `covered` and `archived` are three separate numbers and are **never summed**: entries
  read and rehashed, entries an anchor answered for, entries the walk stepped over because they are
  no longer in the ledger.
- `isIntact()` means *nothing came back wrong*. It does not mean *everything was read*.

> 💡 **Tip.** Switching anchoring on never makes an installation verify *less*: `verifyIntegrity()`
> is deliberately unchanged by it. Use the shallow depths for the routine sweep and the deep one for
> the periodic audit — the schedule is in [The verification playbook](../07-integrity/07-the-verification-playbook.md).

### At the level of one entry

Three questions, asked separately because they have different answer shapes.

```php
use ElPandaPe\Sentinel\Enums\ContentState;
use ElPandaPe\Sentinel\Enums\SignatureState;
use ElPandaPe\Sentinel\Models\Audit;

$audit = Audit::query()->findOrFail($id);

$audit->verifyIntegrity();  // bool          — does this row reproduce its own hash?
$audit->verifyContent();    // ContentState  — Sealed | Redacted | Altered
$audit->verifySignature();  // SignatureState — Signed | Unsigned | Invalid | UnknownKey
```

`verifyIntegrity()` returns **false for a legitimately redacted entry**, on purpose. It has always
meant "does this row reproduce its own hash", and a tombstone does not; answering true would rest on
`redacted_hash`, a column no signature and no fold covers. Use `verifyContent()` to tell a declared
redaction from a tampering — see [Redaction and tombstones](../08-lifecycle/04-redaction-and-tombstones.md).

### Reproducing a hash outside this package

The formula is publishable, which is the point of it: an auditor holding the rows and the verifying
half of `integrity.signature.keys` reproduces every hash with no encryption key and, under
`openssl`, no private key. The recipe — prefix, column list, rendering rules and the one documented
deviation from strict JCS — is written out once, in
[Canonicalization](../07-integrity/03-canonicalization.md).

---

## Why the states live in four enums

A verification distinguishes *what is wrong* from *what merely is*. Collapsing the two would invert
`isIntact()` in silence and make a scheduled `sentinel:verify` exit non-zero on a sound trail.

| Enum | Answers about | Cases | Is any of them a defect? |
|---|---|---|---|
| `Enums\IntegrityBreak` | What went wrong | `HashMismatch`, `LinkMismatch`, `SequenceGap`, `SignatureMismatch`, `ProjectionMismatch`, `CheckpointMismatch` | All six. Each fails the verification and dispatches `Events\IntegrityVerificationFailed` |
| `Enums\SignatureState` | One signature | `Signed`, `Unsigned`, `Invalid`, `UnknownKey` | Only `Invalid` |
| `Enums\ContentState` | One entry's content | `Sealed`, `Redacted`, `Altered` | Only `Altered` |
| `Enums\CheckpointState` (published as tally keys) | One anchored range | `'anchored'`, `'archived'`, `'absent'` | None |

The distinction between `Unsigned`, `Invalid` and `UnknownKey` is the one RFC 4033 §5 draws for
DNSSEC, for the same reason. Collapsing `Unsigned` into `Invalid` would turn every installation that
has not switched signing on into a wall of failures. Collapsing `UnknownKey` into either would have
the verifier deliver a verdict it is not entitled to: `Integrity\NullSigner::verify()` returns
**false**, never true, because a signer with no key cannot tell a good signature from a bad one, and
a key the ring cannot resolve is answered with `null` rather than an exception.

A declared redaction follows the same rule: it is **counted, never announced**. It does not stop the
walk, does not fill `reason` and does not invert `isIntact()` — but a real tampering standing next to
one still wins, or a redaction would be a place to hide one.

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

It is an **event and never an exception** — no class in the package carries that name as an
exception. Full listener reference: [Events](../99-reference/05-events.md).

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `isIntact()` is true but you know a row was edited | The depth you ran never opened it. `verifyAnchors()` reads no anchored entry; `verifyRoots()` folds the stored `hash` column, which an editor who left `hash` alone did not touch | Ask the content question with `verifyIntegrity()` / `--depth=entries`. Only that depth rehashes |
| A dashboard shows fewer entries verified than the table holds | `checked` counts what was *read*. `covered` and `archived` are published beside it and never added into it | Render the three numbers, or say "read" rather than "verified" |
| `$audit->verifyIntegrity()` returns false on an entry you redacted yourself | The method means "reproduces its own hash", and a tombstone reproduces `redacted_hash` instead | Use `verifyContent()`; `ContentState::Redacted` is the declared-redaction answer, not a finding |
| Every entry reports `SignatureState::Unsigned` after switching signing on | Signing is not retroactive and deliberately never will be — it signs what is written from that moment | Nothing to fix. `Unsigned` is not a defect; only `Invalid` is |
| A whole era of history flips to `UnknownKey` | A retired key was removed from `integrity.signature.keys` | Put it back. Every row records the key that signed it; a key nobody holds makes that history unprovable, permanently |
| `sequence` restarted at 1 and old entries no longer appear in the chain | `integrity.stream` was changed on an installation with data. The stream name is in the hash prefix, so old rows keep the old name | There is no in-place rename. Decide the stream strategy before the first write |
| A row edited by `Audit::query()->update(...)` raised no exception | The immutability guard runs on Eloquent model events, which the query builder bypasses | Detection is after the fact: `verifyIntegrity()` reports `HashMismatch`. Do not rely on the guard as a control |
| `verifyEverything()` throws instead of returning an empty report | The configured ledger does not implement `Contracts\EnumeratesStreams` — `NullLedger` does not | Name a stream explicitly, or configure a ledger that can enumerate. "Nothing is broken" about a list nobody could build reads as reassurance and means nothing |
| A `projection_mismatch` was escalated as a broken chain | `sentinel_audit_relations` is outside the hash; a divergence there is a stale index, not a rewritten entry | Alert on it separately. The chain result and the projection result are different facts |
| `CheckpointMismatch` reports an `auditId` that no row has | For that reason, `auditId` carries the anchor's **root hash** and `sequence` its `sequence_from` | Look the value up in `sentinel_checkpoints`, not in `sentinel_audits` |
| `integrity.algorithm` set to `sha512` writes truncated or rejected hashes | `hash`, `previous_hash` and `root_hash` are all `char(64)`; nothing validates digest width | Use a digest that produces 64 hex characters (`sha256` and equivalents) |

---

## ✅ Best practices

✅ **Do** — match the depth to the question you are asking. Only the deep walk rehashes, so only the
deep walk says anything about what an entry contains.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

// "Are the anchors and the tail sound?" — cheap, run it often
Sentinel::verifyAnchors('tenant:acme');

// "Has any entry been edited?" — the only depth that can answer
Sentinel::verifyIntegrity('tenant:acme');
```

❌ **Don't** — treat a range reported `anchored` as verified. Not one of its entries was opened, and
an edit that left the `hash` column alone folds back exactly as before.

```php
// Reports every anchored range sound and never reads one of their rows
$result = Sentinel::verifyAnchors('tenant:acme');
$result->isIntact(); // "nothing came back wrong", NOT "everything was read"
```

---

✅ **Do** — read `checked`, `covered` and `archived` as three separate facts, and keep the two
signature tallies apart: one says whether the entries somebody read are attested, the other whether
the anchors standing in for the entries nobody read are.

```php
$report = Sentinel::verifyEverything();

$report->checked();          // read and rehashed
$report->covered();          // taken on an anchor's word
$report->archived();         // stepped over; no longer in the ledger
$report->signatures();       // ['signed' => 41208, 'unsigned' => 4000]
$report->anchorSignatures(); // ['signed' => 41]
```

❌ **Don't** — add them into one "verified" total. The report keeps them apart precisely so a reader
can see which of the three a number came from.

```php
$verified = $report->checked() + $report->covered(); // a number that means nothing
```

---

✅ **Do** — rotate a signing key by moving `key_id` and leaving the retired key on the ring. Every
row records the key that signed it, so old entries keep verifying with the old key.

```php
// config/sentinel.php
'integrity' => [
    'signature' => [
        'enabled' => true,
        'signer'  => 'openssl',
        'key_id'  => 'v2',                                    // signs from now on
        'keys'    => [
            'v1' => env('SENTINEL_SIGNING_PUBLIC_V1'),         // retired: verifies, never signs
            'v2' => env('SENTINEL_SIGNING_PUBLIC_V2'),
        ],
        'private_key' => env('SENTINEL_SIGNING_PRIVATE_V2'),   // keep off this machine
    ],
],
```

❌ **Don't** — delete a retired key to tidy the config. Every entry it signed becomes `UnknownKey`
and stops being provable, permanently.

```php
'keys' => ['v2' => env('SENTINEL_SIGNING_PUBLIC_V2')], // v1's history is now undecidable
```

---

✅ **Do** — alert only on `SignatureState::Invalid`, and treat the other three states as
information. That is the difference between a watchdog and a pager that everyone mutes.

```php
use ElPandaPe\Sentinel\Enums\SignatureState;

$forged = $report->signatures()[SignatureState::Invalid->value] ?? 0;

if ($forged > 0) {
    // the verifier held the key that could have made it valid, and it did not
}
```

❌ **Don't** — page on `Unsigned` or `UnknownKey`. A trail written before signing was switched on is
sound, and a key nobody holds is a verdict the verifier is not entitled to give.

```php
$bad = ($report->signatures()['unsigned'] ?? 0) > 0; // true on every pre-signing installation
```

---

✅ **Do** — put anything that has to be provable in `metadata`, which is inside the canonical
payload the hash covers.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

Sentinel::event('invoice.approved')
    ->subject($invoice)
    ->metadata(['approval_reference' => $reference])  // sealed by the hash
    ->record();
```

❌ **Don't** — carry it in a label. Labels live in `sentinel_audit_tags`, outside the hash in both
directions: relabelling an entry leaves no trace any verification can find.

```php
Sentinel::event('invoice.approved')
    ->subject($invoice)
    ->tags([$reference])   // classification only; nothing proves it was ever this value
    ->record();
```

---

✅ **Do** — anchor a stream before you prune it, and back up `sentinel_checkpoints` alongside
`sentinel_audits` afterwards. After a prune the anchors are the only thing that can tell a range you
retired on purpose from rows somebody deleted.

```bash
php artisan sentinel:checkpoint --stream=tenant:acme
php artisan sentinel:prune
```

❌ **Don't** — expect an archive manifest to excuse an absence on its own. The manifest row is
unsigned and unhashed; verification steps over a gap only when the manifest accounts for it **and**
the anchors reach past it.

---

**See also:** [The audit record](02-the-audit-record.md) · [The write path](03-the-write-path.md) · [The hash chain](../07-integrity/01-the-hash-chain.md) · [Signing the chain](../07-integrity/04-signing.md) · [Checkpoints and anchors](../07-integrity/05-checkpoints-and-anchors.md) · [Verification](../07-integrity/06-verification.md) · [The verification playbook](../07-integrity/07-the-verification-playbook.md) · [Security checklist](../13-best-practices/03-security-checklist.md)
