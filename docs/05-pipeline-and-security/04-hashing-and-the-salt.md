# 🛡️ Hashing and the salt

> How a field is turned into a salted digest so two entries can be compared without either of them
> being readable — and the one setting that decides whether that comparison keeps working.

**On this page:** [What a digest is for](#what-a-digest-is-for) · [The construction](#the-construction) · [Declaring a hashed field](#declaring-a-hashed-field) · [Where the digest lands](#where-the-digest-lands) · [The salt](#the-salt) · [Rotating the salt](#rotating-the-salt) · [What hashing does not do](#what-hashing-does-not-do) · [Configuration](#configuration)

---

## What a digest is for

Hashing answers exactly one question about a value: **is this the same value as that one?**

It answers it without the value being readable, without a key, and without anything to lose if the
database is copied. Two entries that carry the same digest carried the same value; two entries that
carry different digests carried different values. There is no third thing you can ask.

That is a narrow contract, and the narrowness is the point. Sentinel ships four treatments for a
sensitive field and they are not interchangeable:

| Treatment | Declared as | What lands in the entry | Reverses? | Answers "did it change?" |
|---|---|---|---|---|
| Exclusion | `$auditExclude` | nothing — the field never reaches the pipeline | — | no |
| Redaction | `$auditRedact` | a mask, produced by a `Masker` | no | only if the masker is deterministic |
| **Hashing** | **`$auditHash`** | **a hex digest, fixed length** | **no** | **yes** |
| Encryption | `$auditEncrypt` | ciphertext, plus `{fields, key_id}` in the `encryption` column | with the key | yes |

Pick hashing when the value has to be comparable across entries and must never be readable in any of
them. A session identifier is the canonical case: you want to see that four entries belong to the
same session without the session token itself being sitting in an audit table.

> 📌 **Note.** There is no `security.hashing.enabled` switch, by design. Declaring a field name in
> `$auditHash` or `security.hashing.fields` *is* turning hashing on for it. Nobody can believe they
> are hashing a field they never named.

## The construction

`Security\Digester` builds one digest, and the whole of it is three inputs concatenated:

```
digest = hash( algorithm , salt . "\x1f" . canonicalize(['value' => $value]) )
```

- **`algorithm`** — `security.hashing.algorithm`, default `sha256`. Validated against PHP's
  `hash_algos()`; anything else throws `ConfigurationException` listing the accepted set. It decides
  the length of what you store: 64 hex characters for `sha256`, 128 for `sha512`.
- **`salt`** — `security.hashing.salt`. See [The salt](#the-salt).
- **`"\x1f"`** — an ASCII unit separator between the salt and the value, so a salt of `abc` with a
  value of `de` cannot collide with a salt of `ab` and a value of `cde`.
- **`canonicalize(…)`** — the value is wrapped as `['value' => $value]` and rendered by the same
  canonicaliser the hash chain uses (see [Canonicalization](../07-integrity/03-canonicalization.md)),
  so the representation of a value is decided in one place and two runs agree byte for byte.

So the digest of the string `ada@example.com` is `hash('sha256', $salt."\x1f".'{"value":"ada@example.com"}')`.

Three consequences fall straight out of that shape and all three surprise people:

- **A null is not hashed.** `Digester::digest(null)` returns `null`. The absence of a value stays
  the absence of a value; it is not turned into "the digest of nothing", which every entry would
  then share.
- **The type is part of the digest.** `1` canonicalises to `{"value":1}` and `'1'` to `{"value":"1"}`.
  Changing a model's cast on a hashed column changes every future digest for the same underlying
  data, and old digests stop matching new ones.
- **Object key order does not matter; list order does.** `['b' => 1, 'a' => 2]` and
  `['a' => 2, 'b' => 1]` produce the same digest, because the canonicaliser sorts object members.
  `['a', 'b']` and `['b', 'a']` do not, because in a list the position is the meaning.

> ⚠️ **Warning.** A hashed field whose value is an **object** throws
> `CanonicalizationException` — "a canonical payload holds scalars, arrays and null" — from inside
> the pipeline, which takes the write that triggered it down with it. `on_write_failure` does not
> cover this: that policy applies to the ledger write, and the pipeline runs before it. The same
> value under `$auditRedact` does not throw, because the shipped masker returns four mask characters
> for anything it cannot stringify. The realistic route in is `Sentinel::withContext()` putting a
> value object under a key you named in `security.hashing.fields`.

## Declaring a hashed field

On a model, as a list of column names:

```php
use ElPandaPe\Sentinel\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

final class Patient extends Model
{
    use Auditable;

    /** @var list<string> */
    protected array $auditHash = ['national_id'];
}
```

And in configuration, for keys no model owns — a context key, a metadata key, a console argument:

```php
// config/sentinel.php
'security' => [
    'hashing' => [
        'algorithm' => 'sha256',
        'salt' => env('SENTINEL_HASH_SALT'),
        'fields' => ['session_id'],
    ],
],
```

The two lists are a **union, never a fallback**. `Config::hashedFields()` appends the configured
list to whatever the model declared and de-duplicates. That is deliberate: a key like `session_id`
belongs to no model, so the config list has to apply to entries whose subject declared something of
its own. A model that declares nothing still gets the configured fields hashed.

> ⚠️ **Warning.** A column declared in `$auditTransitions` may not also be hashed. `Support\AuditPolicy`
> refuses the combination at construction with `ConfigurationException::unreadableTransition` — "a
> lifeline the entry cannot show is not a lifeline". See
> [State transitions](../03-capture/08-state-transitions.md).

## Where the digest lands

`MaskSensitiveData` hands the field list to `Security\Fields`, which walks **six containers** and
matches **by key name, at any depth**:

| Container | What is walked |
|---|---|
| `before` | every key, recursively |
| `after` | every key, recursively |
| `metadata` | every key, recursively |
| `context` | every key, recursively |
| `changes` | if the operation's JSON Pointer `path` contains a protected segment, its `old` and `new`; otherwise the operation is walked by key name |
| `criteria` | only `wheres` — the `value` and each element of `values` of a clause whose `column` is protected, nested groups included |

Two things follow. First, a name declared once is covered wherever it surfaces — you do not declare
it again for the diff, the metadata or the mass-operation criteria. Second, **matching a key replaces
the whole value at that key**: `Fields::walk` transforms a matched key and does not descend into it,
so declaring a container key like `profile` digests the entire subtree under it as one value.

For a change operation, the `path` survives untouched. The entry proves that `/national_id` moved
without saying what it moved from or to — which is usually exactly what you want on the diff.

```php
// after MaskSensitiveData, an entry whose model declares $auditHash = ['national_id']
[
    'path' => '/national_id',
    'op'   => 'replace',
    'old'  => 'c1f0…64 hex characters…',
    'new'  => '9ab7…64 hex characters…',
]
```

**Field names are never hashed — only values.** `national_id` being a hashed field is public
information in every entry that has one, and it is inside the canonical payload, so it is part of the
chain hash. See [The hash chain](../07-integrity/01-the-hash-chain.md).

## The salt

`security.hashing.salt` is prefixed to every value before it is hashed. Its job is to make the same
value digest differently in two installations, so a digest lifted from your staging database means
nothing against production, and a rainbow table built for "sha256 of an email address" means nothing
against either.

**When it is `null` or an empty string, it is derived:**

```php
hash_hmac('sha256', 'sentinel:hashing', config('app.key'))
```

The label `sentinel:hashing` is what keeps this value distinct from the signing secret, which derives
from the same `app.key` under the label `sentinel:signature`. If there is no salt **and** no
`app.key`, `Config::hashingSalt()` throws `ConfigurationException::missingApplicationKey`, whose
message tells you to run `php artisan key:generate` or declare the value yourself.

> ⚠️ **Warning.** `APP_KEY` is silently doing two jobs here. With `SENTINEL_HASH_SALT` unset,
> rotating `APP_KEY` rotates your hashing salt — and, if `SENTINEL_ENCRYPTION_KEY` is also unset,
> your encryption key at the same time. Neither change reports anything. Laravel's
> `APP_PREVIOUS_KEYS` does not help: nothing in the digest path tries a second value.

> 🔒 **Security.** The salt never reaches an entry. `Config::hashingSalt()` is read inside the
> `Digester` and nothing writes it anywhere; `ConfigurationException::expected` names the config key
> and the *type* it was given, never the value; `php artisan about` prints six rows about Sentinel
> and no key material of any kind. A test asserts that a run writing a redacted, an encrypted and a
> hashed field produces stored rows containing neither the encryption key, the signing key nor the
> salt.

> 🧪 **Verify it.** Check whether your salt is pinned or derived:
> `php artisan tinker --execute="dd(config('sentinel.security.hashing.salt'));"`
> A `null` there means it is coming out of `APP_KEY`.

## Rotating the salt

The salt is **stable by definition**. That phrase is doing real work, so here is what it costs.

Rotating the salt **does not break the hash chain.** The chain hash is computed over what is stored —
the digest, not the value behind it — so every entry written before the rotation goes on reproducing
its own hash and `Sentinel::verifyIntegrity()` keeps returning intact. Nothing to repair.

Rotating the salt **destroys every comparison the digests existed to support.** From the moment it
changes, the same value digests differently, so:

- An entry from before the rotation and an entry from after it never match, for any value.
- Grouping "all the entries from one session" silently splits into two groups at the rotation point.
- `AuditQuery::compare()` diffs the stored `after` snapshots of two versions, so a hashed field
  reads as changed across the boundary even though nothing moved. See
  [Field history and comparing versions](../06-reading/04-field-history.md).

And unlike the encryption key, **there is no way to migrate the history.** `sentinel:rekey` and
`Security\Rekeyer` re-encrypt; they read a value back under one key and write it under another, and
they return `null` for an entry that carries no encrypted field. A digest cannot be re-salted,
because the value it was made from is gone. See
[Export and rekey](../08-lifecycle/06-export-and-rekey.md).

### What to do instead

| You want to… | Do this |
|---|---|
| Pin the salt so `APP_KEY` rotation is safe | Set `SENTINEL_HASH_SALT` explicitly, **before the first hashed entry is written**, in every environment that will outlive one `APP_KEY`. |
| Rotate `APP_KEY` on an installation that never pinned it | Read the current derived salt once — `hash_hmac('sha256', 'sentinel:hashing', $oldAppKey)` — and pin *that* string as `SENTINEL_HASH_SALT` before changing `APP_KEY`. The digests stay comparable. |
| Stop a compromised salt from mattering | Accept that it does not help the digests already written, and change what the field is: move it to `$auditEncrypt` so future values are recoverable and re-keyable, or to `$auditExclude` so they stop being recorded. Rotating the salt protects future writes only. |
| Compare a value against history | Reproduce the construction under the same salt and algorithm, or read the entries and compare digests to each other in PHP. |
| Change the algorithm | Only before the first digest is written. `sha256` → `sha512` has exactly the same consequence as a salt rotation, with a different digest length on top. |

> 📌 **Note.** `Security\Digester` is marked `@internal`. The construction above is stable enough to
> reproduce — it is what the chain's own canonicaliser and PHP's `hash()` do — but the class is not
> part of the published surface, so do not type-hint it. See
> [API stability](../99-reference/09-api-stability.md).

## What hashing does not do

State these to yourself before you reach for `$auditHash`.

- **It is not encryption.** There is no key, and there is no path back. `Restore\Planner` refuses a
  hashed field outright with `Omission::HashedField` — "The :key was stored as a digest, which cannot
  be reversed." If a value may ever have to come back, encrypt it. See
  [Restoring state](../06-reading/08-restoring-state.md) and
  [Encryption and the keyring](03-encryption-and-the-keyring.md).
- **It is not a one-way guarantee over a small domain.** `hash()` is a plain, fast, unkeyed digest —
  not a password hash, with no work factor. Anyone holding both the database and the salt can digest
  a candidate list and match it against the column. Email addresses, national identity numbers,
  phone numbers and postcodes are all small enough domains for that to work in one pass. The salt
  raises the cost of a *pre-built* table; it does not raise the cost of a targeted one.
- **It does not hide equality.** One salt for the whole installation means identical values produce
  identical digests everywhere. That is the feature, and it is also a disclosure: an entry set
  reveals which records shared a value even when no value is readable.
- **It is not searchable through the package.** No shipped filter matches a field's value. The
  published filters cover the columns (`whereEvent()`, `whereIp()`, `whereRoute()`, …) and, for the
  diff, `whereFieldChanged()` by JSON Pointer path — never by value. And nothing indexes `before` or
  `after`: `Support\AuditSchema` indexes the subject, actor, tenant, transaction, request, trace,
  type, event and severity columns, and the one optional JSON index the package publishes covers
  `context.ip` and `context.route` only. A query that matched a digest inside `after` walks the
  table. See [Filters reference](../06-reading/02-filters-reference.md) and
  [Indexes and JSON](../10-database-engines/05-indexes-and-json.md).
- **It does not stop a value leaking under another name.** Protection matches key *names*. A value a
  resolver copied into `notes`, or a model wrote into a differently-named column, is recorded in the
  clear. See [Protecting sensitive data](02-protecting-sensitive-data.md).

> 🐘 **Engine.** The digest lands in a JSON column: `jsonb` on PostgreSQL, `json` on MySQL, text on
> SQLite. Neither `jsonb` nor MySQL's `json` preserves key order or whitespace, so never compare two
> of these columns as raw text to decide whether two entries agree — decode them, or use the engine's
> JSON path operators. Hash stability does not depend on the stored bytes: the chain re-canonicalises
> in PHP on read, which is why a hashed entry verifies identically on SQLite, MySQL 9 and
> PostgreSQL 16.

## Configuration

| Key | Default | What it does | When you change it |
|---|---|---|---|
| `security.hashing.algorithm` | `'sha256'` | The digest algorithm, validated against `hash_algos()`. Decides the stored length (64 hex chars for `sha256`, 128 for `sha512`). | Only before the first digest is written. Changing it later has the same cost as rotating the salt. |
| `security.hashing.salt` | `env('SENTINEL_HASH_SALT')`, and when null `hash_hmac('sha256', 'sentinel:hashing', app.key)` | Prefixed to the canonicalised value, separated by `\x1f`, so two installations disagree on the digest of the same value. | Set it once, explicitly, and never again. |
| `security.hashing.fields` | `[]` | Key names to digest, **added** to every model's `$auditHash`. Matched at any depth in the six containers. | For keys no model owns — `session_id` is the canonical case. |

Both `algorithm` and `salt` are read on every digest, from `Support\Config`, so a change to either
takes effect on the next entry with no cache to clear — and with no warning that the digests either
side of it no longer compare.

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| Every hashed field reads as changed after a deploy, on records nobody touched | `SENTINEL_HASH_SALT` was unset and `APP_KEY` was rotated, so the derived salt changed with it | Pin `SENTINEL_HASH_SALT` before rotating `APP_KEY`; restore the old key's derived salt with `hash_hmac('sha256', 'sentinel:hashing', $oldAppKey)` if the old key still exists |
| Digests stop matching between staging and production for the same value | Different `APP_KEY`s, hence different derived salts — which is the intended isolation | Nothing to fix. Do not compare digests across installations; they are deliberately incomparable |
| A save throws `CanonicalizationException`: "a canonical payload holds scalars, arrays and null" | A hashed key holds an object — usually a value object pushed in with `Sentinel::withContext()` | Cast it to a scalar before it reaches the context, or redact the key instead of hashing it |
| A whole nested structure came back as one 64-character string | The declared name is also a container key; `Fields::walk` transforms a matched key without descending | Declare the leaf key names, not the container's |
| `ConfigurationException` names `security.hashing.algorithm` with a list of accepted values | The algorithm is not in PHP's `hash_algos()` on this machine | Use one that is, and check the same build in CI and production |
| `ConfigurationException` says to run `php artisan key:generate` | No salt configured and no `app.key` to derive one from | Set `APP_KEY`, or declare `SENTINEL_HASH_SALT` |
| A hashed field is stored as `null`, not as a digest | `Digester::digest(null)` returns `null` by design | Nothing to fix — the absence of a value is not a value |
| A restore skips the field with `hashed_field` | `Restore\Planner` refuses hashed fields outright | If the value has to come back, it must be encrypted, not hashed. Change the declaration; entries already written keep the treatment they were written with |
| The same field is stored as a digest of asterisks | It is declared in both `$auditRedact` and `$auditHash`; `MaskSensitiveData` masks first and digests the mask | Declare each field under exactly one treatment |
| A query filtering on a digest inside `after` is slow at volume | Nothing indexes `before` or `after` | Read the entries with an indexed filter first and compare digests in PHP |

## ✅ Best practices

✅ **Do** — pin the salt explicitly, in every environment, before the first hashed entry is written.
The derived default ties your digests to `APP_KEY`, and the day someone rotates that key is the day
your comparisons quietly stop working.

```bash
# .env — set once, then treat as immutable
SENTINEL_HASH_SALT=6f0a1c8b4e2d7a93c5f18b0e4a6d2c7f9b3e5a1d8c0f2b4e6a9d3c7f1b5e8a02
```

❌ **Don't** — leave `security.hashing.salt` at `null` in production and rotate `APP_KEY` later.

```php
// config/sentinel.php — the salt now lives wherever APP_KEY happens to be today
'hashing' => ['salt' => null],
```

---

✅ **Do** — pick the treatment from what you will need later, and declare a field under exactly one of
them. Hashing is for "did it change"; encryption is for "give it back".

```php
final class Patient extends Model
{
    use Auditable;

    /** @var list<string> */
    protected array $auditHash = ['national_id'];      // comparable, never readable

    /** @var list<string> */
    protected array $auditEncrypt = ['diagnosis'];     // recoverable with the key
}
```

❌ **Don't** — declare the same column twice and expect the treatments to be independent.
`MaskSensitiveData` runs the redaction list and then the hashing list over the same entry, so this
digests the mask, not the value — and every patient whose mask has the same shape now shares a digest.

```php
protected array $auditRedact = ['national_id'];
protected array $auditHash = ['national_id'];   // digests '1****9', not the id
```

---

✅ **Do** — name context-only keys in `security.hashing.fields`. Everything pushed into the execution
context is audited; the config list is the only way to protect a key no model owns.

```php
// config/sentinel.php
'security' => ['hashing' => ['fields' => ['session_id']]],
```

❌ **Don't** — push a token into the context and assume the pipeline will notice it.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

// 'reset_token' is in no list, so it is written to `context` in the clear
Sentinel::withContext(['reset_token' => $token], fn () => $user->save());
```

---

✅ **Do** — treat a digest as an equality token and compare digests to each other, in PHP, over a set
you narrowed with an indexed filter.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Models\Audit;

$sessions = Sentinel::audits()
    ->for($patient)
    ->take(200)
    ->get()
    ->map(static fn (Audit $audit): mixed => $audit->context['session_id'] ?? null)
    ->filter()
    ->unique()
    ->count();
```

❌ **Don't** — reach for a raw `LIKE` against the JSON column to find "the entries with this value".
There is no index there, and on PostgreSQL and MySQL the stored text is not the text you wrote.

```php
Audit::query()->whereRaw("after::text LIKE ?", ["%{$digest}%"])->get();
```

---

✅ **Do** — say out loud, in your own threat model, that a digest over a small domain is not
anonymisation. If someone holding the database and the `.env` must not be able to recover the value,
hashing is not the tool.

```php
// config/sentinel.php — the value must be unrecoverable even to us
'security' => ['redaction' => ['fields' => ['card_number']]],
```

❌ **Don't** — describe a hashed email column to a compliance reviewer as "anonymised". It is
pseudonymised at best: one pass over a candidate list, with the salt in hand, recovers it.

---

✅ **Do** — settle the algorithm and the salt at installation time and record the decision, because
neither has a migration path.

```php
'hashing' => [
    'algorithm' => 'sha256',
    'salt' => env('SENTINEL_HASH_SALT'),
    'fields' => ['session_id'],
],
```

❌ **Don't** — "refresh security" by rotating the salt on a schedule. It repairs nothing, protects
nothing already written, and destroys the only thing a digest was ever for.

---

**See also:** [Protecting sensitive data](02-protecting-sensitive-data.md) · [Encryption and the keyring](03-encryption-and-the-keyring.md) · [Writing a masker](05-writing-a-masker.md) · [The write pipeline](01-the-write-pipeline.md) · [Canonicalization](../07-integrity/03-canonicalization.md) · [Restoring state](../06-reading/08-restoring-state.md) · [Configuration](../99-reference/02-configuration.md) · [Security checklist](../13-best-practices/03-security-checklist.md)
