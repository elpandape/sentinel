# 📚 Serialization

> The exact shape an entry takes when it leaves PHP: every key, its type, its nullability and the
> column it came from — plus the two other serialized formats the package ships, and what a change
> to any of them costs.

**On this page:** [Three formats](#three-serialized-formats) · [The frozen shape](#the-frozen-shape) · [Top-level keys](#every-top-level-key) · [The integrity block](#the-integrity-block) · [`changes`](#changes-two-shapes-and-a-third) · [`verified`](#verified-a-truth-table) · [Column map](#every-column-and-where-it-lands) · [Deliberate omissions](#deliberate-omissions) · [Fixed orders](#the-orders-the-package-fixes) · [AuditResource](#auditresource) · [Round-tripping](#round-tripping-through-json) · [The dispatch payload](#the-dispatch-payload) · [Versioning](#versioning-what-a-change-here-costs) · [⚠️ Pitfalls](#️-pitfalls) · [✅ Best practices](#-best-practices)

---

## Three serialized formats

The package renders an entry three ways, for three audiences. They are not interchangeable, and two
of them are not the one you probably want.

| | `Models\Audit::toArray()` | `Data\AuditData::toPayload()` | `Archive\Line::entry()` |
|---|---|---|---|
| Audience | anything outside the package | a queue worker or a Redis buffer | a cold NDJSON batch on a disk |
| Stage | after the ledger sealed the entry | before it, on the capture side | after it, on the way out of the hot table |
| Keys | 26 top-level + 10 in `integrity` | 28, flat | 42 — every one of the forty columns, plus `tags` and `kind` |
| Carries `encryption` | no | yes | yes |
| Carries `hash` / `sequence` | yes, inside `integrity` | **refused** — those are the ledger's | yes |
| Clock rendering | `Y-m-d\TH:i:s.uP` | `Y-m-d\TH:i:s.uP` | `Y-m-d H:i:s.u` (`CanonicalPayload::DATE_FORMAT`) |
| Can be read back into the ledger | **no** | yes, by `Jobs\SettleAudit` | yes, by `Archive\Rehydrator` |
| Frozen public contract | yes | yes | no — `@internal` |

Only the first is a public serialization contract you build against. `toPayload()` is frozen because
it crosses a process boundary between two releases of your own application; `Archive\Line` is
`@internal` and exists so a batch can reproduce its own hash on the way back.

> 📌 **Note.** `Archive\Line` says out loud why it is not `toArray()`: `toArray()` "leaves out
> `encryption`, renders `changes` through the diff and stamps the clocks in another format. Either
> would produce a line that cannot reproduce its own hash on the way back." That sentence is the
> whole of [Round-tripping](#round-tripping-through-json) in one line.

---

## The frozen shape

`Audit::toArray()` overrides Eloquent's, so it is also what `toJson()`, `jsonSerialize()`, a
`JsonResponse` and `return $audit;` from a controller produce. It has been frozen since v0.15.0:
keys are only ever **added**; none is renamed, removed or reinterpreted.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

$audit = Sentinel::audits()->for($invoice)->latest()->take(1)->get()->first();

$audit->toArray();   // array<string, mixed>, 26 keys, in a fixed order
$audit->toJson();    // the same, JSON-encoded
```

Because the override is total, it never calls `attributesToArray()`. Three consequences follow, and
all three surprise people who subclass the model:

- `$hidden` and `$visible` have **no effect**. There is nothing to hide from — the method names its
  keys itself.
- `$appends` and Eloquent accessors are **not** included. An accessor you add to a replacement model
  is invisible to the serialized entry.
- Casts still matter, because the method reads cast attributes (`severity->value`,
  `$this->before`, `$this->occurred_at->format(...)`).

`tests/Models/FrozenShapeTest.php` pins the list *and its order* with a strict comparison, for a
freshly written entry, for an entry with nothing in any field, and for every entry a seeded trail
holds. Changing the emitted array without changing that constant is a red build.

---

## Every top-level key

26 keys, in this exact order. "Source" is where the value comes from — the column name unless stated.

| # | Key | JSON type | `null` when | Source |
|---|---|---|---|---|
| 1 | `id` | string | never | `id` — a 26-character ULID |
| 2 | `audit_type` | string | never | `audit_type` — one of nine values, below |
| 3 | `event` | string | never | `event`, raw and untranslated |
| 4 | `severity` | string | never | `severity->value`: `info` · `notice` · `warning` · `critical` |
| 5 | `source` | string | never | `source->value`: `http` · `api` · `cli` · `queue` · `job` · `scheduler` · `console` · `system` · `import` |
| 6 | `subject` | `{type, id}` \| null | either half was not recorded | `subject_type` + `subject_id` |
| 7 | `actor` | `{type, id}` \| null | same rule | `actor_type` + `actor_id` |
| 8 | `impersonator` | `{type, id}` \| null | same rule | `impersonator_type` + `impersonator_id` |
| 9 | `tenant_id` | string \| null | no tenant resolver contributed | `tenant_id` |
| 10 | `version` | int \| null | the ledger never numbered this entry | `version` |
| 11 | `changes` | array \| null | the entry recorded no change set | `changes`, re-rendered — see below |
| 12 | `before` | object \| null | snapshots off, or the event has no earlier state | `before` |
| 13 | `after` | object \| null | snapshots off, or the event has no later state | `after` |
| 14 | `metadata` | object \| null | nothing attached any | `metadata` |
| 15 | `tags` | array of string | **never** — `[]` when there are none | the `tags` relation, mapped to `AuditTag::$tag` and sorted |
| 16 | `context` | object | **never** — the column is `NOT NULL` | `context` |
| 17 | `transaction_id` | string \| null | not inside a `Sentinel::transaction()` | `transaction_id` |
| 18 | `request_id` | string \| null | no inbound request | `request_id` |
| 19 | `trace_id` | string \| null | telemetry off, or no trace | `trace_id` |
| 20 | `span_id` | string \| null | same | `span_id` |
| 21 | `source_audit_id` | string \| null | this entry did not come from another | `source_audit_id` |
| 22 | `criteria` | array \| null | not a mass operation | `criteria` |
| 23 | `affected_rows` | int \| null | not a mass operation | `affected_rows` |
| 24 | `integrity` | object | **never** — ten keys, always present | see below |
| 25 | `occurred_at` | string | never | `occurred_at->format(Audit::SERIALIZED_AT)` |
| 26 | `created_at` | string | never | `created_at->format(Audit::SERIALIZED_AT)` |

`Audit::SERIALIZED_AT` is `'Y-m-d\TH:i:s.uP'` — ISO 8601 with **microseconds and an offset**. It is
`public` because `Integrity\Checkpoint::toArray()` stamps an anchor with the same constant, and two
definitions of an instant agree right up until they do not.

The nine `audit_type` values, each a `public const string AUDIT_TYPE` on the class that writes it:
`model` · `relation` · `mass` · `custom` · `auth` · `transition` · `restore` · `security` ·
`access`. Slice by this, never by `event` — an application is free to name a custom event
`updated`. See [Enums](04-enums.md).

> 📌 **Note.** `transaction` is **not** one of them, and no serialized entry ever carries it.
> `Sentinel::transaction()` writes a header row in `sentinel_transactions`, which takes no
> `sequence` and carries no `hash`; the entries captured inside the scope are the ones that reach
> `sentinel_audits`, each stamped with key 17 above. Read the header through
> `Models\AuditTransaction` and its entries through `Sentinel::audits()->inTransaction($header)` —
> `whereType('transaction')` matches nothing. See
> [Business transactions](../03-capture/06-business-transactions.md).

> ⚠️ **Warning.** `subject` is `null` unless **both** `subject_type` and `subject_id` were recorded.
> A mass **summary** entry records the class and no key on purpose (`Mass\MassCapture::summary()`),
> so its `subject` is `null` and the class it was about **is not in the serialized shape at all**.
> Read `criteria` and `affected_rows` on those, and see [Mass operations](../03-capture/05-mass-operations.md).

---

## The integrity block

Ten keys, in this order, always present.

| # | Key | JSON type | `null` when | Source |
|---|---|---|---|---|
| 1 | `stream` | string | never | `stream` — which chain this entry belongs to |
| 2 | `sequence` | int | never | `sequence` — its dense position in that chain |
| 3 | `algorithm` | string | never | `algorithm`, read from the row and not from config |
| 4 | `payload_version` | int | never | `payload_version` — which canonical format the hash covers |
| 5 | `previous_hash` | string \| null | this is the first entry of the stream | `previous_hash` |
| 6 | `hash` | string | never | `hash` — 64 hex characters under `sha256` |
| 7 | `signature` | string \| null | signing is off, or `NullSigner` | `signature` |
| 8 | `signature_key_id` | string \| null | same | `signature_key_id` |
| 9 | `verified` | **always `null`** | always | a literal `null` in the method |
| 10 | `redacted` | `{at, reason, hash}` \| null | the entry is not a tombstone | `redacted_at` · `redaction_reason` · `redacted_hash` |

`redacted.at` is stamped with the same `SERIALIZED_AT` format. `redacted.hash` is the **second**
hash, taken over what the redaction left; `hash` above it stays the entry's original one, which the
next entry's `previous_hash` still points at. See
[Redaction and tombstones](../08-lifecycle/04-redaction-and-tombstones.md).

---

## `changes`: two shapes, and a third

`Audit::serialized()` decides between two shapes by looking at the **first element** of the column
for a `relation` key next to an `operation` key.

**Diff entries** — the shape of a model, mass or transition entry:

```php
[['path' => '/email', 'op' => 'replace', 'old' => 'a@x.test', 'new' => 'b@x.test']]
```

`path` is an RFC 6901 pointer, `op` is `add` · `remove` · `replace`. Every entry is rebuilt through
`Diff\Change`, which emits `path`, `op`, `old` and `new` and nothing else — so **any other key you
put in that column is dropped on the way out**, and `old` is *omitted entirely* rather than `null`
when the old
value is genuinely unknown. That happens on a mass summary (`Mass\Writes` records the written
columns with no old side) and on a diff reconstructed from a JSON Patch with no guarding `test`.
Read it with `array_key_exists('old', $change)`, never `?? null`. See [Diffs](../03-capture/03-diffs.md).

**Relation lines** — the shape of a relation entry, and of a restoration that put a relation back:

```php
[['relation' => 'items', 'operation' => 'attach', 'related_type' => 'App\\Models\\Product',
  'related_id' => '7', 'pivot_before' => null, 'pivot_after' => ['quantity' => 2]]]
```

Always those six keys, in that order, with pivot maps `ksort`ed. Unlike a diff entry, **extra keys
survive**: `Data\RelationLine::ordered()` puts anything else the line carries behind the six, sorted
by name, "so a reordering is never also a loss". `pivot_before => null` means the pivot row did not
exist; `[]` means it existed and carried nothing. See [Relationship auditing](../03-capture/04-relationships.md).

**The third shape is whatever was there.** A `changes` column the package cannot read as either —
only possible on a row it did not write — is returned **exactly as found**, because
`Diff\DiffException` is caught inside `serialized()`. One bad row must not stop a page of the trail
from serialising. Note the asymmetry: in the *relation* branch a non-array element is silently
filtered out instead, and the rest of the lines go through.

| Column holds | `toArray()['changes']` | `$audit->diff()` |
|---|---|---|
| `null` | `null` | an empty `Diff` computed from `before`/`after` |
| `[]` | `[]` | an empty `Diff` |
| valid diff entries | re-rendered `{path, op, old?, new}` | a `Diff` over them |
| relation lines | re-ordered six-key lines | a `Diff` reading each line as add/remove/replace |
| anything else | the raw column, unchanged | **throws** `DiffException::malformedEntry` |

---

## `verified`: a truth table

`toArray()` does not walk the chain. Verifying means rebuilding the canonical payload and rehashing
it, and a serialiser that did that would turn one page of 50 entries into 50 rehashes.

| Value | Means | How it is reached |
|---|---|---|
| `null` | not checked | every call to `toArray()`, on every entry, always |
| `true` | this row still reproduces its own hash | only you, writing the result of `verifyIntegrity()` into your own payload |
| `false` | it does not | the same call returning `false` |

```php
$state = match ($audit->toArray()['integrity']['verified']) {
    true  => 'verified',
    false => 'tampered',
    null  => 'not checked',
};
```

> ⚠️ **Warning.** `! $data['integrity']['verified']` is `true` for every entry you will ever
> serialise. `null` is falsy in PHP and in JavaScript alike, so the negation renders *not checked*
> as **TAMPERED** everywhere. Match on the three values.

The three questions the model actually answers are separate on purpose — an unsigned entry is not a
failure and a redaction is not tampering: `verifyIntegrity(): bool`, `verifyContent(): ContentState`,
`verifySignature(): SignatureState`. See [Verification](../07-integrity/06-verification.md).

---

## Every column, and where it lands

`sentinel_audits` has forty columns (`Support\AuditSchema::columns()`). This is the complete map.

| Column(s) | Serialized as |
|---|---|
| `id` | `id` |
| `stream` · `sequence` | `integrity.stream` · `integrity.sequence` |
| `audit_type` · `event` · `severity` · `source` | the same four top-level keys |
| `subject_type` + `subject_id` | `subject` — **both or neither** |
| `actor_type` + `actor_id` | `actor` — both or neither |
| `impersonator_type` + `impersonator_id` | `impersonator` — both or neither |
| `tenant_id` · `transaction_id` · `request_id` · `trace_id` · `span_id` | the same five keys |
| `version` | `version` |
| `context` · `before` · `after` · `metadata` | the same four keys |
| `changes` | `changes`, re-rendered |
| `criteria` · `affected_rows` · `source_audit_id` | the same three keys |
| `payload_version` · `algorithm` · `previous_hash` · `hash` | inside `integrity` |
| `signature` · `signature_key_id` | `integrity.signature` · `integrity.signature_key_id` |
| `redacted_at` · `redaction_reason` · `redacted_hash` | `integrity.redacted.{at, reason, hash}` |
| `occurred_at` · `created_at` | the same two keys, reformatted |
| **`encryption`** | **absent** |
| **`capture_id`** | **absent** |
| *(not a column — the `tags` relation)* | `tags` |

Two columns of forty never appear. Everything else is published, somewhere.

---

## Deliberate omissions

| Absent | Why |
|---|---|
| `encryption` | `toArray()` never decrypts, and publishing the block would tell every consumer of your API **which fields are protected and which key is current**. The ciphertext stays inline in `before`/`after`, in the key it replaced. |
| `capture_id` | Correlation and idempotency metadata, deliberately outside the canonical payload the hash covers. It is not going to appear. |
| a top-level `signature` | It *is* published — inside `integrity`, where the rest of the proof lives. Without it an exported entry is not something a third party can verify. |
| a top-level `relation` | A relation entry's lines live in `changes`, which the chain seals. `sentinel_audit_relations` is their queryable projection, not the fact. |
| the `subject` / `actor` **models** | Both are `{type, id}` references. An entry outlives the record it describes; resolving one is `AuditCollection::loadReferences()`, and its result is not part of this shape. |

`tests/Models/FrozenShapeTest.php` asserts the absences directly, so they cannot drift back in by
accident.

> 🔒 **Security.** There is no reader-side helper that reverses an encrypted field.
> `Security\Keyring` is `@internal`, and the only paths that decrypt are a restore and a rekey. If a
> screen must show plaintext, that is a decision you implement and authorise yourself. See
> [Encryption and the keyring](../05-pipeline-and-security/03-encryption-and-the-keyring.md).

---

## The orders the package fixes

MySQL and PostgreSQL both reorder an object's keys when a JSON column is written, so a value read
back and published as it came would have a different shape on each engine. The serialiser puts the
package's own order back on exactly the things the package wrote.

| Fixed | How |
|---|---|
| every shape inside `changes` | a diff entry as `path, op, old?, new`; a relation line as the six keys, pivot maps `ksort`ed, extras sorted by name behind them |
| `tags` | mapped to strings and sorted, always a plain list |

| Not fixed | Why |
|---|---|
| keys inside `before`, `after`, `metadata`, `context`, `criteria` | your columns and your resolvers' keys — reshaping them would be inventing a shape |
| a change's `old` / `new` **values** | same reason |
| the list order of `changes` | it was fixed at capture, so two runs of one `sync()` hash alike; the serialiser does not re-sort it |

None of this touches the hash. Canonicalisation sorts object members before hashing (RFC 8785), so
stored key order has never affected `integrity.hash` in either direction. See
[Canonicalization](../07-integrity/03-canonicalization.md).

---

## `AuditResource`

`Http\Resources\AuditResource` extends `JsonResource` and its `toArray(Request $request)` returns
`$audit->toArray()` verbatim. It adds no key, renames none, hides none.

| | `Audit::toArray()` | `AuditResource` |
|---|---|---|
| Shape | the 26 keys | identical, byte for byte |
| Takes a `Request` | no | yes, and ignores it |
| Gives you | an array | Laravel's envelope: `collection()`, the response wrapper, `additional()` |
| Routes | — | **none.** The package mounts no route for it |

It exists for the envelope and nothing else: a resource with a shape of its own would be a second
contract to keep in step with the first. Which entries a request may see is an authorisation
question the package has no standing to answer for you.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Http\Resources\AuditResource;

Route::get('/invoices/{invoice}/audits', function (Invoice $invoice) {
    Gate::authorize('viewAudits', $invoice);

    return AuditResource::collection(
        Sentinel::audits()->for($invoice)->latest()->take(50)->get(),
    );
})->middleware(['auth', 'throttle:30,1']);
```

> ⚠️ **Warning.** Given a `Query\AuditPage`, `AuditResource::collection()` yields a bare JSON array
> with **no** `meta` or `links`. `AuditPage` implements `Countable` and `IteratorAggregate`, not
> Laravel's `AbstractPaginator`. Wrap `$page->entries` and build the envelope from `$page->page`,
> `$page->perPage` and `$page->hasMore`. See
> [Order, paging and walking the trail](../06-reading/03-order-paging-and-walking.md).

---

## Round-tripping through JSON

### What survives

`json_decode($audit->toJson(), true)` equals `$audit->toArray()` — asserted directly in
`tests/Models/AuditToArrayTest.php`. Every scalar, every string, both clocks to the microsecond, the
whole `integrity` block. `sequence`, `version` and `affected_rows` stay JSON numbers; every
identifier stays a **string**, `subject.id` included — the morph key columns are `string(64)` and
capture casts an integer primary key on the way in (`Capture\ModelCapture::key()`), so an
auto-increment subject arrives as `"500"`, never `500`.

> 🐘 **Engine.** `id`, `transaction_id`, `source_audit_id`, `previous_hash`, `hash` and
> `redacted_hash` are `char(n)` columns. PostgreSQL blank-pads a short value to the declared width
> and hands the padding back; MySQL and SQLite trim it. Every identifier the package mints fills the
> column exactly, so this is only visible on a row inserted by hand or by an import — and only on
> PostgreSQL, which is why `tests/helpers.php` mints fixed-width test identifiers on purpose.

### What does not

| What | What happens | Why |
|---|---|---|
| an empty map | `{}` becomes `[]` | the `array` cast decodes with `assoc: true`, and PHP cannot tell an empty map from an empty list. Applies to `before`, `after`, `context`, `metadata`, `criteria` and a pivot map |
| a map keyed `{"0":…,"1":…}` | comes back as a JSON **array** | same cause. `Archive\BatchWriter` re-hashes every archived batch after reading it back precisely because of this shape — "a shape the database round trip preserves and this one does not" |
| extra keys on a diff entry | dropped | `Diff\Change` rebuilds the entry and emits only `path`, `op`, `old?`, `new` |
| the `old` key | absent, not `null`, when unknown | `Change::toArray()` omits it |
| `encryption` | never present | see [Deliberate omissions](#deliberate-omissions) |
| `capture_id` | never present | idempotency metadata, outside the payload the hash covers |
| the canonical clock | different string | `toArray()` stamps `Y-m-d\TH:i:s.uP`; the hash covers `Y-m-d H:i:s.u`, with no offset |
| a CSV export | 16 columns only | `Compliance\Export::CSV_COLUMNS` drops `integrity` entirely — a CSV cannot be verified at all |

### What a consumer on the other side can verify

Give somebody an NDJSON export and the public half of your signing key, and they can check:

- **the chain's shape** — `integrity.previous_hash` of each entry against `integrity.hash` of the
  one before it, and that `integrity.sequence` is dense within one `integrity.stream`;
- **the signature over each entry's hash**, with `integrity.signature` and `integrity.signature_key_id`;
- **the export's own bytes** — `sentinel:export` writes a `.manifest.json` beside the body carrying
  `format`, `entries`, `digest` and a `signature` over that digest.

What they **cannot** do is recompute `integrity.hash` from the serialized entry. The hash covers the
27 columns of `Integrity\CanonicalPayload::COLUMNS`, which include `encryption` (absent here),
exclude `created_at` (present here), and stamp `occurred_at` in the other format. Rehashing a
`toArray()` result and comparing it to `integrity.hash` will not match, ever, on a perfectly intact
entry. Proving what an entry *says* needs the row, which means `$audit->verifyIntegrity()` or
`sentinel:verify`.

> ⚠️ **Warning.** `toArray()` is a **hand-off** format, not an import format. There is no path back
> in: `sentinel:import` reads `owenit` and `altek` tables only, and the sole format the package
> rehydrates into the ledger is the internal NDJSON of `Archive\Line`, through
> `Archive\Rehydrator`. Do not plan a migration around re-importing an export. See
> [Cold archiving](../08-lifecycle/02-cold-archiving.md) and
> [Export and rekey](../08-lifecycle/06-export-and-rekey.md).

---

## The dispatch payload

`Data\AuditData` is the entry as the *capture* knows it, before a sequence or a hash exists. Its
fields are named after their columns on purpose — "a translation layer between capture and hash is
where silent integrity bugs come from". `toPayload()` and `fromPayload()` are what let it wait in a
queue job (`Jobs\SettleAudit`) or in a Redis buffer (`Buffer\RedisBuffer`).

`toPayload()` emits 28 flat keys — one per constructor parameter, `encryption`, `capture_id` and
`tags` included. It is written out by hand rather than serialised as an object, so that a worker
running last week's code can read a payload written by this week's: PHP would hand such a worker an
object with an uninitialised property and fail on first access, where this drops what it does not
recognise and fills in what is missing with the constructor's own defaults.

`fromPayload()` is deliberately forgiving in one direction and absolute in the other:

| Input | Result | Reason |
|---|---|---|
| a key the class does not know | **dropped, silently** | a worker on the previous release still reads a newer payload |
| `audit_type`, `event` or `occurred_at` missing | `DispatchException::incompletePayload` | an entry that cannot say what happened, or when, is not one the ledger can settle |
| `sequence`, `hash` or `previous_hash` present | `DispatchException::proposedItsOwnPlaceInTheChain` | the ledger reads the chain and assigns those, inside the same operation as the write, in every mode |
| an unknown `severity` or `source` value | falls back to `Severity::Info` / `Source::System` | those lists grow; a worker that has not learned a new shade should lose the shade, not the entry |
| an optional field of the wrong type | read **as if absent** | `tenant_id: 7` → `null`; `before: 'not a map'` → `null`; `affected_rows: 'many'` → `null`; `tags: 'billing'` → `[]` |
| a `tags` list with non-strings in it | the non-strings are filtered out | `['billing', 7, 'urgent']` → `['billing', 'urgent']` |

```php
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Enums\Severity;

$data = new AuditData(
    audit_type: 'custom',
    event: 'invoice.approved',
    severity: Severity::Notice,
    occurred_at: new DateTimeImmutable('2026-09-07 10:02:03.456789'),
);

AuditData::fromPayload($data->toPayload())->toPayload() === $data->toPayload();   // true
```

> ⚠️ **Warning.** `occurred_at` is required *and* parsed: a value present but unparseable reaches
> `new DateTimeImmutable(...)` and raises PHP's own exception, not a `DispatchException`. See
> [Running audits on a queue](../09-operations/03-queues.md) and
> [The buffered mode](../09-operations/02-the-buffered-mode.md).

---

## Versioning: what a change here costs

| Change | Allowed | What it takes |
|---|---|---|
| adding a top-level or `integrity` key | yes | append it to `FROZEN_KEYS` / `FROZEN_INTEGRITY_KEYS` and emit it in the same position. The test compares the ordered list, so the decision cannot be made by accident |
| renaming a key | no, before v2 | ship the new shape **beside** the old one; the old survives to v2 with its deprecation noted in `UPGRADE.md` |
| removing a key | no, before v2 | same |
| reinterpreting a key's meaning | no | it is indistinguishable from a rename to a consumer that already stored the old values |
| changing a rendering (a clock format, an enum's backed value) | treat as a break | `Audit::SERIALIZED_AT` is also what `Integrity\Checkpoint::toArray()` uses |
| adding a column to `sentinel_audits` | yes | it must land somewhere in `Archive\Line`, whose key-set assertion is `CanonicalPayload::COLUMNS + SEALED + KEPT` = the whole table. A new column is a loud failure there, not a silent loss out of a cold batch |
| touching `sequence`, `hash`, `previous_hash` or the canonical payload | only with the full cost | bump `payload_version` and ship a backwards-compatibility test. `CanonicalPayload::COLUMNS` is the frozen definition of `canonical(core)` for `payload_version 1`, and nothing else in the package may enumerate those columns — a second list of them **is** a second payload format |

For a consumer, the rule that matters is the mirror image: **tolerate keys you do not know.** A
reader that rejects an unrecognised key turns the one change the contract permits into a breaking
one. `AuditData::fromPayload()` is the package's own worked example of that discipline. See
[API stability](09-api-stability.md).

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| Every entry reads **TAMPERED** in your UI | `! $data['integrity']['verified']` — `null` is falsy | Match on `true` / `false` / `null` |
| `verified` is still `null` right after `verifyIntegrity()` returned `true` | the key is a literal `null`; the method never walks the chain | Write the result into your own payload |
| Rehashing the serialized entry never matches `integrity.hash` | `toArray()` is not the canonical payload — different columns, different clock format | Verify with `$audit->verifyIntegrity()` or `sentinel:verify` |
| `Undefined array key "old"` | `old` is omitted, not `null`, when the old value is unknown | `array_key_exists('old', $change)` |
| A mass summary's serialized `subject` is `null` although the row names a class | `subject` needs both halves; a summary records `subject_type` and no `subject_id` | Read `criteria` and `affected_rows`; read the class off the model, not off the shape |
| An extra key you wrote into a `changes` entry vanished | diff entries are rebuilt through `Diff\Change`, which emits only those four | Put it in `metadata`, or use a relation line, which keeps extras |
| `$audit->diff()` throws `DiffException` on a row `toArray()` serialised happily | the exception is caught only inside `serialized()`, which falls back to the raw column | Guard `diff()` on data you did not write |
| `{}` in a JSON column comes back as `[]` | the `array` cast decodes with `assoc: true`; PHP has one empty array | Treat an empty map and an empty list as the same value, on both sides |
| One query per entry when serialising a page — or a `LazyLoadingViolationException` | `toArray()` reads the `tags` relation unconditionally; a bare `Audit::find()` does not eager-load it | Read through `Sentinel::audits()`, `Ledger::find()` or `$model->audits()` — all three load `tags` |
| A hand-inserted `id` or `hash` comes back with trailing spaces on PostgreSQL only | those are `char(n)` columns; PostgreSQL blank-pads, MySQL and SQLite trim | Write identifiers at the column's full width |
| A CSV export cannot be verified by the recipient | `Export::CSV_COLUMNS` is 16 keys and `integrity` is not one of them | Export `ndjson` for anything that will be checked or read back |
| A key you added by overriding `toArray()` disappeared after an upgrade | the shape is frozen; a replacement model that overrides it forks a published contract | Add keys in your own `JsonResource`, beside the shape |
| `$hidden` on a replacement audit model has no effect | `toArray()` is a total override and never calls `attributesToArray()` | Filter in your own resource |

---

## ✅ Best practices

✅ **Do** — read `integrity.verified` as three states, in PHP and in the client alike.

```php
$state = match ($audit->toArray()['integrity']['verified']) {
    true  => 'verified',
    false => 'tampered',
    null  => 'not checked',
};
```

❌ **Don't** — collapse it to a boolean. Every unchecked entry then reports as tampered, and a real
break drowns in false alarms.

```php
$tampered = ! $audit->toArray()['integrity']['verified'];   // true, always
```

---

✅ **Do** — add your keys *beside* the frozen shape, in your own resource.

```php
use ElPandaPe\Sentinel\Models\Audit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class VerifiedAudit extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var Audit $audit */
        $audit = $this->resource;

        return [...$audit->toArray(), 'verified' => $audit->verifyIntegrity()];
    }
}
```

❌ **Don't** — override `Audit::toArray()` in a replacement model. Consumers, exports and any reader
downstream are entitled to every key being where it was.

```php
final class SlimAudit extends Audit
{
    public function toArray(): array
    {
        return ['id' => $this->id, 'event' => $this->event];   // breaks the contract
    }
}
```

---

✅ **Do** — export `ndjson` when the file will be checked or read by a machine, and keep the manifest
next to it.

```bash
php artisan sentinel:export --format=ndjson --disk=s3 --path=audits/2026-09.ndjson
```

❌ **Don't** — hand out a CSV as evidence. `Export::CSV_COLUMNS` holds 16 keys and `integrity` is not
among them, so the hash, the sequence and the signature are simply gone.

```bash
php artisan sentinel:export --format=csv --path=evidence.csv   # unverifiable
```

---

✅ **Do** — treat an unknown key in a payload as data you pass on, the way the package does.

```php
use ElPandaPe\Sentinel\Data\AuditData;

$data = AuditData::fromPayload($payload);   // unknown keys dropped, missing ones defaulted
```

❌ **Don't** — build a consumer that rejects a key it does not recognise. Adding keys is the one
change the frozen shape permits, so a strict reader turns every future release into a break.

```php
if (array_diff(array_keys($entry), MY_EXPECTED_KEYS) !== []) {
    throw new RuntimeException('unexpected audit shape');   // fails on the next minor
}
```

---

✅ **Do** — serialise entries you obtained through a path that eager-loads `tags`.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

$entries = Sentinel::audits()->for($patient)->take(100)->get();

$payload = $entries->map(static fn ($audit): array => $audit->toArray())->all();
```

❌ **Don't** — serialise entries pulled straight off the model. `toArray()` reads `tags`
unconditionally, so this is one query per entry, or a `LazyLoadingViolationException` in an
application that forbids one.

```php
Audit::query()->limit(100)->get()->map(fn (Audit $a): array => $a->toArray());
```

---

✅ **Do** — verify with the model, out of band, when the answer matters.

```php
$audit->verifyIntegrity();   // bool — does this row still reproduce its own hash
$audit->verifyContent();     // Enums\ContentState — sealed, redacted or altered
$audit->verifySignature();   // Enums\SignatureState
```

❌ **Don't** — reconstruct the canonical payload from `toArray()` and hash it yourself. It omits
`encryption`, adds `created_at` and stamps the clocks differently; an intact entry fails the check.

```php
hash('sha256', json_encode($audit->toArray())) === $audit->hash;   // false on a healthy entry
```

---

**See also:** [Presenting and serializing](../06-reading/07-presenting-and-serializing.md) · [Schema](03-schema.md) · [Enums](04-enums.md) · [API stability](09-api-stability.md) · [Canonicalization](../07-integrity/03-canonicalization.md) · [The hash chain](../07-integrity/01-the-hash-chain.md) · [Verification](../07-integrity/06-verification.md) · [Diffs](../03-capture/03-diffs.md) · [Relationship auditing](../03-capture/04-relationships.md) · [Mass operations](../03-capture/05-mass-operations.md) · [Encryption and the keyring](../05-pipeline-and-security/03-encryption-and-the-keyring.md) · [Redaction and tombstones](../08-lifecycle/04-redaction-and-tombstones.md) · [Export and rekey](../08-lifecycle/06-export-and-rekey.md) · [Running audits on a queue](../09-operations/03-queues.md)
