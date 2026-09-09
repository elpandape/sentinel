# 🔐 Signing the chain

> What a signature adds on top of the hash chain, how the verifying half and the signing half are
> split, and what each of the four signature states obliges you to do.

**On this page:** [What a signature adds](#what-a-signature-adds) · [Where the signature sits](#where-the-signature-sits) · [The three signers](#the-three-signers) · [`keys` verifies, `private_key` signs](#keys-verifies-private_key-signs) · [Rotating and retiring](#rotating-and-retiring) · [The four signature states](#the-four-signature-states) · [Threat model](#threat-model) · [What signing costs](#what-signing-costs) · [Key custody](#key-custody) · [⚠️ Pitfalls](#️-pitfalls) · [✅ Best practices](#-best-practices)

---

## What a signature adds

The [hash chain](01-the-hash-chain.md) is unconditional: every entry links to the one before it and
there is no configuration that writes an unlinked row. What the chain proves is *internal
consistency* — that the rows you are holding are the rows that were written, in that order.

It does not prove who wrote them. Someone who can rewrite the `sentinel_audits` table can also
recompute every `hash` and every `previous_hash` from the row after the one they edited to the end
of the stream, and hand you a chain that verifies perfectly. Rehashing a trail costs one digest per
entry; nothing in the chain stops it.

A signature is a second, independent statement over the same 64 characters: *this hash was produced
by something holding a key*. An attacker who rewrites the table and rehashes it now also has to
produce a signature for every row they touched, and cannot, unless they also hold the key.

Signing is **off by default** and is switched on with two keys:

```php
// config/sentinel.php
'integrity' => [
    'signature' => [
        'enabled' => true,
        'signer'  => 'hmac',
    ],
],
```

> 📌 **Note.** Signing is not retroactive. Switching it on signs what is written from that moment;
> every entry already in the table stays `unsigned`, which is a state and not a failure. There is no
> command that signs history, and there deliberately is not one — a mass `UPDATE` over immutable
> rows would produce a signature proving only that somebody with write access passed through.

---

## Where the signature sits

`Ledger\EntryBuilder::seal()` signs `$audit->hash` — the hex string, not the payload — immediately
after `Integrity\Hasher` produces it, and stores the result in two columns:

| Column | Type | Holds |
|---|---|---|
| `signature` | `text` | The signature. Hex under `hmac`, base64 under `openssl`. |
| `signature_key_id` | `string(64)` | The identifier of the key that made it. |

Three consequences follow from signing the hash rather than the payload, and all three matter:

1. **It is cheap enough for the write path.** One operation over 64 characters, whatever the entry
   weighs.
2. **Verification needs no encryption key.** `Integrity\CanonicalPayload::from()` decrypts nothing,
   so an auditor holding no encryption key reproduces the hash byte for byte and then checks the
   signature over it. This is covered by the test *"verifies a signed entry with encrypted fields
   while holding no encryption key"*.
3. **Neither column is inside the hash.** `signature` and `signature_key_id` are absent from
   `Integrity\CanonicalPayload::COLUMNS`, so filling them costs no `payload_version` bump and
   writing them does not disturb the chain. See [Canonicalization](03-canonicalization.md).

The same signer signs **anchors**. `Integrity\Checkpoints::write()` signs the folded `root_hash` of
each window and records it in the checkpoint row's own `signature` / `key_id` columns. An unsigned
anchor is a row anybody with write access can reissue, so anchoring without signing buys speed and
no trust — see [Checkpoints and anchors](05-checkpoints-and-anchors.md).

`Compliance\Export` signs a third thing: the digest of an export body, recorded in the
`.manifest.json` file written beside it. See [Export and rekey](../08-lifecycle/06-export-and-rekey.md).

Signatures travel in `Audit::toArray()` under the `integrity` key, alongside `hash`,
`previous_hash`, `stream` and `sequence` — see [Serialization](../99-reference/08-serialization.md).

---

## The three signers

`integrity.signature.signer` names one of exactly three drivers. Anything else throws
`ConfigurationException` at the first resolve, with the message ending `Accepted: hmac, openssl, null.`

| Driver | Class | Signs with | Verifies with | Signature format | Use it when |
|---|---|---|---|---|---|
| `hmac` | `Integrity\HmacSigner` | the shared secret | the same shared secret | hex, `hash_hmac` | The default. Whoever can verify can also forge, so the verifier must be as trusted as the writer. |
| `openssl` | `Integrity\OpenSslSigner` | the private key | the public key | base64 of the DER signature | The verifier must not be able to forge — an external auditor, or a node you do not administer. |
| `null` | `Integrity\NullSigner` | nothing | nothing (always `false`) | — | You want the shape without the attestation: a test double, or a deployment that must not sign. |

`NullSigner::sign()` returns the empty string, and `EntryBuilder::seal()` treats that as *attests to
nothing*: both columns stay `NULL` rather than storing a value that would read as a claim.
`NullSigner::verify()` returns `false` and never `true` — a signer with no key cannot tell a good
signature from a bad one, and agreeing would be the one answer that is never right.

> ⚠️ **Warning.** `integrity.signature.algorithm` (default `sha256`) is validated against
> `hash_algos()`, which is a strictly longer list than the digests OpenSSL will sign with.
> `OpenSslSigner::digest()` re-checks against `openssl_get_md_methods()` and throws
> `SignatureException::unsignable` rather than letting OpenSSL answer with a warning — so a
> configuration that boots fine can still fail on the first signed write. `crc32b` is the canonical
> example: it is a hash and it is not a digest.

---

## `keys` verifies, `private_key` signs

This is the split the whole design turns on.

- **`integrity.signature.keys`** is a map of identifier → the material that **verifies**. Under
  `hmac` that is the shared secret; under `openssl` it is the **public** key, as a PEM string or a
  `file://` path to one.
- **`integrity.signature.private_key`** is a single value used under `openssl` only: what the
  **current** identifier signs with. `hmac` ignores it, because one secret does both jobs.

`Integrity\Signers::openssl()` hands the private half to exactly one signer — the one whose
identifier equals `integrity.signature.key_id`. Every other key on the ring is built with a `null`
private key and can only verify.

```php
// config/sentinel.php
'integrity' => [
    'signature' => [
        'enabled'   => true,
        'signer'    => 'openssl',
        'algorithm' => 'sha256',
        'key_id'    => 'v2',                                  // what signs now
        'keys' => [                                           // what VERIFIES
            'v1' => env('SENTINEL_SIGNING_PUBLIC_V1'),         // retired: verifies, never signs
            'v2' => env('SENTINEL_SIGNING_PUBLIC_V2'),         // current
        ],
        'private_key' => env('SENTINEL_SIGNING_PRIVATE_V2'),   // openssl only; keep it off this box
    ],
],
```

That configuration is what you can hand to a third party minus the last line. They resolve every key
on the ring, prove every entry untouched, and can write nothing — `sign()` on a signer with no
private half throws `SignatureException::verifyOnly`, whose message is *"A node that holds only the
public half verifies what others wrote and writes nothing itself."*

Under `hmac` there is nothing to split. Handing over `keys` hands over the ability to forge, which
is why `hmac` and `openssl` are not two flavours of the same guarantee but two different tiers.

### The application-key fallback

Under `hmac`, leaving `keys.default` null derives the secret from the application key:

```php
hash_hmac('sha256', 'sentinel:signature', config('app.key'))
```

The label is its own, so the signing secret is not the same bytes as the [hashing
salt](../05-pipeline-and-security/04-hashing-and-the-salt.md): one leaking does not hand over the
other. `Config::derivedSigningSecret()` throws `ConfigurationException::missingApplicationKey` when
there is no application key to derive from.

> 📌 **Note.** The fallback applies to the identifier `default` and to no other. Any other
> identifier was named on purpose, and silently verifying it with a key it did not name would make
> the `signature_key_id` recorded in the row a lie. A typo in `key_id` therefore fails loudly on the
> first write with `SignatureException::unknownKey`, instead of quietly signing with `APP_KEY`.

---

## Rotating and retiring

Every row records the identifier of the key that signed it, and verification resolves *that*
identifier rather than the current one. That is what makes rotation possible at all.

| Act | What you change | What happens to old entries |
|---|---|---|
| **Rotate** | Add the new key to `keys`, point `key_id` at it, move `private_key` (openssl) | Keep verifying with the key they name. New entries carry the new identifier. |
| **Retire** | Nothing — leave the old key on `keys` and stop pointing `key_id` at it | Keep verifying. The key signs nothing more. |
| **Remove** | Delete the key from `keys` | Report `unknown_key`. Permanently unprovable. |

```php
// Before the rotation
'key_id' => 'v1',
'keys'   => ['v1' => env('SENTINEL_SIGNING_KEY_V1')],

// After it — v1 stays, and that is the point
'key_id' => 'v2',
'keys'   => [
    'v1' => env('SENTINEL_SIGNING_KEY_V1'),
    'v2' => env('SENTINEL_SIGNING_KEY_V2'),
],
```

The test *"keeps verifying what the key before the rotation signed"* asserts exactly this: the entry
written before the rotation still reports `Signed`, and the one written after reports `Signed` under
`v2`.

> ⚠️ **Warning.** Under `openssl`, rotating `key_id` without moving `private_key` produces a node
> that verifies everything and can write nothing. `Signers::openssl()` gives the private half only
> to the identifier that equals `key_id`, so the current signer is built verify-only and the first
> write throws `SignatureException::verifyOnly`. Move both, in the same deploy.

---

## The four signature states

`Enums\SignatureState` has four cases, and the reason it is not three is that only one of them is a
defect. The distinction is the one RFC 4033 §5 draws for DNSSEC — secure, insecure, bogus,
indeterminate — for the same reason: collapsing "not signed" into "signature failed" turns a report
into noise.

| State | Value | What it means | What you do |
|---|---|---|---|
| `Signed` | `signed` | The key the row names verified the signature over the row's hash. | Nothing. |
| `Unsigned` | `unsigned` | The `signature` column is `NULL`. The entry was written before signing was on, or with `signer: null`. | Nothing, if it predates your rollout. Investigate if it does not. |
| `Invalid` | `invalid` | The verifier **held** the key the row names, and that key does not verify the signature. | Treat as tampering. This is the only defect of the four. |
| `UnknownKey` | `unknown_key` | The row names a key the ring cannot resolve, or names no key at all. | Put the key back on `keys`. The verifier is not entitled to a verdict without it. |

Three of the four coexist with a perfectly intact chain, which is why none of them is a case of
`Enums\IntegrityBreak` — see [Enums](../99-reference/04-enums.md). Only `Invalid` produces
`IntegrityBreak::SignatureMismatch`, fails `StreamVerification::isIntact()`, dispatches
`Events\IntegrityVerificationFailed` and makes `sentinel:verify` exit `1`.

```php
use ElPandaPe\Sentinel\Enums\ContentState;
use ElPandaPe\Sentinel\Enums\SignatureState;
use ElPandaPe\Sentinel\Models\Audit;

$audit = Audit::query()->findOrFail('01JB9Z8Q0000000000000000AB');

$audit->verifyIntegrity();  // bool          — does this row reproduce its own hash?
$audit->verifyContent();    // ContentState  — Sealed | Redacted | Altered
$audit->verifySignature();  // SignatureState

// Only Invalid is a defect.
$forged = $audit->verifySignature() === SignatureState::Invalid;
```

Four states do not fit in a bool, which is why `verifyIntegrity()` was never widened to include the
signature: making an unsigned entry return `false` would call every trail written before signing was
switched on a failure.

Across a whole stream the two tallies are kept apart, and the reason is that they answer different
questions:

```php
use ElPandaPe\Sentinel\Enums\SignatureState;
use ElPandaPe\Sentinel\Facades\Sentinel;

$report = Sentinel::verifyEverything();

$report->signatures();       // ['signed' => 41208, 'unsigned' => 4000]  — the entries that were READ
$report->anchorSignatures(); // ['signed' => 41]                        — the anchors standing in for the rest

$forged = $report->signatures()[SignatureState::Invalid->value] ?? 0;
```

"Two unsigned" is not an answer until you know which of the two tallies it came from.
`sentinel:verify` prints them in one column, the anchors' suffixed *on the anchors*. See
[Verification](06-verification.md).

---

## Threat model

State this in the terms of who can do what, not in terms of algorithm names.

| Signer | Stops | Does not stop |
|---|---|---|
| `NullSigner` | Nothing. It attests to nothing by design. | Everything. |
| `HmacSigner` | Someone with database access and no application access: a stolen backup, a read replica, a database console, a SQL-injection sink. They can rewrite rows and rehash, and cannot produce the MAC. | Anyone who can read the secret — which, on the fallback, is `APP_KEY`. Whoever can verify can forge. |
| `OpenSslSigner` | All of the above, **and the machine's own administrator**, when `private_key` lives off the machine the entries do. | Whoever holds the private key at the moment of the write. |

RFC 5848 §8.3 acknowledges the last row rather than mitigating it: a signing key present on a
running system is a key an attacker on that system can use for as long as they are there.

A **fourth tier exists and is deliberately out of scope**: a forward-secure MAC that evolves its key
after each entry and erases the previous one, so an attacker who takes the key today cannot forge
yesterday. `systemd-journald` ships one. Sentinel does not, and this page says so rather than
implying otherwise.

> 🔒 **Security.** No signature proves the content is *true*. Someone with application access at
> capture time produces a perfectly intact, perfectly signed chain of false statements. What the
> chain and the signature prove is that nobody touched the row **after** it was written. That is
> append-time integrity, and it is the honest limit of it.

---

## What signing costs

Measured by the package's own harness, `benchmarks/bench.php`, which runs 1 000 writes per variant
after a 200-write warm-up, all four variants inside one process against the same SQLite file. The
figures below are the medians of three passes published in `CHANGELOG.md` for `v0.18.0`, with
`synchronous` and journalling off:

| Variant | µs per write | Δ vs unsigned |
|---|---|---|
| unsigned | 2400.3 | — |
| `HmacSigner` (sha256) | 2436.9 | +1.5 % |
| `OpenSslSigner` (RSA-2048, sha256) | 3252.3 | +35.5 % |
| `NullSigner` | 2226.1 | −7.3 % |

Read the `NullSigner` row as the noise gauge, not as an optimisation: it adds one method call and
still moves between −10 % and +12 % across passes. That is what licenses the two conclusions —
the HMAC figure is indistinguishable from zero, and the RSA one is not. RSA-2048 is roughly 850 µs
of private-key work **per entry, on the write path, every write**.

> 💡 **Tip.** If you need the `openssl` tier's guarantee but not its per-write cost, sign with
> `hmac` and lean on [anchors](05-checkpoints-and-anchors.md): the anchor is signed with the same
> signer, and one anchor covers `integrity.checkpoints.every` entries. Measure before you conclude —
> these numbers came from one machine and one engine.

---

## Key custody

The rules are short, and each one is a consequence of something above.

**Do:**

- Keep `private_key` off the machine the entries live on. That single fact is the entire difference
  between the `hmac` tier and the `openssl` tier.
- Name your HMAC secret explicitly rather than relying on the `APP_KEY` fallback if you will ever
  hand verification to someone else — otherwise "let them verify" means "give them the application
  key", which decrypts everything else Laravel encrypted too.
- Back up `integrity.signature.keys` with the same seriousness as the database. A key that is gone
  is history that is permanently `unknown_key`.

**Never:**

- Never remove a retired key to tidy up.
- Never point `integrity.signature.keys` at `security.encryption.keys` or reuse the encryption key
  as the signing secret. The encryption ring is consumed by an `Illuminate\Encryption\Encrypter`
  that cannot express a public key at all, and the reuse makes one compromise into two. See
  [Encryption and the keyring](../05-pipeline-and-security/03-encryption-and-the-keyring.md).
- Never paste signing material into an issue. `php artisan about` deliberately reports six things
  about Sentinel — version, mode, ledger, payload version, compliance, telemetry — and **no key, no
  key identifier and no signer configuration**, precisely because that output is pasted into
  issues and captured by deploy logs.

### Provisioning an isolated verification node

A verification node is an ordinary Laravel application with the package installed, read access to
the trail (or a copy of it), and a deliberately incomplete configuration:

```dotenv
# .env on the verification node — openssl tier
SENTINEL_SIGNING_KEY=                      # unused under openssl
SENTINEL_SIGNING_PUBLIC_V1=file:///etc/sentinel/v1.pub.pem
SENTINEL_SIGNING_PUBLIC_V2=file:///etc/sentinel/v2.pub.pem
SENTINEL_SIGNING_PRIVATE_KEY=              # left empty: this node writes nothing

SENTINEL_ENCRYPTION_KEY=                   # left empty: the hash covers ciphertext
```

What this node can do: walk the chain, rehash every entry, resolve every key on the ring and report
`Signed` for all of them, and refold every anchor.

What it cannot do: write an entry (`SignatureException::verifyOnly` on the first attempt), read the
plaintext of any encrypted field, or forge a signature.

```bash
php artisan sentinel:verify --depth=entries
```

Exit `0` is sound, `1` is a bad finding from a run that happened, `2` is a run that could not
happen. See [Artisan commands](../09-operations/06-artisan-commands.md) and
[The verification playbook](07-the-verification-playbook.md).

> 🐘 **Engine.** Nothing about signing is engine-specific: it is a column write and a userland
> computation. The columns are `text` and `string(64)` on all three engines, which is why an
> RSA-2048 signature — 256 bytes, about 344 base64 characters — fits where a 64-character HMAC does.

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| Every entry written before today reports `unsigned` after you enabled signing | Signing is not retroactive and never will be. Prior history carries no signature. | Nothing to fix. `unsigned` is a state, not a defect; `sentinel:verify` still exits `0`. |
| Every previously signed entry suddenly reports `invalid` | You changed `integrity.signature.algorithm`. The signature digest is **not** recorded per row — only `signature` and `signature_key_id` are — so verification recomputes with whatever the config now says. | Put the algorithm back. Unlike the chain's `algorithm`, which is read off each row, this one is global and changing it retroactively reinterprets every signature. |
| A signed entry reports `unknown_key` after a config change | The key left `integrity.signature.keys`, or the driver was switched to `null` (which resolves only the identifier `null`). | Put the key back on the ring under the identifier the row names. |
| The first write after a deploy throws `SignatureException` ending *"writes nothing itself"* | Under `openssl`, `key_id` points at a key whose private half is not in `private_key`. | Set `private_key` to the private half of the key `key_id` names, or point `key_id` back. |
| The first write after a deploy throws `SignatureException` saying the key is *"not on [sentinel.integrity.signature.keys]"* | `key_id` names an identifier absent from `keys`, and the `APP_KEY` fallback applies to `default` only. | Add the key to `keys`, or fix the typo in `key_id`. |
| `SignatureException` saying *"OpenSSL does not know that digest"* on the first signed write, though boot was clean | `integrity.signature.algorithm` passed the `hash_algos()` check at config read and failed the `openssl_get_md_methods()` check at sign time. | Use a digest OpenSSL signs with — `sha256` is the default for a reason. |
| `SignatureException` ending *"as EdDSA keys do"* | An Ed25519/Ed448 key was given. Those keys sign the message themselves and refuse one that was already hashed, and Sentinel always hands the signer a hash. | Use an RSA or ECDSA key. |
| A signature that verified before a transfer now reports `invalid` | `OpenSslSigner::verify()` refuses anything that is not strict base64 rather than cleaning it up. Whitespace padding or a re-encode in transit is enough. | Transfer the column verbatim. `invalid` here reads as forgery and was a transport problem. |
| A rotation set in `config()` mid-request is not picked up | `Integrity\Signers` is registered as a **scoped** singleton and memoises each resolved key. | In production this is correct — configuration does not change inside a request. In a test or a `tinker` session, call `app()->forgetScopedInstances()`. |
| An export's `.manifest.json` carries `"signature": ""` and `"signature_key_id": "null"` | `Compliance\Export` signs the body digest with `Signers::current()`, which is the `NullSigner` when signing is off. Unlike the entry path, the manifest stores what it gets. | Enable signing before exporting anything a recipient is meant to verify. |
| The application refuses to boot with `ComplianceException` | `compliance => true` and `Compliance\Requirements::enforce()` found `integrity.signature.enabled` or `integrity.checkpoints.enabled` false. It fails at boot rather than at the first write, because the first write may be a year away. | Enable both, or turn compliance mode off. See [Compliance mode](../08-lifecycle/05-compliance-mode.md). |

---

## ✅ Best practices

✅ **Do** — rotate by adding, never by replacing. Every row names the key that signed it, so
yesterday's entries need yesterday's key to stay provable.

```php
'key_id' => 'v2',
'keys'   => [
    'v1' => env('SENTINEL_SIGNING_KEY_V1'), // retired, still on the ring
    'v2' => env('SENTINEL_SIGNING_KEY_V2'),
],
```

❌ **Don't** — swap the value under one identifier. Every entry signed by the old secret now reports
`invalid` — the one state that means forgery — and you have manufactured an incident.

```php
'key_id' => 'default',
'keys'   => ['default' => env('SENTINEL_SIGNING_KEY_V2')], // v1's entries are now "forged"
```

✅ **Do** — treat `Invalid` as the alarm and the other three as information. This is the only
comparison that means tampering.

```php
use ElPandaPe\Sentinel\Enums\SignatureState;

$forged = $report->signatures()[SignatureState::Invalid->value] ?? 0;

if ($forged > 0) {
    // page someone
}
```

❌ **Don't** — alert on "not signed". A monitor written this way fires on every entry that predates
your signing rollout and on every key you legitimately have not been given.

```php
$bad = ($report->signatures()['unsigned'] ?? 0)
     + ($report->signatures()['unknown_key'] ?? 0); // fires on a sound trail
```

✅ **Do** — under `openssl`, give the auditor the ring and keep the private half. This is the one
configuration that survives the machine's own administrator, and it is the whole reason the split
exists.

```php
'signer'      => 'openssl',
'keys'        => ['v2' => env('SENTINEL_SIGNING_PUBLIC_V2')], // hand this over
'private_key' => env('SENTINEL_SIGNING_PRIVATE_V2'),          // never leaves the writer
```

❌ **Don't** — hand over an HMAC secret and call it an external audit. Under `hmac` the verifying
material and the signing material are the same bytes: whoever can check your trail can also rewrite
it and re-sign it.

```php
'signer' => 'hmac',
'keys'   => ['default' => env('SENTINEL_SIGNING_KEY')], // giving this away gives away forgery
```

✅ **Do** — sign your anchors. `Checkpoints::write()` signs the folded root with the same
`Signers::current()`, so enabling signing before you start anchoring is all it takes.

```php
'integrity' => [
    'signature'   => ['enabled' => true, 'signer' => 'hmac'],
    'checkpoints' => ['enabled' => false, 'every' => 1000], // anchor from the scheduler
],
```

❌ **Don't** — anchor with signing off and treat the anchors as evidence. An unsigned anchor is a
row anyone with write access can reissue, so it buys a cheaper verification and no trust at all.

```php
'integrity' => [
    'signature'   => ['enabled' => false],
    'checkpoints' => ['enabled' => true], // anchors nobody attests to
],
```

✅ **Do** — name the HMAC secret explicitly when the trail will ever be verified elsewhere. The
fallback is a convenience for a single-node install, not a custody model.

```php
'keys' => ['default' => env('SENTINEL_SIGNING_KEY')], // a secret you can share alone
```

❌ **Don't** — rely on the `APP_KEY` fallback and then hand the application key to an auditor. That
key also decrypts sessions, cookies and every `encrypted` cast in the application.

```php
'keys' => ['default' => null], // "just give them APP_KEY" is not a plan
```

✅ **Do** — configure a verification node with an empty `private_key` and an empty encryption key,
and let the code enforce the boundary.

```dotenv
SENTINEL_SIGNING_PRIVATE_KEY=
SENTINEL_ENCRYPTION_KEY=
```

❌ **Don't** — copy production's `.env` onto the verification node "so it works". A node holding the
private key is a second machine that can write the trail, which is exactly what the tier was
supposed to prevent.

---

**See also:** [The hash chain](01-the-hash-chain.md) · [Checkpoints and anchors](05-checkpoints-and-anchors.md) · [Verification](06-verification.md) · [The verification playbook](07-the-verification-playbook.md) · [Canonicalization](03-canonicalization.md) · [Enums](../99-reference/04-enums.md) · [Exceptions](../99-reference/06-exceptions.md) · [Configuration](../99-reference/02-configuration.md) · [Encryption and the keyring](../05-pipeline-and-security/03-encryption-and-the-keyring.md) · [Compliance mode](../08-lifecycle/05-compliance-mode.md) · [Security checklist](../13-best-practices/03-security-checklist.md)
