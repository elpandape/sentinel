# 🛡️ Protecting sensitive data

> The four mechanisms — exclude, redact, hash, encrypt — as a decision you make once per field:
> what each one promises, what it refuses to promise, and exactly how far into an entry it reaches.

**On this page:** [The four mechanisms](#the-four-mechanisms) · [Choosing one](#choosing-one) · [What each one actually does](#what-each-one-actually-does) · [Declaring them](#declaring-them) · [The union rule](#the-union-rule) · [Where protection reaches](#where-protection-reaches) · [Where it does not reach](#where-it-does-not-reach) · [Two mechanisms on one field](#two-mechanisms-on-one-field) · [Who can undo it](#who-can-undo-it) · [⚠️ Pitfalls](#️-pitfalls) · [✅ Best practices](#-best-practices)

---

## The four mechanisms

Sentinel has four ways to keep a value out of the trail, and they are not interchangeable. Three of
them happen in pipeline stages that rewrite the entry before the ledger seals it. The fourth happens
earlier and is not a transformation at all — the value simply never gets built into the entry.

| | Exclude | Redact (mask) | Hash | Encrypt |
|---|---|---|---|---|
| Declared with | `$auditExclude` | `$auditRedact` | `$auditHash` | `$auditEncrypt` |
| Config list | *none* | `security.redaction.fields` | `security.hashing.fields` | `security.encryption.fields` |
| Where it happens | `Snapshot\SnapshotBuilder` | `Stages\MaskSensitiveData` | `Stages\MaskSensitiveData` | `Stages\EncryptSensitiveData` |
| What lands in the entry | nothing — the key is absent | `c****s@e****e.c****m` | 64 hex chars (sha256) | ciphertext, plus `{fields, key_id}` in `encryption` |
| Recoverable | ❌ never | ❌ never | ❌ never | ✅ with the key it names |
| Two equal values look equal | n/a | ⚠️ yes, and unequal ones can too | ✅ yes, within one installation | ❌ no — the ciphertext is randomised |
| Type survives | n/a | ❌ becomes a string | ❌ becomes a string | ✅ round-trips through the encrypter |
| Survives export / archive | n/a — nothing to export | ✅ as the mask | ✅ as the digest | ✅ as the ciphertext |
| Who can undo it | nobody | nobody | nobody | whoever holds `security.encryption.keys.<key_id>` |
| Proves the field changed | ❌ no — the diff never sees it | ✅ path and both sides kept | ✅ path and both digests kept | ✅ path kept, but see below |

> 📌 **Note.** "Proves the field changed" under encryption is weaker than it looks. Two encryptions
> of the same plaintext differ, so `/national_id` appearing in `changes` means the entry was
> written, not that the value moved. Under a mask or a digest, equal sides really do mean an
> unchanged value.

---

## Choosing one

One field, one mechanism. The question that picks it is always the same: **what will you need from
this value in eighteen months?**

| You will need… | Use | Because |
|---|---|---|
| nothing at all; the value must not exist in the trail | `$auditExclude` | It is dropped before the entry is built, so no stage, no event and no discard can leak it. |
| a human to recognise it in a UI without being able to use it | `$auditRedact` | The mask keeps the shape — an address still looks like an address. |
| to answer "did this change?" and nothing more | `$auditHash` | The digest is stable per installation, so two entries compare. |
| the value itself, to read or to restore | `$auditEncrypt` | It is the only mechanism the package can reverse. |

> ⚠️ **Warning.** The restore engine enforces this rather than advising it.
> `Restore\Planner` refuses a field declared redacted (`Omission::RedactedField`) or hashed
> (`Omission::HashedField`) outright, before it even looks at the value. If a field may ever have to
> come back, encrypt it. See [Restoring state](../06-reading/08-restoring-state.md).

---

## What each one actually does

### Exclude

`SnapshotBuilder::keys()` is the only place in the package that applies `$auditExclude`. It drops the
named keys before the snapshot is built, which means they are absent from `before`, from `after`,
and — because the diff is computed from the snapshot pair — from `changes` as well.

Two things ride alongside it in that same method:

- **`$auditInclude` beats `$auditExclude`.** When an include list is declared, the builder returns
  `array_intersect($keys, $policy->included)` and never consults the exclusions or `$hidden` at all.
- **`$hidden` is audited by default.** `snapshots.include_hidden` defaults to `true`. Set it to
  `false` and every attribute in the model's `$hidden` is dropped exactly as an exclusion is.

### Redact

`Stages\MaskSensitiveData` walks the entry and replaces each matched value with whatever the field's
`Contracts\Masker` returns. The shipped default is `Security\PartialMasker`:

| Input | Output | Rule |
|---|---|---|
| `carlos@example.com` | `c****s@e****e.c****m` | first and last character of each alphanumeric run, four mask characters between |
| `A. B.` | `****. ****.` | a run shorter than three characters is replaced whole |
| `1234567` | `1****7` | scalars are stringified first — the number comes back a string |
| `['name' => 'Ada']` | `['name' => 'A****a']` | arrays are mapped over, the structure survives |
| an object | `****` | four mask characters, nothing else |
| `null` | `null` | the absence of a value is not a value |

The mask width is fixed at four **on purpose**: padding to the original length would hand back how
long the secret was.

> 🔒 **Security.** A mask is not anonymisation. On a small domain the shape plus both ends is often
> enough to identify a person, and the package promises a mask and nothing more. If that is not
> enough, [write a masker](05-writing-a-masker.md) that returns a constant.

### Hash

`Security\Digester` prefixes the installation salt, a `\x1f` separator and the canonicalised value
(`['value' => $value]` through the same canonicaliser the chain uses), then hashes with
`security.hashing.algorithm`. Same value, same digest — that is the entire promise.

Two consequences worth knowing before you rely on it:

- A `null` stays `null`. The absence of a value is not digested.
- A value the canonicaliser cannot render — an object left in `context` by a resolver — throws
  `CanonicalizationException` at that point. The same value under `$auditRedact` would have quietly
  become `****`. The two irreversible treatments fail differently on the same input.

The salt derives from `APP_KEY` when `security.hashing.salt` is null. See
[Hashing and the salt](04-hashing-and-the-salt.md) for why you must pin it before the first write.

### Encrypt

`Stages\EncryptSensitiveData` replaces the value **inline, in the same key** — the snapshot's shape
does not change, only the value does — and records what it did:

```json
"encryption": { "fields": ["expires_at", "national_id"], "key_id": "default" }
```

There is deliberately **no on/off switch**. Declaring a field is what turns encryption on for it. An
entry where no declared field surfaced gets `encryption = null`, not an empty block.

> 🔒 **Security.** The chain hash is computed over the ciphertext and is unkeyed, so
> `verifyIntegrity()` works in an environment holding no key at all. `encryption` is inside the
> canonical payload, so a stored `key_id` or a swapped ciphertext no longer reproduces the entry's
> hash. The trade is stated rather than hidden: **the chain proves the row is the one that was
> written, not what the value said.** See [The hash chain](../07-integrity/01-the-hash-chain.md).

---

## Declaring them

```php
use ElPandaPe\Sentinel\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

final class Patient extends Model
{
    use Auditable;

    /** Never reaches the pipeline. No stage, no event, no discard can leak it. */
    protected array $auditExclude = ['remember_token'];

    /** Masked: a human recognises it, nobody uses it, nobody gets it back. */
    protected array $auditRedact = ['email', 'phone'];

    /** Digested: proves it moved, gives back neither side. */
    protected array $auditHash = ['insurance_number'];

    /** Ciphertext plus {fields, key_id}. The only mechanism that reverses. */
    protected array $auditEncrypt = ['national_id'];
}
```

A pivot table has no class of its own to declare on, so **the parent that owns the relation declares
for its pivot columns** with the same three properties:

```php
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class Team extends Model
{
    use Auditable;

    protected array $auditRedact = ['role'];
    protected array $auditEncrypt = ['expires_at'];

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Member::class)->withPivot('role', 'expires_at');
    }
}
```

> ⚠️ **Warning.** `$auditExclude` does **not** reach a pivot column. Pivot state is read straight off
> `$pivot->getAttributes()` with only the two foreign keys removed. A pivot value you cannot afford
> to store must be redacted, hashed or encrypted — dropping it is not an option the code offers.

A column named in `$auditTransitions` may not appear in any of the four lists. `Support\AuditPolicy`
refuses the combination at construction with `ConfigurationException::unreadableTransition`: a
lifeline the entry cannot show is not a lifeline. See
[State transitions](../03-capture/08-state-transitions.md).

---

## The union rule

`Support\Config::redactedFields()`, `hashedFields()` and `encryptedFields()` all call one private
`union()`. The model's declared list and the config list are **added together, deduplicated, model's
order first**. The config list never replaces a model's, and a model's never suppresses the config's.

```php
// config/sentinel.php
'security' => [
    'redaction' => [
        'mask'    => '*',
        'fields'  => ['ip', 'user_agent', 'url'],   // added to every model's $auditRedact
        'masker'  => null,                          // null means the shipped PartialMasker
        'maskers' => ['ip' => App\Sentinel\NetworkMasker::class],
    ],
    'hashing' => [
        'algorithm' => 'sha256',
        'salt'      => env('SENTINEL_HASH_SALT'),
        'fields'    => ['session_id'],
    ],
    'encryption' => [
        'cipher' => 'aes-256-gcm',
        'key_id' => 'default',
        'keys'   => ['default' => env('SENTINEL_ENCRYPTION_KEY')],
        'fields' => [],
    ],
],
```

This is not a convenience. **It is the only way to protect a key no model owns.** `ip`, `user_agent`,
`url`, `session_id`, a console argument name and anything you push in with `Sentinel::withContext()`
belong to no Eloquent model, so there is nowhere to declare them but here — and the list has to keep
applying to entries whose subject *did* declare something of its own.

The rule holds even where there is no model at all. An entry with `subject_type = null` — a custom
event, a compliance read, an authentication entry — gets `AuditPolicy::none()` and is protected by
the config lists alone.

> 📌 **Note.** `Support\PolicyRegistry` resolves the subject type through
> `Relation::getMorphedModel()`, so a morph alias finds the model behind it. A subject type that is
> not a class, or a class that is not a model, declares nothing rather than failing.

> ⚠️ **Warning.** There is a **second, older** redaction list that this mechanism never absorbed.
> `resolvers.command.redact` (default `['password', 'token', 'secret']`) masks the *value* of any
> console argument whose **name** matches by case-insensitive substring, with a fixed width of eight,
> inside `ResolveContext` —
> before `MaskSensitiveData` runs. It shares the mask character and nothing else. Two lists, two
> configurations, both of which you have to maintain. See
> [The ten resolvers](../04-context/02-resolvers-reference.md).

---

## Where protection reaches

Redaction, hashing and encryption all run through one walker, `Security\Fields`, which matches **by
key name, at any depth, in six containers**. A name declared once is protected wherever it surfaces.

| Container | Exclude | Redact / hash / encrypt | How |
|---|---|---|---|
| `before`, `after` | ✅ key absent | ✅ | walked recursively; a matched key's whole value is transformed |
| `changes` — model entry | ✅ op absent | ✅ | the diff comes from the snapshots, so exclusion removes the operation; the pointer path is matched segment by segment and only `old`/`new` are transformed |
| `changes` — mass **summary** | ❌ | ✅ | built from the update values array, not the snapshot — exclusion never sees it |
| `changes` — relation lines | ❌ | ✅ | a line carries no `path`, so the whole line is walked and `pivot_before` / `pivot_after` are descended into |
| `metadata` | ❌ | ✅ | walked recursively |
| `context` | ❌ | ✅ | walked recursively, resolver output and `withContext()` alike |
| `criteria.wheres` | ❌ | ✅ | `value` and every element of `values`, on a clause whose `column` matches; nested groups are descended into |

Two design choices inside that walk are worth stating out loud:

**A protected field that changed still shows its path.** `Fields::changes()` transforms `old` and
`new` and leaves `path` alone, so `/national_id` stays visible in the entry. The trail proves
something moved without saying what it moved to.

**Matching a key replaces the whole value at that key.** The walk uses mutually exclusive arms: if
the key matches, the value is transformed and *not* descended into. Declaring a name that is also a
container key — `profile`, `arguments`, `payload` — replaces the entire subtree beneath it. Under a
mask that degrades gracefully (`PartialMasker` maps over arrays); under the encrypter the whole
subtree becomes one opaque string.

### Downstream of the entry

Everything below reads what the pipeline already produced, so the protection travels with it.

| Destination | What it carries | Note |
|---|---|---|
| The queue job (`mode = queue`) | the transformed entry | The pipeline runs inside the capture, never behind the queue. |
| The buffer (`mode = buffered`) | the transformed entry | Same reason. A process that dies holding entries loses records, never plaintext. |
| The write-failure log line (`on_write_failure = log`) | identity and the exception, never the payload | `Capture\WriteFailure` logs `audit_type`, `event`, the subject and the transaction. Asserted by a test that sweeps the log for the declared values. |
| `sentinel_audit_relations` projection | masked / encrypted pivot values | `Ledger\RelationProjection` derives the rows from the sealed `changes`. |
| A cold archive batch | ciphertext, masks, digests, and the `encryption` block | `Archive\Line` carries the entry's own columns. Cold storage holds no plaintext. |
| `sentinel:export` / `Audit::toArray()` | the values as sealed | ⚠️ **but not** the `encryption` block — `toArray()` omits it, so an export consumer sees ciphertext with no field list and no `key_id`. See [Presenting and serializing](../06-reading/07-presenting-and-serializing.md). |
| Every event the package dispatches | the transformed entry | `Auditing` fires at the **end** of the pipeline; `AuditDiscarded` carries identity only, never a payload. |

---

## Where it does not reach

This is the half a security review needs. `Fields::protect` touches six containers and no others.

| Not protected | Why | What to do instead |
|---|---|---|
| `actor_type`, `actor_id`, `impersonator_type`, `impersonator_id`, `tenant_id`, `request_id`, `trace_id`, `span_id`, `source` | `ContextEngine` promotes these out of the payload into their own columns before the protection stages run. The walker never sees them. | Do not put a secret in an identifier. Write your own resolver if the value needs shaping. |
| `subject_type`, `subject_id` | Identity, and the subject decides which chain signs the entry. `Pipeline::announce()` restores both after the `Auditing` event for exactly that reason. | Nothing. A subject id is not a place for a secret. |
| Labels | Labels live in their own table, are outside the canonical payload, and are not one of the six containers. | Never derive a label from a protected value. |
| `criteria` outside `wheres` — the columns of an upsert, `unique_by`, `update`, `rows`, the tables of a join, a `{"type":"raw"}` clause | Deliberately treated as the query's own vocabulary, which holds nothing of the caller's. | A raw fragment already records nothing but its type. Do not hand a mass builder a value in a column name. |
| `affected_rows`, `sequence`, `hash`, `previous_hash`, `signature` | Structural. | Nothing. |
| Field **names** | `encryption.fields` is inside the canonical payload and is sorted so it hashes stably. That `national_id` is encrypted is public information in every entry that has one. | Accept it, or exclude the field so no entry mentions it. |
| A value stored under a different key name | The walk matches names, not values. It does not scan. | Name every key the value can appear under. |
| `sentinel_access_log.query` (compliance mode) | Written from the query's description, not from the sealed entry. | It records identifiers and field *names*, never values — the risk is nil, but do not extend it to carry one. |

> ⚠️ **Warning.** A `Sentinel::filter()` policy and an `Auditing` listener both run **after**
> `MaskSensitiveData` and `EncryptSensitiveData`. That is intentional — a listener holding plaintext
> is the leak the pipeline exists to close — but it means a policy cannot branch on the plaintext of
> a protected field. Decide on `subject_type`, on `event`, on `metadata` you put there yourself.

> 🐘 **Engine.** The six content columns are `jsonb` on PostgreSQL and `json` on MySQL, and neither
> preserves key order. Never compare a protected JSON column as text across engines; hash stability
> comes from re-canonicalising in PHP on read. See
> [Canonicalization](../07-integrity/03-canonicalization.md).

---

## Two mechanisms on one field

Nothing warns you, and the result is almost never what you wanted.

`MaskSensitiveData` applies the redaction list and *then* the hashing list to the same entry.
`EncryptSensitiveData` runs after both. Each transformation sees the previous one's output:

| Declared in | Stored value | Recoverable |
|---|---|---|
| `$auditRedact` + `$auditHash` | the digest **of the mask** | ❌ — and it no longer compares to a digest of the real value |
| `$auditRedact` + `$auditEncrypt` | an encrypted **mask** | ❌ — decrypting returns `c****s@e****e.c****m` |
| `$auditHash` + `$auditEncrypt` | an encrypted digest | ❌ |
| `$auditExclude` + anything | nothing | n/a — the field never arrives |

```php
// ❌ silently produces an encrypted mask
protected array $auditRedact  = ['national_id'];
protected array $auditEncrypt = ['national_id'];
```

Remember that the config lists are a union with the model's, so a field the model encrypts and the
config redacts is the same trap arriving from two files.

---

## Who can undo it

Exactly one code path hands a plaintext back to an application: `Restore\Planner::readable()`. The
only other place that decrypts at all is `Security\Rekeyer`, which opens a value under the old key
and immediately re-encrypts it under the new one, without the plaintext ever leaving the method.

```php
use ElPandaPe\Sentinel\Enums\Omission;

$result = $audit->restore(['national_id', 'email', 'insurance_number']);

$result->applied;                        // ['national_id'] — keys only, never the values written back
$result->skipped['email'];               // Omission::RedactedField
$result->skipped['insurance_number'];    // Omission::HashedField
```

Three rules govern that path, and each is a decision you cannot revisit later:

1. **A redacted or hashed field is refused before the value is read.** The refusal is decided from
   the *current* declaration — model plus config — so declaring a field redacted today makes it
   unrestorable from entries written before the declaration.
2. **An encrypted field is decrypted with the `key_id` the entry recorded**, not the current one.
   That is what makes rotation possible. If the key is gone, the field comes back as
   `Omission::KeyUnavailable` and the rest of the restoration proceeds.
3. **How an entry was written decides how it is read.** A field the model declares encrypted today
   but that this entry stored in the clear goes back as it is.

Everything else on the read path hands you the stored value untouched: the Query API, the presenter,
`sentinel:show`, and `sentinel:export`. There is no "decrypt for display" helper, by design.

> 🔒 **Security.** Losing a key loses the values it wrote. The entries keep verifying and stop being
> readable, and that is the intended behaviour: the keyring belongs to the application and lives
> outside the database the entries do. See
> [Encryption and the keyring](03-encryption-and-the-keyring.md) and
> [Export and rekey](../08-lifecycle/06-export-and-rekey.md).

> 🧪 **Verify it.** Prove the chain does not depend on your keys:
> ```php
> config(['sentinel.security.encryption.keys' => []]);
> Sentinel::verifyIntegrity('tenant:acme')->isIntact();   // still true
> ```
> Run that in CI and a regression that made verification need a key fails loudly, rather than in an
> auditor's hands.

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `encryption` names a field but the value in `after` is `null` | `Fields::protect` marks a field as *found* before the transform runs, and the encrypting closure short-circuits on `null`. The block lists what was found, not what was ciphered. | Check the value itself. Never read `encryption.fields` as proof a ciphertext exists. |
| `whereIp('203.0.113.7')` returns nothing after you added `ip` to `security.redaction.fields` | The filter matches `context->ip`, which now holds `2****3.****.1****3.****`. | Filter on a value you did not protect, or drop `ip` from the redaction list and accept that it is stored. |
| A whole subtree became one opaque string | You declared a name that is also a container key (`profile`, `arguments`, `payload`). The walk transforms the matched key's value without descending. | Declare the leaf key names, not the container. |
| A masked integer column breaks a downstream consumer | `PartialMasker` stringifies every scalar: `1234567` becomes `'1****7'`. | Expect a string from any masked field, or write a masker that preserves the type. |
| `CanonicalizationException` from an entry that used to write fine | A `$auditHash` field (often a config-level one matched inside `context`) received an object. The digester canonicalises first and the canonicaliser refuses objects. | Normalise in your resolver, or redact the field instead — `PartialMasker` returns `****` for an unrepresentable value. |
| Two entries with the same secret have different `after` values | Encryption is randomised — a test asserts it explicitly. | Use `$auditHash` when you need equal values to look equal. |
| Every value in the trail lost its meaning after an `APP_KEY` rotation | `security.encryption.keys.default` falls back to `app.key`, and `security.hashing.salt` falls back to `hash_hmac('sha256', 'sentinel:hashing', app.key)`. Rotating `APP_KEY` breaks both at once, silently. Laravel's `APP_PREVIOUS_KEYS` does not help — the keyring builds a single-key `Encrypter` per identifier. | Pin `SENTINEL_ENCRYPTION_KEY` and `SENTINEL_HASH_SALT` before the first protected write. |
| `EncryptionException::unknownKey` on write | `security.encryption.key_id` names an identifier that is not in `security.encryption.keys`. Only `default` falls back to `app.key`; any other identifier is refused, because writing an entry with a key it did not name would make the recorded `key_id` a lie. | Declare the key under `keys` before pointing `key_id` at it. |
| An excluded column shows up in a mass **summary**'s `changes` | The summary's changes are built from the update values array by `Mass\Writes`, which never reads `$auditExclude`. | Redact, hash or encrypt the column — those *are* applied to `criteria.wheres` and to the summary's changes by pointer path. |
| A restore skips a field with `Omission::RedactedField` on an old entry that plainly holds the value | The refusal is decided from the declaration as it stands today, not from what the entry recorded. | Decide protection before you write history, not after. |
| A digest you computed by hand does not match the stored one | The salt, a `\x1f` separator and the JCS canonicalisation of `['value' => $value]` all take part, and `Security\Digester` and `Contracts\Canonicalizer` are both `@internal`. | Treat digests as comparable **between entries**, not as searchable from a candidate plaintext. |

---

## ✅ Best practices

✅ **Do** — put each field in exactly one of the four lists, chosen by what you will need back.

```php
protected array $auditExclude = ['remember_token'];   // must not exist
protected array $auditRedact  = ['email'];            // a human must recognise it
protected array $auditHash    = ['insurance_number']; // only "did it change" matters
protected array $auditEncrypt = ['national_id'];      // it has to come back
```

❌ **Don't** — declare one field twice. The transformations chain in stage order, and the second one
transforms the first one's output.

```php
protected array $auditRedact  = ['national_id'];
protected array $auditEncrypt = ['national_id'];   // stores an encrypted '1****7'
```

---

✅ **Do** — name context-only keys in the config lists. They are the only way to protect something no
model owns, and `url` in particular carries the query string.

```php
'security' => [
    'redaction' => ['fields' => ['ip', 'user_agent', 'url']],
    'hashing'   => ['fields' => ['session_id']],
],
```

❌ **Don't** — assume the pipeline will catch a secret you pushed into the execution context.
Everything in `context` is audited; that is what the context is for.

```php
Sentinel::withContext(['api_token' => $token], fn () => $order->save());
// written in the clear unless 'api_token' is named in a config list
```

---

✅ **Do** — declare the leaf key, and declare it under every name it can appear under.

```php
'security' => ['encryption' => ['fields' => ['national_id', 'nif', 'tax_id']]],
```

❌ **Don't** — declare a container key. `Fields::walk` transforms the matched key's whole value and
never descends into it.

```php
'security' => ['encryption' => ['fields' => ['profile']]],
// the entire profile subtree becomes one opaque string in before, after and changes
```

---

✅ **Do** — pin the encryption key and the hashing salt explicitly, in every environment that will
outlive one `APP_KEY`, before the first protected entry is written.

```dotenv
SENTINEL_ENCRYPTION_KEY=base64:…
SENTINEL_HASH_SALT=…
```

❌ **Don't** — leave both null and rely on `APP_KEY`. Rotating it makes every encrypted value
unreadable and every historical digest incomparable, in one move, with no error.

```php
'encryption' => ['keys' => ['default' => null]],   // silently becomes app.key
'hashing'    => ['salt' => null],                  // silently derives from app.key
```

---

✅ **Do** — declare pivot protection on the parent that owns the relation, using the same properties.

```php
protected array $auditRedact  = ['role'];
protected array $auditEncrypt = ['expires_at'];
```

❌ **Don't** — expect `$auditExclude` to drop a pivot column. Pivot state is read off
`$pivot->getAttributes()`; only the two foreign keys are removed.

```php
protected array $auditExclude = ['role'];   // has no effect on the pivot line
```

---

✅ **Do** — verify with an empty keyring in whatever job or environment does the verifying, so the
keyless guarantee is tested rather than assumed.

```php
config(['sentinel.security.encryption.keys' => []]);

expect(Sentinel::verifyIntegrity('global')->isIntact())->toBeTrue();
```

❌ **Don't** — build your own decrypting view helper and call it a read path. Only
`Restore\Planner` decrypts, and it refuses redacted and hashed fields on purpose; a helper that
worked around that would quietly undo the decision the model made.

```php
$plain = decrypt($audit->after['national_id']);   // wrong key on a rotated entry, and unaudited
```

---

**See also:** [The write pipeline](01-the-write-pipeline.md) · [Encryption and the keyring](03-encryption-and-the-keyring.md) · [Hashing and the salt](04-hashing-and-the-salt.md) · [Writing a masker](05-writing-a-masker.md) · [What a model declares](../02-getting-started/03-what-a-model-declares.md) · [Snapshots](../03-capture/02-snapshots.md) · [Relationship auditing](../03-capture/04-relationships.md) · [Mass operations](../03-capture/05-mass-operations.md) · [Execution context](../04-context/01-execution-context.md) · [Restoring state](../06-reading/08-restoring-state.md) · [The hash chain](../07-integrity/01-the-hash-chain.md) · [Redaction and tombstones](../08-lifecycle/04-redaction-and-tombstones.md) · [Security checklist](../13-best-practices/03-security-checklist.md) · [Configuration](../99-reference/02-configuration.md)
