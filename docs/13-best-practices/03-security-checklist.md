# ✅ Security checklist

> A reviewable list of the security decisions Sentinel leaves to you, each with the check, the way to
> verify it, and what a failure costs — followed by the threat model in plain terms and the packet to
> hand a security reviewer.

**On this page:** [How to use this page](#how-to-use-this-page) · [1. What never reaches the ledger](#1-what-never-reaches-the-ledger) · [2. Key management and custody](#2-key-management-and-custody) · [3. The chain and what it proves](#3-the-chain-and-what-it-proves) · [4. Access to the trail itself](#4-access-to-the-trail-itself) · [5. Trusting inbound values](#5-trusting-inbound-values) · [6. Exporting and handing over](#6-exporting-and-handing-over) · [7. Erasure requests](#7-erasure-requests) · [The threat model](#the-threat-model) · [What to hand a security reviewer](#what-to-hand-a-security-reviewer) · [⚠️ Pitfalls](#️-pitfalls) · [✅ Best practices](#-best-practices)

---

## How to use this page

Every item below is a claim you should be able to demonstrate on your own installation, not a policy
to agree with. Work down the seven groups; for each row, run the verification and write down the
answer. Where a check fails, the third column says what you have actually lost — which is usually
narrower, and occasionally wider, than the wording of the check suggests.

Two framing facts, because most misreadings of this package start with one of them:

> 📌 **Note.** Sentinel is tamper-**evident**, not tamper-proof. Nothing here prevents a write to
> `sentinel_audits`. What the hash chain does is make an unannounced write detectable by anyone who
> re-reads the rows. Prevention is your database's job — see
> [A database of its own](../10-database-engines/07-a-database-of-its-own.md).

> 🔒 **Security.** Sentinel ships **no authorization**. There is no gate, no policy, no route and no
> published permission around reading the trail. Who may call `Sentinel::audits()`, who may run
> `sentinel:redact`, and who may connect to the audit database are decisions your application makes
> and Sentinel never checks.

---

## 1. What never reaches the ledger

Protection happens in the write pipeline, during the capture, in the request — never behind the queue
or the buffer. See [The write pipeline](../05-pipeline-and-security/01-the-write-pipeline.md).

| # | Check | How to verify it | What a failure means |
|---|---|---|---|
| 1.1 | Every sensitive column is declared in **exactly one** of `$auditExclude`, `$auditRedact`, `$auditHash`, `$auditEncrypt` | Read the model. Intersect the four arrays; the intersection must be empty | A field in two lists is transformed twice, in stage order. `MaskSensitiveData` applies redaction then hashing, and `EncryptSensitiveData` runs after both — so redact+encrypt stores an *encrypted mask*, and the plaintext is unrecoverable even holding the key |
| 1.2 | Keys no model owns (`ip`, `user_agent`, `url`, `session_id`) are named in `security.redaction.fields` / `hashing.fields` / `encryption.fields` | Read the config block; then create a record and inspect the entry's `context` | The config lists are a **union** with each model's declarations, never a fallback. They are the only way to protect a key no model declares |
| 1.3 | Console arguments carrying secrets are covered by `resolvers.command.redact` | Run the command; read the entry's `context.arguments` | This is a *second, separate* list from `security.redaction.fields`. It matches the argument **name** case-insensitively as a substring and runs in `ResolveContext`, before `MaskSensitiveData`. Adding a name to one list does not add it to the other |
| 1.4 | Nothing secret is pushed into the execution context under an undeclared key | Grep for `Sentinel::withContext(`; check every key against the three config lists | Everything in the context is audited — that is what the context is for. An undeclared key is written in the clear |
| 1.5 | No declared name is also a *container* key (`profile`, `arguments`, `payload`) | Compare the declared names with the shape of your snapshots | `Security\Fields::walk` transforms a matched key's whole value and does **not** descend into it, so the entire subtree becomes one ciphertext or one mask |
| 1.6 | A plaintext sweep over a real write finds nothing | Write a record with known sentinel values and grep every column of the row, the dispatched job payload and the buffer contents for them | This is exactly what `tests/Security/NoPlaintextTest.php` does for the shipped protections, in `sync`, `queue` and `buffered` mode and in the failure log line |
| 1.7 | The write-failure log line cannot leak an undeclared column | If `on_write_failure = log`, set `mask_bindings_in_exception_messages => true` on the audit connection | `Capture\WriteFailure` logs identity plus the throwable. Laravel's `QueryException` interpolates the statement's bindings into its message unless that connection flag is set |
| 1.8 | `php artisan about` output is safe to paste into a ticket | Run it | `Console\About` publishes six rows — version, mode, ledger, payload version, compliance, telemetry — and deliberately no key, key identifier or signer configuration |

> ⚠️ **Warning.** `encryption.fields` lists the declared names that were **found** in the entry, not
> the ones that hold ciphertext. `Fields::protect` marks a field as touched before the transform
> runs, and the encrypting closure short-circuits on `null` — so an entry can carry
> `encryption = {"fields":["secret"],"key_id":"default"}` with `after.secret === null`. Check the
> value, never the block.

```php
use ElPandaPe\Sentinel\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

final class Patient extends Model
{
    use Auditable;

    protected array $auditExclude = ['remember_token'];  // never enters the pipeline
    protected array $auditRedact  = ['email'];           // masked, irreversible
    protected array $auditHash    = ['insurance_number'];// digested, comparable, unreadable
    protected array $auditEncrypt = ['national_id'];     // ciphertext + {fields, key_id}
}
```

Field **names** are stored in the clear and are inside the hash: `encryption.fields` is part of the
canonical payload. That `national_id` is encrypted is public information in every entry that has one.
Only values are protected. See
[Protecting sensitive data](../05-pipeline-and-security/02-protecting-sensitive-data.md).

---

## 2. Key management and custody

Two independent rings, and they must never be the same bytes: `security.encryption.keys` decrypts,
`integrity.signature.keys` verifies.

| # | Check | How to verify it | What a failure means |
|---|---|---|---|
| 2.1 | `SENTINEL_ENCRYPTION_KEY` and `SENTINEL_HASH_SALT` are pinned explicitly, in every environment, before the first protected write | Read `.env`. Neither may be empty | `security.encryption.keys.default` falls back to `app.key`, and `security.hashing.salt` falls back to `hash_hmac('sha256', 'sentinel:hashing', app.key)`. Rotating `APP_KEY` then makes every encrypted value unreadable **and** every earlier digest incomparable, in one move, with no error |
| 2.2 | `security.encryption.key_id` names a key that exists under `keys` | Boot the app and write one protected entry | Only the identifier `default` falls back to `app.key`. Any other missing identifier throws `EncryptionException::unknownKey` on the write rather than quietly signing history to a key it did not name |
| 2.3 | Every retired encryption key is still on the ring | Compare the distinct `encryption->>'key_id'` values in the table with the keys in config | A key that leaves the ring takes its values with it. The entries keep verifying and stop being readable — that is the designed behaviour, not a bug |
| 2.4 | The signing key is **not** the encryption key | Read both blocks | The encryption ring builds `Illuminate\Encryption\Encrypter` and cannot express a public key. Reusing it makes the signing secret literally the encryption key |
| 2.5 | Under `signer: openssl`, `private_key` does not live on the machine holding the entries | Read the config on each node | This split is the only reason the OpenSSL tier is stronger than HMAC. A node with the ring and no `private_key` verifies everything and `sign()` throws `SignatureException::verifyOnly` |
| 2.6 | Every retired **signing** key is still under `integrity.signature.keys` | `php artisan sentinel:verify --depth=entries` and read the signature tally | A removed key makes its entries `SignatureState::UnknownKey` — unprovable, permanently. `UnknownKey` is a verdict, not a defect; only `Invalid` is |
| 2.7 | Rotation is done by appending, never by rewriting | `php artisan sentinel:rekey --key=<id> --dry-run` first | `Security\Rekeyer` writes a **new** entry (`audit_type = 'security'`, `event = 'rekeyed'`, `source_audit_id` pointing back) and leaves the original byte for byte, with its hash, `previous_hash` and `sequence` intact |
| 2.8 | Exception messages carry no key material | Force a bad key and read the message | `EncryptionException` and `SignatureException` name the key by **identifier**. `tests/Security/NoKeyMaterialTest.php` asserts the written rows contain the identifiers and none of the secrets |

> 📌 **Note.** `Security\Keyring` and `Integrity\Signers` are scoped singletons and memoise each
> resolved key. A key change is seen on the next request or process, never inside the one that made it.

```php
// config/sentinel.php — rotating the signing key without losing yesterday
'integrity' => [
    'signature' => [
        'enabled'     => true,
        'signer'      => 'openssl',
        'key_id'      => 'v2',                                   // what signs now
        'keys' => [
            'v1' => env('SENTINEL_SIGNING_PUBLIC_V1'),            // retired: verifies, never signs
            'v2' => env('SENTINEL_SIGNING_PUBLIC_V2'),
        ],
        'private_key' => env('SENTINEL_SIGNING_PRIVATE_V2'),      // openssl only — keep off this box
    ],
],
```

Under `hmac`, one secret both signs and verifies, and `keys.default` left null derives from `app.key`
under its own label (`hash_hmac('sha256', 'sentinel:signature', app.key)`) — deliberately different
bytes from the hashing salt, so one leaking does not hand over the other. See
[Encryption and the keyring](../05-pipeline-and-security/03-encryption-and-the-keyring.md) and
[Signing the chain](../07-integrity/04-signing.md).

---

## 3. The chain and what it proves

| # | Check | How to verify it | What a failure means |
|---|---|---|---|
| 3.1 | `integrity.algorithm` yields a 64-hex-character digest | Read the config; `sha256` is the shipped value | `hash`, `previous_hash` and `redacted_hash` are `char(64)`, as is `root_hash` on anchors. Nothing validates the width: `sha512` passes configuration and then writes a digest that does not fit — truncated on a lax MySQL, refused on PostgreSQL |
| 3.2 | `integrity.signature.enabled` was turned on **before** the entries you need provable | `sentinel:verify` and read the `signatures()` tally | Signing is not retroactive and never will be. Everything written before the switch stays `Unsigned` — which is sound, not broken |
| 3.3 | Anchors are emitted and **signed** | `php artisan sentinel:checkpoint`, then `sentinel:verify --depth=anchors` and read `anchorSignatures()` | An unsigned anchor is a row anyone with write access can reissue. Anchoring without signing buys speed and no trust |
| 3.4 | Verification runs at a depth that answers your question | `--depth=entries` for content; `roots` for a periodic sweep; `anchors` for the cheap one | Only `verifyIntegrity()` / `--depth=entries` rehashes. `verifyRoots()` folds the stored `hash` column, so a canonical column edited while `hash` was left alone passes both shallow depths |
| 3.5 | Dashboards read `checked`, `covered` and `archived` as three facts | Read `IntegrityReport::checked()`, `covered()`, `archived()` | They are published side by side and are never summed. `covered` is what an anchor answered for; nothing in it was read |
| 3.6 | Verification is proved to work with **no key at all** | `config(['sentinel.security.encryption.keys' => []]);` then `Sentinel::verifyIntegrity($stream)` | The chain hash is unkeyed and covers the ciphertext. If this ever stops working, something has made the hash depend on decryptable plaintext, and the auditor-with-no-key property is gone |
| 3.7 | Nobody relies on the model guard to stop a rewrite | Read your own code for `Audit::query()->…->update(` | `ImmutableAuditException` is raised from Eloquent model events. A query-builder `update()` never fires them. What catches it is `verifyIntegrity()` reporting `hash_mismatch`, after the fact |
| 3.8 | No migration rehashes existing rows | Read every migration that touches `sentinel_audits` | Verification reads the algorithm off each row. Rehashing destroys the exact property the package exists for. A format change bumps `payload_version` instead |
| 3.9 | Anything that must be provable lives in `metadata`, not in labels | Read what you write to `tags` | Labels (`sentinel_audit_tags`) are outside the hash in both directions: classifying an old entry breaks no hash, and relabelling leaves no trace any verification can find |
| 3.10 | A `projection_mismatch` is not read as a broken chain | `sentinel:verify --projections` | `sentinel_audit_relations` is a reconstructible index and is outside the hash. Detection ships; repair does not |

> 🧪 **Verify it.** Reproduce an entry's hash outside the package, once, by hand — it is the cheapest
> proof that your understanding matches the code, and `CanonicalPayload::from()` decrypts nothing, so
> it works holding no key. The recipe is in
> [Canonicalization](../07-integrity/03-canonicalization.md); what an outside reviewer reimplements is
> that formula, never `Contracts\Canonicalizer`, which is `@internal`.

---

## 4. Access to the trail itself

| # | Check | How to verify it | What a failure means |
|---|---|---|---|
| 4.1 | Reading the trail is behind your own authorization | Read your controllers, commands and jobs | Sentinel checks nothing. Every `Sentinel::audits()` call runs with whatever privilege the caller already had |
| 4.2 | The audit tables are on a connection the application cannot `DROP` or `ALTER` | Read `sentinel.database.connection` and the grants on it | Immutability lives in the model, and the model is bypassable. A grant that allows only `INSERT` and `SELECT` on `sentinel_audits` is the enforcement the package cannot provide |
| 4.3 | Artisan access is restricted where it matters | Check who can run `sentinel:redact`, `sentinel:prune --action=delete`, `sentinel:rekey`, `sentinel:export` | These four destroy content, remove rows, move keys and hand the trail out of the building, respectively |
| 4.4 | If reads must be recorded, they go through the Query API | `Sentinel::audits()->…->get()` / `->paginate()` | The record hangs off `AuditQuery::read()`. `$model->audits()`, `latestAudit()`, `sentinel:show <id>` and every verification walk leave **no** access record — by design: a relation on your own model is not "the trail being queried", and the verifier reads to prove rather than to disclose |
| 4.5 | Compliance mode's cost per read is budgeted | Enable it on a copy and measure your own p95 | A recorded read becomes a read plus two writes: a chained `access` entry that consumes a `sequence` of the audited stream, and a `sentinel_access_log` row |
| 4.6 | `sentinel_access_log` has a retention or partition plan | Look for one | Nothing in `Retention\Cascade` touches that table. A retention policy on `'access'` prunes the chained entries and orphans the projection rows, which then answer `null` from `AuditAccess::audit()`. `sentinel:partitions --table=access_log` is the only reaper the package ships |
| 4.7 | An unbounded read is refused rather than truncated | Call `Sentinel::audits()->get()` with no `take()` on a large trail | `get()` probes for `AuditQuery::DEFAULT_LIMIT + 1` (501) and throws `QueryException::unbounded` above the bound. A refused read is not recorded — nothing was handed over |

```php
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Models\AuditAccess;

// The route that leaves a record, under compliance mode:
Sentinel::audits()->forTenant('acme')->take(50)->get();

// Who read what, afterwards:
AuditAccess::query()
    ->where('actor_type', 'user')
    ->where('actor_id', (string) $suspect->getKey())
    ->latest('created_at')
    ->get();
```

> ⚠️ **Warning.** An `access` entry is an ordinary entry, so it consumes a sequence of the very stream
> it audits. A panel paging through a tenant's history interleaves its own access entries into the
> history it is paging through. That is what makes a read provable; it is also a surprise the first
> time you see it. See [Compliance mode](../08-lifecycle/05-compliance-mode.md).

---

## 5. Trusting inbound values

Three values arrive from outside the process and land in indexed, hash-covered columns:
`traceparent`, the request-id header, and anything a caller put in the job payload.

| # | Check | How to verify it | What a failure means |
|---|---|---|---|
| 5.1 | `telemetry.trust_incoming_header` is **false** on any endpoint third parties can reach | Read the config for that application | It defaults to `true`. `traceparent` is a value the caller chooses and `trace_id` is indexed, so a third party can file your entries under a trace of their own and inflate the cardinality of that index |
| 5.2 | With trust off, `telemetry.root_context` is on if you still want correlation | Write one entry from that edge and read `trace_id` | With both off, entries of a public-edge request simply carry a null `trace_id`. There is no "read but do not believe" middle ground: the trust check runs before the header is read, so `tracestate` goes with it |
| 5.3 | `trace_id` is never used for authorization, tenancy, deduplication or as a lookup key that grants access | Grep for `withTrace(` and for `trace_id` in your own code | It is caller-supplied, unverified, and never normalised. `withTrace()` compiles a plain equality and validates nothing |
| 5.4 | The jobs table is trusted to the same degree the worker trusts it | Read who can write to your queue backend | `Telemetry\Envelope::receive()` re-parses the `traceparent` strictly, but applies no length cap and never consults `trust_incoming_header` — trust was decided on the producing side. Anyone who can write a job payload chooses the trace the worker believes |
| 5.5 | `telemetry.store_tracestate` is off unless you consume the vendor list | Read the config | It writes client-controlled text into `context`, which is one of the canonical columns the hash covers. The inbound cap is `TraceContext::TRACESTATE_LIMIT` (512 characters), above which the value is dropped entirely |
| 5.6 | An inbound request id cannot become arbitrary text | Send a hostile `X-Request-Id` and read the entry | `Http\Middleware\AssignRequestId` accepts an inbound value only when it is at most 64 characters and matches `/^[\x21-\x7e]+$/`; otherwise it mints a ULID. It is registered in no middleware group — you opt in |
| 5.7 | A custom `StreamResolver` returns a bounded, stable name | Write one entry per shape of input | `Integrity\Stream::guard()` throws `ConfigurationException::streamEmpty` / `::streamTooLong` above `Stream::MAX_LENGTH` (64) — never truncates. The name is in the hash prefix, so a resolver whose output drifts forks the chain |
| 5.8 | `occurred_at` supplied by a caller is not mistaken for a clock you control | Read who passes `occurred_at` | It is the caller's statement of when the fact happened. Retention measures from `created_at` for exactly this reason |

> 🔒 **Security.** A malformed `traceparent` is treated as **absent**: no exception, no log line, no
> warning. A misconfigured upstream shows up only as missing correlation. Uppercase hex is refused
> outright — the spec mandates lowercase and Sentinel does not normalise. See
> [Distributed tracing](../04-context/06-distributed-tracing.md).

---

## 6. Exporting and handing over

| # | Check | How to verify it | What a failure means |
|---|---|---|---|
| 6.1 | `integrity.signature.enabled` is on **before** any export you intend to be checkable | Open the `.manifest.json` and look at `signature_key_id` | With signing off, `Signers::current()` is `NullSigner`: the manifest ships `"signature": ""` and `"signature_key_id": "null"`, and `NullSigner::verify()` always returns false. The manifest proves nothing and does not say so |
| 6.2 | Both `--disk` and `--path` are passed when you meant to write a file | Read the command's output | `ExportCommand` writes the body and `<path>.manifest.json` only when **both** are present; with one of them the body goes to standard output instead |
| 6.3 | The recipient can actually check what you sent | Have them recompute the digest over the body bytes and verify it against the verifying half of `integrity.signature.keys` | The manifest travels beside the body, not inside it. Putting the digest into the bytes it digests is the one shape that cannot work |
| 6.4 | You know what the export does and does not decrypt | Export one entry with an encrypted field and read it | `Audit::toArray()` decrypts nothing. Ciphertext leaves as ciphertext, masks as masks, digests as digests. The only two places in the package that decrypt are `Restore\Planner` and `Security\Rekeyer` |
| 6.5 | The format matches the purpose | `ndjson` to be read back, `csv` for a person | `csv` flattens nested columns into JSON strings inside cells and is lossy by construction |
| 6.6 | A redacted entry is exported as redacted | Export a tombstone | The serialized shape carries `integrity.redacted` — `at`, `reason`, `hash` — so what leaves the building says the contents were destroyed rather than pretending they were empty |
| 6.7 | Under compliance mode, the export is itself recorded | Export, then read `sentinel_access_log` | An export is the largest read a trail ever serves, and it leaves the same two records as any other |

```bash
php artisan sentinel:export \
    --format=ndjson --tenant=acme --limit=5000 \
    --disk=exports --path=exports/acme-2027-01.ndjson

# exports/acme-2027-01.ndjson.manifest.json
# { "format": "ndjson", "entries": 4188, "digest": "sha256:9f2c…",
#   "signature": "…", "signature_key_id": "v2" }
```

Hand over `integrity.signature.keys` — the verifying half — and nothing else. Never `private_key`,
never `security.encryption.keys`, never `security.hashing.salt`, never `APP_KEY`. See
[Export and rekey](../08-lifecycle/06-export-and-rekey.md).

---

## 7. Erasure requests

Two different things share the word "redaction" and are unrelated:
`security.redaction.*` masks values as they are **captured**;
`Redaction\Redactor` destroys the contents of an entry sealed long ago.

| # | Check | How to verify it | What a failure means |
|---|---|---|---|
| 7.1 | Entry-level erasure is done with `Redactor`, not with retention | Read your runbook | The prune unit is the **anchored window**, whole. The effective retention of a range is that of its longest-lived entry, so a ninety-day policy frees nothing in a window that also holds a seven-year entry |
| 7.2 | Every redaction names an actor, compliance mode or not | `sentinel:redact <id> --reason=… --actor=App\\Models\\User:100` | The chained trail entry is the only thing separating a declared redaction from an attack, and one with nobody on it is the shape of an unattributable deletion. The redaction writes through the query builder — the same door an unsanctioned write would use, and nothing in the row tells them apart |
| 7.3 | You know what a redaction empties | Redact a test entry and diff the row | Six columns: `context` (to `[]`), `before`, `after`, `changes`, `metadata`, `criteria` (to `null`) — plus the entry's labels and relation lines. A relation entry keeps its content **only** in `changes`, so a shorter list would redact nothing at all |
| 7.4 | The three refusals are handled | Force each one | `RedactionException::unverifiable` (the row no longer reproduces its own hash), `::archived` (its range lives in a cold batch — the message names the disk and path), `::retired` (the range was pruned) |
| 7.5 | An erasure inside an archived range uses the round trip | `Rehydrator::restore()` → redact → `sentinel:prune --action=archive` | There is no other supported route, and the batch carries the tombstones back out perfectly well |
| 7.6 | The archive disk's versioning setting is a decision, not a default | Check the bucket | Object versioning and object-lock are what make the archive tamper-resistant — and they are also exactly what keeps the **pre-redaction** version of a re-archived batch alive. The batch path is a pure function of the range, and the package never deletes a file from the archive disk |
| 7.7 | Nobody promises a *completed* erasure | Read what you tell the data subject | The package offers no way to prove a redaction reached replicas, backups or copies you made yourself |
| 7.8 | Redaction by key is not expected | Read the API | `metadata` goes whole, destroying facts that are not personal data. Redacting by key would leave an operator deciding which part of the content survives, which is the discretion a tombstone exists not to have |

```php
use ElPandaPe\Sentinel\Enums\ContentState;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Redaction\Redactor;
use ElPandaPe\Sentinel\Support\Reference;

$tombstone = app(Redactor::class)->redact(
    Audit::query()->findOrFail($auditId),
    'erasure request 4711',
    Reference::to($officer),
);

$tombstone->sequence;     // unchanged — the entry stays in its place
$tombstone->redactedHash; // a second hash, over what the redaction left
$tombstone->trail?->id;   // the chained, signed 'security'/'redacted' entry

Audit::query()->findOrFail($auditId)->verifyContent() === ContentState::Redacted;  // true
```

> ⚠️ **Warning.** `redacted_at`, `redaction_reason` and `redacted_hash` are outside the canonical
> payload, outside the signature and outside the fold. Whoever can empty `before` can equally write
> `redacted_at` and recompute `redacted_hash`. The second hash catches a **later** write into a
> redacted row and nothing more. What distinguishes a declared redaction from an attack is the trail
> entry — which an attacker does not write. See
> [Redaction and tombstones](../08-lifecycle/04-redaction-and-tombstones.md).

---

## The threat model

### What each signing tier stands against

| Tier | Stops | Does not stop |
|---|---|---|
| `NullSigner` (`signer: null`, or signing off) | Nothing. It holds the shape of the write path and attests to nothing | Everything. `verify()` returns false rather than true: a signer with no key cannot tell a good signature from a bad one |
| `HmacSigner` (the default when signing is on) | Someone who reached the **database** and not the application: a stolen backup, a read replica, a SQL console, an injection sink | Anyone who can read the signing secret — which under `hmac` is the same secret that verifies, and by default is derived from `APP_KEY` |
| `OpenSslSigner` | All of the above, **and the machine's own administrator**, when `private_key` lives off the box that holds the entries | Whoever holds the private key. The verifying half proves; it cannot sign |

A fourth tier exists and is deliberately out of scope: a forward-secure MAC that evolves its key and
erases the previous one, so that a key stolen today cannot forge yesterday. `systemd-journald` ships
one. Sentinel does not, and says so rather than implying otherwise.

### The honest limit: a compromised application at capture time

**No signature proves the content is true.** It proves nobody touched the row after it was written.
Someone with application access at capture time — an injected service, a compromised deploy, an
insider with a shell — produces a perfectly intact, perfectly signed chain of false statements, and
every verification depth agrees it is sound. That is what "append-time integrity" means, and it is
the boundary of what this package can offer.

The same boundary, in three concrete shapes:

- **The chain proves the row is the one that was written, not what the value said.** The hash covers
  the ciphertext. Forging a stored `key_id` breaks the hash, because `encryption` is inside the
  canonical payload — but a value that was wrong when it was captured is sealed exactly as wrongly.
- **The chain proves what settled, never that everything that happened settled.** Under
  `mode = buffered`, entries a process dies holding have no `sequence` and no `hash`; the walk of a
  shorter chain reports it intact, correctly. Listen for `Events\BufferFlushFailed`, or use `sync`.
- **A pruned or archived absence is crossed only when two independent things agree** — the manifest
  says the range was retired *and* the anchors reach past it. Nothing in `sentinel_archives` is
  hashed or signed; on its own it would make "delete the rows, then insert one row" a supported way
  of laundering a gap.

### What is outside the hash, on purpose

| Outside the hash | Consequence |
|---|---|
| `signature`, `signature_key_id` | Signing an entry costs no `payload_version` bump |
| `redacted_at`, `redaction_reason`, `redacted_hash` | A redaction rewrites nothing the chain covers, and proves nothing against someone who can write the row |
| `sentinel_audit_tags` (labels) | Classifying an old entry breaks no hash; relabelling leaves no trace |
| `sentinel_audit_relations` (the projection) | Editing it leaves the chain intact and every relation query answering a different question. `--projections` detects it; nothing repairs it |
| `sentinel_archives` (the manifest) | A map, never evidence |

---

## What to hand a security reviewer

A packet a reviewer can work from without any access to your systems:

1. **The verifying half of the signing keys** — `integrity.signature.keys`, and nothing else from
   that block. Under `openssl` this is a public key and handing it over costs nothing.
2. **An `ndjson` export and its `.manifest.json`**, produced with signing on, so the digest and its
   signature are real. See [Export and rekey](../08-lifecycle/06-export-and-rekey.md).
3. **The hash formula and the column list.** `hash = algorithm(payload_version ␟ stream ␟ sequence ␟
   (previous_hash ?? '') ␟ canonical(core))`, separator `\x1f`, canonicalisation per RFC 8785, and
   `Integrity\CanonicalPayload::COLUMNS` as the single enumeration of what `core` is. That is enough
   to reimplement the verifier — point them at
   [Canonicalization](../07-integrity/03-canonicalization.md) for the two documented deviations from
   strict JCS.
4. **The output of `php artisan sentinel:verify --depth=entries`, with its exit code.** `0` is sound
   (a wholly unsigned trail included, and one whose only finding is declared redactions), `1` is a
   bad finding from a run that happened, `2` is a run that could not happen.
5. **The anchoring configuration and schedule** — `integrity.checkpoints.every`, and where
   `sentinel:checkpoint` runs from.
6. **The seven configuration keys that decide everything above**: `compliance`,
   `integrity.signature.enabled`, `integrity.signature.signer`, `integrity.checkpoints.enabled`,
   `on_write_failure`, `telemetry.trust_incoming_header`, and the three `security.*.fields` lists.
7. **The retention and redaction runbook** — which logical types are declared, who may run
   `sentinel:redact`, and what an erasure request does end to end.

Never in the packet: `integrity.signature.private_key`, `security.encryption.keys`,
`security.hashing.salt`, `APP_KEY`, or a database credential.

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| A field you declared encrypted comes back as an unreadable mask, even holding the key | It is in `$auditRedact` **and** `$auditEncrypt`. `MaskSensitiveData` runs first, so the ciphertext is a ciphertext of the mask | Declare each field in exactly one of the four lists. Nothing warns about the combination |
| Every encrypted value became unreadable and every digest stopped comparing, on the same deploy | `APP_KEY` was rotated while `SENTINEL_ENCRYPTION_KEY` and `SENTINEL_HASH_SALT` were unset, so both were deriving from it | Pin both explicitly *before* the first protected write. Laravel's `APP_PREVIOUS_KEYS` does not help: `Keyring` builds a single-key `Encrypter` per identifier |
| `sentinel:verify` exits 0 on a trail you know somebody edited | The edit went through `Audit::query()->update(...)`, and you checked at `--depth=roots` or `--depth=anchors` | Only `--depth=entries` rehashes. The shallow depths fold the stored `hash` column, which the editor left alone |
| An export's manifest verifies as false for everybody | `integrity.signature.enabled` was off, so `Signers::current()` was `NullSigner` and the manifest carries `"signature": ""` with key id `"null"` | Turn signing on before exporting anything meant to be checked. `NullSigner::verify()` returns false by contract |
| `sentinel:export --disk=exports` printed thousands of lines to the terminal | `--path` was omitted; the command writes files only when both options are present | Pass both, or accept standard output deliberately |
| Entries from a public endpoint are all filed under one `trace_id` you have never seen | `telemetry.trust_incoming_header` is at its `true` default and a caller is choosing the value | Set it to `false` at that edge and turn `telemetry.root_context` on if you still want per-request correlation |
| Redacting an entry throws `RedactionException::archived` | Its range has left the hot table for a cold batch | Rehydrate the range, redact, then re-archive. Note that a versioned bucket keeps the pre-redaction object |
| A second `sentinel:redact` on the same entry succeeds under compliance mode without `--actor` | Idempotency is the first branch of `Redactor::redact()`; the compliance guard follows it, so an already-redacted entry returns its tombstone before the check | Do not read that as the guard being optional; the first redaction is the one that is refused |
| `sentinel_access_log` grew without bound after enabling compliance mode | Nothing in the prune removes rows from it; a retention policy on `'access'` only prunes the chained entries | Partition it (`sentinel:partitions --table=access_log`) or give it a reaper of your own |
| `signature_key_id` values in the table name a key nobody can find | A retired key was removed from `integrity.signature.keys` to "clean up" | Put it back. Those entries report `UnknownKey` — unprovable, and permanently so if the key is gone |

---

## ✅ Best practices

✅ **Do** — pick one protection per field, by what you will need later: exclude when the value must
not exist, redact when a human must recognise it, hash when only "did it change" matters, encrypt
when it has to come back. The restore planner enforces this, it does not merely advise it.

```php
protected array $auditExclude = ['remember_token'];
protected array $auditRedact  = ['email'];
protected array $auditHash    = ['insurance_number'];
protected array $auditEncrypt = ['national_id'];   // the only recoverable one
```

❌ **Don't** — list a field twice hoping for defence in depth. The transformations chain, and the
second one sees the first one's output.

```php
protected array $auditRedact  = ['national_id'];
protected array $auditEncrypt = ['national_id'];   // stores an encrypted mask; plaintext is gone
```

---

✅ **Do** — pin the two secrets that silently derive from `APP_KEY`, in every environment, before the
first protected entry is written.

```dotenv
SENTINEL_ENCRYPTION_KEY=base64:...
SENTINEL_HASH_SALT=...
SENTINEL_SIGNING_KEY=...
```

❌ **Don't** — leave them unset and rotate `APP_KEY`. Nothing throws; every encrypted value becomes
unreadable and every earlier digest becomes incomparable, in one move.

```dotenv
# APP_KEY rotated, SENTINEL_* absent — two silent losses at once
APP_KEY=base64:...
```

---

✅ **Do** — prove in CI that verification survives an empty keyring, so a regression that made the
chain depend on decryptable plaintext fails there rather than in an auditor's hands.

```php
config(['sentinel.security.encryption.keys' => []]);

expect(Sentinel::verifyIntegrity('tenant:acme')->isIntact())->toBeTrue();
```

❌ **Don't** — verify only on a node that holds the keys. The property you are selling is that an
auditor with no key can reproduce the hash; an unverified property is not a property.

```php
// keys present, so a chain that quietly started depending on plaintext still passes
Sentinel::verifyIntegrity('tenant:acme');
```

---

✅ **Do** — turn the inbound trace header off at a public edge and open your own root trace instead.

```php
'telemetry' => [
    'enabled'               => true,
    'trust_incoming_header' => false,  // the caller chooses traceparent; trace_id is indexed
    'root_context'          => true,   // correlate anyway, with no client input in the column
],
```

❌ **Don't** — leave the default in an internet-facing application because "it is only correlation".
The column is indexed and hash-covered, and a caller who chooses its value chooses how your trail
groups.

```php
'telemetry' => ['enabled' => true],   // trust_incoming_header defaults to true
```

---

✅ **Do** — name who ordered every redaction, and keep the tombstone that comes back.

```php
$tombstone = app(Redactor::class)->redact($entry, 'erasure request 4711', Reference::to($officer));
```

❌ **Don't** — redact anonymously and rely on `redacted_hash` to prove the act was declared. It is
outside the payload, the signature and the fold; the trail entry is the proof.

```php
app(Redactor::class)->redact($entry, 'cleanup');   // legal outside compliance mode, and unattributable
```

---

✅ **Do** — hand a reviewer the verifying half of the signing ring and an export with a real
signature.

```php
'integrity' => ['signature' => [
    'enabled' => true,
    'signer'  => 'openssl',
    'keys'    => ['v2' => env('SENTINEL_SIGNING_PUBLIC_V2')],  // this is what leaves the building
]],
```

❌ **Don't** — hand over the encryption ring, the hashing salt or `private_key` "so they can check
the values". None of the three is needed to verify anything, and all three are needed to forge.

```php
// nothing a reviewer needs is in here
'security' => ['encryption' => ['keys' => ['default' => env('SENTINEL_ENCRYPTION_KEY')]]],
```

---

**See also:** [Protecting sensitive data](../05-pipeline-and-security/02-protecting-sensitive-data.md) · [Encryption and the keyring](../05-pipeline-and-security/03-encryption-and-the-keyring.md) · [Hashing and the salt](../05-pipeline-and-security/04-hashing-and-the-salt.md) · [Signing the chain](../07-integrity/04-signing.md) · [Verification](../07-integrity/06-verification.md) · [The verification playbook](../07-integrity/07-the-verification-playbook.md) · [Redaction and tombstones](../08-lifecycle/04-redaction-and-tombstones.md) · [Compliance mode](../08-lifecycle/05-compliance-mode.md) · [Export and rekey](../08-lifecycle/06-export-and-rekey.md) · [Distributed tracing](../04-context/06-distributed-tracing.md) · [Anti-patterns](02-anti-patterns.md) · [Production readiness](04-production-readiness.md) · [Configuration](../99-reference/02-configuration.md) · [Exceptions](../99-reference/06-exceptions.md) · [Exit codes](../99-reference/07-exit-codes.md)
