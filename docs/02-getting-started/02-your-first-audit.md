# 🚀 Your first audit

> Ten minutes from an unaudited model to a chain you have walked and verified yourself.

**On this page:** [What you need](#what-you-need) · [1. Add the trait](#1-add-the-trait) · [2. Make a change](#2-make-a-change) · [3. Read the entry back](#3-read-the-entry-back) · [4. Read the diff](#4-read-the-diff) · [5. Put an actor on it](#5-put-an-actor-on-it) · [6. Label it](#6-label-it) · [7. Verify the chain](#7-verify-the-chain) · [8. Break it on purpose](#8-optional--break-it-on-purpose) · [What just happened](#what-just-happened) · [Where to go next](#where-to-go-next) · [⚠️ Pitfalls](#️-pitfalls) · [✅ Best practices](#-best-practices)

---

## What you need

[Installed and migrated](01-installation.md), and one Eloquent model with a table behind it. This
walkthrough uses `App\Models\Invoice` with `number` (string), `status` (string) and `total` (integer
cents), plus Laravel's timestamps.

Everything runs in `php artisan tinker` except step 5, which needs a request to have an
authenticated user in it.

```bash
php artisan tinker
```

---

## 1. Add the trait

```php
namespace App\Models;

use ElPandaPe\Sentinel\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

final class Invoice extends Model
{
    use Auditable;

    protected $fillable = ['number', 'status', 'total'];
}
```

That is the entire opt-in. There is no interface to implement and no observer to register.

**What the trait did.** `bootAuditable()` registered five Eloquent events —
`created`, `updated`, `deleted`, `forceDeleted` and `updating` — with the listener given as
`[ModelObserver::class, $event]`, so the container re-resolves the observer, and with it the scoped
ledger, every time one fires. It also replaced `newBelongsToMany()` and `newMorphToMany()` with
audited relations, which is the only way a pivot write gets recorded at all: Eloquent fires no model
event for `attach`, `detach`, `sync` or `toggle`.

**What it did not do.**

- It does **not** listen for `restored`. By the time that event fires, `save()` has already called
  `syncOriginal()`, so the state the record had while deleted is unreachable; a restore is derived
  from the `updated` that cleared the deletion mark instead.
- `restored` and `force_deleted` entries only ever appear on a model that also uses `SoftDeletes`.
  The trait neither requires nor adds it.
- `updating` writes nothing. It is registered so a model that declares state transitions can refuse
  a move **before** the row is written.
- It adds no columns to your table and no `belongsTo` on your model. Entries live in
  `sentinel_audits` and point back through a morph.

---

## 2. Make a change

```php
use App\Models\Invoice;

$invoice = Invoice::create(['number' => 'INV-1001', 'status' => 'draft', 'total' => 420_000]);

$invoice->update(['status' => 'approved', 'total' => 450_000]);
```

Two entries now exist: one `created`, one `updated`. Both were written **inside** the request that
caused them, in the shipped `sync` mode, and both are already sealed into a hash chain.

---

## 3. Read the entry back

```php
$audit = $invoice->latestAudit();

$audit->event;         // 'updated'
$audit->audit_type;    // 'model'
$audit->severity;      // ElPandaPe\Sentinel\Enums\Severity::Info
$audit->source;        // ElPandaPe\Sentinel\Enums\Source::Cli   — you are in a console process
$audit->version;       // 2   — the subject's own counter, not the chain's
$audit->stream;        // 'global'
$audit->sequence;      // 2   — position in that chain
$audit->actor_type;    // null — see step 5
$audit->occurred_at;   // CarbonImmutable, stamped at capture
$audit->created_at;    // CarbonImmutable, stamped when the ledger settled it
```

The two states are the **whole** record, not the dirty attributes:

```php
$audit->before;
// [
//     'created_at' => '2026-09-07T10:14:02.000000+00:00',
//     'id'         => 1,
//     'number'     => 'INV-1001',
//     'status'     => 'draft',
//     'total'      => 420000,
//     'updated_at' => '2026-09-07T10:14:02.000000+00:00',
// ]

$audit->after;
// same keys, with status 'approved', total 450000 and a newer updated_at
```

Three things about that shape are deliberate and worth knowing now. Keys are **sorted** and lists
are kept as lists, because neither MySQL `json` nor PostgreSQL `jsonb` preserves the order you wrote
and the payload has to hash the same on every engine. Dates are rendered with a fixed microsecond
format (`Y-m-d\TH:i:s.uP`), frozen with `payload_version` 1. And hidden attributes are audited by
default — auditing is what the package is for; `snapshots.include_hidden` is what turns that off.

To see the serialised entry — the shape that is frozen and only ever grows:

```php
$audit->toArray();
// [
//     'id'         => '01K4H0S6M9YQ2WV7Z0N3B8DF5T',   // ULID
//     'audit_type' => 'model',
//     'event'      => 'updated',
//     'severity'   => 'info',
//     'source'     => 'cli',
//     'subject'    => ['type' => 'App\Models\Invoice', 'id' => '1'],
//     'actor'      => null,
//     'impersonator' => null,
//     'tenant_id'  => null,
//     'version'    => 2,
//     'changes'    => [ ... see step 4 ... ],
//     'before'     => [ ... ],
//     'after'      => [ ... ],
//     'metadata'   => null,
//     'tags'       => [],
//     'context'    => ['command' => 'tinker', 'arguments' => [...], 'environment' => 'local', 'hostname' => '...'],
//     'transaction_id' => null,
//     'request_id' => null,
//     'trace_id'   => null,
//     'span_id'    => null,
//     'source_audit_id' => null,
//     'criteria'   => null,
//     'affected_rows'   => null,
//     'integrity'  => [
//         'stream' => 'global', 'sequence' => 2, 'algorithm' => 'sha256',
//         'payload_version' => 1, 'previous_hash' => '…', 'hash' => '…',
//         'signature' => null, 'signature_key_id' => null,
//         'verified' => null, 'redacted' => null,
//     ],
//     'occurred_at' => '2026-09-07T10:14:07.481000+00:00',
//     'created_at'  => '2026-09-07T10:14:07.492000+00:00',
// ]
```

> 📌 **Note.** `integrity.verified` is always `null` here. Serialising an entry does not verify it —
> that is a separate walk and a separate cost. Ask `$audit->verifyIntegrity()` when you want the
> answer.

From a terminal, the same entry in a sentence:

```bash
php artisan sentinel:show 01K4H0S6M9YQ2WV7Z0N3B8DF5T
# Someone changed Invoice #1

php artisan sentinel:show --subject="App\Models\Invoice:1"
# 10:14  Someone created Invoice #1
# 10:14  Someone changed Invoice #1
```

`--subject` takes `type:id` exactly as the entry recorded it — the type is not resolved to a class,
because a morph map is what decides what a type means.

---

## 4. Read the diff

The comparison ran at capture and travels on the entry, as RFC 6901 pointers with the old value beside
the new one:

```php
$audit->diff()->toArray();
// [
//     ['path' => '/status',     'op' => 'replace', 'old' => 'draft', 'new' => 'approved'],
//     ['path' => '/total',      'op' => 'replace', 'old' => 420000,  'new' => 450000],
//     ['path' => '/updated_at', 'op' => 'replace', 'old' => '2026-09-07T10:14:02.000000+00:00',
//                                                  'new' => '2026-09-07T10:14:07.000000+00:00'],
// ]

$audit->diffFor('status')->toArray();   // just the /status change
count($audit->diff());                   // 3
$audit->diff()->isEmpty();               // false
```

Yes, `updated_at` is in there. The snapshot is the whole record and Eloquent touches the timestamp
before the `updated` event fires, so it genuinely changed. Drop it with `$auditExclude` if the noise
matters — see [What a model declares](03-what-a-model-declares.md).

For interoperability, the same diff as an RFC 6902 patch, with a `test` in front of what it
overwrites so a consumer can check it against the document it came from:

```php
$audit->diff()->toJsonPatch();
// [
//     ['op' => 'test',    'path' => '/status', 'value' => 'draft'],
//     ['op' => 'replace', 'path' => '/status', 'value' => 'approved'],
//     ...
// ]
```

---

## 5. Put an actor on it

Nothing authenticated your tinker session, which is why `actor_type` was null. The actor comes from
the guard, resolved on **every** capture — it is not memoized, because a user can log in mid-request.

The realistic path is a request:

```php
// routes/web.php
use App\Models\Invoice;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->patch('/invoices/{invoice}/approve', function (Invoice $invoice) {
    $invoice->update(['status' => 'approved']);

    return $invoice->latestAudit();
});
```

The entry that comes back now carries the person and the request:

```php
$audit->actor_type;   // 'App\Models\User'  — the morph alias, so a query can filter on it
$audit->actor_id;     // '7'
$audit->source;       // Source::Http  (Source::Api if the path matches resolvers.request.api)
$audit->request_id;   // a ULID shared by every entry that request writes

$audit->context;
// [
//     'hostname'    => 'web-01',
//     'environment' => 'production',
//     'ip'          => '203.0.113.42',
//     'user_agent'  => 'Mozilla/5.0 …',
//     'url'         => 'https://example.test/invoices/1/approve',
//     'route'       => 'invoices.approve',
//     'method'      => 'PATCH',
//     'session_id'  => '…',
// ]
```

`request_id` is not in there. `Context\ContextEngine` strips the nine promoted keys out of the
payload before writing it, so each of them lives in its own column and nowhere else.

If you want to see it without leaving tinker, set the user on the guard directly — the resolver only
asks the guard for a user, and does not care how it got there:

```php
use App\Models\User;
use Illuminate\Support\Facades\Auth;

Auth::setUser(User::query()->firstOrFail());

$invoice->update(['status' => 'sent']);

$invoice->latestAudit()?->actor_id;   // '1'
```

> ⚠️ **Warning.** `Sentinel::withContext()` merges keys into the `context` payload and can **never**
> reach a promoted column — `actor_*`, `impersonator_*`, `tenant_id`, `request_id`, `trace_id`,
> `span_id` and `source` are filled by resolvers only. To change who acted, replace the actor
> resolver. See [Writing your own resolver](../04-context/07-writing-your-own-resolver.md).

---

## 6. Label it

Labels are operational classification: cheap to filter on, and declared per model.

```php
final class Invoice extends Model
{
    use Auditable;

    /** @var list<string> */
    protected array $auditTags = ['billing', 'finance'];
}
```

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

$invoice->update(['status' => 'paid']);

$invoice->latestAudit()?->tags->pluck('tag')->all();   // ['billing', 'finance']  — ordered by tag

Sentinel::audits()->for($invoice)->whereTag('billing')->get();               // carries 'billing'
Sentinel::audits()->for($invoice)->whereTag(['billing', 'finance'])->get();  // carries ALL of them
Sentinel::audits()->for($invoice)->whereAnyTag(['billing', 'legal'])->get(); // carries EITHER
```

The model's list is **unioned** with `sentinel.tags.default` and with whatever the caller already put
on the entry — a config list never overrides a model declaration, and it is the only way to label
entries no model owns.

Two limits worth learning here rather than later:

- Only entries written **after** you declare the labels carry them. History is append-only; the two
  entries from step 2 have no labels and never will.
- A label over 64 characters throws `ConfigurationException::tagTooLong` from the labelling stage.

> 🔒 **Security.** Labels sit **outside** the hashed payload. That cuts both ways: reclassifying an
> old entry does not break the chain, and relabelling is therefore not tamper-evident. Anything that
> has to be provable belongs in `metadata`, which is inside the canonical payload.

---

## 7. Verify the chain

```bash
php artisan sentinel:verify
```

```text
+--------+---------+--------+---------+------------+
| Stream | Entries | Chain  | Anchors | Signatures |
+--------+---------+--------+---------+------------+
| global | 4       | intact | —       | 4 unsigned |
+--------+---------+--------+---------+------------+

Verified 4 entries across 1 streams. The chain is intact.
```

Read the row left to right:

| Column | What it says here | Why |
|---|---|---|
| Stream | `global` | `integrity.stream` ships as `tenant`, which behaves exactly like `global` until a tenant actually resolves. Nothing resolved one. |
| Entries | `4` | Entries this walk read **and rehashed**. It would also show retired and redacted counts, but only when there are some — a zero beside every stream would make an installation that has never pruned read as though it had. |
| Chain | `intact` | Every entry reproduced its own hash and linked to the one before it. |
| Anchors | `—` | `integrity.checkpoints.enabled` is `false` by default, so nothing anchored anything. A dash, not a zero. |
| Signatures | `4 unsigned` | `integrity.signature.enabled` is `false` by default. **Unsigned is not a defect** and the command exits `0`: saying otherwise would make it useless on every installation that has not switched signing on. |

The same three questions from PHP:

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

$report = Sentinel::verifyEverything();   // every stream, no range
$report->isIntact();                      // true
$report->checked();                       // 4

Sentinel::verifyIntegrity('global')->isIntact();   // one stream, optionally a sequence range
$invoice->latestAudit()?->verifyIntegrity();       // one row: does it still reproduce its hash?
```

**Exit codes**, because a cron has to tell three cases apart: `0` the walk completed and nothing came
back wrong · `1` the chain is broken, and the failing entry is named · `2` the command could not run,
which is not the same as nothing being wrong.

---

## 8. (Optional) Break it on purpose

Do this on a scratch database. It is the fastest way to see what the chain is actually for.

The audit model refuses to be edited — `updating` and `deleting` throw `ImmutableAuditException`, and
a subclass named in `models.audit` inherits the guard. So tampering means going around Eloquent
entirely, which is exactly the attack the hash exists to detect:

```php
use Illuminate\Support\Facades\DB;

$audit = $invoice->latestAudit();

DB::table('sentinel_audits')
    ->where('id', $audit->id)
    ->update(['after' => json_encode([...$audit->after, 'total' => 1])]);
```

```bash
php artisan sentinel:verify
# | global | 3 | BROKEN | — | 3 unsigned |
# Audit 01K4H0S6M9YQ2WV7Z0N3B8DF5T no longer reproduces its own hash at sequence 4 of stream global.
# exit code 1
```

The walk stops at the break and reports the three entries it read before it: past a broken link,
nothing can be said, so it is not said. Note also that the row is still there — Sentinel is
**tamper-evident**, not
tamper-proof. It proves the record was altered; it does not prevent the alteration and it cannot
recover the original.

---

## What just happened

| Step | Mechanism | Where it is explained |
|---|---|---|
| The trait registered listeners and swapped the relation factories | `Concerns\Auditable::bootAuditable()` | [What a model declares](03-what-a-model-declares.md) · [Relationship auditing](../03-capture/04-relationships.md) |
| Four of those events became six (`event`, `audit_type`) pairs, with `restored` and `transition` derived from an `updated` rather than listened for | `Capture\ModelObserver` | [What gets audited](../03-capture/01-what-gets-audited.md) |
| The whole record was stored, key-sorted, with casts applied | `Snapshot\SnapshotBuilder` | [Snapshots](../03-capture/02-snapshots.md) |
| The comparison became JSON Pointer paths with old beside new | `Diff\Diff` | [Diffs](../03-capture/03-diffs.md) |
| Ten resolvers filled nine promoted columns and the `context` payload | `Context\ContextEngine` | [Execution context](../04-context/01-execution-context.md) · [The ten resolvers](../04-context/02-resolvers-reference.md) |
| The actor came from the guard, and only from the guard | `Context\Resolvers\ActorResolver` | [Actor and impersonation](../04-context/03-actor-and-impersonation.md) |
| Seven stages transformed the entry before the ledger saw it | `Pipeline` | [The write pipeline](../05-pipeline-and-security/01-the-write-pipeline.md) |
| Labels were unioned and written inside the sealing transaction | `Pipeline\Stages\ResolveTags` | [Labels](../06-reading/06-labels.md) |
| A sequence, a canonical payload and a hash linked to the one before | `Ledger\DatabaseLedger` | [The hash chain](../07-integrity/01-the-hash-chain.md) · [Canonicalization](../07-integrity/03-canonicalization.md) |
| `sentinel:verify` re-read and rehashed every entry | `Integrity\Verifier` | [Verification](../07-integrity/06-verification.md) |
| Reads went through a query stated against a contract, not Eloquent | `Query\AuditQuery` | [The Query API](../06-reading/01-the-query-api.md) |

---

## Where to go next

| If you want to… | Go to |
|---|---|
| Control what a model records, protects or grades | [What a model declares](03-what-a-model-declares.md) |
| Stop writing entries for one operation, or for a whole environment | [Turning auditing off](04-turning-auditing-off.md) |
| Decide sync vs queue vs buffered, and one chain vs one per tenant | [Choosing your setup](05-choosing-your-setup.md) |
| Keep a password, a card number or a national id out of the trail | [Protecting sensitive data](../05-pipeline-and-security/02-protecting-sensitive-data.md) |
| Audit `attach`/`sync`/`detach` on a pivot | [Relationship auditing](../03-capture/04-relationships.md) |
| Record something no model change describes (an approval, a dispatch) | [Custom and authentication events](../03-capture/07-custom-and-authentication-events.md) |
| Correlate several models under one business operation | [Business transactions](../03-capture/06-business-transactions.md) |
| Build a screen out of the trail — filters, paging, a timeline | [The Query API](../06-reading/01-the-query-api.md) · [The timeline](../06-reading/05-the-timeline.md) |
| Put a record back the way an entry found it | [Restoring state](../06-reading/08-restoring-state.md) |
| Make verification cheap on a long trail | [Checkpoints and anchors](../07-integrity/05-checkpoints-and-anchors.md) |
| Sign entries so an outsider can verify them | [Signing the chain](../07-integrity/04-signing.md) |
| Understand what the record is before writing more code | [The audit record](../01-concepts/02-the-audit-record.md) · [The write path](../01-concepts/03-the-write-path.md) |

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| No entry at all after a save | `sentinel.enabled` is false, or something called `Sentinel::pause()` earlier in the scope and never resumed. | `Sentinel::isRecording()` answers both. Use `Sentinel::withoutAuditing()` instead of bare `pause()`. |
| `Model::query()->where(...)->update([...])` wrote no entry | Eloquent fires no model event for a builder update or delete. This is a documented limitation across the ecosystem, not a bug. | Use the `auditing()` builder macro — [Mass operations](../03-capture/05-mass-operations.md). |
| Every diff contains `/updated_at` | The snapshot is the whole record, and Eloquent updates the timestamp before the `updated` event fires. | Declare `$auditExclude = ['updated_at']`, or narrow with `$auditInclude`. |
| `actor_type` and `actor_id` are null | Nothing authenticated the process. A console command, a queue worker and the scheduler have no authenticated user. | Expected in tinker. In a job, the actor has to travel with the job — [Queues, commands and schedulers](../04-context/05-queues-commands-and-schedulers.md). |
| An `updated` save produced no entry | Nothing that is audited changed, so the `FilterUnchanged` stage discarded it. No sequence was taken and the chain has no gap. | Expected. Only updates are filtered this way — [Discarding entries](../05-pipeline-and-security/06-discarding-entries.md). |
| `$invoice->audits()` returns entries in an order that surprises you | It orders by `id`, a ULID minted when the entry **settled**. Under `queue` or `buffered` that is not when the fact happened. | Order by the fact's clock with `Sentinel::timeline()` or `byOccurrence()` — [Order, paging and walking](../06-reading/03-order-paging-and-walking.md). |
| `QueryException` about an unbounded read on `get()` | An uncapped `get()` **refuses** once more than 500 entries match — exactly 500 comes back whole — rather than truncating: a prefix shaped like a complete answer is the one mistake a trail cannot afford. | Add a filter, `take(n)`, or `paginate()`. |
| `ImmutableAuditException` when you try to fix an entry | Entries are append-only by design. | Nothing rewrites history. To destroy the contents of one entry while keeping its position, hash and link, use `sentinel:redact` — [Redaction and tombstones](../08-lifecycle/04-redaction-and-tombstones.md). |
| Entries suddenly move to a `tenant:…` stream with `sequence` back at 1 | A tenant resolver started returning a tenant, and `integrity.stream` ships as `tenant`. Existing chains keep verifying; they stop growing. | Decide before you wire a tenant — [Streams](../07-integrity/02-streams.md) · [Multi-tenancy](../04-context/04-multi-tenancy.md). |

---

## ✅ Best practices

✅ **Do** — read a subject's own trail through the relation when you just want the entries, and
through the facade when the read itself has to be accountable.

```php
$invoice->audits()->get();                        // ergonomic, tags eager-loaded
Sentinel::audits()->for($invoice)->latest()->get(); // the auditable route
```

❌ **Don't** — assume the relation is instrumented. Only the Query API path writes to the compliance
access log; `$model->audits()` and `$model->latestAudit()` are declared out of scope, because hooking
them would mean one write per audit row hydrated — the verifier's and the exporter's included.

```php
$invoice->audits()->get();   // under compliance mode, this read is not recorded
```

---

✅ **Do** — protect a field you still want to see the shape of, rather than dropping it.

```php
/** @var list<string> */
protected array $auditRedact = ['email'];      // masked, but the change is still on the record
```

❌ **Don't** — reach for `$auditExclude` when the fact that a field changed matters. Exclusion drops
the key **before** the pipeline, so nothing downstream — no diff, no transition, no restore — can say
anything about it, ever.

```php
protected array $auditExclude = ['email'];     // the change becomes invisible, not private
```

---

✅ **Do** — declare labels once on the model and let the union do the rest.

```php
/** @var list<string> */
protected array $auditTags = ['billing'];
```

❌ **Don't** — expect a label to be evidence. Labels live outside the hashed payload, so relabelling
an old entry is not tamper-evident. Put what must be provable in `metadata`.

```php
Sentinel::event('invoice.approved')->subject($invoice)->tags(['approved-by-finance'])->record();
// the label is classification; the proof belongs in ->metadata([...])
```

---

✅ **Do** — verify the chain, not just one row, and let the exit code drive the alert.

```bash
php artisan sentinel:verify --depth=entries    # reads and rehashes every entry
```

❌ **Don't** — read `--depth=anchors` or `--depth=roots` as "the trail is intact". Both walk
anchors and report an anchored range as **anchored**; only the `entries` depth proves what an
entry says.

```bash
php artisan sentinel:verify --depth=anchors    # cheap, and answers a different question
```

---

✅ **Do** — treat the first write of a model as the moment its declarations are validated. A state
column named in `$auditTransitions` must stay readable in the entry.

```php
protected array $auditTransitions = ['status'];
protected array $auditInclude = ['status', 'total'];   // status is in the whitelist — required
```

❌ **Don't** — redact, hash, encrypt, exclude or whitelist-out a column you also declared as a
transition. It is refused, and the refusal fires on the model's very first insert — not on the first
transition.

```php
protected array $auditTransitions = ['status'];
protected array $auditRedact = ['status'];     // ConfigurationException on the next Invoice::create()
```

---

**See also:** [Installation](01-installation.md) · [What a model declares](03-what-a-model-declares.md) · [Turning auditing off](04-turning-auditing-off.md) · [Choosing your setup](05-choosing-your-setup.md) · [The audit record](../01-concepts/02-the-audit-record.md) · [The write path](../01-concepts/03-the-write-path.md) · [What gets audited](../03-capture/01-what-gets-audited.md) · [The Query API](../06-reading/01-the-query-api.md) · [Verification](../07-integrity/06-verification.md)
