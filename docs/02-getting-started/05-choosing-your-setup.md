# 🚀 Choosing your setup

> Seven installation profiles, what each one turns on, what each one must leave off, and the single
> mistake each one makes most often.

**On this page:** [How to read this](#how-to-read-this) · [You want a change log](#profile-1--you-want-a-change-log-not-a-ledger) · [Multi-tenant SaaS](#profile-2--multi-tenant-saas) · [Regulated fintech or healthcare](#profile-3--regulated-fintech-or-healthcare) · [High-write ingestion](#profile-4--high-write-ingestion) · [Migrating an existing trail](#profile-5--migrating-an-existing-trail) · [Internal back-office](#profile-6--internal-back-office-nobody-on-call) · [External verification node](#profile-7--external-verification-node) · [Decision table](#the-decision-table) · [What each profile schedules](#what-each-profile-schedules) · [Pitfalls](#️-pitfalls) · [Best practices](#-best-practices)

---

## How to read this

Sentinel ships with almost everything optional switched **off**: `mode` is `sync`, signatures are
off, anchors are off, `retention` is empty, `compliance` is `false`, `telemetry` is off. The one
thing you cannot switch off is the hash chain — `sequence`, `previous_hash` and `hash` are assigned
inside the ledger on every write, in every mode, with no configuration key that disables them.

So "choosing your setup" is really seven independent decisions:

| Axis | Config key | Ships as | Reversible later? |
|---|---|---|---|
| Where the entry settles | `mode` | `sync` | Yes — but drain the buffer before leaving `buffered` |
| Where the entry is stored | `ledger.default` | `database` | Yes, for new entries only |
| How the chain is scoped | `integrity.stream` | `tenant` | **No.** Changing it forks the history into two chains |
| Whether entries are attested | `integrity.signature.enabled` | `false` | Yes, but never retroactive |
| Whether ranges are anchored | `integrity.checkpoints.enabled` | `false` | Yes — the first pass is expensive |
| How long entries are kept | `retention` | `[]` (forever) | Yes, and it only ever removes |
| Whether reads are recorded | `compliance` | `false` | Yes, but it changes the table's growth rate |

Two of those are effectively one-way doors. `integrity.stream` goes into the hash prefix, so an
installation that changes it after writing entries ends up with two independent chains and a history
that stops growing under the old name. And `retention` cannot be undone for what it already removed.
Decide both before the first write.

> 📌 **Note.** Sentinel requires PHP `^8.4` and `illuminate/*: ^13.0`, and nothing older. Supported
> engines are PostgreSQL, MySQL and SQLite — floors of 9.4, 8.0.4 and 3.38, with CI exercising
> PostgreSQL 16 and MySQL 9 on every push. MariaDB is not one of them: the SQL for
> `whereFieldChanged()` is compiled per driver in `src/Ledger/ChangedFieldPredicate.php` and an
> unrecognised driver name throws `LedgerException::cannotTranslateOn`.

---

## Profile 1 — you want a change log, not a ledger

**The honest answer is: do not adopt Sentinel.** If the question you need answered is "who edited
this invoice", nobody will ever run `sentinel:verify`, and no auditor will ever ask you to prove the
record was not rewritten, then you are paying for a property you will never exercise:
`sentinel_audits` is a forty-column table with thirteen indexes, beside six side tables, eleven
artisan commands, seven pipeline stages and ten context resolvers. `owen-it/laravel-auditing` is
older, smaller and does that job.

Reconsider only if you are already on PHP 8.4 / Laravel 13 **and** you specifically want the two
things that have no equivalent elsewhere: pivot auditing that needs no call-site changes, and
mass-operation auditing for `Builder::update()` / `delete()`.

If you adopt on those grounds, run it as thin as it goes.

```php
// config/sentinel.php
'mode' => 'sync',
'ledger' => ['default' => 'database'],
'integrity' => [
    'stream' => 'global',
    'signature' => ['enabled' => false],
    'checkpoints' => ['enabled' => false],
],
'retention' => [],
'compliance' => false,
'telemetry' => ['enabled' => false],
```

```php
use ElPandaPe\Sentinel\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

final class Invoice extends Model
{
    use Auditable;

    /** Exclusion is free. Every other protection mechanism costs. */
    protected array $auditExclude = ['remember_token'];
}
```

**Scheduled commands:** none.

**What this profile gets wrong most often.** Setting `retention` because "we should not keep this
forever", without scheduling `sentinel:checkpoint`. The unit of retirement is the anchored window,
so a trail with no anchors releases nothing — `sentinel:prune` reports the reason
(`RetentionHold::Unanchored`) and exits 0, forever, and it is correct to do so.

---

## Profile 2 — multi-tenant SaaS

This is the shape the defaults were built around: `integrity.stream` already ships as `tenant`.

`tenant` behaves exactly like `global` until a tenant actually resolves. The moment one does, entries
move to a `tenant:<id>` stream of their own with `sequence` restarting at 1. Existing chains keep
verifying — nothing is rewritten — but they stop growing. **Wire the tenant resolver before the
first entry is written**, or set `integrity.stream` to `global` explicitly and accept one chain for
everyone.

Use the class form of the resolver, not the closure form. `resolvers.tenant.using` takes a
`Closure`, and a closure cannot survive `php artisan config:cache`.

```php
// app/Sentinel/CurrentTenant.php
use ElPandaPe\Sentinel\Contracts\Resolver;

final readonly class CurrentTenant implements Resolver
{
    /** @return array<string, mixed> */
    public function resolve(): array
    {
        $id = Tenant::current()?->getKey();

        return $id === null ? [] : ['tenant_id' => (string) $id];
    }
}
```

```php
// config/sentinel.php
'mode' => 'sync',
'resolvers' => ['tenant' => ['class' => App\Sentinel\CurrentTenant::class]],
'integrity' => [
    'stream' => 'tenant',
    'signature' => ['enabled' => true, 'signer' => 'hmac'],
    'checkpoints' => ['enabled' => false, 'every' => 1000],
],
'ledger' => [
    'default' => 'database',
    'ledgers' => ['archive' => ['disk' => 's3', 'path' => 'sentinel', 'codec' => 'gzip', 'batch' => 1000]],
],
```

Per-tenant streams buy two things. Concurrent writes to different tenants do not serialise against
each other on the ledger's write gate, and one tenant's chain can be verified — or exported — without
reading anyone else's:

```bash
php artisan sentinel:verify --stream=tenant:acme --depth=roots
php artisan sentinel:export --format=ndjson --tenant=acme --disk=exports --limit=500
```

Every export carries a manifest beside the body: the entry count, a digest of the body, and a
signature over that digest — so the recipient can check the bytes without database access.

If you partition on PostgreSQL, publish the **tenant** stub, not the range stub:

```bash
php artisan vendor:publish --tag=sentinel-partitioned-pgsql-tenant   # new installations only
```

**Scheduled commands:**

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('sentinel:checkpoint')->hourly();          // after one manual first pass
Schedule::command('sentinel:prune')->daily();                // --action=archive is the default
Schedule::command('sentinel:verify --depth=roots')->dailyAt('03:00');
```

**What this profile gets wrong most often.** Turning the tenant resolver on months after go-live and
then reporting the restarted `sequence` as a bug. It is not: the old chain is intact and closed, and
the new one is a different chain because the stream name is inside the hash prefix.

---

## Profile 3 — regulated fintech or healthcare

Turn on **compliance mode** and let it refuse to boot until signatures and anchors are on. That
refusal is the feature: `Compliance\Requirements::enforce()` throws `ComplianceException::incomplete`
naming whichever key is missing, at boot rather than at the first write — because the first write may
be a year away, and by then the entries that were supposed to be signed are not.

Compliance mode also, without asking:

- forces `on_write_failure` to `throw`, whatever the config says (`Support\Config::writeFailurePolicy()`),
  so a failed audit write now fails the user's request;
- requires an actor on every redaction (`Redaction\Redactor`), which is why `sentinel:redact` takes
  `--actor=type:id`;
- refuses `sentinel:prune --action=delete` over a range with no archive batch
  (`ComplianceException::unarchived`);
- writes **two** records for every read through the Query API — a chained, hashed, signed entry with
  `audit_type = 'access'` that consumes a sequence in `sentinel_audits`, plus a row in
  `sentinel_access_log`. The editable copy is deliberately never the only one.

```php
// config/sentinel.php
'compliance' => true,        // refuses to boot unless the next two are true
'on_write_failure' => 'throw',
'integrity' => [
    'stream' => 'tenant',
    'checkpoints' => ['enabled' => true, 'every' => 1000],
    'signature' => [
        'enabled' => true,
        'signer' => 'openssl',
        'algorithm' => 'sha256',
        'key_id' => 'v1',
        'keys' => ['v1' => env('SENTINEL_SIGNING_PUBLIC_KEY')],   // verifies
        'private_key' => env('SENTINEL_SIGNING_PRIVATE_KEY'),      // signs — keep off this box
    ],
],
'retention' => [
    'model:App\Models\Patient' => '7 years',
    'auth' => '90 days',
    'access' => '1 year',
],
```

`openssl` is the only tier that survives the machine's own administrator, and only when
`private_key` lives somewhere the entries do not. It is not free: measured with `make bench` on
SQLite, median of three passes, an unsigned write cost 2 400 µs and an RSA-2048 signed write 3 252 µs
(+35.5%), against `HmacSigner` at 2 437 µs (+1.5%, inside the noise floor). If that is too much on
your write path, sign with HMAC and put the trust boundary in an exported, signed anchor instead.

Three things compliance mode deliberately does **not** record, and you should know before you claim
otherwise in an audit: `$model->audits()` and the `field()` relation scope, `sentinel:show` and
`sentinel:redact` (they find one entry by identifier, not through a query), and the four
verification walks (they read in order to hash and discard). Reach the trail through
`Sentinel::audits()` and the read is covered.

**Scheduled commands:** `sentinel:checkpoint` hourly, `sentinel:prune` daily, and `sentinel:verify`
behind a watchdog that distinguishes all three exit codes — 0 sound, 1 a bad finding from a run that
happened, 2 a run that could not happen.

**What this profile gets wrong most often.** Budgeting for the read latency (+2.5 ms per read,
measured over 100 reads of 50 entries) and forgetting the structural cost: the audit table's growth
rate is now driven by queries, not by business writes. And `sentinel_access_log` has no published
partitioned migration — `sentinel:partitions --table=access_log` will maintain one, but you declare
the partitioned table by hand.

> 🔒 **Security.** Sentinel certifies nothing. It ships primitives — a chain, signatures, anchors,
> tombstones, an access record. Whether a given regime is satisfied by them is a question for
> somebody who knows that regime. And no signer here claims proof against a compromised application:
> someone with application access at capture time produces a perfectly intact, perfectly signed chain
> of false statements.

---

## Profile 4 — high-write ingestion

Use **`buffered`**, and write down what it can lose before you ship it.

```php
// config/sentinel.php
'mode' => 'buffered',
'buffer' => [
    'store' => 'redis',
    'connection' => 'sentinel',   // a Redis whose persistence you chose deliberately
    'key' => 'sentinel:buffer',
    'size' => 500,
    'flush_interval' => 60,
],
'integrity' => [
    'signature' => ['enabled' => true, 'signer' => 'hmac'],
    'checkpoints' => ['enabled' => false, 'every' => 1000],
],
'mass_operations' => ['mode' => 'summary', 'threshold' => 100, 'sample' => 20],
```

What `buffered` loses is what a process dies holding. Those entries never reached the ledger: they
have no sequence, no hash and no place in any chain — which means `verifyIntegrity()` walks a
shorter chain and reports it **intact**, correctly. The chain proves that what settled was not
tampered with, never that everything that happened settled. Loss detection is out of band.

```php
use ElPandaPe\Sentinel\Events\BufferFlushFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

Event::listen(function (BufferFlushFailed $event): void {
    Log::channel('audit-alerts')->critical($event->message(), [
        'taken' => $event->taken,
        'settled' => $event->settled,
        'returned' => $event->returned,   // back in the buffer — a retry, not a loss
        'skipped' => $event->skipped(),   // deduplicated, or gone
    ]);
});
```

`BufferFlushFailed` — not `AuditWriteFailed` — is the event that names the batch. A
threshold-triggered flush that fails raises `AuditWriteFailed` against the entry that just arrived,
which is by design the one entry that is safe.

Both flush thresholds are evaluated **only when an entry arrives**; nothing in PHP watches a clock
between requests. A buffer that stops receiving entries stops being evaluated, so schedule the
command:

```php
Schedule::command('sentinel:flush')->everyMinute()->withoutOverlapping();
Schedule::command('sentinel:checkpoint')->hourly();
```

Other decisions for this profile: sign with HMAC, never RSA. Anchor on the schedule, never on the
write-path threshold. Turn snapshots off on your widest tables — `protected bool $auditSnapshots =
false` keeps the entry, the chain and the diff and drops the two states. Keep mass operations on
`summary`, whose cost does not grow with the set. Do not partition until a `DELETE` proves it
necessary: measured with `make bench-volume` at 10 million entries, PostgreSQL 16 cost 2.20 ms per
entry flat and 8.34 ms partitioned; volume itself is nearly free, partitioning is what costs.

**What this profile gets wrong most often.** Leaving `mode` on `buffered` in code while flipping it
to `sync` in an env file mid-deploy. Both shutdown hooks return early and `sentinel:flush` exits 2
under any other mode, so whatever is still in the buffer is stranded with nothing reporting it. Run
`sentinel:flush` until it prints `Settled 0 entries`, then switch.

> ⚠️ **Warning.** `buffer.store = memory` is a reference implementation and a test double, never a
> store. The binding is container-`scoped`, so its contents die with the request or the job. An
> unknown store raises `ConfigurationException` rather than falling back to it.

---

## Profile 5 — migrating an existing trail

Read the guide for **your** package first — `owen-it/laravel-auditing` and `altek/accountant` are
genuinely different migrations — then dry-run before anything, because an import cannot be undone
(and under compliance mode, undoing it means archiving first).

```bash
php artisan sentinel:import --from=owenit --dry-run
php artisan sentinel:import --from=owenit --size=1000
php artisan sentinel:import --from=owenit --size=1000 --after=<last key printed>
php artisan sentinel:verify
php artisan sentinel:show --subject="App\Models\Invoice:77"
```

Three expectations to set with stakeholders before you start:

| Expectation | Why |
|---|---|
| **The chain starts at the import.** | Nobody hashed the source rows as they were written. Sentinel will not fabricate a link backwards — that would be a proof that nobody touched data this package never saw. |
| **The source already lost things.** | owen-it wrote only `getDirty()` for updates and drops array-valued attributes by default; altek wrote no `before` at all. Because no source is trusted to have recorded the whole record, a whole-record `restore()` on any entry with `source = 'import'` is refused by name (`Omission::EntryImported`) — name the fields and they are put back. |
| **Two things will look wrong and are not.** | Imported entries sit at the *end* of the chain — order by `occurred_at` to read a history. And if you partitioned by `created_at`, they all land in the current partition regardless of their year. |

Import runs are resumable and idempotent: every entry carries an identity derived from its source
row, so a second run finds its work done. Exit codes are the usual three — 0 all rows across, 1
something did not, 2 wrong package, table or connection.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

// The order things happened, which is what a history wants after an import.
Sentinel::timeline()->for($invoice)->get();
```

**Scheduled commands:** none during the migration. Add Profile 2's or Profile 3's schedule once the
import is complete and `sentinel:verify` exits 0.

**What this profile gets wrong most often.** Running the Rector stub in anything but dry-run. It
renames imports and nothing else; every behavioural difference between the packages is yours to make
by hand.

---

## Profile 6 — internal back-office, nobody on call

Real accountability need, no scheduler discipline, no one paged at 3 a.m. Adopt the chain — it is
unconditional and needs no configuration — and turn **everything else off**, because every optional
mechanism here has a scheduled job attached to it and the failure mode of an unowned job is silent.

```php
// config/sentinel.php
'mode' => 'sync',
'ledger' => ['default' => 'database'],
'integrity' => [
    'stream' => 'global',
    'signature' => ['enabled' => false],
    'checkpoints' => ['enabled' => false],
],
'retention' => [],
'compliance' => false,
```

Specifically, do not set `retention` unless you also schedule `sentinel:checkpoint`: the prune unit
is the anchored window, so an unanchored trail is an unprunable one and a `'90 days'` policy will do
nothing forever while being perfectly correct in doing so. And do not enable compliance mode: it
forces `on_write_failure` to `throw`, so an audit write failure becomes a failed user request, and it
starts growing the table with reads.

Accept instead that the table grows without a ceiling, and revisit when it hurts. Volume alone is
cheap on the write path — `make bench-volume` measured 2.20 ms per entry at 10 million rows against
2.47 ms at 1 million on flat PostgreSQL 16.

**Scheduled commands:** none. Revisit `retention` only together with `sentinel:checkpoint`.

**What this profile gets wrong most often.** Enabling `integrity.checkpoints.enabled` because
anchors sound like a good idea, without a schedule. That puts the fold on whichever write happens to
complete a window, which is the one route the package steers away from.

---

## Profile 7 — external verification node

Given the data, trusted with none of the keys. This is a procedure rather than a configuration, and
it is the case the whole hash design exists for.

Hand over the rows — or a `sentinel:export --format=ndjson` batch with its manifest — plus
`integrity.signature.keys` and nothing else. That is sufficient, because the hash covers the
**ciphertext**: `Integrity\CanonicalPayload::from()` decrypts nothing, and `encryption`
(`{fields, key_id}`) is itself one of the twenty-seven canonical columns. Under `openssl`, `keys`
holds only public halves, so the holder verifies everything and signs nothing.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

config(['sentinel.security.encryption.keys' => []]);   // verification still passes

Sentinel::verifyIntegrity('tenant:acme')->isIntact();
```

```bash
php artisan sentinel:verify --stream=tenant:acme --depth=entries
# exit 0 intact (declared redactions alone still exit 0) · 1 broken · 2 could not run
```

Read the vocabulary properly, because three of the four depths and states are not failures:

| Reading | What it means |
|---|---|
| `--depth=anchors` / `--depth=roots` report **anchored** | Nothing in that range was read. `anchored` is never `intact`. |
| `checked` vs `covered` vs `archived` | Entries read · entries taken on an anchor's word · entries stepped over. Three facts, never summed. |
| `SignatureState::Unsigned` | Written before signing was switched on. Sound, not a defect. |
| `SignatureState::UnknownKey` | The key is not on the ring. A verdict the verifier is not entitled to give. |
| `SignatureState::Invalid` | The only signature state that is a defect. |
| `ContentState::Redacted` | A declared tombstone. `$audit->verifyIntegrity()` returns `false` for one, on purpose. |

**Scheduled commands:** `sentinel:verify --depth=entries` on whatever cadence the engagement asks
for, behind a watchdog that tells 1 from 2.

**What this profile gets wrong most often.** Reporting a `--depth=roots` pass as a verified trail.
The fold is over the stored `hash` column, so someone who edits a canonical column and leaves `hash`
alone passes both shallow walks and is caught only by `--depth=entries`.

---

## The decision table

| Profile | `mode` | `ledger.default` | `integrity.stream` | Signatures | Anchors | `retention` | `compliance` |
|---|---|---|---|---|---|---|---|
| Change log | `sync` | `database` | `global` | off | off | `[]` | `false` |
| Multi-tenant SaaS | `sync` | `database` | `tenant` | `hmac` | scheduled | per type | `false` |
| Regulated | `sync` | `database` | `tenant` | `openssl` | **required** | per type | **`true`** |
| High-write | `buffered` | `database` | `tenant` or `global` | `hmac` | scheduled | per type | `false` |
| Migrating | `sync` | `database` | decide before importing | off during import | off during import | `[]` during import | `false` |
| Back-office | `sync` | `database` | `global` | off | off | `[]` | `false` |
| Verification node | n/a (read-only) | `database` | as received | verify-only keys | as received | `[]` | `false` |

The `archive` driver is a destination, not a hot ledger: naming it as `ledger.default` throws
`ConfigurationException::coldLedgerAsDefault()`. Reach it through `fanout`, or through
`sentinel:prune --action=archive`.

> 📌 **Note — `queue` is not the recommended mode for any profile above, and that is deliberate.**
> It is a real mode with a real use, and it is simply not the answer to the question most of these
> profiles are asking. `queue` buys back **request** latency and costs a little more end to end —
> one job per entry, dequeued by somebody. So it earns its place only where two things hold at once:
> page latency is a measured problem you have traced to the audit write, *and* the loss window of
> `buffered` is unacceptable to you. If page latency is fine, `sync` is simpler and is the only mode
> that can still tell the caller a write failed. If throughput rather than latency is the
> constraint, `buffered` is the mode that amortises, and `queue` will not help. Take a profile above
> to `queue` deliberately, after measuring — never as a default.
>
> Two things change the day you do, in any profile: `created_at` and `sequence` stop being capture
> order, and a backed-up worker pool becomes part of your trail's correctness. See
> [Performance modes](../09-operations/01-performance-modes.md) and
> [Running audits on a queue](../09-operations/03-queues.md).

## What each profile schedules

| Command | Who needs it | Cadence | If nobody runs it |
|---|---|---|---|
| `sentinel:checkpoint` | anyone with `retention` set, and every compliance installation | hourly, **after one manual first pass** | nothing is anchored, so nothing is ever prunable |
| `sentinel:prune` | anyone with `retention` set | daily | the table grows without a ceiling |
| `sentinel:flush` | `mode = buffered` only | every minute | a quiet buffer is never evaluated; a dying process loses what it holds |
| `sentinel:verify` | anyone who will ever be asked to prove the trail | daily `--depth=roots`, weekly `--depth=entries` | tampering is discovered by whoever asks, not by you |
| `sentinel:partitions --ahead=N` | partitioned tables only | monthly, with a small `--ahead` | writes start failing when the last partition ends |

> ⚠️ **Warning.** Run the first `sentinel:checkpoint` by hand, off the schedule. It is the one
> trail-walking command with no `--limit`: it anchors every complete window each stream still owes,
> so at the default `every = 1000` a ten-million-entry stream emits ten thousand anchors and reads
> the whole trail on that first pass.

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `sequence` restarted at 1 and old entries stopped appearing in the new chain | A tenant resolver started resolving, so entries moved to a `tenant:<id>` stream. The stream name is inside the hash prefix. | Expected. Verify the old stream by name; set `integrity.stream => 'global'` explicitly if you do not want the split. |
| Application throws at boot naming `integrity.signature.enabled` | `compliance => true` with signatures or anchors off. `Compliance\Requirements::enforce()` refuses to boot. | Turn both on, or turn compliance off. It fails at boot on purpose — the first write may be a year away. |
| `'auth' => '90 days'` frees nothing, run after run | The unit of retirement is the anchored window, and no anchors exist — `sentinel:prune` reports `RetentionHold::Unanchored` and exits 0. | Schedule `sentinel:checkpoint`. Retention without anchoring is inert. |
| Entries vanished after switching `SENTINEL_MODE` away from `buffered` | The shutdown hooks return early and `sentinel:flush` exits 2 under any other mode, so what was waiting is stranded. | Flush to `Settled 0 entries` first, then switch. |
| `verifyIntegrity()` says intact but entries are missing | An entry that never reached the ledger consumed no sequence, so there is no gap to find. | Detect loss out of band: `BufferFlushFailed`, the `sentinel:flush` count and exit code. |
| The trail grows faster than business activity | Compliance mode writes a chained `audit_type = 'access'` entry per Query API read, consuming a sequence in the same table. | Expected. Budget for it, and give `sentinel_access_log` its own retention policy. |
| `php artisan config:cache` fails with `LogicException: Your configuration files could not be serialized because the value at "sentinel.resolvers.tenant.using" is non-serializable.` | A `Closure` in `resolvers.tenant.using` or `integrity.stream`. Laravel's config cache is `var_export`, and a closure has no literal form. | Use the class form: `resolvers.tenant.class`, or a `Contracts\StreamResolver` class-string. |
| Writes got much slower after partitioning PostgreSQL | Every write reads the tail of its stream, and `where stream = ?` gives the planner nothing — it is a `Merge Append` over every partition. | Keep `--ahead` to a few months and give `--retire` a period, or stay flat until a `DELETE` proves partitioning necessary. |
| `sentinel:verify` exits 2 with no `--stream` | The configured ledger does not implement `Contracts\EnumeratesStreams` — `NullLedger` does not. | Name a stream, or use a ledger that can enumerate them. |
| Entries signed yesterday now report `UnknownKey` | A retired key was removed from `integrity.signature.keys`. | Put it back. Rotation is moving `key_id`; retirement is leaving the old key on the ring forever. |

---

## ✅ Best practices

✅ **Do** — decide `integrity.stream` before the first write and treat it as frozen. The resolved
name is part of the hash prefix, so it can never be renamed in place.

```php
// config/sentinel.php — chosen once, at install
'integrity' => ['stream' => 'tenant'],
```

❌ **Don't** — switch it on a populated installation and expect the history to follow. Old rows keep
their old stream in their hash prefix; you get two independent chains, and the first one stops
growing.

```php
'integrity' => ['stream' => 'subject_type'],   // month six: the trail forks here
```

---

✅ **Do** — schedule `sentinel:checkpoint` at the same moment you declare a retention policy. They
are one decision, because the prune unit is the anchored window.

```php
Schedule::command('sentinel:checkpoint')->hourly();
Schedule::command('sentinel:prune')->daily();   // --action=archive is the default
```

❌ **Don't** — set `retention` alone and assume something is being removed. Nothing is, the command
exits 0, and the report names the reason nobody reads.

```php
'retention' => ['auth' => '90 days'],   // with no anchors: inert, forever
```

---

✅ **Do** — under `buffered`, move every read that rebuilds a history off `created_at`.
`occurred_at` is stamped at capture and `created_at`/`sequence` in the ledger at settlement; the two
stop agreeing the moment settlement leaves the request.

```php
Sentinel::timeline()->for($invoice)->get();          // occurred_at
Sentinel::audits()->for($invoice)->byOccurrence()->get();
```

❌ **Don't** — keep a lifeline built on the default ordering after switching modes. It keeps working
and quietly starts answering a different question.

```php
Sentinel::audits()->for($invoice)->get();   // created_at: the order entries settled
```

---

✅ **Do** — hand an external auditor `integrity.signature.keys` and nothing else, and keep
`private_key` off the machine the entries live on. Under `openssl` the ring holds only public halves,
so the holder verifies everything and signs nothing.

```php
'signature' => [
    'signer' => 'openssl',
    'keys' => ['v1' => env('SENTINEL_SIGNING_PUBLIC_KEY')],
    'private_key' => env('SENTINEL_SIGNING_PRIVATE_KEY'),   // absent on a verifying node
],
```

❌ **Don't** — reuse the encryption key as the signing secret, or point the two rings at each other.
The encryption ring maps to an `Illuminate\Encryption\Encrypter` and cannot express a public key at
all.

```php
'signature' => ['keys' => ['default' => env('SENTINEL_ENCRYPTION_KEY')]],
```

---

✅ **Do** — pick `hmac` unless your threat model actually includes the machine's own administrator.
Measured with `make bench` on SQLite, median of three passes: `HmacSigner` +1.5% per write,
`OpenSslSigner` RSA-2048 +35.5%.

```php
'signature' => ['enabled' => true, 'signer' => 'hmac'],
```

❌ **Don't** — reach for `openssl` on a hot write path without measuring first. It is roughly 850 µs
of private-key work per entry, on every write, in the request.

---

✅ **Do** — give `buffered` a Redis connection whose persistence and eviction policy you chose, and
keep `buffer.key` exclusive to Sentinel.

```php
'buffer' => ['store' => 'redis', 'connection' => 'sentinel', 'key' => 'sentinel:buffer'],
```

❌ **Don't** — run `buffer.store = memory` outside tests. The binding is container-`scoped`, so
whatever it holds dies with the request or the job, with no event and no trace.

```php
'buffer' => ['store' => 'memory'],   // a test double, never a store
```

---

**See also:** [Installation](01-installation.md) · [What a model declares](03-what-a-model-declares.md) · [Turning auditing off](04-turning-auditing-off.md) · [Performance modes](../09-operations/01-performance-modes.md) · [The buffered mode](../09-operations/02-the-buffered-mode.md) · [Scheduling](../09-operations/07-scheduling.md) · [Artisan commands](../09-operations/06-artisan-commands.md) · [Streams](../07-integrity/02-streams.md) · [Signing the chain](../07-integrity/04-signing.md) · [Checkpoints and anchors](../07-integrity/05-checkpoints-and-anchors.md) · [The verification playbook](../07-integrity/07-the-verification-playbook.md) · [Retention and pruning](../08-lifecycle/01-retention-and-pruning.md) · [Compliance mode](../08-lifecycle/05-compliance-mode.md) · [Multi-tenancy](../04-context/04-multi-tenancy.md) · [Partitioning](../10-database-engines/06-partitioning.md) · [The import runbook](../12-migrating/03-the-import-runbook.md) · [Configuration](../99-reference/02-configuration.md) · [Production readiness](../13-best-practices/04-production-readiness.md)
