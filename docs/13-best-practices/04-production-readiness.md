# ✅ Production readiness

> The go-live checklist for a Sentinel installation, ordered the way a team works it: the decisions
> that cannot be taken back once the first entry is written, then configuration, schedule,
> monitoring, key custody, the drills you actually rehearse, and a sign-off sheet to copy.

**On this page:** [Before the first entry](#before-the-first-entry) · [Configuration review](#configuration-review) · [The schedule](#the-scheduled-commands) · [Monitoring](#monitoring-and-alerting) · [Key custody](#key-custody-and-backup) · [The drills](#the-drills) · [Capacity](#capacity-plan) · [Pitfalls](#️-pitfalls) · [Best practices](#-best-practices) · [Sign-off sheet](#sign-off-sheet)

---

## Before the first entry

Items marked 🔒 **IRREVERSIBLE** cannot be applied to entries already written. History is
append-only: nothing in the package rewrites, renumbers or re-signs an existing entry, which is
exactly why a decision taken after the first write stays taken. Everything outside this section can
be changed on a Tuesday afternoon.

> ⚠️ **Warning.** Irreversible does not mean the application crashes. These fail quietly: the trail
> keeps being written, and the thing you wanted to prove three years from now is the thing you can
> no longer prove.

### 1. 🔒 Stream scope — `integrity.stream`

The chain is per stream: `(stream, sequence)` is dense and monotonic *within* a stream, and a
stream's entries link to each other and to nothing else. Changing the strategy migrates nothing — it
starts writing into differently-named streams and the old ones stop. The shipped default is
`tenant`, which `Integrity\Stream` resolves as `global` when `tenant_id` is null and `tenant:<id>`
otherwise, so the day a tenancy resolver first answers, entries move and `sequence` restarts at 1.

| Strategy | Stream name | Consequence for retention and verification |
|---|---|---|
| `global` | `global` | One chain. One `sentinel:verify` walk. Anchors accumulate in one place. |
| `tenant` (default) | `tenant:<id>`, or `global` when no tenant | One chain per tenant. Anchoring, verification and pruning are per tenant. |
| `subject_type` | `type:<morph alias>` | One chain per subject kind. The morph map decides the name, so a morph map change renames future streams. |
| A closure or a `Contracts\StreamResolver` | Whatever it returns | Yours. Guarded: empty is refused, longer than 64 characters is refused. |

The sizing consequence is the one teams miss. Anchors cover **complete** windows of
`integrity.checkpoints.every` entries (`Integrity\Checkpoints`), and `Retention\Frontiers` only
offers a window whose `sequence_to` is below the stream's `max(sequence)`. So:

- a stream with fewer than `every` entries **never gets an anchor** and never releases a row —
  `sentinel:prune` reports the `unanchored` hold and exits 0;
- every stream permanently retains the window holding its highest sequence.

At the default `every = 1000` and a per-tenant stream, that is a floor of up to a thousand entries
per tenant that retention will never touch. Pick the scope with that in mind.

> 📌 **Note.** Enabling a tenancy resolver later is the same decision arriving late. See
> [Multi-tenancy](../04-context/04-multi-tenancy.md) and [Streams](../07-integrity/02-streams.md).

### 2. 🔒 Tenant resolver — `resolvers.tenant`

Under the default stream strategy the tenant resolver *is* the stream strategy. Beyond that,
`tenant_id` is a promoted column inside the canonical payload and therefore covered by the hash: an
entry written before the resolver existed carries `null` there and will carry `null` forever. Decide
whether a tenant exists, how it is resolved in a queue worker and in a console command (nothing
resolves it for you there), and whether `null` is a legal answer.

### 3. 🔒 Connection — `database.connection`

The ledger reads the tail of the stream on the connection this key names, and derives the next
`sequence` and `previous_hash` from it. Point it somewhere else after entries exist and the new
table is empty: the same stream name starts a second chain at sequence 1 with a null link, and the
first chain is orphaned where `sentinel:verify` will never look.

`sentinel:install` checks the seven tables on **this** connection, not the application default:

```
sentinel_audits · sentinel_audit_tags · sentinel_audit_relations · sentinel_transactions
sentinel_checkpoints · sentinel_archives · sentinel_access_log
```

Decide now whether the trail lives beside the application's data or in a database of its own — see
[A database of its own](../10-database-engines/07-a-database-of-its-own.md). The same applies to
`tables.prefix` and every name under `tables`.

### 4. 🔒 Engine and table shape

Sentinel is engine-agnostic through the Ledger contract, but two shapes are chosen by publishing a
migration **before the table is created**:

| Tag | What it changes | Engine |
|---|---|---|
| `sentinel-partitioned-pgsql-range` | `sentinel_audits` divided by date range, with a DEFAULT partition | PostgreSQL |
| `sentinel-partitioned-pgsql-tenant` | `sentinel_audits` divided by tenant | PostgreSQL |
| `sentinel-partitioned-mysql-range` | `sentinel_audits` divided by range, with a MAXVALUE partition | MySQL |
| `sentinel-json-indexes` | The index behind `whereIp()` and `whereRoute()` | All |

The published file lands under the same name as the package's own and `Support\PackageMigrations`
stops loading that one. The provider says it plainly: these are **for a new installation**, and
converting a table that already holds entries is a maintenance window the package does not attempt.

```bash
php artisan vendor:publish --tag=sentinel-partitioned-pgsql-range
php artisan migrate
```

> 🐘 **Engine.** SQLite does not partition: `sentinel:partitions` exits 0 saying so. See
> [Choosing an engine](../10-database-engines/01-choosing-an-engine.md) and
> [Partitioning](../10-database-engines/06-partitioning.md).

### 5. 🔒 Keys, and the APP_KEY trap

Three secrets fall back to `APP_KEY` when you leave them null, and the fallback is silent:

| Config key | Fallback when null | What rotating `APP_KEY` then costs you |
|---|---|---|
| `security.hashing.salt` | `hash_hmac('sha256', 'sentinel:hashing', APP_KEY)` | Every hashed field digest written before stops being comparable with the ones after. |
| `security.encryption.keys.default` | `APP_KEY` itself | Every field encrypted under `default` becomes unreadable. The entries still verify — the hash covers the ciphertext — but the values are gone. |
| `integrity.signature.keys.default` | `hash_hmac('sha256', 'sentinel:signature', APP_KEY)` | Every signature written before verifies as `Invalid`, and `sentinel:verify` exits 1. |

Name them explicitly before the first entry, and never remove an old identifier from a ring —
retiring a key is leaving it there and moving on.

```php
// config/sentinel.php
'security' => [
    'encryption' => [
        'key_id' => env('SENTINEL_ENCRYPTION_KEY_ID', '2027-q1'),
        'keys' => [
            '2026-q4' => env('SENTINEL_ENCRYPTION_KEY_2026_Q4'), // retired, still on the ring
            '2027-q1' => env('SENTINEL_ENCRYPTION_KEY_2027_Q1'), // current
        ],
    ],
    'hashing' => ['salt' => env('SENTINEL_HASH_SALT')], // set it; do not inherit APP_KEY
],
```

### 6. 🔒 The signer driver — `integrity.signature.signer`

An entry records **which key** signed it (`signature_key_id`), never **which driver**.
`Integrity\Signers::build()` matches on the configured driver alone, for every identifier. Switching
`hmac` → `openssl` in place therefore asks the OpenSSL signer to read yesterday's shared secret as a
public key; `openssl_pkey_get_public()` refuses and `OpenSslSigner::verify()` raises
`SignatureException::unusableKey`, which `sentinel:verify` reports as a run that could not happen
(exit 2). Choose the driver once.

- `hmac` — one secret signs and verifies. Cheap, and whoever can verify can forge. It stands
  between an entry and someone who reached the database without reaching the application.
- `openssl` — the private half signs, the public half verifies. This is the only shape that lets an
  external auditor check the trail without being able to write one. Roughly 850 µs of private-key
  work per entry on the write path (RSA-2048), against an HMAC cost the package's own benchmark
  cannot distinguish from zero.

Turning signing **on** later is safe: entries written before carry no signature, report as
`Unsigned`, and the walk still exits 0. There is simply no way to sign them afterwards.

### 7. Anchors and compliance mode

These two are *not* irreversible, and it is worth knowing which way round:

- `integrity.checkpoints.enabled` governs anchoring on the write path only. `sentinel:checkpoint`
  ignores it entirely and anchors every complete window a stream owes, retroactively. So anchoring
  can be adopted late — but the first run over a pre-existing trail reads the whole trail and has no
  `--limit`. Run it by hand, off the schedule.
- `compliance = true` refuses to boot unless `integrity.signature.enabled` **and**
  `integrity.checkpoints.enabled` are both true (`Compliance\Requirements`). It fails at boot, not
  at the first write, because by the first write the entries that should have been signed are not.

---

## Configuration review

Walk this table with the person who will be on call.

| Key | Ships as | Set it to | Why |
|---|---|---|---|
| `enabled` | `true` | `true` | The kill switch. `false` records nothing at all. |
| `mode` | `sync` | `sync` until latency is a *measured* problem | Only `sync` can tell the caller the write did not work. |
| `on_write_failure` | `throw` | `throw`, unless availability outranks completeness | Under `log` you must consume `AuditWriteFailed` or a missing entry is silent. Compliance mode forces `throw`. |
| `log_channel` | null | A channel somebody is alerted on | It stands in for the exception nobody will catch. |
| `transactions.after_commit` | `true` | `true` | A rolled-back operation must leave no record of what never happened. |
| `ledger.default` | `database` | `database` | `null` cannot enumerate streams, which turns three scheduled commands red (exit 2); `memory` can, and answers for one process. |
| `ledger.ledgers.archive.disk` | `local` | Durable object storage | The default writes cold archives to the local disk. |
| `ledger.ledgers.archive.codec` | `gzip` | `gzip` with `ext-zlib`, else `null` | There is no guard; without the extension the failure is PHP's. |
| `integrity.checkpoints.every` | `1000` | Sized against your smallest stream | It is the floor on what retention can never release per stream. |
| `integrity.signature.enabled` | `false` | `true` before any export is trusted | With it off, an export manifest ships an empty signature and key id `null`. |
| `security.hashing.salt` | null → APP_KEY | An explicit secret | See the APP_KEY trap above. |
| `retention` | `[]` | One logical type at a time | Empty means nothing is ever released. Not a bug — the opt-in default. |
| `prune.batch` / `prune.pause` | `1000` / `0` | Tuned on a busy table | `pause` is **microseconds**, applied after every batch. |
| `compliance` | `false` | Only when the regime requires it | Doubles the cost of a read and needs signatures and anchors on. |
| `telemetry.trust_incoming_header` | `true` | `false` at a public edge | `traceparent` is a value the client chooses. |
| `resolvers.command.redact` | `password, token, secret` | Extend it | Console arguments land in `context`. |

Two things that are opt-in and easy to forget: `Http\Middleware\AssignRequestId` is registered in
**no** middleware group (add it if you want every entry of one request under one `request_id`), and
the JSON index behind `whereIp()` and `whereRoute()` is published rather than loaded — both filters
work without it, they scan.

Then confirm what actually loaded with `php artisan about`. Six rows — Version, Mode, Ledger,
Payload version, Compliance mode, Telemetry — and deliberately no key, key identifier or signer
configuration, so the output is safe to paste into an issue. Full key-by-key reference:
[Configuration](../99-reference/02-configuration.md).

---

## The scheduled commands

The package registers commands and **never** schedules itself. Every cadence below is something you
write in `routes/console.php`.

```php
<?php

use Illuminate\Support\Facades\Schedule;

// Anchor first, always. Retention's unit is the anchored window: an unanchored
// stream releases nothing and sentinel:prune reports that at exit 0.
Schedule::command('sentinel:checkpoint')->hourly();

// Then prune. --action defaults to archive: the range is written out, read back
// and rehashed before a row is removed.
Schedule::command('sentinel:prune')->dailyAt('03:20');

// A cheap chain check nightly, a full rehash of every entry weekly.
Schedule::command('sentinel:verify --depth=anchors')->dailyAt('04:00');
Schedule::command('sentinel:verify')->weeklyOn(7, '04:30');

// Only on a partitioned table, and only once prune has emptied the old months.
Schedule::command('sentinel:partitions --ahead=6 --retire="18 months"')->monthly();
```

Under the buffered mode, add the one command that puts a ceiling on how long an entry waits:

```php
Schedule::command('sentinel:flush')->everyMinute()->withoutOverlapping();
```

The two buffer thresholds are evaluated only when an entry *arrives*. Nothing in PHP watches a clock
between requests, so a buffer that stops receiving entries stops being evaluated.
`withoutOverlapping()` is courtesy, not correctness: taking from the buffer is atomic and every
entry carries a `capture_id` the database will not accept twice.

**Cadence provenance.** Hourly for `sentinel:checkpoint`, monthly for `sentinel:partitions` and
every minute for `sentinel:flush` are the intervals the package documents. The prune and verify
cadences above are recommendations — derive yours from your retention periods and your tolerance for
a full entries walk.

Never schedule `sentinel:export`, `sentinel:redact`, `sentinel:rekey`, `sentinel:import`,
`sentinel:show` or `sentinel:install`. Four are one-shot operator actions, one is destructive, and
export is bounded by `--limit` rather than resumable.

Verify the schedule is real, not intended:

```bash
php artisan schedule:list
php artisan sentinel:checkpoint          # by hand, first, on a pre-existing trail
php artisan sentinel:prune --dry-run     # read the Note column
php artisan sentinel:verify --depth=anchors
```

More: [Artisan commands](../09-operations/06-artisan-commands.md) ·
[Scheduling](../09-operations/07-scheduling.md).

---

## Monitoring and alerting

### The exit-code vocabulary

Three codes, one meaning each across all eleven commands. Branch your cron on the vocabulary, not
per command.

| Code | Means | What a watchdog should do |
|---|---|---|
| `0` | The ordinary outcome, **including having found nothing to do** | Nothing |
| `1` | A bad finding from a run that happened | Wake a human |
| `2` | A run that could not happen | Retry, then page the operator |

Two inversions worth writing on the runbook:

- `sentinel:redact` exit 1 is **not** retryable — it means the refusal is permanent for this version
  (the entry is archived, or it no longer reproduces its own hash). Exit 2 is the retryable one.
- `sentinel:flush` exit 1 **is** retryable, deliberately: the batch was taken, refused and put back.

Only `sentinel:verify`, `sentinel:prune`, `sentinel:partitions`, `sentinel:redact`,
`sentinel:import` and `sentinel:flush` can ever return 1. A watchdog written for one command's
vocabulary does not transfer unexamined to another's. See
[Exit codes](../99-reference/07-exit-codes.md).

### The four events worth an alert

```php
<?php

use ElPandaPe\Sentinel\Events\AuditWriteFailed;
use ElPandaPe\Sentinel\Events\BufferFlushFailed;
use ElPandaPe\Sentinel\Events\IntegrityVerificationFailed;
use ElPandaPe\Sentinel\Events\LedgerDestinationFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

Event::listen(function (AuditWriteFailed $event): void {
    Log::channel('audit-alerts')->critical($event->message(), [
        'audit_type' => $event->auditType,
        'event' => $event->event,
        'subject' => [$event->subjectType, $event->subjectId],
        'transaction_id' => $event->transactionId,
        'exception' => $event->failure,
    ]);
});

Event::listen(function (BufferFlushFailed $event): void {
    Log::channel('audit-alerts')->critical($event->message(), [
        'taken' => $event->taken,
        'settled' => $event->settled,
        'returned' => $event->returned,   // back in the buffer: a retry, not a loss
        'skipped' => $event->skipped(),   // deduplicated OR lost — see below
        'exception' => $event->reason,
    ]);
});

Event::listen(function (IntegrityVerificationFailed $event): void {
    Log::channel('audit-alerts')->emergency($event->message(), [
        'stream' => $event->stream,
        'reason' => $event->reason->value,  // hash_mismatch, link_mismatch, sequence_gap, …
        'sequence' => $event->sequence,
        'audit_id' => $event->auditId,
    ]);
});

Event::listen(function (LedgerDestinationFailed $event): void {
    Log::channel('audit-alerts')->error($event->message(), [
        'destination' => $event->destination,
        'stream' => $event->stream,
        'sequence' => $event->sequence,
    ]);
});
```

`BufferFlushFailed::skipped()` is `taken − settled − returned`. It counts entries dropped as
already-settled duplicates **and** entries lost outright because the buffer refused to take the
batch back. `skipped() > 0` with `returned = 0` on a failing batch is the one path on which the
buffered mode drops a fact that no process died holding — treat it as loss, not as deduplication.

### What monitoring cannot come from the chain

An entry that never reached the ledger consumed no sequence, so it leaves no gap and no broken link.
`sentinel:verify` will correctly report a shorter chain as intact. **The chain proves that what
settled was not tampered with. It never proves that everything that happened settled.** Loss
detection is out of band: `BufferFlushFailed`, the count and exit code of `sentinel:flush`, and your
own handed-over-versus-landed counters. The package emits no metrics of its own.

Under `queue`, a failure inside a real worker announces nothing from Sentinel — the queue is the
failure policy. Watch the queue's own events and `failed_jobs`.

More: [Monitoring and troubleshooting](../09-operations/08-monitoring-and-troubleshooting.md) ·
[Events](../99-reference/05-events.md) · [Failure policy](../09-operations/05-failure-policy.md).

---

## Key custody and backup

The keyring belongs to the application and lives outside the database the entries do
(`Security\Keyring`). That separation is the point, and it makes custody your problem.

| Material | Config key | Who needs it | If it is lost |
|---|---|---|---|
| Encryption keys | `security.encryption.keys` | The application, to read protected fields | The values it wrote are gone. Entries still verify: the hash covers the ciphertext. |
| Hashing salt | `security.hashing.salt` | The application, to compare digests | Digests written before stop being comparable. Nothing else changes. |
| Signing key — HMAC | `integrity.signature.keys` | Signer and verifier (same secret) | Nothing written under it can be verified again. |
| Signing key — private half | `integrity.signature.private_key` | Only the node that signs | New entries cannot be signed. Old ones still verify with the public half. |
| Signing key — public half | `integrity.signature.keys` | Anyone verifying, including auditors | Old signatures report `UnknownKey`, which the verifier deliberately does **not** call forged. |

Rules to write down before go-live:

1. Back up the keyring separately from the database, and restore-test it separately.
2. Never remove a retired identifier from a ring. Rotation is adding a key and moving the current
   pointer; retirement is leaving the old one in place.
3. Under `openssl`, keep the private key off the machines that only read.
4. `php artisan about` is safe to paste anywhere precisely because it carries none of this. Keep it
   that way.
5. Rotating `APP_KEY` is a Sentinel operation whenever any of the three secrets is null.

Rotating an encryption key rewrites nothing: `sentinel:rekey` writes a **new** entry holding the
same values under the new key and pointing back at the original via `source_audit_id`, and the
original keeps its hash, its link and its sequence. See
[Encryption and the keyring](../05-pipeline-and-security/03-encryption-and-the-keyring.md),
[Hashing and the salt](../05-pipeline-and-security/04-hashing-and-the-salt.md) and
[Export and rekey](../08-lifecycle/06-export-and-rekey.md).

---

## The drills

A drill you have not run is a plan, not a capability. Run all six on a staging copy, record the
wall-clock time of each, and attach the output to the sign-off sheet.

### Drill 1 — Verify the whole trail, and time it

```php
<?php

use ElPandaPe\Sentinel\Facades\Sentinel;

$report = Sentinel::verifyEverything();

$report->isIntact();   // bool
$report->checked();    // entries actually read and rehashed
$report->covered();    // entries an anchor answered for — nobody read these
$report->archived();   // entries stepped over, with a manifest and anchors accounting for them
$report->firstBreak()?->message();
```

`verifyEverything()` needs a ledger that can enumerate its streams; one that cannot is refused
rather than reported empty. Record how long the `entries` depth takes at today's volume — that is
what tells you whether a weekly full walk is realistic next year.

### Drill 2 — Restore a backup and verify it there

Restore last night's database backup into staging, then run the deep walk against it:

```bash
php artisan sentinel:verify --depth=entries
```

This is the drill that catches a backup taken mid-transaction, a restore that dropped a table, or a
restore into a database whose `sentinel.database.connection` no longer points where it did. A chain
that verifies in production and does not verify from the backup is a backup you do not have.

### Drill 3 — Rehydrate an archived range

```php
<?php

use ElPandaPe\Sentinel\Archive\Rehydrator;

$done = app(Rehydrator::class)->restore('tenant:acme', 5001, 6000);

$done->restored;    // entries written back
$done->skipped;     // entries already present
$done->operations;  // transaction headers put back
$done->batches;     // files read
```

Idempotent, **not** atomic, and single-writer: the already-there check happens before the write, so
two concurrent passes can collide on a unique index. Rehearse it once so nobody discovers that
during an erasure request. See [Rehydration](../08-lifecycle/03-rehydration.md).

### Drill 4 — Export, and verify the export the way a recipient will

```bash
php artisan sentinel:export \
    --format=ndjson --tenant=acme --limit=5000 \
    --disk=exports --path=exports/acme-2027-01.ndjson
```

Both `--disk` **and** `--path` are required for a file. With either missing the command prints the
whole body to standard output and still exits 0.

The manifest lands beside the body at `<path>.manifest.json` and carries `format`, `entries`,
`digest`, `signature` and `signature_key_id`. Verify it as a recipient would:

```bash
sha256sum exports/acme-2027-01.ndjson   # compare against the hex after "sha256:"
```

Under `signer => hmac`, the signature is `hash_hmac('sha256', $digest, $secret)` over the full
`sha256:<hex>` digest string. With `integrity.signature.enabled` off, the manifest ships an empty
signature and key id `null` — it proves nothing, and this drill is how you find that out.

### Drill 5 — Redact one entry, then verify the chain

```php
<?php

use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Redaction\Redactor;
use ElPandaPe\Sentinel\Support\Reference;

$entry = Audit::query()->findOrFail($auditId);

$tombstone = app(Redactor::class)->redact($entry, 'right-to-erasure request', Reference::to($officer));

$tombstone->sequence;      // where it was, and still is
$tombstone->redactedHash;  // the second hash, over what is left
$tombstone->trail?->id;    // the chained entry recording who ordered it
```

Then confirm the outcome: `sentinel:verify` counts the redaction and exits **0**, the reloaded entry
answers `ContentState::Redacted` from `verifyContent()` and `false` from `verifyIntegrity()`, and
its `hash` is unchanged so the link to the next entry holds. See
[Redaction and tombstones](../08-lifecycle/04-redaction-and-tombstones.md).

### Drill 6 — Rotate an encryption key across more than one page

```bash
php artisan sentinel:rekey --key=2027-q1 --dry-run
php artisan sentinel:rekey --key=2027-q1 --limit=5000
# … The last entry read was <entry id>. Pass --after=<entry id> to carry on behind it.
php artisan sentinel:rekey --key=2027-q1 --limit=5000 --after=<entry id>
```

The walk is oldest-first, so a bare second run re-reads the same page. It is safe — the rotation's
identity is derived from (source entry, target key) and checked before the ledger — but it is not
progress. `--after` is the only way past `--limit`.

---

## Capacity plan

| Driver of growth | What to measure | Where the surprise is |
|---|---|---|
| Entries | Rows/day in `sentinel_audits`, plus `audit_tags`, `audit_relations`, `transactions` | A mass update in `individual` mode writes one entry per row. |
| Anchors | `sentinel_checkpoints` grows one row per `every` entries per stream | Smaller windows mean finer pruning and more rows. |
| Access log | Under compliance mode, one chained `access` entry **and** one `sentinel_access_log` row per recorded read | `sentinel:prune` never touches `access_log`. Only partition retirement removes those rows. |
| Cold archive | Objects on the archive disk, one file per anchored window | The package only ever `get`s and `put`s. It never deletes. Lifecycle is yours. |
| Buffer | Redis `LLEN` on `buffer.key` | Nothing bounds it. While the ledger is unreachable every failed flush puts its batch back and every arrival re-triggers a flush. |
| Queue depth | Jobs on `sentinel.queue.queue` | `queue` does not batch: one job per entry, so a 5 000-row individual mass update enqueues 5 000 jobs. |

Costs worth budgeting, with their provenance:

- **Compliance mode roughly doubles a read.** Each read becomes a read plus two writes. The
  package's published measurement: 0.309 s → 0.557 s over a hundred reads of fifty entries, about
  +2.5 ms per read.
- **The JSON index** costs fifteen per cent per write on PostgreSQL and twenty-one on MySQL for the
  engine's INSERT, per the provider's own note on the publish tag. End to end the delta sits inside
  the benchmark's noise, which is not a contradiction — it is a different measurement.
- **Partition planning** is paid on every write: reading the tail of a stream is a Merge Append
  across every partition. Keep `--ahead` to a few months and give `--retire` a period.
- **Measurement noise.** The package's write-path benchmark moves by up to 24.5 % between passes on
  an identical configuration. Do not act on a delta smaller than that, yours or ours.

And two things pruning does not do: it does not reclaim disk space, and it runs neither
`OPTIMIZE TABLE` nor `VACUUM`. See
[Retention and pruning](../08-lifecycle/01-retention-and-pruning.md#it-does-not-reclaim-disk-space)
for what each engine leaves behind, and
[Scaling playbook](../10-database-engines/08-scaling-playbook.md) for when to partition instead.

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| Every scheduled `sentinel:checkpoint`, `prune` and `verify` goes red with exit 2 | `ledger.default` was set to `null` to "turn auditing off". That driver cannot enumerate streams and is refused rather than reported empty | Use `enabled => false`, or `Sentinel::withoutAuditing()`. Leave the ledger as `database` |
| `sentinel:prune` exits 0 every night and never removes a row | No anchors yet, or the only anchored window holds the stream's `max(sequence)` | Run `sentinel:checkpoint` first and read the Note column of `--dry-run`: `undeclared`, `unanchored`, `tail` or `retained` |
| A tenant's trail is never pruned at all | That stream has fewer than `integrity.checkpoints.every` entries, so it has no complete window and no anchor | Lower `every`, or accept the floor as a per-stream cost of the tenant stream scope |
| `sentinel:partitions` exits 1 on an ordinary monthly run | Any partition kept makes the run exit 1, and a partition behind the `--retire` cutoff that still holds rows is always kept | Schedule `sentinel:prune` to archive those ranges **before** partitions runs |
| `sentinel:export --disk=exports` produced no file and exited 0 | The write branch needs **both** `--disk` and `--path`; otherwise the body goes to standard output | Always pass both, and keep `<path>.manifest.json` beside the body |
| `sentinel:prune --dry-run --action=delete` said "would remove N", the real run exited 2 | The dry run returns before the compliance archived-first guard is reached | Under compliance mode, treat `--action=delete` as unrehearsable and archive instead |
| `sentinel:redact --dry-run` reported success, the real run was refused | The dry run returns before the redaction's own checks — archived, retired, unverifiable, unattributed. The same is true of `sentinel:rekey --dry-run` | Only `prune`, `partitions` and `import` have a dry run that can find something |
| `sentinel:verify --from=yesterday` exited 0 having verified everything | A non-numeric numeric option is treated as **absent**, not as zero — so `--from` became "no bound" | Pass a sequence number, and read the entry count in the summary |
| `sentinel:install` exited 2 right after publishing the config | The schema check runs on `sentinel.database.connection`, which is unreachable or misnamed. The publish is not rolled back | Fix the connection and re-run; publishing is idempotent and never overwrites |
| Entries stopped being written after switching `mode` away from `buffered` | Whatever was still in the buffer is stranded: both shutdown hooks and `sentinel:flush` refuse to touch it under another mode | Flush until it prints `Settled 0 entries`, **then** change the mode |
| `sentinel:verify` exits 2 with an unusable key after a signer change | The driver is global and not recorded per entry, so the old key is being read by the new driver | Decide the driver before the first entry. There is no in-place migration |
| A rehydrated subject has two entries claiming `version` 1 | `version` is inside the canonical payload; renumbering would stop the entry reproducing its own hash | Expect it, and page by `(stream, sequence)` rather than by version |

---

## ✅ Best practices

✅ **Do** — decide the stream scope, the tenant resolver, the connection and the table shape before
the first write, and record the decision. They are the four that cannot be taken back.

```php
// config/sentinel.php — decided at go-live, reviewed by two people
'integrity' => ['stream' => 'tenant'],
'database' => ['connection' => 'audits'],
'resolvers' => ['tenant' => ['class' => App\Sentinel\TenantResolver::class]],
```

❌ **Don't** — ship with the defaults and "add tenancy later". The day the resolver first answers,
entries move to a `tenant:<id>` stream and `sequence` restarts at 1 there. Nothing migrates the
history that is already in `global`.

```php
'resolvers' => ['tenant' => ['class' => null]], // …and a tenant resolver added in month four
```

---

✅ **Do** — name every secret explicitly, and keep retired identifiers on their ring forever.

```php
'integrity' => ['signature' => [
    'enabled' => true,
    'signer' => 'openssl',
    'key_id' => '2027',
    'keys' => ['2026' => env('SENTINEL_PUBLIC_2026'), '2027' => env('SENTINEL_PUBLIC_2027')],
    'private_key' => env('SENTINEL_SIGNING_PRIVATE_KEY'),
]],
```

❌ **Don't** — leave `keys.default` null and rely on the `APP_KEY` fallback. A routine key rotation
then makes every signature written before verify as `Invalid`, and `sentinel:verify` exits 1 over a
trail nobody touched.

```php
'integrity' => ['signature' => ['enabled' => true, 'keys' => ['default' => null]]],
```

---

✅ **Do** — schedule anchoring before pruning, and run the very first `sentinel:checkpoint` by hand.

```php
Schedule::command('sentinel:checkpoint')->hourly();
Schedule::command('sentinel:prune')->dailyAt('03:20');
```

❌ **Don't** — schedule the prune on its own and read `exit 0, nothing removed` as success. Retention
releases only anchored windows, so an unanchored stream reports the `unanchored` hold and removes
nothing, forever, at exit 0.

```php
Schedule::command('sentinel:prune')->daily(); // and no sentinel:checkpoint anywhere
```

---

✅ **Do** — alert on `BufferFlushFailed` and count what you handed over against what landed.

```php
Event::listen(function (BufferFlushFailed $event): void {
    if ($event->skipped() > 0 && $event->returned === 0) {
        // The one path on which the buffered mode drops a fact for good.
    }
});
```

❌ **Don't** — rely on `sentinel:verify` to notice that the buffer lost something. A missing entry
consumed no sequence, so there is no gap and the chain verifies. Correctly.

```php
Sentinel::verifyEverything()->isIntact(); // true, and it tells you nothing about loss
```

---

✅ **Do** — rehearse the restore against a real backup, in staging, and time the deep walk.

```bash
php artisan sentinel:verify --depth=entries
```

❌ **Don't** — verify only with `--depth=anchors` and call the trail proved. That depth reads the
anchors and takes their word for what they cover; the summary line says how many entries were never
read, and that is not the same as having read them.

```bash
php artisan sentinel:verify --depth=anchors   # cheap, and it proves less
```

---

✅ **Do** — branch your watchdog on the three exit codes as a vocabulary, and note the two commands
whose meaning inverts.

```bash
php artisan sentinel:prune; case $? in 0) ;; 1) page_human ;; 2) retry_then_page ;; esac
```

❌ **Don't** — retry `sentinel:redact` on exit 1. That code means the refusal is deliberate and
permanent for this version: the entry is archived, or it no longer reproduces its own hash.

```bash
until php artisan sentinel:redact "$id" --reason=… --actor=…; do :; done  # never terminates
```

---

## Sign-off sheet

Copy this into your own runbook. "Irreversible" means the decision cannot be applied to entries
already written.

| # | Item | Irreversible | Evidence to attach | Owner | Date |
|---|---|---|---|---|---|
| 1 | Stream scope chosen and written down | 🔒 Yes | `integrity.stream` value + why | | |
| 2 | Tenant resolver decided, including its answer in workers and commands | 🔒 Yes | Resolver class or `null`, with rationale | | |
| 3 | Audit connection and table names fixed | 🔒 Yes | `sentinel:install` output showing 7 of 7 tables | | |
| 4 | Engine chosen; partition stub published before `migrate` if used | 🔒 Yes | `vendor:publish` tag and migration list | | |
| 5 | Hashing salt, encryption keys and signing keys named explicitly | 🔒 Yes | Secret names (never values) in the secret store | | |
| 6 | Signer driver chosen (`hmac` or `openssl`) | 🔒 Yes | Config value + who holds the private half | | |
| 7 | Signing enabled before the entries you will need to attest | 🔒 Partly | `php artisan about` | | |
| 8 | Anchoring adopted; first `sentinel:checkpoint` run by hand | No | Command output + wall-clock time | | |
| 9 | Compliance mode decision, with signatures and anchors on if true | No | Boot succeeds; `about` shows ENABLED | | |
| 10 | Retention policies declared, one logical type at a time | No | `sentinel:prune --dry-run` Note column per stream | | |
| 11 | Archive disk pointed at durable storage; codec matches `ext-zlib` | No | Disk name + a listed batch object | | |
| 12 | Schedule registered in the right order | No | `php artisan schedule:list` | | |
| 13 | `sentinel:flush` scheduled if mode is `buffered` | No | `schedule:list` + a `Settled N entries` run | | |
| 14 | Exit-code watchdog wired for every scheduled command | No | Alert rule, with the redact/flush inversions noted | | |
| 15 | Listeners for the four failure events, on an alerted channel | No | `log_channel` value + a test alert delivered | | |
| 16 | Keyring backed up and restore-tested separately from the database | No | Restore test record | | |
| 17 | Drill 1 — full `verifyEverything()` run and timed | No | Report counts + duration | | |
| 18 | Drill 2 — backup restored to staging and verified there | No | `sentinel:verify --depth=entries` output | | |
| 19 | Drill 3 — one archived range rehydrated | No | `Rehydration` counts | | |
| 20 | Drill 4 — export produced and its manifest verified externally | No | Digest comparison | | |
| 21 | Drill 5 — one entry redacted, chain still verifies | No | Tombstone + verify exit 0 | | |
| 22 | Drill 6 — key rotation chained past one page with `--after` | No | Two `sentinel:rekey` runs | | |
| 23 | Capacity plan written: entries/day, access log, archive, buffer, queue | No | The numbers, with dates | | |
| 24 | Runbook links published to whoever is on call | No | The links below | | |

---

**See also:** [Artisan commands](../09-operations/06-artisan-commands.md) · [Scheduling](../09-operations/07-scheduling.md) · [Monitoring and troubleshooting](../09-operations/08-monitoring-and-troubleshooting.md) · [The verification playbook](../07-integrity/07-the-verification-playbook.md) · [Retention and pruning](../08-lifecycle/01-retention-and-pruning.md) · [Compliance mode](../08-lifecycle/05-compliance-mode.md) · [Security checklist](03-security-checklist.md) · [Configuration](../99-reference/02-configuration.md) · [Exit codes](../99-reference/07-exit-codes.md)
