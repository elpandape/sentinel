# ♻️ Export and rekey

> Handing the trail to someone who does not have your database, and changing the lock on a protected value without touching the seal on the entry that holds it.

**On this page:** [Two operations](#two-operations-that-hand-something-over) · [What export produces](#what-sentinelexport-produces) · [The manifest](#the-manifest) · [The three formats](#the-three-formats) · [What an entry carries out](#what-an-exported-entry-carries-and-what-it-strips) · [Narrowing and size](#narrowing-order-and-the-missing-cursor) · [Verifying an export](#verifying-an-export-holding-none-of-your-keys) · [Rekey](#sentinelrekey-rotating-without-rewriting) · [What rotation writes](#what-a-rotation-writes) · [The cursor](#the-cursor-and-the-last-entry-hint) · [Ran twice](#the-rotation-that-ran-twice) · [A full handover](#a-full-handover) · [Pitfalls](#️-pitfalls) · [Best practices](#-best-practices)

---

## Two operations that hand something over

`sentinel:export` copies a slice of the trail out of the database and puts a small manifest beside
it, so that whoever receives the bytes can tell they are the bytes you sent without being given
access to anything.

`sentinel:rekey` moves protected values from one encryption key to another. It is the one that
sounds impossible: `encryption` is inside the canonical payload the hash covers, so re-encrypting a
stored row in place would stop that row reproducing its own hash. It does not re-encrypt in place.
It appends.

Both are internal machinery driven by a command. `ElPandaPe\Sentinel\Compliance\Export` and
`Compliance\Exported` sit in a namespace `tests/SurfaceTest.php` classifies whole as internal —
"a command drives it, and the published surface is the command — its name, its options and its exit
codes". `ElPandaPe\Sentinel\Security\Rekeyer` is the exception: it is on the published list and you
may call it. See [API stability](../99-reference/09-api-stability.md).

## What `sentinel:export` produces

```
php artisan sentinel:export
    {--format=ndjson}  json, ndjson or csv
    {--disk=}          the filesystem disk to write to
    {--path=}          where on that disk
    {--tenant=}        only this tenant
    {--type=}          only this audit type
    {--limit=500}      how many entries at most
```

There are two output modes and the flags choose between them silently:

| `--disk` | `--path` | What happens |
|---|---|---|
| set | set | Two files: the body at `<path>`, the manifest at `<path>.manifest.json`. Exit 0. |
| set | missing | The whole body is printed to standard output. Exit 0. |
| missing | set | The whole body is printed to standard output. Exit 0. |
| missing | missing | The whole body is printed to standard output. Exit 0. |

`ExportCommand::write()` takes the file branch only when both are non-null. There is no warning for
the other three rows — an operator who typed `--disk=exports` and forgot `--path` gets no file, no
message and a successful exit.

> ⚠️ **Warning.** Do not build the file with a shell redirect. Standard-output mode prints the body
> with `line()` and then a human summary with `info()`, both on stdout, so
> `php artisan sentinel:export > trail.ndjson` writes a file whose last line is
> `Rendered 4188 entries. Digest sha256:…` — and whose digest therefore no longer matches. Use
> `--disk` and `--path`, which write the body and nothing else.

Exit codes are two: `0` for a render or a write, `2` for a format the command does not write, a
disk it cannot reach, or any other throwable. There is no exit `1` — an export has no findings to
report. See [Exit codes](../99-reference/07-exit-codes.md).

## The manifest

`Exported::manifest()` has exactly five keys, and they do not change with the format:

| Key | Type | What it is |
|---|---|---|
| `format` | string | `json`, `ndjson` or `csv` — which of the three renderings the body is. |
| `entries` | int | How many entries the body holds. Not how many exist. |
| `digest` | string | `<algorithm>:<hex>` over the exact bytes of the body, e.g. `sha256:9f2c…`. The algorithm is `integrity.algorithm`. |
| `signature` | string | The signature over the **digest string, prefix included** — not over the body, not over each entry. |
| `signature_key_id` | string | Which key identifier signed it. `"null"` means nothing signed it. |

The manifest travels beside the body rather than inside it, for the reason `Exported`'s own docblock
gives: putting the digest into the bytes it digests is the one shape that cannot work.

> ⚠️ **Warning.** With `integrity.signature.enabled => false` — the shipped default —
> `Signers::current()` returns `NullSigner`, whose `sign()` returns `''` and whose `verify()`
> returns `false` for everything. The export still succeeds and still ships a manifest, carrying
> `"signature": ""` and `"signature_key_id": "null"`. That manifest proves the digest and nothing
> about who produced it. Turn signing on before you export anything you expect somebody to trust.
> See [Signing the chain](../07-integrity/04-signing.md).

What the manifest does **not** carry: the instant of the export, the narrowing that produced it, the
streams it covers, which signer driver was used (`hmac` or `openssl`), and which signature algorithm.
The recipient needs the last two out of band before they can check anything.

## The three formats

| Format | Shape | Round-trips | Carries the `integrity` block |
|---|---|---|---|
| `ndjson` | One JSON object per line, `\n`-separated, trailing newline. Empty body for zero entries. | Yes | Yes |
| `json` | One pretty-printed JSON array, `JSON_UNESCAPED_SLASHES \| JSON_UNESCAPED_UNICODE`, trailing newline. | Yes | Yes |
| `csv` | A header row, then one quoted row per entry. Nested values are JSON inside the cell. | **No** | **No** |

`csv` renders only the sixteen columns in `Export::CSV_COLUMNS`:

```
id, audit_type, event, severity, source, subject, actor, tenant_id,
version, changes, before, after, metadata, tags, occurred_at, created_at
```

Everything else is gone — the whole `integrity` block, so no `stream`, no `sequence`, no `hash`, no
`previous_hash`, no `signature`, and no redaction block; and also `context`, `impersonator`,
`transaction_id`, `request_id`, `trace_id`, `span_id`, `source_audit_id`, `criteria` and
`affected_rows`.

> 📌 **Note.** A CSV export is a spreadsheet for a person to read. It is not evidence and it cannot
> be read back in: `subject` arrives as `"{""type"":""invoice"",""id"":""77""}"` and there is no
> hash in the file to check anything against. Reach for `ndjson` for everything else.

## What an exported entry carries, and what it strips

The body is `Audit::toArray()`, one entry at a time. That serialisation is a frozen contract: the
keys of the top level and of the `integrity` block only grow, and a key is never renamed or
reinterpreted. See [Serialization](../99-reference/08-serialization.md) and
[Presenting and serializing](../06-reading/07-presenting-and-serializing.md).

One line of an `ndjson` body, pretty-printed here and with the middle of the top level elided:

```json
{
  "id": "01JB7Q…",
  "audit_type": "model",
  "event": "updated",
  "subject": {"type": "invoice", "id": "77"},
  "actor": {"type": "user", "id": "9"},
  "tenant_id": "acme",
  "version": 3,
  "changes": [{"path": "/total", "op": "replace", "old": 100, "new": 120}],
  "before": {"total": 100},
  "after": {"total": 120},
  "tags": ["billing"],
  "context": {"ip": "203.0.113.7", "route": "invoices.update"},
  "integrity": {
    "stream": "tenant:acme",
    "sequence": 4188,
    "algorithm": "sha256",
    "payload_version": 1,
    "previous_hash": "…",
    "hash": "…",
    "signature": "…",
    "signature_key_id": "default",
    "verified": null,
    "redacted": null
  },
  "occurred_at": "2027-01-14T09:31:07.412000+00:00",
  "created_at": "2027-01-14T09:31:07.418000+00:00"
}
```

Three deliberate absences, and one of them has a consequence people trip over:

| Absent | Why it is absent | Consequence |
|---|---|---|
| `encryption` | Publishing it would tell every recipient which fields are protected and which key is current. | It is **inside the canonical payload**. A recipient cannot recompute the hash of an entry that carries one. |
| `capture_id` | Correlation and idempotency metadata, deliberately outside the canonical payload. | None for verification. |
| `verified` | Always serialised as `null` — nothing verifies on the way out. | A verdict has to be computed by the reader, not read off the file. |

> 🔒 **Security.** Protected values leave as ciphertext, because `toArray()` never decrypts. The
> recipient of an export holding no encryption key sees `{"diagnosis": "eyJpdiI6…"}` and can prove
> the entry was not touched without ever learning what it said. That is the trade the chain makes:
> it proves the row is the row that was written, not what the value meant. See
> [Encryption and the keyring](../05-pipeline-and-security/03-encryption-and-the-keyring.md).

A redacted entry exports **as redacted**, not as one that was always empty: the content keys are
null or `[]` and `integrity.redacted` carries `{at, reason, hash}`. See
[Redaction and tombstones](04-redaction-and-tombstones.md).

## Narrowing, order and the missing cursor

Narrowing is the [Query API](../06-reading/01-the-query-api.md) and not a second query language, but
the command exposes only three of its filters: `--tenant` (`forTenant`), `--type` (`whereType`) and
`--limit` (`take`). There is no `--stream`, no `--subject`, no `--actor`, no date range and no event
filter. Entries come back in the ledger's default order — `created_at` ascending, then `id`
ascending — so an export of a multi-stream installation interleaves the streams.

> ⚠️ **Warning.** `sentinel:export` has no `--after` and no other cursor. It is **not resumable**.
> `sentinel:rekey` has one; the export does not, and the asymmetry catches people who used one
> before the other. A trail larger than `--limit` is exported in slices you cut by hand with
> `--tenant` and `--type`, or not at all.

Two more edges of `--limit`, both from `ReadsOptions::number()` and `AuditQuery::take()`:

- `--limit=abc` is not a number, so it is treated as absent and the command uses `500` silently.
- `--limit=0` and `--limit=-5` are numbers, so `take()` throws
  `QueryException::unreachableLimit` — `A read of 0 entries is not a read: ask for at least one.` —
  and the command exits 2.

The whole body is built in memory as one string before a byte is written. A `--limit` of a million
entries is a million hydrated models and one very large string.

> 📌 **Note.** Under [compliance mode](05-compliance-mode.md) an export is a read like any other: it
> goes through `AuditQuery::get()`, so it leaves an `audit_type = 'access'` entry in the chain and a
> row in `sentinel_access_log`. The access entry is written after the query returned, so it is never
> inside the body it records.

## Verifying an export holding none of your keys

This is the procedure to hand a recipient. It needs the two files, the verifying half of the key
named in `signature_key_id`, and two facts you tell them: the signer driver and the signature
algorithm.

```php
// The recipient's script. It imports nothing from Sentinel and reaches no database.
$body = (string) file_get_contents('acme-2027-01.ndjson');
$manifest = json_decode((string) file_get_contents('acme-2027-01.ndjson.manifest.json'), true);

// 1. The digest is the algorithm name, a colon, and the hex over the exact bytes of the body.
[$algorithm, $expected] = explode(':', (string) $manifest['digest'], 2);

if (! hash_equals($expected, hash($algorithm, $body))) {
    throw new RuntimeException('The body is not the body this manifest describes.');
}

// 2. The signature covers the digest STRING, prefix included — not the body, not each entry.
$signed = (string) $manifest['digest'];

// Under integrity.signature.signer = 'hmac': one shared secret signs and verifies.
$valid = hash_equals(hash_hmac('sha256', $signed, $sharedSecret), (string) $manifest['signature']);

// Under 'openssl': the public half verifies and cannot sign. The signature is base64.
$valid = openssl_verify(
    $signed,
    (string) base64_decode((string) $manifest['signature'], true),
    $publicKeyPem,
    'sha256',
) === 1;
```

> 🔒 **Security.** Under `hmac`, whoever can verify can forge — one secret does both, and if
> `integrity.signature.keys.default` is left null the secret is
> `hash_hmac('sha256', 'sentinel:signature', config('app.key'))`. Handing that to an outside auditor
> hands them the ability to sign an export of their own. Use `openssl` for anyone outside your
> organisation: they get the public key, they can verify every export and every entry signature, and
> they can produce neither.

> 🧪 **Verify it.** `shasum -a 256 acme-2027-01.ndjson` prints the hex that must follow the colon in
> `digest`. That check needs no PHP and no keys at all.

What this proves: the bytes are the bytes that left your installation, signed by the key the
manifest names. What it does not prove: that they are *all* of them. Nothing signs the claim that a
slice is complete — `entries` counts what the body holds, and an operator with `--limit=10` produces
a perfectly valid manifest over ten entries.

## `sentinel:rekey`: rotating without rewriting

```
php artisan sentinel:rekey
    {--key=}          the key identifier to re-encrypt under; defaults to the current one
    {--tenant=}       only this tenant
    {--type=}         only this audit type
    {--limit=500}     how many entries at most
    {--after=}        resume behind this entry id
    {--dry-run}       count, and write nothing
```

An entry that carries protected fields records `encryption = {"fields": ["diagnosis"], "key_id":
"2026-q1"}`, and that column is one of the twenty-seven in `Integrity\CanonicalPayload::COLUMNS`.
So the entry's hash covers both the ciphertext and the name of the key that produced it. Re-encrypt
in place and three things break at once: the row stops reproducing its own `hash`, the next entry's
`previous_hash` stops pointing at anything, and the anchor covering that window stops folding to the
root it recorded. See [The hash chain](../07-integrity/01-the-hash-chain.md) and
[Canonicalization](../07-integrity/03-canonicalization.md).

Rotation therefore does not touch the original row at all. It appends a new entry at the tail of the
same stream, holding the same values under the new key. `Rekeyer::rekey()` returns that entry, or
`null` when there was nothing to do.

```php
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Security\Rekeyer;

$original = Audit::query()->findOrFail($auditId);

$rotated = app(Rekeyer::class)->rekey($original, '2027-q1');

$rotated?->encryption;       // ['fields' => ['diagnosis'], 'key_id' => '2027-q1']
$rotated?->source_audit_id;  // the original's id
$rotated?->previous_hash;    // the original's hash, when it was the tail
$original->hash;             // unchanged, byte for byte
```

`rekey()` returns `null` — writing nothing — in four cases: the entry carries no protected fields,
its `encryption` names no key, it already uses the target key, or this exact rotation is already on
the trail.

## What a rotation writes

| Field of the new entry | Value |
|---|---|
| `audit_type` | `security` (`Rekeyer::AUDIT_TYPE`) |
| `event` | `rekeyed` |
| `severity` | `severity.events.rekeyed`, shipped as `notice` |
| `occurred_at` | now — **not** the original's clock |
| `source_audit_id` | the original's id |
| `capture_id` | the derived rotation identity (see below) |
| `encryption` | `{"fields": […], "key_id": "<target>"}` |
| `metadata` | the original's metadata plus `rekeyed: {audit_id, from, to}` |
| `sequence`, `hash`, `previous_hash` | assigned by the ledger, at the tail of the stream |
| `version` | the subject's next version number |

Copied from the original: `source`, `subject_type`, `subject_id`, `tenant_id`, `context`, `before`,
`after`, `changes`. The four scalars travel verbatim; `Security\Fields` re-encrypts inside the array
columns — `context`, `before`, `after`, `changes` and the carried `metadata` — and inside the
`old`/`new` pair of a `changes` line whose pointer names a protected field.

**Not carried at all**, because `Rekeyer::carry()` does not name them and the entry bypasses the
capture pipeline: `actor_type`/`actor_id`, `impersonator_type`/`impersonator_id`, `transaction_id`,
`request_id`, `trace_id`, `span_id`, `criteria`, `affected_rows` and labels.

Two of those drops matter:

> ⚠️ **Warning.** `criteria` is not carried. A mass-operation entry stores the bindings of the
> statement there, and `Security\Fields` protects them — so rotating that entry produces a rotation
> entry with no criteria, and the encrypted bindings on the original stay under the **old** key
> permanently. Retire that key and they become unreadable, however many times you rotated. See
> [Mass operations](../03-capture/05-mass-operations.md).

> ⚠️ **Warning.** Nothing records who ran the rotation. The new entry carries the original's
> `context` — the ip, route and user agent of the request that caused the original change — and no
> actor, because rotation writes straight to the ledger without going through the pipeline that
> resolves one. This is the opposite of `sentinel:redact`, where `--actor` is mandatory. If you need
> the operator on the record, write your own entry saying so.

### What rotation does not change

- The original entry. Pinned by a test that freezes `getAttributes()` before and compares after.
- Any `hash`, `previous_hash`, `sequence`, `stream`, `signature` or `payload_version`.
- Any anchor root. Nothing folded is re-folded.
- The plaintext. A rotation is a change of lock, never a change of content.
- The keyring. It does not add or remove a key; you edit `security.encryption.keys` yourself.
- Archived ranges. The read goes through the ledger, so entries the prune already removed are not
  seen and not rotated. Bring the range back first — see [Rehydration](03-rehydration.md).
- Hashed fields. `security.hashing.salt` is stable by definition and there is no rotation for it;
  changing it destroys the comparability of every digest written before it.

> 📌 **Note.** Each rotation adds a copy of the protected value and removes none. Rotating from
> `a` to `b` and then to `c` leaves three entries holding the same secret under three keys — and the
> rotation entries themselves carry `encryption`, so a later pass to a fourth key rotates them too.

## The cursor and the last-entry hint

The walk is oldest-first and bounded by `--limit`, default 500. Every pass prints, when it read at
least one entry:

```
Re-encrypted 500 of the 500 entries read. The originals keep their hash, their link and their
sequence, and keep verifying while their old key stays on the keyring.
The last entry read was 01JB7Q… Pass --after=01JB7Q… to carry on behind it.
```

Feed that identifier to the next pass. Without it the second pass reads the same oldest 500 entries
again — safe, since the identity guard makes it write nothing, but it never reaches the trail behind
them.

```php
use Illuminate\Support\Facades\Artisan;

$after = null;

do {
    $arguments = ['--key' => '2027-q1', '--limit' => '500'];

    if ($after !== null) {
        $arguments['--after'] = $after;
    }

    $code = Artisan::call('sentinel:rekey', $arguments);

    $after = preg_match('/--after=(\S+)/', Artisan::output(), $found) === 1 ? $found[1] : null;
} while ($code === 0 && $after !== null);
```

The cursor compiles to `id > <value>`, while the results are ordered by `created_at` then `id`.
Those are two different axes. They agree for ordinarily written entries, whose identifier is minted
as the row is created; an entry whose identifier does not sort with its clock will be stepped over.

`--after=` with an empty value is read as no cursor at all, not as a cursor of nothing.

Exit codes: `0` for a rotation, for a dry run, and for a pass that rotated nothing; `2` for a run
that could not happen. There is no exit `1`.

> ⚠️ **Warning.** `--dry-run` returns before the rotation loop, so it can never report a refusal —
> it prints `Would re-encrypt what it finds among N entries` and stops. A `--key` the keyring cannot
> resolve, an unusable cipher and a value that will not decrypt are all invisible to it. The same is
> true of a real run over a trail where nothing carries protected fields: the keyring is only asked
> for the target key when there is something to re-encrypt, so `--key=nonesuch` exits 0 there.

> ⚠️ **Warning.** The read runs *before* the try/catch, so a query that throws escapes as an
> uncaught exception with a stack trace instead of the command's own `Nothing was re-encrypted` and
> exit 2. And the rotation loop has no per-entry recovery: if entry 40 of 500 throws, the 39
> rotation entries already written stay. That is not a bug to fix by hand — history is append-only,
> and re-running the pass is free.

## The rotation that ran twice

This package shipped a rotation that duplicated itself, and the shape of the bug is the useful part.

The original entry keeps its own `key_id` by design — that is exactly what lets it go on verifying
after the rotation. So **nothing on the original says it has been rotated.** A pass narrowed only by
`--limit` reads the same oldest entries every time, found no evidence of its own earlier work, and
appended a second set of rotation entries. Every one of them valid, chained and verifying.

The fix does not mark the original. It gives the rotation an identity derived from what it stands
for rather than minted:

```php
// Security\Rekeyer::identity()
DerivedIdentity::of('rekey', $audit->id.':'.$target);
```

That value is written as the new entry's `capture_id`, and `Rekeyer::rotated()` asks the ledger
about it — through `Contracts\Deduplicates` — *before* the write rather than after. A second pass
over the same entries finds its own work already settled and writes nothing. `capture_id` is outside
the canonical payload, so carrying it costs the hash nothing, and `sentinel_audits.capture_id` is
uniquely indexed, which is what has the last word if the read and the write race.

Two things to take from it:

> ⚠️ **Warning.** The duplicates already written stay. They are valid, chained, verifying entries;
> history is append-only and nothing in this package deletes one. Emptying their contents is a
> redaction and nothing else. What the fix changes is that the next pass advances instead of
> duplicating again.

> 📌 **Note.** The guard needs a ledger that implements `Contracts\Deduplicates` — `DatabaseLedger`,
> `MemoryLedger` and `FanoutLedger` (which delegates to its primary) do. A ledger that cannot answer
> is taken at its word and the write goes ahead, because idempotency belongs to the caller and a
> caller with no reliable read cannot have it. On such a driver, chaining `--after` is not a
> convenience. See [The Ledger contract](../11-extending/01-the-ledger-contract.md).

## A full handover

An auditor asks for one tenant's trail for January. Here is the whole sequence, and the note that
goes with it.

**1. Make the chain answerable first.** A window is anchored before it is proved, and a signature
that does not exist cannot be checked.

```php
// config/sentinel.php
'integrity' => [
    'signature' => [
        'enabled' => true,
        'signer'  => 'openssl',      // the auditor gets the public half and can forge nothing
        'key_id'  => 'default',
        'keys'    => ['default' => env('SENTINEL_SIGNING_PUBLIC_KEY')],
        'private_key' => env('SENTINEL_SIGNING_PRIVATE_KEY'),
    ],
],
```

```bash
php artisan sentinel:checkpoint
php artisan sentinel:verify --depth=entries --stream=tenant:acme
```

**2. Export.** Both flags, or you get stdout.

```bash
php artisan sentinel:export \
    --format=ndjson --tenant=acme --limit=5000 \
    --disk=exports --path=acme-2027-01.ndjson
# Wrote 4188 entries to [acme-2027-01.ndjson] on the [exports] disk, with its manifest beside it.
# Digest sha256:9f2c…
```

**3. Transfer both files.** The manifest is useless without the body and the body is unprovable
without the manifest.

**4. The recipient verifies**, with the script above and the public key. They can also walk the
chain inside the export by hand: every entry carries `integrity.stream`, `integrity.sequence`,
`integrity.previous_hash` and `integrity.hash`, so a contiguous run of one stream can be checked
link by link without a database.

**5. Tell them what you cannot prove.** A covering note that says this is worth more than one that
does not:

| Claim | Status |
|---|---|
| These bytes came from our installation | Proved, by the manifest signature. |
| Each entry is signed | Proved per entry, by `integrity.signature` against `signature_key_id`. |
| This is every entry for that tenant and month | **Not proved.** Nothing signs a completeness claim; `entries` counts what the file holds. |
| Every entry's own hash can be recomputed from this file | **Only for entries carrying no protected fields.** `encryption` is inside the canonical payload and is deliberately not exported. |
| The gaps in `sequence` are accounted for | **Not in this file.** Anchors and archive manifest rows live in the database; export them separately. See [Checkpoints and anchors](../07-integrity/05-checkpoints-and-anchors.md). |
| The redacted entries were redacted lawfully | **Not proved by the block.** `redacted_at`, `redaction_reason` and `redacted_hash` are outside the payload and outside the signature; the proof is the chained trail entry. |
| History before our import is chained | **No.** Imported history has no link before the point of import — nobody hashed those rows as they were written. |

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| The digest in the manifest does not match the file the recipient received | The file was built with a shell redirect, so it ends with the `Rendered … Digest …` summary line that `info()` printed to stdout | Re-export with `--disk` and `--path` |
| `sentinel:export --disk=exports` produced no file and exited 0 | The file branch needs **both** `--disk` and `--path`; with one missing the body went to standard output | Pass both |
| `signature` is `""` and `signature_key_id` is `"null"` in the manifest | `integrity.signature.enabled` is false, so `Signers::current()` is `NullSigner` | Enable signing before exporting anything anyone will rely on |
| The recipient cannot reproduce an entry's hash | That entry carries protected fields, and `encryption` is inside the canonical payload but deliberately absent from `toArray()` | Expected. Say so in the covering note; the entry signature still verifies |
| `Nothing was exported: A read of 0 entries is not a read` | `--limit=0` or a negative limit reached `AuditQuery::take()` | Pass a limit of at least 1, or omit it for 500 |
| An export silently covered 500 entries instead of the 5000 asked for | `--limit=5o00` is not numeric, so `ReadsOptions::number()` treated it as absent | Check the value; the command will not tell you |
| A CSV export has no hashes in it | `Export::CSV_COLUMNS` is sixteen columns and the `integrity` block is not among them | Use `ndjson` for anything that must be provable |
| `sentinel:rekey` ran five times and never got past the first 500 entries | The walk is oldest-first and bounded by `--limit`; without `--after` every pass reads the same prefix | Chain the passes with the identifier each one prints |
| `sentinel:rekey --key=typo` exited 0 and rotated nothing | The keyring is only asked for the target key when an entry actually carries protected fields | Run without `--dry-run` on a slice you know has encrypted entries, or check `security.encryption.keys` first |
| The trail has two rotation entries per original | A pass from before the derived-identity guard, or a ledger that does not implement `Deduplicates` | The duplicates are permanent; redaction is the only way to empty them. Chain `--after` from now on |
| A subject's `version` numbers jumped after a rotation | A rotation entry is an ordinary entry and takes the subject's next version | Expected — see [Field history](../06-reading/04-field-history.md) |
| Rotated everything, retired the old key, and a mass-operation entry's search bindings are now unreadable | `criteria` is not carried by `Rekeyer::carry()`, so the encrypted bindings stayed on the original under the old key | Keep the old key on the ring, or accept the loss knowingly |
| Rotation skipped a whole year of the trail | That range was archived by the prune, so the ledger cannot see it | Rehydrate the range, rotate, let the next prune archive it again |

## ✅ Best practices

✅ **Do** — turn signing on before the first export anyone will act on, and prefer `openssl` when the
recipient is outside your organisation. The public half verifies everything and signs nothing.

```php
'integrity' => ['signature' => [
    'enabled' => true,
    'signer' => 'openssl',
    'keys' => ['default' => env('SENTINEL_SIGNING_PUBLIC_KEY')],
    'private_key' => env('SENTINEL_SIGNING_PRIVATE_KEY'),
]],
```

❌ **Don't** — export with signing off and hand over the manifest anyway. `signature` is the empty
string, `signature_key_id` is the literal `"null"`, and a recipient who checks it gets `false` for
every input, including the correct one.

```php
'integrity' => ['signature' => ['enabled' => false]],  // the shipped default
```

✅ **Do** — write the export to a disk and keep the two files together. Both `put()` calls go to the
same disk, the body first and `<path>.manifest.json` second.

```bash
php artisan sentinel:export --format=ndjson --tenant=acme \
    --disk=exports --path=exports/acme-2027-01.ndjson
```

❌ **Don't** — capture standard output into the file. The body and the human summary go to the same
stream, so the file you keep is not the file the digest describes.

```bash
php artisan sentinel:export --format=ndjson > acme.ndjson   # digest will never match
```

✅ **Do** — chain `sentinel:rekey` on the identifier each pass reports, and stop when a pass reads
nothing.

```bash
php artisan sentinel:rekey --key=2027-q1 --limit=500
php artisan sentinel:rekey --key=2027-q1 --limit=500 --after=01JB7Q…
```

❌ **Don't** — loop the bare command and assume it is making progress. It is safe to repeat and it
advances nowhere; a trail larger than `--limit` never rotates past its first page.

```bash
for i in $(seq 20); do php artisan sentinel:rekey --key=2027-q1; done
```

✅ **Do** — add the new key to the ring *before* rotating, and leave the old one there afterwards.
An entry keeps the key identifier it recorded, and that is what lets it go on being readable.

```php
'security' => ['encryption' => [
    'key_id' => '2027-q1',
    'keys' => [
        'default' => env('SENTINEL_ENCRYPTION_KEY'),   // still needed by everything it wrote
        '2027-q1' => env('SENTINEL_ENCRYPTION_KEY_2027_Q1'),
    ],
]],
```

❌ **Don't** — remove the old key once the rotation reports success. The originals are still there,
still verify, and stop being readable the moment their key leaves — and any `criteria` the rotation
did not carry goes with it.

```php
'keys' => ['2027-q1' => env('SENTINEL_ENCRYPTION_KEY_2027_Q1')],  // the old values are now noise
```

✅ **Do** — rehydrate an archived range before rotating it, then let the next prune write it back
out. Rotation reads through the ledger and cannot see what the prune removed.

```php
app(ElPandaPe\Sentinel\Archive\Rehydrator::class)->restore('tenant:acme', 5001, 6000);
```

❌ **Don't** — treat a clean `sentinel:rekey` exit as proof the whole trail moved. It reports what it
read and rotated in that pass, and a pass reads only what the ledger holds and only up to `--limit`.

✅ **Do** — schedule the two commands that make an export worth receiving, and leave the export
itself to a person. Anchoring and verification are idempotent, quiet and have somewhere to report to.

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('sentinel:checkpoint')->hourly();
Schedule::command('sentinel:verify --depth=anchors')->dailyAt('04:00');
```

❌ **Don't** — schedule `sentinel:export` or `sentinel:rekey`. One is unbounded by anything but a
`--limit` it cannot resume past; the other writes to the chain and needs its cursor carried between
runs, which a cron cannot do. See [Scheduling](../09-operations/07-scheduling.md).

```php
Schedule::command('sentinel:rekey --key=2027-q1')->daily();   // rotates the same page forever
```

✅ **Do** — send a covering note with every export, naming what the manifest does not prove:
completeness, the accounting for sequence gaps, and the hashes of entries that carry protected
fields. A recipient who is told the boundary trusts what is inside it.

❌ **Don't** — let "signed export" stand in for "verified chain". The manifest proves the bytes;
whether the chain those bytes describe is intact is what `sentinel:verify` answers, and its result
is not in the file. See [Verification](../07-integrity/06-verification.md).

---

**See also:** [Redaction and tombstones](04-redaction-and-tombstones.md) · [Compliance mode](05-compliance-mode.md) · [Rehydration](03-rehydration.md) · [Cold archiving](02-cold-archiving.md) · [Encryption and the keyring](../05-pipeline-and-security/03-encryption-and-the-keyring.md) · [Signing the chain](../07-integrity/04-signing.md) · [Canonicalization](../07-integrity/03-canonicalization.md) · [Verification](../07-integrity/06-verification.md) · [The verification playbook](../07-integrity/07-the-verification-playbook.md) · [The Query API](../06-reading/01-the-query-api.md) · [Presenting and serializing](../06-reading/07-presenting-and-serializing.md) · [Artisan commands](../09-operations/06-artisan-commands.md) · [Exit codes](../99-reference/07-exit-codes.md) · [Serialization](../99-reference/08-serialization.md) · [Configuration](../99-reference/02-configuration.md) · [API stability](../99-reference/09-api-stability.md)
