# 🛡️ Encryption and the keyring

> The only reversible protection Sentinel offers: what the cipher does to a declared field, what the entry records so the value stays readable, why the chain hash covers the ciphertext instead of the plaintext, and how a key is rotated without rewriting a single row.

**On this page:** [What encryption does](#what-encryption-does) · [The keyring](#the-keyring) · [What an entry records](#what-an-entry-records) · [The hash covers the ciphertext](#the-hash-covers-the-ciphertext) · [Rotating a key](#rotating-a-key) · [Rekeying an existing trail](#rekeying-an-existing-trail) · [Key storage, and losing one](#key-storage-and-losing-one) · [Pitfalls](#️-pitfalls) · [Best practices](#-best-practices)

---

## What encryption does

`Pipeline\Stages\EncryptSensitiveData` replaces the value of every declared field with a ciphertext,
**in the same key**, and records which fields it touched and which key wrote them in the entry's
`encryption` column. It is the sixth of the seven shipped stages and runs inside the capture, in the
request — so what reaches a queue job, a buffer, the ledger or any event the package dispatches is
already ciphertext.

Of the four protections a model can declare, this is the only one a value comes back from. Redaction
and hashing are one-way by construction; exclusion never lets the value into the pipeline at all. See
[Protecting sensitive data](./02-protecting-sensitive-data.md) for the comparison, and
[Hashing and the salt](./04-hashing-and-the-salt.md) for the irreversible digest.

### There is no on/off switch

Declaring a field **is** turning encryption on for it. There is no `security.encryption.enabled`
key — an installation cannot believe it is encrypting when it is not. A field is declared in one of
two places, and the two are a **union**, never a fallback:

```php
use ElPandaPe\Sentinel\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

final class Patient extends Model
{
    use Auditable;

    protected array $auditEncrypt = ['national_id', 'diagnosis'];
}
```

```php
// config/sentinel.php
'security' => [
    'encryption' => [
        'cipher'  => 'aes-256-gcm',
        'key_id'  => 'default',
        'keys'    => ['default' => env('SENTINEL_ENCRYPTION_KEY')],
        'fields'  => ['session_id'],   // added to whatever every model declares
    ],
],
```

`Support\Config::encryptedFields()` merges the model's list with `security.encryption.fields`. The
config list is the only way to protect a key no model owns — a resolver-supplied `session_id`, a
console argument, something a service pushed into the execution context.

### Where it reaches

`Security\Fields` walks **six containers**, matching by key name at any depth: `before`, `after`,
`metadata`, `context`, both sides of every `changes` operation, and the `wheres` of a mass
operation's `criteria`. A name declared once is protected wherever it surfaces.

The Encrypter serializes, so the value round-trips with its type — an array goes in and an array
comes back out, not a JSON string.

### What it does not do

| It does not | Consequence |
|---|---|
| Encrypt field **names** | `encryption.fields` is stored in the clear and is part of the hash. That `national_id` is protected is public in every entry that carries one. |
| Encrypt the database column | The column is ordinary `jsonb`/`json`. Anyone with `select` reads ciphertext, not nothing. |
| Reach a value stored under a different key name | The walk matches names, never values. A secret a resolver copied into `notes` is written in the clear. |
| Decrypt on read | `$audit->after['national_id']` is a ciphertext string. `Audit::toArray()` omits the `encryption` block entirely. |
| Encrypt a `null` | The field still counts as *found* — see [Pitfalls](#️-pitfalls). |
| Descend into a matched key | Declaring a container name (`profile`, `arguments`) replaces the whole subtree with one opaque string. |

> 🔒 **Security.** Every event the package dispatches about a captured entry — `Auditing`,
> `AuditDiscarded`, `AuditCreating`, `AuditCreated`, `Audited` — is dispatched at or after the end of
> the pipeline, so no listener of Sentinel's own events sees a declared plaintext. Eloquent's own model events fire before any of
> this and are not covered.

---

## The keyring

`Security\Keyring` maps an **identifier** to an `Illuminate\Encryption\Encrypter`. It is `@internal`:
you configure it, you never call it. The identifier is the whole point — an entry records which key
wrote it, so yesterday's entries keep decrypting with yesterday's key while today's are written with
today's.

| Key | Default | What it does | When you change it |
|---|---|---|---|
| `security.encryption.cipher` | `aes-256-gcm` | The cipher every Encrypter on the ring is built with. **Not recorded per entry.** | Almost never — see the warning below. |
| `security.encryption.key_id` | `default` | The identifier every **new** entry is written with and stamped into `encryption.key_id`. | On every rotation, after the new key is on the ring. |
| `security.encryption.keys` | `['default' => env('SENTINEL_ENCRYPTION_KEY')]` | Identifier → key material. Keep every retired key here for as long as its entries matter. | Whenever you add or retire a key. |
| `security.encryption.fields` | `[]` | Key names to encrypt, added to every model's `$auditEncrypt`. | For keys no model owns. |

Key material is read as raw bytes, or with a `base64:` prefix decoded the way the framework writes
one (`Keyring::key()`). `aes-256-gcm` wants 32 bytes:

```bash
# 32 random bytes, in the form the config expects
printf 'SENTINEL_ENCRYPTION_KEY=base64:%s\n' "$(head -c 32 /dev/urandom | base64)"
```

### The `default` fallback, and only that one

`Config::encryptionKey()` falls back to `app.key` for the identifier `default` and for no other. Any
other identifier is named on purpose, and quietly writing it with a key it did not name would make
the recorded `key_id` a lie — so it throws instead:

```php
// config/sentinel.php
'key_id' => '2027',
'keys'   => ['default' => env('SENTINEL_ENCRYPTION_KEY')],   // no '2027' entry
```

```
ElPandaPe\Sentinel\Exceptions\EncryptionException
Sentinel has no key [2027] on its keyring. Declare it under
sentinel.security.encryption.keys, or point sentinel.security.encryption.key_id at one
that exists. A key that leaves the keyring takes the values it wrote with it: entries
keep verifying, and stop being readable.
```

### How the keyring refuses

| Failure | Exception | Message names |
|---|---|---|
| Identifier not on the ring, and not `default` | `EncryptionException::unknownKey` | the identifier |
| Cipher rejects the key (wrong length, bad base64) | `EncryptionException::unusableKey` | the identifier and the cipher |
| `keys` is not a map, or a key is not a string | `ConfigurationException::expected` | `security.encryption.keys[.<id>]` |
| `key_id` or `cipher` is not a non-empty string | `ConfigurationException::expected` | the config path |
| No `default` key and no `app.key` either | `ConfigurationException::missingApplicationKey` | tells you to run `key:generate` |

`tests/Security/NoKeyMaterialTest.php` asserts what these messages must never contain: the key
material itself. A failure names the identifier and stops there.

> ⚠️ **Warning.** The cipher is **not** recorded in the entry — only the `key_id` is. Changing
> `security.encryption.cipher` on an installation with data makes every existing ciphertext
> undecryptable until the old cipher is restored. Rotating a key is supported; rotating a cipher is
> not.

> 📌 **Note.** `Keyring` is bound as a **scoped** singleton and memoizes one Encrypter per
> identifier. Editing `security.encryption.keys` mid-process is not noticed — it is noticed on the
> next request or the worker's next job.

---

## What an entry records

```json
{
  "fields": ["diagnosis", "national_id"],
  "key_id": "default"
}
```

That is the whole of the `encryption` column, and it is enough to read the entry back later: the
field names say where the ciphertext is, the identifier says which key opens it. The field list is
**sorted**, and the sort is not cosmetic — the column is inside the canonical payload, so an
unstable order would give the same fact two different hashes.

What the column deliberately does not carry: the cipher, the salt, and any key material.

| Situation | `encryption` column |
|---|---|
| A declared field surfaced somewhere in the entry | `{"fields": [...], "key_id": "..."}` |
| No declared field surfaced | `null` — not an empty block |
| A declared field surfaced holding `null` | The name is listed, and **no ciphertext exists for it** |
| No field declared anywhere | `null`, and no key is resolved at all |

That last row is worth stating plainly: an installation that encrypts nothing needs no encryption key
on the ring. `EncryptSensitiveData::handle()` returns before touching the keyring when the declared
list is empty.

### Getting a value back

Nothing in the read path decrypts. `Audit::find()`, `Sentinel::audits()`, `toArray()` and the
exporters all hand back the ciphertext as it was stored, and `toArray()` drops the `encryption`
block so an API consumer is not told which fields are protected and which key is current.

The supported route back to a plaintext is the restore engine, which decrypts with the identifier
**the entry recorded** — not the one the config names today:

```php
use ElPandaPe\Sentinel\Enums\Omission;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Restore\Restorer;

$entry  = Audit::query()->findOrFail($id);
$result = app(Restorer::class)->restore($entry);

$result->applied;                    // ['diagnosis'] — keys only, never values
$result->skipped;                    // ['national_id' => Omission::KeyUnavailable]
$result->reason('national_id');      // Omission::KeyUnavailable
```

See [Restoring state](../06-reading/08-restoring-state.md) for the full set of refusals.

---

## The hash covers the ciphertext

An entry's `hash` is taken over the canonical JSON of twenty-seven columns, and the value it hashes
for a protected field is the **ciphertext** — the plaintext is never hashed, never canonicalised and
never seen by `Integrity\Hasher`. `CanonicalPayload::from()` decrypts nothing.

This is a deliberate trade, and both halves of it belong in your threat model.

### What it buys

- **Verification with no key at all.** `Sentinel::verifyIntegrity()` recomputes the hash from stored
  columns. An auditor, an isolated verification job or a read-only replica can prove every entry
  untouched while holding nothing that could read one.
- **A forged rotation is not quiet.** `encryption` is one of the twenty-seven canonical columns, so
  swapping a stored `key_id`, or swapping one ciphertext for another, breaks the entry's hash. Both
  cases are frozen in `tests/Security/FrozenCiphertextTest.php`.
- **No format fork.** The ciphertext replaces the value inline rather than being wrapped in an
  object, so protecting a field costs no `payload_version`. Encrypted and unencrypted entries are
  the same format, and the frozen dataset asserts it.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

// The verifier holds nothing that could read a single value.
config(['sentinel.security.encryption.keys' => []]);

Sentinel::verifyIntegrity('tenant:acme')->isIntact();   // true
```

> 🧪 **Verify it.** Put that `config()` line at the top of whatever job or test does your verifying.
> A regression that made the chain depend on decryptable plaintext then fails in CI instead of in an
> auditor's hands.

### What it gives up

- **The chain proves the row, not the value.** It attests that this ciphertext is the one that was
  written at this sequence under this key. It says nothing about what the plaintext was. Anything
  that could produce a wrong ciphertext at capture time gets it sealed just as faithfully.
- **Ciphertexts do not compare.** AES-GCM is non-deterministic: the same value encrypted twice
  produces two different strings (`tests/Security/EncryptSensitiveDataTest.php`, "gives the same
  value a different ciphertext every time"). You cannot group by an encrypted field, join on one, or
  read "unchanged" off two ciphertexts. If comparability is what you need, hash the field instead.
- **The field name is public.** `encryption.fields` is stored and hashed in the clear.

> ⚠️ **Warning.** Never add a pipeline stage that recomputes `changes` from `before`/`after` **after**
> `EncryptSensitiveData`. Two ciphertexts of the same value never match, so every field would report
> as changed and `FilterUnchanged` would stop filtering. The shipped stage reads the diff the capture
> already produced and never re-compares values — see [The write pipeline](./01-the-write-pipeline.md).

---

## Rotating a key

Rotation is two edits and, optionally, one command. The two edits are all that is needed for **new**
entries; the command is what moves history.

```php
// config/sentinel.php
'security' => [
    'encryption' => [
        'cipher' => 'aes-256-gcm',
        'key_id' => '2027',                                   // 1. what NEW entries use
        'keys'   => [
            'default' => env('SENTINEL_ENCRYPTION_KEY'),      // 2. retired, and STILL NEEDED
            '2027'    => env('SENTINEL_ENCRYPTION_KEY_2027'),
        ],
    ],
],
```

| Step | Effect |
|---|---|
| Add the new key under `keys` | Nothing changes yet; the ring can now build that Encrypter. |
| Move `key_id` to the new identifier | Every entry written from now on records the new identifier. Entries already written are untouched and keep their own. |
| Leave the old key under `keys` | Old entries stay readable. Removing it is what makes them unreadable — permanently. |
| Run `sentinel:rekey` (optional) | Appends new entries carrying the same values under the new key. See below. |

An installation that only needs *new* secrets under a new key stops after step three. Rekeying is for
the case where the old key is compromised or is about to be destroyed.

> ⚠️ **Warning.** Laravel's `APP_PREVIOUS_KEYS` does not help here. `Keyring::build()` constructs a
> single-key `Encrypter` per identifier; the framework's previous-keys mechanism is never in play.
> Old entries stay readable because they recorded which key wrote them, not because an encrypter
> tries several.

> ⚠️ **Warning.** `APP_KEY` is silently doing two jobs if you left them unpinned:
> `security.encryption.keys.default` falls back to it, and `security.hashing.salt` derives from it.
> Rotating `APP_KEY` without first pinning `SENTINEL_ENCRYPTION_KEY` **and** `SENTINEL_HASH_SALT`
> makes every encrypted value unreadable and every digest incomparable in one move, with no error.

---

## Rekeying an existing trail

`Security\Rekeyer` **writes; it never rewrites.** The original entry is protected by its own hash and
the model is immutable by contract — re-encrypting it in place would break the chain it belongs to.
What happens instead is a new entry carrying the same values under the new key, pointing back at the
one it stands in for.

```php
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Security\Rekeyer;

$rekeyer = app(Rekeyer::class);

foreach (Audit::query()->whereNotNull('encryption')->cursor() as $entry) {
    $rekeyer->rekey($entry, '2027');   // returns the new Audit, or null when there is nothing to do
}
```

`rekey()` returns `null` — writing nothing — when the entry carries no encrypted field, names no key,
is already under the target key, or has already been rotated to it.

### What the rotation entry is

| Column | Value |
|---|---|
| `audit_type` | `security` |
| `event` | `rekeyed` (severity `notice` by default) |
| `source_audit_id` | the original entry's id |
| `metadata.rekeyed` | `{audit_id, from, to}` |
| `encryption` | the original's field list, under the new `key_id` |
| `subject_type` / `subject_id` / `tenant_id` / `source` | copied from the original |
| `context`, `before`, `after`, `changes` | copied, then re-encrypted |
| `sequence`, `previous_hash`, `hash` | assigned by the ledger like any other entry |

And what it is **not**: `Rekeyer::carry()` enumerates what it copies, and `criteria`,
`affected_rows`, `tags` and the original's actor columns are not among them. A rotation entry is not
a replacement for the original — it is a second readable copy of the values, chained at the tail.

> ⚠️ **Warning.** Rotating a mass-operation entry whose only encrypted value lived in
> `criteria.wheres` produces a rotation entry that declares `encryption.fields` for a field it does
> not contain. The readable copy of that value stays in the original, under the old key.

### What it does to the hash

Nothing, to the original. It keeps its `hash`, its `previous_hash` and its `sequence` byte for byte
(`tests/Security/RekeyerTest.php`, "leaves the original entry byte for byte where it was"), and keeps
verifying with the key that wrote it for as long as that key stays on the ring. The rotation entry is
sealed and linked like any other write, so the stream verifies whole afterwards.

> 📌 **Note.** `Rekeyer` bypasses the pipeline and the dispatcher — it writes straight to the ledger,
> synchronously, whatever `sentinel.mode` says. So a rotation entry gets no policy check, no labels
> stage, no context resolution and no `Auditing` event. That is correct — its values are already
> transformed and running them through again would encrypt ciphertext — but it means the entry's
> `context` is the **original event's** ip, url and user agent, not the operator's who ran the
> rotation.

### The command

```bash
php artisan sentinel:rekey --key=2027 --limit=500 --dry-run
php artisan sentinel:rekey --key=2027 --limit=500
php artisan sentinel:rekey --key=2027 --limit=500 --after=01JB7Q…
```

| Option | Default | What it does |
|---|---|---|
| `--key=` | the current `key_id` | The identifier to re-encrypt under. |
| `--tenant=` | — | Only entries of this tenant. |
| `--type=` | — | Only this `audit_type`. |
| `--limit=` | `500` | How many entries to **read**, oldest first. |
| `--after=` | — | Resume behind this entry id, as printed by the previous pass. |
| `--dry-run` | off | Counts the entries it read and writes nothing. |

Exit `0` on a pass that ran (rotation or dry run); exit `2` when the rotation threw — a `--key` that
is not on the ring, most often.

The walk is oldest-first and bounded by `--limit`, so **a bare second run reads the same entries
again**. Chain the passes on the identifier each one prints:

```
Re-encrypted 118 of the 500 entries read. The originals keep their hash, their link and
their sequence, and keep verifying while their old key stays on the keyring.
The last entry read was 01JB7Q…. Pass --after=01JB7Q… to carry on behind it.
```

Running the same range twice is safe rather than duplicating, because a rotation's `capture_id` is
derived from the entry id and the target key (`Rekeyer::identity()`) and is checked before the write.
That check requires the ledger to implement `Contracts\Deduplicates`; a ledger that cannot answer is
taken at its word and the write goes ahead.

See [Export and rekey](../08-lifecycle/06-export-and-rekey.md) for running this as an operational
procedure, and [Artisan commands](../09-operations/06-artisan-commands.md) for the rest of the CLI.

---

## Key storage, and losing one

Keys belong to the application and live **outside the database the entries do**. That is what makes
the protection worth anything: a stolen dump of `sentinel_audits` is a pile of ciphertext.

| Rule | Why |
|---|---|
| Key material reaches `config/sentinel.php` only through `env()` | The published config file is committed; the values must not be. |
| No key in the repository, in a fixture, or in a test | A key in git history is a key you cannot rotate away from for that history. |
| `config:cache` writes resolved values to `bootstrap/cache/config.php` | That file then contains your keys in plain text. Keep it out of images you publish and off shared volumes. |
| Retired keys stay on the ring | They are the only thing that can still read the entries they wrote. |
| The verifying environment gets no keys at all | It does not need them, and the hash proves that. |

Sentinel never puts key material into an entry, an exception message or a log line. `key_id` is what
travels; `tests/Security/NoKeyMaterialTest.php` asserts that a whole trail written with a named
encryption key, a named signing key and a named hashing salt contains all three **identifiers** and
none of the three **values**.

### When a key is gone

There is no recovery path in this package, and there is not going to be one. The position is:

| What still works | What does not |
|---|---|
| `verifyIntegrity()` on those entries — the hash is over ciphertext | Reading the protected values, by any route |
| Every query, filter and export — the ciphertext is returned as stored | `Restorer` on those fields: `Omission::KeyUnavailable`, skipped rather than written back as ciphertext |
| The rest of the entry: actor, event, timestamps, unprotected fields | `sentinel:rekey` over those entries — the source key cannot be built, so the pass throws and exits `2` |

That asymmetry is the intended behaviour and is stated in the exception itself: *entries keep
verifying, and stop being readable*.

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `encryption` names a field, but `after.<field>` is `null` and there is no ciphertext | `Fields::protect()` marks a field as found before transforming it, and the encrypting closure short-circuits on `null` | Read `encryption.fields` as "these declared names were found here", never as proof a ciphertext exists. Check the value. |
| A whole subtree became one opaque string | A declared name is also a container key (`profile`, `arguments`, `payload`); the walk transforms the matched key's value and does not descend | Declare the leaf names, not the container. |
| `EncryptionException: no key [2027]` on the first write after a config change | `key_id` was moved before the key was added under `keys`; only `default` falls back to `app.key` | Add the key first, move `key_id` second. |
| Every existing ciphertext stopped decrypting after a deploy that changed nothing about keys | `security.encryption.cipher` was changed. The cipher is not recorded per entry — only `key_id` is | Restore the old cipher. Rotate keys, never ciphers. |
| Everything encrypted became unreadable *and* every digest stopped matching, in one deploy | `APP_KEY` was rotated while `SENTINEL_ENCRYPTION_KEY` and `SENTINEL_HASH_SALT` were unset and deriving from it | Pin both explicitly before the first protected entry is written, in every environment that will outlive one `APP_KEY`. |
| A rotation pass reports `Re-encrypted 0 of the 500 entries read` | `--limit` bounds entries **read**, not entries rotated, and the query is not narrowed to encrypted entries | Keep chaining passes with `--after`; narrow with `--tenant`/`--type` if the trail is mostly unprotected. |
| A second bare pass reports the same numbers and advances nowhere | The walk is oldest-first and bounded, so it re-reads the same prefix. Idempotency stops it duplicating, not repeating | Feed each pass the identifier the previous one printed via `--after`. |
| `sentinel:rekey` exits `2` saying `Nothing was re-encrypted`, but new rotation entries exist | The catch wraps the whole batch loop, and entries rotated before the failure are already written | Trust the trail, not the message. Re-run from `--after` the last identifier that succeeded. |
| A restored record came back holding a ciphertext string | The field was removed from `$auditEncrypt`/`security.encryption.fields`, so `Planner::readable()` no longer recognises it as encrypted and returns the stored value as-is | Leave a field declared as long as entries that encrypted it may be restored. |
| A restore silently skipped a field with `redacted_field` or `hashed_field` | The field is declared under `$auditRedact` or `$auditHash` — neither reverses | Encrypt what has to come back; do not double-declare. A field in two lists is transformed twice, in stage order. |
| A test rotation had no effect after `config()->set(...)` | `Keyring` is a scoped singleton and memoizes one Encrypter per identifier | Forget scoped instances, or set the configuration before the keyring is first resolved. |
| Rotation entries themselves got rotated on a later pass | A rotation entry is an ordinary entry with `audit_type = 'security'`; the command's query does not exclude them | Narrow with `--type` when you intend to move only business entries. |

---

## ✅ Best practices

✅ **Do** — pin `SENTINEL_ENCRYPTION_KEY` explicitly, in every environment, before the first
protected entry is written. Left unset, the `default` identifier derives from `APP_KEY`, and an
ordinary `APP_KEY` rotation then destroys every encrypted value with no error.

```php
'keys' => ['default' => env('SENTINEL_ENCRYPTION_KEY')],
```

❌ **Don't** — leave the ring empty and rely on the `app.key` fallback in production. The fallback
exists so a local install boots, not so a production install depends on a key that is rotated for
unrelated reasons.

```php
'keys' => [],   // silently becomes APP_KEY, for the 'default' identifier only
```

---

✅ **Do** — declare each field under exactly **one** of `$auditExclude`, `$auditRedact`,
`$auditHash`, `$auditEncrypt`, and pick by what you will need later. Encryption is the only one a
value returns from.

```php
protected array $auditExclude = ['remember_token'];  // never reaches the pipeline
protected array $auditRedact  = ['email'];           // recognisable, unusable
protected array $auditHash    = ['card_number'];     // comparable, unreadable
protected array $auditEncrypt = ['national_id'];     // recoverable, with the key
```

❌ **Don't** — put one field in two lists. `MaskSensitiveData` runs before `EncryptSensitiveData`, so
a redacted-and-encrypted field is stored as an encrypted **mask** and the plaintext is unrecoverable
even with the key. Nothing warns about the combination.

```php
protected array $auditRedact  = ['national_id'];
protected array $auditEncrypt = ['national_id'];   // encrypts the mask, not the value
```

---

✅ **Do** — rotate by adding the new key, moving `key_id`, and leaving the retired key on the ring.
Every entry records the identifier that wrote it, so old entries keep opening with old keys.

```php
'key_id' => '2027',
'keys'   => [
    'default' => env('SENTINEL_ENCRYPTION_KEY'),        // retired, still needed
    '2027'    => env('SENTINEL_ENCRYPTION_KEY_2027'),
],
```

❌ **Don't** — remove a retired key to tidy up. Every value it wrote becomes unreadable
permanently, and nothing in the package can bring it back — the entries go on verifying while
`Restorer` reports `Omission::KeyUnavailable` for those fields forever.

```php
'keys' => ['2027' => env('SENTINEL_ENCRYPTION_KEY_2027')],   // 'default' entries are now dark
```

---

✅ **Do** — rekey a large trail with chained, resumable passes, and dry-run the first one.

```bash
php artisan sentinel:rekey --key=2027 --limit=500 --dry-run
php artisan sentinel:rekey --key=2027 --limit=500
php artisan sentinel:rekey --key=2027 --limit=500 --after=01JB7Q…
```

❌ **Don't** — re-encrypt an entry in place, by any route. The row is sealed by its own hash and the
model refuses updates; a query-builder update slips past the model guards and shows up later as
`hash_mismatch` on the whole stream.

```php
Audit::query()->where('id', $id)->update(['after' => $reEncrypted]);   // breaks the chain
```

---

✅ **Do** — verify with an empty keyring wherever verification runs, so the property the design
bought stays true.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

config(['sentinel.security.encryption.keys' => []]);

Sentinel::verifyIntegrity('tenant:acme')->isIntact();
```

❌ **Don't** — describe a passing verification as proof of what a protected value said. The hash
covers the ciphertext; it proves the row is the one that was written, and nothing about the
plaintext.

```php
// Wrong reading of a true result:
// "the patient's national_id was 12345678Z and the chain proves it"
```

---

✅ **Do** — use rekeying for a compromised key and redaction for an erasure request. They are
opposites, and no path of either calls the other.

```bash
php artisan sentinel:rekey  --key=2027                     # preserves, under a new lock
php artisan sentinel:redact 01JB7Q… --reason="…" --actor=… # destroys the content
```

❌ **Don't** — reach for a redaction to "clean up" duplicate rotation entries. Redaction destroys
content and leaves a tombstone; it is not an undo. Rotation entries are valid, chained and
verifiable, and this package deletes nothing.

```bash
php artisan sentinel:redact 01JB7Q… --reason="tidying"   # not what this is for
```

---

**See also:** [Protecting sensitive data](./02-protecting-sensitive-data.md) · [The write pipeline](./01-the-write-pipeline.md) · [Hashing and the salt](./04-hashing-and-the-salt.md) · [The hash chain](../07-integrity/01-the-hash-chain.md) · [Canonicalization](../07-integrity/03-canonicalization.md) · [Verification](../07-integrity/06-verification.md) · [Export and rekey](../08-lifecycle/06-export-and-rekey.md) · [Redaction and tombstones](../08-lifecycle/04-redaction-and-tombstones.md) · [Restoring state](../06-reading/08-restoring-state.md) · [Configuration](../99-reference/02-configuration.md) · [Exceptions](../99-reference/06-exceptions.md) · [Security checklist](../13-best-practices/03-security-checklist.md)
