# 🔐 Canonicalization

> Why the same entry has to produce the same bytes on every engine, in every process and in every
> year — and exactly which rules make that true.

**On this page:** [Why a canonical form exists](#why-a-canonical-form-exists) · [Where it is used](#where-it-is-used) · [The encoding rules](#the-encoding-rules) · [The list and object hazard](#the-list-and-object-hazard) · [Replacing the canonicalizer](#replacing-the-canonicalizer) · [Reproducing a hash outside the package](#reproducing-a-hash-outside-the-package) · [Debugging a mismatch](#debugging-a-mismatch)

---

## Why a canonical form exists

A hash is a function of bytes. Sentinel's chain hashes an audit entry twice in its life, in two
different places, and the two have to agree exactly:

| When | Where | What is hashed |
|---|---|---|
| On write | `Ledger\EntryBuilder::build()` calls `Integrity\Hasher::hash()` **before** the row is inserted | the PHP arrays the pipeline produced, still in memory |
| On verification | `Integrity\Content::of()` calls the same `Hasher::hash()` on a model loaded from the table | the PHP arrays `json_decode()` produced from whatever the engine stored |

Between those two moments the payload makes a round trip through a JSON column. That round trip is
not lossless in the way most people assume. `Support\AuditSchema` declares seven of the twenty-seven
sealed columns with `$table->jsonb(...)`:

```php
$table->jsonb('context');
$table->jsonb('before');
$table->jsonb('after');
$table->jsonb('changes');
$table->jsonb('metadata');
$table->jsonb('encryption');
$table->jsonb('criteria');
```

Laravel's schema grammars turn that into a different type on each engine:

| Engine | Column type emitted | What it stores | Does the key order you wrote survive? |
|---|---|---|---|
| PostgreSQL 16 | `jsonb` | a parsed binary form | **No** — object keys are reordered on the way in |
| MySQL 9 | `json` | a parsed binary form | **No** — object keys are reordered on the way in |
| SQLite | `text` | the exact string Laravel sent | Yes |

The package states this about itself, in `Integrity\Projections::pivot()`, which had to be written
because of it: `changes` is `jsonb` and sorts the keys of an object on the way in, while
`pivot_before`/`pivot_after` in `sentinel_audit_relations` are declared with `json()` and keep the
text exactly as it arrived. The same map therefore comes back from the two columns in two different
orders, and the projection check has to decode and deep-sort both sides before it can compare them
at all.

Now follow that through. If the chain hashed `json_encode($audit->before)`, then:

1. You write an entry whose `before` is `['name' => 'Ada', 'email' => 'ada@example.com']`.
2. PostgreSQL stores it and hands it back as `{"email": "…", "name": "Ada"}`.
3. Verification re-encodes that and gets different bytes from the ones it hashed.
4. `verifyIntegrity()` reports `IntegrityBreak::HashMismatch` — a tampering alarm caused by the
   database doing exactly what its manual says it does.

That failure would fire on every entry, on two of the three supported engines, forever. **The
canonical form exists to make the hash independent of anything an engine, a PHP version or a
`php.ini` directive is entitled to change.** Sentinel uses RFC 8785 (JSON Canonicalization Scheme),
implemented in `Integrity\JsonCanonicalizer`.

> 📌 **Note.** This is also why key order inside the JSON columns is **not** a serialization
> guarantee. `Pipeline\Stages\NormalizeData` sorts four of them at capture — `before`, `after`,
> `metadata` and `context` — so two entries carrying the same facts read alike, but what comes back
> out of a JSON column is the engine's business. The hash does not care either way. See
> [Presenting and serializing](../06-reading/07-presenting-and-serializing.md).

## Where it is used

`Contracts\Canonicalizer` has one method, `canonicalize(array $payload): string`, and three callers
inside the package:

| Caller | What it canonicalizes | Why it matters |
|---|---|---|
| `Integrity\Hasher::hash()` | `CanonicalPayload::from($audit)` — the twenty-seven sealed columns | the hash chain itself |
| `Security\Digester::digest()` | `['value' => $value]` | a hashed field must compare across entries — see [Hashing and the salt](../05-pipeline-and-security/04-hashing-and-the-salt.md) |
| `Archive\BatchWriter::encode()` | one line of an archived batch | a batch has to be readable back into an entry that still reproduces its hash |

The full sealing formula is in [The hash chain](01-the-hash-chain.md); the part this page owns is
the last term:

```
hash = digest( payload_version ⟨0x1f⟩ stream ⟨0x1f⟩ sequence ⟨0x1f⟩ (previous_hash ?? '')
               ⟨0x1f⟩ canonicalize(CanonicalPayload::from($audit)) )
```

`CanonicalPayload` does two things and nothing else. It names the twenty-seven columns — it is the
**only** enumeration of them in the package, because a second list would be a second payload format
— and it renders each value with `CanonicalPayload::normalize()` before the canonicalizer ever sees
it:

| Value type | Rendered as | Example |
|---|---|---|
| `BackedEnum` | `->value` | `Severity::Warning` → `"warning"` |
| `DateTimeInterface` | `format(CanonicalPayload::DATE_FORMAT)`, i.e. `'Y-m-d H:i:s.u'` | `"2026-08-26 10:00:00.123456"` |
| anything else | unchanged | `null`, `int`, `float`, `string`, `bool`, `array` |

`normalize()` runs **once per column, on the top-level value**. It does not walk into an array, so a
`CarbonImmutable` or a backed enum nested inside `metadata` is still an object when it reaches the
canonicalizer — and the canonicalizer refuses objects. Values that arrive through
`Snapshot\SnapshotBuilder` (`before`, `after`) are already flattened by the time they get here; a
`metadata` array is not, because you built it.

> ⚠️ **Warning.** `CanonicalPayload::DATE_FORMAT` carries **no timezone offset**, and neither does
> the `dateTime('occurred_at', 6)` column it renders. Two instants that differ only by offset
> canonicalize to the same string. Dates *inside* `before` and `after` are a different story: they
> were rendered earlier, at capture, by `Snapshot\SnapshotBuilder::DATE_FORMAT`
> (`'Y-m-d\TH:i:s.uP'`), which does keep the offset. See [Snapshots](../03-capture/02-snapshots.md).

`CanonicalPayload::from()` **decrypts nothing**. It reads the stored ciphertext exactly as the row
holds it, which is what lets an auditor holding no encryption key reproduce a hash byte for byte —
and what means the chain proves the row is the one that was written, not what the value said. See
[Encryption and the keyring](../05-pipeline-and-security/03-encryption-and-the-keyring.md).

## The encoding rules

### Object members are sorted by UTF-16 code unit

`JsonCanonicalizer::compare()` converts both keys to UTF-16BE and compares the bytes:

```php
return strcmp(
    mb_convert_encoding($left, 'UTF-16BE', 'UTF-8'),
    mb_convert_encoding($right, 'UTF-16BE', 'UTF-8'),
);
```

That conversion is not decoration. RFC 8785 orders members by **UTF-16 code unit**, and above the
Basic Multilingual Plane that is not the same order as UTF-8 bytes: `U+10000` is the surrogate pair
`D800 DC00` in UTF-16, which sorts *before* `U+FFFD`, while in UTF-8 it sorts *after*. Sorting by
raw UTF-8 bytes would produce a different canonical string for any payload holding an astral
character as a key. `ext-mbstring` is a hard requirement of the package for this reason.

Three consequences of the ordering, each frozen as a test vector in `tests/Fixtures/CanonicalVectors`:

- Uppercase sorts before lowercase: `['b' => 1, 'a' => 2, 'A' => 3]` → `{"A":3,"a":2,"b":1}`.
- Nesting is sorted too, at every depth: `['z' => ['b' => 1, 'a' => 2]]` → `{"z":{"a":2,"b":1}}`.
- PHP integer keys become strings and sort **as strings**, not numerically:
  `[10 => 'a', 9 => 'b']` → `{"10":"a","9":"b"}`.

### Lists are left exactly as they arrived

`JsonCanonicalizer::value()` dispatches on `array_is_list()`: a PHP array whose keys are exactly
`0..n-1` **in order** is written as a JSON array, everything else as a JSON object. A list is never
reordered — in a list, position is the meaning.

That puts the burden of a stable order on whoever builds the list. Two places in the package carry
it deliberately:

- `Data\RelationLine::canonical()` sorts the lines of a relation operation by their own ordinal at
  capture, because the canonicalizer will not, and `NormalizeData` deliberately does not touch
  `changes`. Without it, two runs of the same `sync()` could hash differently purely because the
  engine returned the pivot rows in another order. See
  [Relationship auditing](../03-capture/04-relationships.md).
- `Snapshot\SnapshotBuilder::each()` sorts maps and leaves lists alone, at every depth.

> 📌 **Note.** An empty PHP array is a list, so `[]` and `{}` are indistinguishable before they reach
> the canonicalizer. An entry whose `context` is `[]` canonicalizes as `"context":[]`, and there is
> no way to write an empty JSON *object* into a sealed column. `null` remains a third, distinct
> answer.

### Numbers

Integers and floats take different paths, and the difference is deliberate:

- `is_int($value)` → `(string) $value`. Exact, always.
- `is_float($value)` → `JsonCanonicalizer::number()`, which reproduces ECMAScript's
  `Number::toString` over the **shortest decimal that round-trips back to the same double**.

The shortest form is found by probing: `sprintf('%.0E', …)`, then `%.1E`, and so on until
`(float) $candidate === $value`, capped at 17 significant digits. It never reads
`serialize_precision`, and there is a test that sets that directive to `10` and asserts the output
does not move — a hash that depended on a `php.ini` value would be the exact failure RFC 8785 exists
to prevent.

| Input | Canonical output | Rule |
|---|---|---|
| `1` | `1` | integer, exact |
| `9007199254740993` | `9007199254740993` | integer beyond 2^53, still exact |
| `-42` | `-42` | |
| `1.0` | `1` | integral float drops its fraction |
| `-0.0` | `0` | negative zero is zero |
| `0.1` | `0.1` | shortest round-tripping decimal |
| `0.1 + 0.2` | `0.30000000000000004` | the double's real value, not a rounded one |
| `0.000001` | `0.000001` | last decimal before the exponent form |
| `1.0e-7` | `1e-7` | first value that takes an exponent |
| `1.0e20` | `100000000000000000000` | largest integral form without an exponent |
| `1.0e21` | `1e+21` | first integral value that takes one |
| `2.5e-10` | `2.5e-10` | mantissa longer than one digit |
| `5.0e-324` | `5e-324` | denormal minimum |
| `1.7976931348623157e308` | `1.7976931348623157e+308` | double maximum |

> ⚠️ **Warning.** This is a **deliberate deviation from strict JCS above 2^53**. Strict JCS routes
> every number through ECMAScript, so `9007199254740993` would canonicalize as
> `9007199254740992`. Sentinel prints a PHP integer exactly, so what is hashed is the value the JSON
> column actually holds. The deviation is frozen by the golden dataset in
> `tests/Fixtures/GoldenLedger`. **A third-party reimplementation of the verifier must copy this
> behaviour, not the RFC's.**

### Strings

Both values and keys go through `JsonCanonicalizer::string()`, which is one call:

```php
json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
```

| Input | Output | Note |
|---|---|---|
| `a/b` | `"a/b"` | slashes are not escaped |
| `ñ€` | `"ñ€"` | non-ASCII stays literal UTF-8 |
| a quote, a backslash, a tab, a newline | `\"`, `\\`, `\t`, `\n` | only what JSON requires |
| `U+0008`, `U+000C` | `\b`, `\f` | the short forms |
| `U+0001`, `U+001F` | `\u0001`, `\u001f` | control characters, **lowercase** hex |

> 📌 **Note.** If you are reimplementing the verifier outside PHP, match what PHP's `json_encode()`
> produces under those two flags, not your own reading of the RFC. The escaping of a canonical
> Sentinel string is defined by that function.

### What it refuses

The canonicalizer throws rather than guessing, and every refusal is an
`Exceptions\CanonicalizationException` — a developer-facing exception in plain English, not a
translated string:

| Input | Factory | Message |
|---|---|---|
| `NAN`, `INF`, `-INF` | `::unsupportedNumber()` | *cannot canonicalize the number [NAN]: JSON carries neither NAN nor INF* |
| an object or a resource | `::unsupportedType()` | *cannot canonicalize a value of type [stdClass]; a canonical payload holds scalars, arrays and null* |
| a string that is not valid UTF-8 | `::invalidString()` | *cannot canonicalize a string that is not valid UTF-8* |

The UTF-8 refusal applies to **keys as well as values** — both go through the same `string()`
method, and both are covered by their own test. A binary blob smuggled into `metadata` fails on the
write, at hashing time, not later.

## The list and object hazard

This is the one edge of `array_is_list()` that costs something, and it is worth understanding
because the package spends a whole read-back on it.

Take a PHP map whose keys happen to be `0..n-1` **out of order**:

```php
$value = [1 => 'second', 0 => 'first'];   // array_is_list() === false
```

The canonicalizer writes it as an object, and it sorts the keys while doing so:

```json
{"0":"first","1":"second"}
```

Read that JSON back with `json_decode($line, true)` — which is exactly what `Archive\Batch` does —
and PHP hands you `['first', 'second']`. Keys `0..n-1`, in order. `array_is_list()` now answers
**true**, so a second canonicalization writes `["first","second"]`. Different bytes, different hash,
and nothing anywhere recorded that the shape changed.

That is why `Archive\BatchWriter::write()` refuses to shorten its order of operations. It builds the
lines, compresses, digests the bytes, writes them, **reads the file back**, digests again, rebuilds
every entry out of the bytes that came back and re-hashes each one through
`Integrity\Content::holds()`. Only a batch that survives all of that is recorded, and only then may
a hot row be removed. Skip the rehash and an entry whose shape changed is archived, pruned, and
discovered to be unrestorable years later — at a point where the rows it was built from are gone and
there is nothing left to do about it.

> 🔒 **Security.** The read-back is not paranoia about the canonicalizer alone. It is also the only
> proof Laravel's `Filesystem` contract offers that the bytes landed at all. See
> [Cold archiving](../08-lifecycle/02-cold-archiving.md).

## Replacing the canonicalizer

Mechanically it is one line. `SentinelServiceProvider` registers the implementation as an ordinary
singleton:

```php
$this->app->singleton(Canonicalizer::class, JsonCanonicalizer::class);
```

so an application could rebind `ElPandaPe\Sentinel\Contracts\Canonicalizer` to something else.
**Do not.** Both the contract and `Integrity\JsonCanonicalizer` are marked `@internal`, and the
package's own surface test files the contract under *"the seams the package uses to talk to itself,
not the points somebody extends"*. It is outside the API freeze — see
[API stability](../99-reference/09-api-stability.md) and
[Swapping components](../11-extending/06-swapping-components.md).

Here is what actually happens if you swap it, and why it is a chain-breaking change rather than a
configuration choice:

1. **Verification uses the currently bound canonicalizer for every row, whatever its age.**
   `Hasher::hash()` reads the digest name off the row's `algorithm` column, so changing
   `integrity.algorithm` leaves history verifying. There is **no equivalent column for the
   canonicalizer**, and `payload_version` does not select one either — `Hasher` has no branch on it.
2. So the first `sentinel:verify` after the swap rehashes every existing entry with the new rules,
   gets different bytes, and reports `ContentState::Altered` → `IntegrityBreak::HashMismatch` at
   sequence 1 of every stream. Every one of them is a false alarm, and none of them is
   distinguishable from a real one.
3. `Security\Digester` uses the same canonicalizer, so every hashed field stops comparing to every
   value hashed before the swap.
4. Archived batches written under the old rules stop reproducing their sealed hashes, so
   rehydration refuses them.

**The compatibility rule.** Any change to the twenty-seven columns in `CanonicalPayload::COLUMNS`,
to how they are canonicalized, or to the link formula, bumps `payload_version` and ships a
backwards-compatibility test. `payload_version` is the first term of the hash prefix, and
`Ledger\EntryBuilder::PAYLOAD_VERSION` is `1` today. The backwards-compatibility test is
`tests/Ledger/GoldenDatasetTest.php`, which holds frozen entries together with the exact canonical
string and the exact hash each one produces, and asserts all three still line up — including one
case that reproduces the hash "without going through the package at all", with a literal
`hash('sha256', $prefix."\x1f".$canonical)`.

> 🧪 **Verify it.** `make test ARGS=tests/Ledger/GoldenDatasetTest.php` — while those vectors
> reproduce, `payload_version` 1 still means what it meant.

---

## Reproducing a hash outside the package

This page owns the recipe. Every other page that mentions it states the fact and links here, so that
there is one description of the formula and not five that drift.

The formula is publishable by design. `CanonicalPayload::from()` decrypts nothing, so an auditor
holding the rows and **no encryption key at all** can recompute every hash; under the `openssl`
signer they also need no private key, because the verifying half of `integrity.signature.keys`
answers the signature.

```
prefix     = payload_version ⟨0x1f⟩ stream ⟨0x1f⟩ sequence ⟨0x1f⟩ (previous_hash or "")
canonical  = RFC 8785 JSON of the 27 columns in CanonicalPayload::COLUMNS, each value first
             rendered as CanonicalPayload::normalize does — a backed enum as its ->value,
             a DateTimeInterface as 'Y-m-d H:i:s.u' (CanonicalPayload::DATE_FORMAT), and
             everything else passed through unchanged — with the one deviation from strict
             JCS documented above: a PHP integer is printed exactly, including beyond 2^53
recomputed = hash(row.algorithm, prefix ⟨0x1f⟩ canonical)
```

The four terms that get guessed wrong:

| Term | The rule | What guessing costs |
|---|---|---|
| `⟨0x1f⟩` | `Integrity\Hasher::SEPARATOR`, the ASCII unit separator. **Four** of them go into one hash: after `payload_version`, after `stream`, after `sequence`, and between the prefix and the canonical payload | The prefix parts are separated so that `("a", 11)` and `("a1", 1)` cannot produce the same link. Join them without it and two different chains agree |
| `previous_hash` | `null` is rendered as the **empty string**, never omitted — so a genesis entry has two adjacent separators | Every stream fails at sequence 1 and the report says tampering |
| the column list | Exactly the twenty-seven names in `CanonicalPayload::COLUMNS`, in that class and nowhere else. `capture_id`, `signature`, `signature_key_id`, `created_at` and the three redaction columns (`redacted_at`, `redaction_reason`, `redacted_hash`) are **outside** it | A second enumeration of the columns is a second payload format |
| `row.algorithm` | Read off the entry's own `algorithm` column, never from `integrity.algorithm` | Every entry written before the last algorithm change fails a check that is wrong |

```php
use ElPandaPe\Sentinel\Integrity\CanonicalPayload;
use ElPandaPe\Sentinel\Models\Audit;

$audit = Audit::query()->findOrFail('01JB9Z8Q0000000000000000AB');

$payload   = CanonicalPayload::from($audit);          // 27 keys, decrypts nothing
$canonical = $yourRfc8785Encoder->encode($payload);

$prefix = implode("\x1f", [
    $audit->payload_version,
    $audit->stream,
    $audit->sequence,
    $audit->previous_hash ?? '',
]);

hash_equals($audit->hash, hash($audit->algorithm, $prefix."\x1f".$canonical));   // true
```

> ⚠️ **Warning.** `CanonicalPayload::from()` and `::normalize()` are public and are the supported way
> to build the payload. `Contracts\Canonicalizer` and `Integrity\Hasher` are **not**: both are
> `@internal` and outside the API freeze, so the encoding is a specification to reimplement and not
> an API to call. The supported in-application checks stay `$audit->verifyIntegrity()` and
> `$audit->verifyContent()`.

`payload_version` is the first term of the prefix, which is what makes this recipe versioned rather
than eternal: any change to the column list, to the rendering or to the link formula bumps it and
ships a backwards-compatibility test. It is `1` today.

---

## Debugging a mismatch

A canonicalization mismatch reaches you as `IntegrityBreak::HashMismatch` on a row you believe
nobody touched, or as `ContentState::Altered` from `$audit->verifyContent()`. Telling it apart from
a real tampering is done by printing the canonical string, not by staring at the columns — the whole
point of the canonical form is that it is the thing the hash was actually taken over.

### Print what the hash was made of

```php
use ElPandaPe\Sentinel\Contracts\Canonicalizer;
use ElPandaPe\Sentinel\Integrity\CanonicalPayload;
use ElPandaPe\Sentinel\Models\Audit;

$audit = Audit::query()->findOrFail('01JB9Z8Q0000000000000000AB');

$canonical = app(Canonicalizer::class)->canonicalize(CanonicalPayload::from($audit));

$prefix = implode("\x1f", [
    $audit->payload_version,
    $audit->stream,
    $audit->sequence,
    $audit->previous_hash ?? '',
]);

$recomputed = hash($audit->algorithm, $prefix."\x1f".$canonical);

dump([
    'stored'    => $audit->hash,
    'recompute' => $recomputed,
    'match'     => hash_equals($audit->hash, $recomputed),
    'canonical' => $canonical,
    'prefix'    => str_replace("\x1f", '⟨SEP⟩', $prefix),
]);
```

> ⚠️ **Warning.** `Contracts\Canonicalizer` is `@internal`. Use this at a `tinker` prompt or in a
> throwaway diagnostic; do not build application code on it. The supported in-application checks are
> `$audit->verifyIntegrity()` and `$audit->verifyContent()`.

### Compare, in this order

| Step | What to compare | What it tells you |
|---|---|---|
| 1 | `payload_version`, `stream`, `sequence`, `previous_hash` against the row's neighbours | a prefix problem, not a payload problem — a re-streamed or renumbered row |
| 2 | `algorithm` against the entries either side of it | somebody edited the column; the rehash then runs under a different digest and cannot match |
| 3 | the canonical string against the same entry's canonical string on another engine or from a backup | a genuine canonicalization divergence |
| 4 | the canonical string character by character, one column at a time | the single column that moved |

Step 3 is the decisive one, because a canonicalization problem is reproducible and a tampering is
not: dump the same row from a second replica and canonicalize both. Two canonical strings that
differ while both rows hold the same values point at the environment; two rows that hold different
values point at whoever wrote them.

### What it usually turns out to be

| Cause | How it shows up |
|---|---|
| A `Builder::update()` on `sentinel_audits` | The immutability guard is on Eloquent model events (`ImmutableAuditException::update()`), so a query-builder update bypasses it entirely. This is by far the most common cause, and it is a real alteration, not a false alarm. |
| A migration or a data-fix script that rewrote a JSON column | Same thing wearing better clothes. Never rehash existing rows to "repair" it — that destroys the only property the chain sells. |
| A column typed by hand instead of by `AuditSchema` | e.g. `text` where the package declares `jsonb`, so an integer comes back as the string `"7"` and canonicalizes as `"7"`, not `7`. |
| A custom ledger driver that reshapes the payload | Run the contract suite — see [The contract test suite](../11-extending/04-the-contract-test-suite.md). |
| A rebound `Contracts\Canonicalizer` | Every entry breaks at once, from sequence 1 of every stream. If *everything* is broken, suspect this before suspecting an attacker. |

> 💡 **Tip.** "Every entry in every stream" and "one entry in one stream" are different diagnoses. A
> `VerificationResult` names the `sequence` and `auditId` where the walk stopped; `verifyEverything()`
> tells you whether it stopped in one stream or in all of them. See
> [Verification](06-verification.md) and [The verification playbook](07-the-verification-playbook.md).

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `before` comes back with its keys in a different order than you wrote them | `jsonb` on PostgreSQL and `json` on MySQL reorder object keys on the way in; only SQLite's `text` keeps them | Nothing to fix — the hash is unaffected because canonicalization re-sorts. Do not build code that depends on JSON key order coming back |
| `CanonicalizationException: … type [stdClass]` on a write | a value object reached `metadata` or `context` without being converted to an array | Convert it at the call site; the canonical payload holds scalars, arrays and `null` only |
| `CanonicalizationException: … not valid UTF-8` on a write | a binary string or a `latin1` byte sequence reached a sealed column, as a value **or** as a key | Encode it (base64) before it reaches the entry. The refusal is on the write, not on a later read |
| `CanonicalizationException: … neither NAN nor INF` | a division produced `INF` or `NAN` inside a snapshot | Cast to `null` or to a string before the field is captured; JSON cannot carry either |
| `CanonicalizationException: … type [Carbon\CarbonImmutable]` from a `metadata` array | `CanonicalPayload::normalize()` is applied **per column, to the top-level value only** — it does not walk into an array. A date nested inside `metadata` is still an object when it reaches the canonicalizer | Format it yourself before you pass it: `$date->format(DATE_ATOM)` |
| Two lists of the same items hash differently | lists keep the order they arrived in; the canonicalizer sorts objects and never lists | Order the list where you build it. Relation lines are already ordered for you by `Data\RelationLine::canonical()` |
| An entry's `context` reads `[]` and you expected `{}` | an empty PHP array is a list, so both render as `[]` | Use `null` when you mean "nothing to record"; `null` and `[]` are distinct in the canonical form |
| Two `occurred_at` values that differ only by UTC offset canonicalize identically | `CanonicalPayload::DATE_FORMAT` carries no offset, and neither does the `dateTime(6)` column | Keep stored instants in one timezone; if the offset has to be provable, put it in `metadata` as a string |
| Every entry in every stream reports `HashMismatch` at once | `Contracts\Canonicalizer` was rebound, or rows were rehashed by a migration | Restore the original binding; a chain cannot be repaired by rehashing it |
| An archived batch is refused with `ArchiveException::unverifiable` | an entry did not reproduce its sealed hash out of the bytes read back — often the list/object shape change | Do not force it. The refusal happened while the hot rows still exist, which is the whole point of the read-back |

---

## ✅ Best practices

✅ **Do** — keep everything that has to be provable inside the twenty-seven sealed columns, and put
structured facts in `metadata`. Labels live in a separate table that the hash does not cover, so
relabelling leaves no trace any verification can find.

```php
use App\Models\Invoice;
use ElPandaPe\Sentinel\Facades\Sentinel;

Sentinel::event('invoice.approved')
    ->subject($invoice)
    ->metadata(['approval_threshold' => 5000, 'policy' => 'four-eyes'])
    ->record();
```

❌ **Don't** — encode a provable fact as a label and expect the chain to defend it. Labels are
outside the canonical payload in both directions: adding one does not break a hash, and removing one
is undetectable.

```php
// The label is not sealed. Nothing in verification will ever notice it changed.
Sentinel::event('invoice.approved')
    ->subject($invoice)
    ->tags(['four-eyes-approved'])
    ->record();
```

✅ **Do** — hand a third party the formula and let them reimplement it. Canonicalization is
publishable by design, and `CanonicalPayload::from()` decrypts nothing, so an auditor can reproduce
every hash holding no encryption key.

```
prefix     = payload_version ⟨0x1f⟩ stream ⟨0x1f⟩ sequence ⟨0x1f⟩ (previous_hash or "")
canonical  = RFC 8785 JSON of the 27 columns in CanonicalPayload::COLUMNS, each value first
             rendered as CanonicalPayload::normalize does — a backed enum as its ->value,
             a DateTimeInterface as 'Y-m-d H:i:s.u', everything else unchanged — with PHP
             integers printed exactly, including beyond 2^53
recomputed = hash(row.algorithm, prefix ⟨0x1f⟩ canonical)
```

❌ **Don't** — tell them to call `Contracts\Canonicalizer` or `Integrity\Hasher`. Both are
`@internal` and outside the API freeze; an auditor's tool that depends on them is a tool that breaks
on a patch release.

```php
// Not a supported integration point, in your code or in theirs.
$canonical = app(Canonicalizer::class)->canonicalize(CanonicalPayload::from($audit));
```

✅ **Do** — normalise value objects into arrays and scalars *before* they reach a sealed column, so
the refusal happens at your boundary rather than at the ledger's.

```php
use App\Models\Order;
use ElPandaPe\Sentinel\Facades\Sentinel;

Sentinel::event('order.repriced')
    ->subject($order)
    ->metadata(['total' => $order->total->toArray(), 'currency' => (string) $order->currency])
    ->record();
```

❌ **Don't** — pass a `Money`, a `Collection` of models or a `SplFileObject` and hope something
flattens it. `JsonCanonicalizer::value()` throws `CanonicalizationException::unsupportedType()`
naming the class, and under the default failure policy that surfaces on the write.

```php
Sentinel::event('order.repriced')
    ->subject($order)
    ->metadata(['total' => $order->total])
    ->record();
```

✅ **Do** — order a list yourself if you build one, at the point you build it. The canonicalizer
sorts objects and never lists, because in a list the position *is* the datum.

```php
use App\Models\User;

$reviewers = User::query()->whereIn('id', $ids)->orderBy('id')->pluck('name')->all();

Sentinel::event('invoice.reviewed')
    ->subject($invoice)
    ->metadata(['reviewers' => $reviewers])
    ->record();
```

❌ **Don't** — hand over whatever order the database returned. Two identical business events will
hash differently, on the same engine, with no explanation visible in the row.

```php
// No ORDER BY: the plan decides the order, and the plan is allowed to change.
$reviewers = User::query()->whereIn('id', $ids)->pluck('name')->all();
```

✅ **Do** — treat any change to `CanonicalPayload::COLUMNS`, to the encoding, or to the link formula
as a `payload_version` bump with a frozen vector shipped alongside it, and read
`tests/Ledger/GoldenDatasetTest.php` before touching either.

```php
// The list is frozen and lives in exactly one place.
public const array COLUMNS = ['id', 'audit_type', /* … twenty-four more … */, 'occurred_at'];
```

❌ **Don't** — enumerate the sealed columns anywhere else, however convenient. A second list is a
second payload format, and the two will agree right up until the day one of them does not.

```php
// A copy in an exporter, a job, a test helper — this is the bug, three years early.
$payload = $audit->only(['id', 'event', 'before', 'after', 'occurred_at']);
```

✅ **Do** — let the archive prove itself. `BatchWriter` reads every batch back and rehashes every
entry before a hot row is removed, precisely because the list/object shape can change across a JSON
file round trip.

```bash
php artisan sentinel:prune --action=archive --stream=tenant:acme
```

❌ **Don't** — write your own exporter that deletes rows after a successful `put()`. The filesystem
returning `true` is not evidence that what you can read back still reproduces its sealed hash — and
a query-builder `delete()` walks straight past the immutability guard, which lives on Eloquent model
events.

```php
// Nothing here has checked that the batch is restorable.
Storage::disk('s3')->put($path, $bytes);
Audit::query()->whereBetween('sequence', [$from, $to])->delete();
```

---

**See also:** [The hash chain](01-the-hash-chain.md) · [Verification](06-verification.md) · [The integrity model](../01-concepts/04-the-integrity-model.md) · [Indexes and JSON](../10-database-engines/05-indexes-and-json.md) · [Cold archiving](../08-lifecycle/02-cold-archiving.md) · [Schema](../99-reference/03-schema.md) · [Exceptions](../99-reference/06-exceptions.md) · [API stability](../99-reference/09-api-stability.md)
