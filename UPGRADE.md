# Upgrade guide

Every version that changes a published contract, a driver's behaviour or the schema is documented
here, with the before and the after. Versions that only add are covered by the
[CHANGELOG](CHANGELOG.md).

Sentinel is in its `0.x` cycle: only the last minor receives fixes, and there are no backports
before `1.0.0`.

---

## v0.19.4 → v0.19.5

One new table. Nothing else changes shape.

### `sentinel_access_log`

A migration ships with this release. Run it. The table is only written to in compliance mode, so an
installation that has not switched compliance on pays nothing for it beyond the migration.

### Compliance mode now refuses to boot

If you have `'compliance' => true` **and** signatures or anchors switched off, the application will
now fail at boot with a message naming which. That is the point: the mode is a claim about what the
trail can prove, and it could not prove it.

Either turn them on:

```php
'integrity' => [
    'signature' => ['enabled' => true, /* ... */],
    'checkpoints' => ['enabled' => true, /* ... */],
],
```

or turn compliance off. There is no third state, deliberately.

### Compliance mode changes two operations you may already be calling

- `Redactor::redact()` throws without an actor. Pass one.
- `sentinel:prune --action=delete` refuses a range that has no archive batch. Archive first, or use
  `--action=archive`, which has been the default since `v0.19.0`.

### Every read now writes, in compliance mode

Each `AuditQuery::get()` produces one chained entry and one row. On a read-heavy installation that is
a write per query — measure before switching it on, and note that the access entries consume sequences
in their stream like any other entry.

---

## v0.19.3 → v0.19.4

No migration, no new column, no batch format change.

### One manifest row per range, not two

`sentinel_archives` now keeps a single row per `(stream, sequence_from, sequence_to)` and updates it
when the range is written out again. Before, a second write added a second row.

`v0.19.0` chose to record twice on purpose: a run interrupted after recording and before removing
looks exactly like a range that was brought back, and it preferred recording too much over recording
too little. Nothing is recorded less now — the row is updated where it stands — and the reason for the
change is that two rows for one range are two answers to `Manifest::batchesIn()` with no tiebreak, so
a rehydration would read that range from both files.

**If you query `sentinel_archives` yourself** and counted rows to detect re-archived ranges, that
signal is gone. The row's `checksum` and `path` tell you what the current batch is.

### Redaction now reaches archived ranges, through a round trip

Redacting an entry whose range left the hot table is still refused directly, and the refusal still
names the batch. What changed is that the round trip works:

```php
app(Rehydrator::class)->restore($stream, $from, $to);
app(Redactor::class)->redact($entry, $reason, $actor);
// the next prune writes the range out again, tombstone included
```

`v0.19.3` refused to archive a range holding a tombstone. That refusal, and its message, are gone.

### A rehydrated range can be redacted

If you brought a range back with `v0.19.3` and tried to redact one of its entries, it was refused
because a manifest row still claimed the range. It is not refused any more.

---

## v0.19.2 → v0.19.3

No migration, no new column, no hash recomputed. The three redaction columns were created back in
`v0.2.0` and only receive use here.

### `Audit::verifyIntegrity()` answers `false` for a redacted entry

This is the one behaviour change, and it can only reach you once you redact something.

The method keeps its signature and its meaning — *whether this row still reproduces its own hash, and
only that* — and a tombstone does not reproduce the hash it carries. It answers `false`.

Widening it to `true` would rest the answer on `redacted_hash`, a column no signature covers, and
would report a row somebody emptied by hand as healthy. Erring towards the alarm is the choice.

What an entry's content actually is, is a separate question with three answers:

```php
$entry->verifyContent(); // ContentState::Sealed | Redacted | Altered
```

The same pattern `v0.18.0` used when signatures arrived: `verifySignature()` was added beside the
bool rather than folded into it.

### `toArray()` gains one key, inside `integrity`

`integrity.redacted` is `null` for an entry nobody redacted, and otherwise carries `at`, `reason` and
`hash`. Nothing existing moved or changed shape.

If you froze the serialized shape in your own test suite, it needs the new key. And if you asserted
the **absence** of redaction keys — as this package's own frozen-shape test did — that assertion is
what this version invalidates.

### `VerificationResult` and `isIntact()` are untouched

A declared redaction is not a break. It does not stop the walk, does not fill `reason`, and does not
invert `isIntact()`. `sentinel:verify` exits **0** for a stream whose only finding is redactions, and
says how many it found. A watchdog must not page for an act somebody performed on purpose.

A real tampering still wins over a tombstone standing beside it: it is `HashMismatch`, it stops the
walk, and it is what the result reports.

### What this version refuses, out loud

- **Archiving a range that holds a tombstone.** `sentinel:prune --action=archive` refuses that range
  with a readable message rather than writing a batch that cannot be proved. Lifted in `v0.19.4`.
- **Redacting an entry whose range already left the hot table.** The refusal names the batch that
  holds it, so you know which object to deal with. Also `v0.19.4`.

### What redaction does not reach

Replicas, backups and cold batches. The package offers no way to prove a redaction completed
everywhere, and does not pretend otherwise.

---

## v0.19.1 → v0.19.2

No migration, no new column, no hash recomputed.

### `LedgerContractTestCase` gains an assertion, and your driver may fail it

This is the one thing that can break you, and only if you ship a driver of your own that runs the
published suite.

`Contracts\Ledger::append()` now says in writing that it moves whatever the driver uses to number a
subject's next entry, and the suite checks it. A driver that derives the number from what it holds —
as `DatabaseLedger` does, reading the highest version back out of the table — passes without doing
anything. A driver that keeps a counter has to be told:

```php
public function append(Audit $audit): Audit
{
    // …store it as before…
    $this->versions->seen($audit);   // whatever your counter is

    return $audit;
}
```

Failing loudly is the point. Until now such a driver handed the next write of that subject a number
the appended entry already held, permanently and with nothing to notice it by.

### `version` stops being unique per subject

A restored entry brings its original number back, so a subject whose whole history was purged and
then written to again can end up with two entries claiming version 1. Renumbering is not an option:
`version` is inside the canonical payload, so a renumbered entry stops reproducing its own hash,
breaks its successor's link and undoes its anchor's fold.

Two consequences to read before you rely on either:

- `whereVersion()` is a filter that may legitimately return several entries.
- `Query\Comparison` can pair two eras of one subject. Where it used to fail loudly on a missing
  version, it can now succeed on the wrong pairing. That is the only real cost of returning entries
  verbatim, and it cannot be avoided without renumbering.

### Rehydration is single-writer

The check for what is already restored happens before the write and not inside it, so two passes at
once can still collide on a unique index. Run one.

---

## v0.19.0.1 → v0.19.1

No migration, no new column, no hash recomputed. `sentinel_archives` was born complete in `v0.19.0`
and this version fills the four columns it left null.

### `--action` now has a default, and it is `archive`

```bash
php artisan sentinel:prune --action=delete   # exactly what it did before
php artisan sentinel:prune                   # now archives, where before it refused to run
```

A command that names `--action=delete` keeps doing what it did. One that named nothing used to exit
`2`; it now archives. That is the change the defaultlessness of `v0.19.0` existed to make safe: the
default could only ever be introduced once the action that loses nothing existed.

**Before scheduling it**, point `ledger.ledgers.archive.disk` at a disk you mean, and run a dry run:

```php
'archive' => ['disk' => 's3', 'path' => 'sentinel', 'codec' => 'gzip', 'batch' => 1000],
```

```bash
php artisan sentinel:prune --dry-run
```

`compress => true` became `codec => 'gzip'`. This one matters even if you never touched it: the
config merge is one level deep, so a `sentinel.php` published before this version still has
`compress` and **no** `codec` — which would resolve to null and write every batch in the clear
without saying so. Sentinel refuses to start archiving while the old key is there, and the error
says what to replace it with. Delete `compress` and set `codec` to `'gzip'` or to `null`.

### `gzip` needs `ext-zlib`

It is a `suggest` and not a `require`, because archiving is a driver most installations never
resolve. If you archive with `codec => 'gzip'`, make sure the extension is there; `codec => null`
writes plain NDJSON and needs nothing.

### The archive driver cannot be `ledger.default`

It is a destination. Naming it as the default now fails at boot with an error saying why: its stream
tail lives on the instance, so a second process would start a second chain under the same name. Use
it as a fanout destination, or let `sentinel:prune` write to it.

---

## v0.18.1 → v0.19.0

One new table, `sentinel_archives`. `payload_version` stays at `1`, `sentinel_audits` is not touched,
and no hash is recomputed.

```bash
php artisan migrate
```

An installation that changes nothing behaves exactly as it did. `retention` ships empty, and an
empty map means nothing is ever removed.

### Anchoring is a precondition, not a suggestion

A range is only retired while an anchor still answers for it, so a trail has to be
[anchored](README.md#anchoring-ranges) before it can be pruned. On an installation with history that
is one read of the whole trail, two columns wide — run it by hand once before scheduling anything:

```bash
php artisan sentinel:checkpoint
```

A stream with no anchors reports `unanchored` and releases nothing. That is the honest outcome, not
a failure, and the command exits `0`.

### `--action` is required

```bash
php artisan sentinel:prune --action=delete --dry-run
```

There is no default, and `delete` is the only action this release has. The next version adds the one
that writes a range out to cold storage before removing it, and **that** will become the default —
so a command written today with `--action=delete` keeps doing exactly what it does today, and one
written without it fails now rather than changing meaning later.

### Retention is per logical type, but purging is per anchored range

This is the part worth reading before declaring a policy. A range leaves only when an anchor covers
it **and every entry in it has been released**, because a window is folded whole and a partly
emptied one could never reproduce its root again.

So the effective retention of a range is that of its longest-lived entry. Under the shipped
`integrity.stream => 'tenant'` a stream mixes logical types, and `'auth' => '90 days'` beside
`'model:App\Models\User' => '7 years'` frees only the ranges with no user entry in them. Nothing is
lost and nothing is wrong — but an operator who expects the auth entries to disappear on day 91 will
be surprised, so `sentinel:prune` names the entry holding each range it could not offer.

### `version` counts what the hot table still holds

`version` is the position of an entry in the history of its subject, and it is derived from the
highest one still in the table. Retiring a subject's **earlier** entries changes nothing: the
highest survivor is still the highest. Retiring a subject's history **entirely** means the next
entry about it starts again at `1` — the entry that held that number is gone, so nothing in the
table repeats, and `whereVersion()` keeps answering about what is there.

### `sequence_gap` no longer means every missing sequence

`sentinel:verify` steps over an absence when the manifest accounts for it and the anchors reach past
it, counting those entries apart from the ones it read. An absence nothing accounts for is still
reported exactly as before. Nothing that verified before stops verifying: the change can only turn a
break into a range that is explained, never the other way round.

If you read `VerificationResult` or `StreamVerification` directly, both gained a count of entries the
walk stepped over. It is published beside `checked` and deliberately not added into it.

---

## v0.18.0 → v0.18.1

One new table, `sentinel_checkpoints`. `payload_version` stays at `1`, `sentinel_audits` is not
touched, and no hash is recomputed — the anchors fold over hashes that already exist.

```bash
php artisan migrate
```

An installation that changes nothing behaves exactly as it did. Anchoring is off by default.

### Turning it on over an existing trail

Anchors calculate over the rows without touching them, so emitting one today over `[1, 40000]`
proves that range has not changed **since today**, which is exactly what it says and nothing more.

```php
'integrity' => [
    'enabled' => true,
    'checkpoints' => ['enabled' => true, 'every' => 1000],
],
```

Then anchor what the history already owes, and put the command on your own schedule:

```bash
php artisan sentinel:checkpoint
```

```php
// routes/console.php
Schedule::command('sentinel:checkpoint')->hourly();
```

The first run on an installation with history anchors every complete window at once. That is a read
of the whole trail, two columns wide — run it once by hand before scheduling it rather than
discovering it inside a cron window.

**Sign them, or do not bother.** An unsigned anchor is a row anyone with write access can reissue.
Anchoring without [signing](README.md#signing-the-chain) buys speed and no trust.

### `verifyStream()` can now report a break it used to miss

Verifying a bounded range reads the entry before it to check the link the range hangs from. Until
now that link was skipped, so a range whose first entry did not hang off its predecessor came back
intact. Nothing that was correct becomes incorrect — the change can only turn a false intact into a
true break — but a range that was reported intact on `v0.18.0` may report `link_mismatch` here, and
if it does, it was already broken.

Where the predecessor is not in the table, the walk behaves exactly as it did before.

### `Integrity\StreamVerification` gains two properties

`anchors` and `covered` are appended to the constructor with defaults, so existing construction and
every existing read is unaffected. `covered` counts entries an anchor answered for — entries nobody
read — and is deliberately **not** added into `checked()`: one total would hide which number came
from where.

---

## v0.17.0 → v0.18.0

Nothing to migrate: no new tables, no new columns, and `payload_version` stays at `1`. `signature`
and `signature_key_id` have been in `sentinel_audits` since `v0.2.0`, and both sit outside
`CanonicalPayload::COLUMNS` — so this is the first version that writes them, and writing them
changes nothing about any hash.

An installation that changes nothing behaves exactly as it did. Signing is off by default.

### `ext-openssl` is now declared

It moves into `require`. This is documentation of a fact rather than a new constraint:
`illuminate/encryption` already demands it and `Security\Keyring` has always built an `Encrypter`
that calls through it, so no installation of Sentinel has ever run without it. This is simply the
first version whose own code names `openssl_*` functions.

### `toArray()` gains one key

`signature` joins the `integrity` block, beside `hash` and `signature_key_id`:

```php
$entry['integrity']['signature'];   // string|null
```

Keys are only ever added to the shape `v0.15.0` froze, so this is the ordinary kind of change — but
a consumer asserting on an exact key list will see one more:

```php
// Fine, and what the contract is for
$entry['integrity']['hash'];

// Will now find one more key than it did
expect(array_keys($audit->toArray()['integrity']))->toBe([...]);
```

It is published because without it an exported entry cannot be verified by the third party the
signature exists for. `encryption` stays out, for its own reason: `toArray()` never decrypts.

### Turning signing on over an existing trail

```php
'integrity' => [
    'signature' => [
        'enabled' => true,
        'signer' => 'hmac',
        'key_id' => 'default',
        'keys' => ['default' => env('SENTINEL_SIGNING_KEY')],
    ],
],
```

**Entries written before this point stay unsigned, and that is the correct outcome.** They are
reported as `unsigned`, `sentinel:verify` exits zero over them, and nothing about them is a failure.

Signing them retroactively is not offered, and not because it would be slow: it would mean an
`UPDATE` over rows the package refuses to let anything update, and it would produce a signature
proving only that someone with write access passed through afterwards. That is the opposite of what
a signature is for.

Leaving `keys.default` null derives the secret from `APP_KEY`. Rotating `APP_KEY` then invalidates
every signature made under it — the entries still verify their own hashes, and their signatures
report as `invalid`. Name a secret of your own if `APP_KEY` rotation is part of your operations.

### Rotating and retiring a signing key

Move `key_id` and **leave the old key on the ring**:

```php
'key_id' => 'v2',
'keys' => [
    'v1' => env('SENTINEL_SIGNING_KEY_V1'),   // retired: still verifies
    'v2' => env('SENTINEL_SIGNING_KEY_V2'),   // current: signs from now on
],
```

Removing `v1` instead does not make its history invalid — it makes it undecidable, and it is
reported as `unknown_key` rather than as forgery. If you need to prove that history later, the key
has to still be there.

### A new capability interface on the Ledger contract

`Contracts\EnumeratesStreams` declares that a driver can list the chains it holds.
`Contracts\Ledger` itself is unchanged, so **a driver you wrote keeps working**; it simply does not
answer `Sentinel::verifyEverything()` or a `sentinel:verify` with no `--stream`, and is told so
rather than answered with an empty report.

```php
final class YourLedger implements Ledger, EnumeratesStreams
{
    /** @return list<string> */
    public function streams(): array
    {
        return [...];   // stable order: a report that reshuffles cannot be diffed
    }
}
```

### `writeMany()` and a repeated capture id

`Contracts\Ledger` now states what it always meant: a batch naming the same `capture_id` twice is a
caller error. The driver seals both and the unique index refuses them together. Nothing the package
hands a ledger contains one — `Dispatch\Settlement` drops the repeat first — but a direct caller of
`writeMany()` should not build one.

---

## v0.16.1 → v0.17.0

Nothing to migrate: no new columns, no new tables, and `payload_version` stays at `1`. `criteria`
and `affected_rows` have been in `sentinel_audits` since `v0.2.0` and are already inside the
canonical payload — this is simply the first version that writes them.

An installation that changes nothing behaves exactly as it did. Mass operations are opt-in per
query, so until something calls `auditing()` no statement in your application is audited, read or
slowed by any of this.

### `toArray()` gains two keys

`criteria` and `affected_rows` join the serialised shape, `null` on every entry that is not a mass
operation. Keys are only ever added to that shape, so this is the ordinary kind of change — but a
consumer asserting on an exact key list will see them:

```php
// Fine, and what the contract is for
$entry['changes'];

// Will now find two more keys than it did
expect(array_keys($audit->toArray()))->toBe([...]);
```

### `auditing` is a global name

It is registered as a macro on `Illuminate\Database\Eloquent\Builder`, which is what lets a query
opt in without this package sitting on the path of every query you make. A macro has no namespace:
if something else in your application or another package registers `auditing` on the Eloquent
builder, one of the two wins by boot order.

Nothing in this ecosystem has claimed the name so far. If yours has, rename it before upgrading.

### `mass_operations.sample` is new

If you published `config/sentinel.php` before this tag, your `mass_operations` section does not have
that key. That is fine — it defaults in code to `20`, the number of values of a long set the
criteria keeps. Add it if you want a different one:

```php
'mass_operations' => [
    'mode' => 'summary',
    'threshold' => 100,
    'sample' => 20,
],
```

### Before you turn on `individual`

It writes one entry per row plus the summary over them, at roughly nine hundred microseconds a row.
Over a set of three thousand five hundred rows that is three thousand five hundred and one entries
in one operation. That is the mode working as designed, and it is why `summary` is the default and
why `hybrid` has a threshold. Read *Mass operations* in the README before changing
`mass_operations.mode` globally.

---

## v0.16.0 → v0.16.1

Nothing to migrate: no new columns, no new tables, and `payload_version` stays at `1`. The default
mode is still `sync`, so an installation that changes nothing behaves exactly as it did.

### `sentinel.buffer.connection` is new

If you published `config/sentinel.php` before this tag, your `buffer` section does not have that key.
That is fine — every key in it defaults in code, and `connection` defaults to your application's
default Redis connection. Add it only if audits should wait on a Redis of their own:

    'buffer' => [
        'store' => env('SENTINEL_BUFFER_STORE', 'redis'),
        'connection' => env('SENTINEL_BUFFER_CONNECTION'),
        'key' => 'sentinel:buffer',
        'size' => 500,
        'flush_interval' => 60,
    ],

`store` names the driver, the way `ledger.default` does: `redis` or `memory`. Anything else is
refused at the point of use rather than served by the one that keeps everything on the instance.

### Before turning `mode` to `buffered`

Everything the `queue` section of the previous entry says still applies — `created_at` becomes the
order entries settled, `AuditCreated` fires where the flush runs, `RestoreResult::$entry` is `null`,
and an operation counts what it handed over. On top of that, one thing is true of this mode and of
no other:

**Entries a process dies holding are lost.** They never reached the ledger: no sequence, no hash, no
place in any chain. `buffer.size` and `buffer.flush_interval` are what bounds that window, and they
are evaluated when an entry arrives — nothing in PHP watches a clock between requests. A buffer that
stops receiving entries stops being evaluated, so schedule the command if you need a ceiling:

    Schedule::command('sentinel:flush')->everyMinute();

**The chain will not tell you when it happens.** An entry that never reached the ledger consumed no
sequence, so it leaves no gap and `verifyIntegrity()` reports a shorter chain as intact — correctly.
Detect loss by counting what you handed over against what landed; the count and the exit code of
`sentinel:flush` are there for that. If you cannot accept that, this is not your mode.

Nothing else is a loss. A batch the ledger refused goes back into the buffer at the head, in order,
and a flush that runs twice settles once.

### Redis is now a test dependency of this package

Only if you run Sentinel's own suite. `make test` brings the service up for you; a CI job that runs
the suite needs a Redis service and the `redis` extension. Nothing changes for an application that
depends on the package: Redis is required only by the mode that uses it.

---

## v0.15.0 → v0.16.0

Nothing to migrate: no new columns, no new tables, and `payload_version` stays at `1`. The default
mode is `sync`, which is what the package already did, so an installation that changes nothing
behaves exactly as it did.

### `capture_id` starts being written

Every captured entry now carries an identifier of its own, stamped where the capture reaches the
ledger. The column and its unique index have existed since `v0.2.0`; nothing filled them until now.

It is outside the canonical payload, so no hash changes and entries written before this tag keep
verifying with the column empty. What it buys is that a retry can be recognised as the same unit of
work once the capture and the entry stop happening in the same process.

Entries written by `Security\Rekeyer` have none, because rotation writes to the ledger without going
through a capture.

### `Context\Runtime::writingAuditEntry()` is gone

**Before:** a latch with no way back.

    app(Runtime::class)->writingAuditEntry();

**After:** a scope.

    app(Runtime::class)->whileWritingAudit(fn () => /* ... */);

It is the first branch the source resolver takes, so once it was on, every later entry of that
process claimed to come from a queue. It is internal plumbing for the resolver and appears in no
documented surface; the rename is here for anyone who found it anyway.

### Before turning `mode` to `queue`

Three things behave differently once an entry settles somewhere other than where it was captured.
None of them is a breaking change on `sync`.

**`created_at` becomes the order entries settled.** It stops being the order things happened in.

    Sentinel::audits()->get();                  // ordered by created_at — the order they settled
    Sentinel::timeline();                       // ordered by occurred_at — the order things happened
    Sentinel::audits()->byOccurrence()->get();  // the same query, by the clock of the fact

Anything rebuilding a lifeline from `created_at` keeps working and quietly starts answering a
different question. The chain is unaffected: `(stream, sequence)` is dense and monotonic in every
mode, and it is what `verifyIntegrity()` walks.

**`AuditCreated` fires in the worker.** It is announced wherever the ledger assigns identity. The new
`Events\Audited` is the one announced in the process that captured — it carries the entry only when
the two are the same place, and a `null` there means "settled elsewhere", never "not settled".

    Event::listen(Audited::class, function (Audited $event): void {
        $event->entry;   // the Audit under sync; null under queue
    });

**`RestoreResult::$entry` is `null`.** The record moved; the entry recording it has not been written
yet. The property was already nullable and already `null` inside a transaction of your own, so no
type changes — but code that assumed it was there stops finding it.

**The write-failure policy governs the request only.** In a worker the queue is the policy: it
retries under the same `capture_id`, which settles at most once, and what still does not land goes
to `failed_jobs`. `on_write_failure` still decides what a queue refusing the job costs the request.

**An operation counts what it handed over.** `sentinel_transactions.audits_count` is what the request
accepted for settlement rather than what landed, because the header closes before the worker runs.

### Rolling deploys

The job carries the entry as an array rather than a serialised object: unknown keys are dropped and
missing ones take their defaults, so a worker on the previous release can read a payload the current
one wrote. That covers anything additive.

It cannot rescue a payload whose fields have been reinterpreted. **Drain the queue before deploying a
change to what an entry means**, as opposed to what it contains.

### `mode = buffered` is refused

It arrives in `v0.16.1`. Until then, setting it raises a `ConfigurationException` naming that version
rather than falling back to `sync`: a mode nobody chose is a durability guarantee nobody made.

---

## v0.14.0 → v0.15.0

Nothing to migrate: no new columns, no new tables, and `payload_version` stays at `1`. One published
shape changes, and one contract is frozen.

### `metadata.restore.skipped` is a list, not a map

**Before:** a map keyed by field name.

```json
{"restore": {"applied": ["name"], "skipped": {"email": "redacted_field", "id": "identity_field"}}}
```

**After:** a list of pairs, in the same order.

```json
{"restore": {"applied": ["name"], "skipped": [
  {"field": "email", "reason": "redacted_field"},
  {"field": "id", "reason": "identity_field"}
]}}
```

The map had to go because the security stages match a protected field **by key name** at any depth
of `metadata`. A model declaring `email` as redacted sealed `r****d_f****d` where the word
`redacted_field` belonged; one declaring it hashed sealed a digest of it; one declaring it encrypted
sealed ciphertext and then advertised `email` in `encryption.fields` for a value the entry never
carried. All of it inside the canonical payload, so nothing could correct it after the write: the
entry verified, and what it said was wrong.

Entries written before this tag keep the old shape and still reproduce their hashes. If you read
that block, branch on it:

```php
$skipped = $entry->metadata['restore']['skipped'] ?? [];

$reasons = array_is_list($skipped)
    ? array_column($skipped, 'reason', 'field')
    : $skipped;
```

`RestoreResult::$skipped` in PHP is unchanged — still `array<string, Omission>`.

### `toArray()` is now a contract

**Before:** the keys could move in any minor.

**After:** they only ever grow. None is renamed, none is removed, and none is reinterpreted under
the same name. A shape that has to change arrives beside the one it replaces, and the old one stays
until `v2` with its deprecation documented here.

Two keys arrive with this tag: `source_audit_id` at the top level, and `verified` inside
`integrity`. Both are additions, so nothing you read today moves.

`verified` is `true | false | null`, and it is `null` in every version before `v0.18.0` — it means
"not checked in this call", never "failed". Write the three-way check:

```php
match ($entry['integrity']['verified']) {
    true => 'verified',
    false => 'TAMPERED',
    null => 'not checked',
};
```

`!$entry['integrity']['verified']` renders "not checked" as "TAMPERED", in PHP and in JavaScript
alike.

`RestoreResult` and `Enums\Omission` are frozen under the same rule, and stay outside `toArray()`.

### A failed write still throws, unless you say otherwise

`sentinel.on_write_failure` is new and defaults to `throw`, which is what the package already did —
so nothing changes for anyone who does nothing. Set `SENTINEL_ON_WRITE_FAILURE=log` for a failed
audit write to be recorded through `sentinel.log_channel` and let the request through.

It governs the write that happens in the request. A write deferred to a commit never propagates
whatever the setting says, because by then the transaction has committed.

### `Events\AuditRestored` is announced later

It now leaves from a commit callback rather than from the return of `restore()`, so its result
always carries the entry that recorded the restoration. Inside a transaction of your own, the event
arrives after your commit instead of during it — and a rollback announces nothing at all, where
before it announced a restoration that did not survive.

---

## v0.12.1 → v0.13.0

One published contract changes. Nothing to migrate.

### `Contracts\Auditable` gains `auditTransitions()`

**Before:** eleven methods.

**After:** twelve. `auditTransitions(): array` returns the columns whose movement is a state change
rather than an edit. An `update` that moves one of them is written as `audit_type = transition`
instead of `model`/`updated`.

Nothing to do if your model uses the `Concerns\Auditable` trait: the trait implements it, and
declaring `protected array $auditTransitions = ['status'];` on the model is all it reads. A model
that declares nothing keeps auditing exactly as before.

If you implement the interface by hand, add the method:

```php
/**
 * @return list<string>
 */
public function auditTransitions(): array
{
    return [];
}
```

A column named there cannot also be in `$auditExclude`, `$auditRedact`, `$auditEncrypt` or
`$auditHash`, nor left out of a declared `$auditInclude`: a lifeline the entry cannot show is not a
lifeline, and the combination raises a `ConfigurationException` the first time the model is audited.

---

## v0.11.1 → v0.12.0

No published contract changes. There is one migration, and one behaviour that changes for code that
audits inside a database transaction.

### `sentinel_transactions`

Additive and reversible. It does **not** touch `sentinel_audits`: the `transaction_id` column and
its index have been there since `v0.2.0`, and this version only stops writing them `null`.

```bash
php artisan vendor:publish --tag=sentinel-migrations   # only if you publish them
php artisan migrate
```

Existing entries keep `transaction_id = null` and the new table starts empty. There is no backfill:
inferring operations over history already settled would be inventing facts, and rewriting settled
entries is forbidden by the 0.x schema policy.

### Entries captured inside a `DB::transaction()` now wait for it

`transactions.after_commit` has been in `config/sentinel.php` since `v0.1.0` and nothing read it.
It does now, and it defaults to `true`.

**Before:** an entry captured inside a database transaction was handed to the ledger immediately.

**After:** it is handed over when that transaction commits, and not at all if it rolls back. A
rollback to a `SAVEPOINT` discards only that level.

Nothing changes outside a transaction — same write, same return value, same exceptions. Inside one,
two things are worth checking before you upgrade:

- **A test or a job that asserts on an audit while the transaction is still open** will now find
  nothing there. Assert after the transaction, which is also where the entry is now true.
- **Code that uses the return value of a capture inside a transaction** gets `null`, because the
  entry does not exist yet. Read it back after the commit instead.

To keep the old behaviour exactly:

```php
// config/sentinel.php
'transactions' => [
    'after_commit' => false,
],
```

That is supported and it means what it says: a ledger allowed to assert what a rollback undid —
where it can. With the ledger on the connection that rolled back, which is the default, the database
takes the entry with it either way; the difference shows up with a dedicated `database.connection`,
a ledger that is not this database, or a fanout to somewhere external.

### A deferred write that fails is announced, not thrown

A write that waited for the commit and then failed dispatches `Events\AuditWriteFailed` instead of
throwing. It has to: the framework runs commit callbacks in a bare loop, so an exception there would
stop every later entry of the same transaction from even being attempted, and would surface out of a
`DB::transaction()` that had already committed.

If you relied on a ledger failure surfacing as an exception from `$model->save()`, that still
happens outside a transaction. Inside one, listen for the event:

```php
Event::listen(ElPandaPe\Sentinel\Events\AuditWriteFailed::class, function ($failed) {
    Log::critical($failed->message(), ['transaction' => $failed->transactionId]);
});
```

---

## v0.11.0 → v0.11.1

One published contract changes. Nothing to migrate.

### `Contracts\Auditable` gains `auditParents()`

**Before:** ten methods.

**After:** eleven. `auditParents(): array` returns the `belongsTo` relations whose parent gets a
relation entry when this model changes hands, as a map of *relation on this model* to *the name the
parent gives that collection*.

Nothing to do if your model uses the `Concerns\Auditable` trait: the trait implements it, and
declaring `protected array $auditParents = ['author' => 'articles'];` on the model is all it reads.
A model that declares nothing keeps auditing exactly as before.

If you implement the interface by hand, add the method:

```php
/**
 * @return array<string, string>
 */
public function auditParents(): array
{
    return [];
}
```

---

## v0.10.1 → v0.11.0

Two published contracts change, and there is a migration.

### `Contracts\Auditable` gains `relationHistory()`

**Before:** nine methods.

**After:** ten. A class that implements the interface by hand rather than using the trait needs:

```php
public function relationHistory(string $relation): AuditQuery
{
    return app(Sentinel::class)->audits()->for($this)->whereRelation($relation);
}
```

Models using `Concerns\Auditable` get it for nothing.

### A driver must declare the three new filters to answer them

`Filter` gains `Relation`, `Related` and `Operation`. As with every filter published after `v0.9.0`,
`Filter::assumed()` does **not** grow: a driver that does not name them refuses them rather than
ignoring a criterion it cannot translate.

**If you ship a `DeclaresFilters` driver** and want to answer `whereRelation()`, `whereRelated()` and
`whereOperation()`, add the three cases to `supportedFilters()` and translate them. The criterion
arrives as one `Query\RelationCriteria` holding all three parts, and they must narrow the **same
line** — an entry matches when one of its lines satisfies every part at once. Compiling them into
separate conditions is a subtly wrong answer, not a slower one.

A driver over arrays can hand the whole thing to `Ledger\ArrayQuery`, which already answers them.

**If you do nothing**, your driver keeps working and refuses the three by name.

### The trait now overrides two relation factories

`Concerns\Auditable` overrides `newBelongsToMany()` and `newMorphToMany()` so every many-to-many a
model declares is wrapped. Nothing in your application changes — `$team->members()->sync([...])` is
written and behaves exactly as before, and every return value is untouched.

**If your model already overrides either factory**, one of the two overrides wins and the other is
lost. Call the trait's version from yours, or the relation is not audited.

### Migration

```bash
php artisan migrate
```

`sentinel_audit_relations` is created. It is additive and reversible, and `sentinel_audits` is not
touched: no new column, no `ALTER`, no rewritten hash. `payload_version` stays at `1` and every
entry frozen before this version reproduces its hash byte for byte.

If you publish migrations, publish again to pick up the new one — the package loads its own for any
file you have not published.

---

## v0.9.0 → v0.10.0

Four published contracts change. Three of them are one edit each; the fourth is a read that used to
answer and now refuses, on purpose.

### `Contracts\Auditable` gains `auditTags()`

**Before:** eight methods. **After:** nine — `auditTags(): array` returns the labels every entry of
that model is born with.

Nothing to do if your model uses the `Concerns\Auditable` trait: the trait implements it, and
declaring `protected array $auditTags = ['billing'];` on the model is all it reads.

If you implement the interface by hand, add the method:

```php
/**
 * @return list<string>
 */
public function auditTags(): array
{
    return [];
}
```

### A new pipeline stage, which a published configuration will not pick up on its own

**Before:** six stages. **After:** seven — `Pipeline\Stages\ResolveTags` gathers what an entry is
classified as, and it sits between `ResolveContext` and `NormalizeData`.

The published `config/sentinel.php` names every stage and is taken verbatim, so **an installation
that published its configuration keeps running the six it published** — labels would silently stay
empty. If you published it, add the stage:

```php
// config/sentinel.php
'pipeline' => [
    ElPandaPe\Sentinel\Pipeline\Stages\FilterUnchanged::class,
    ElPandaPe\Sentinel\Pipeline\Stages\ResolveContext::class,
    ElPandaPe\Sentinel\Pipeline\Stages\ResolveTags::class,      // <- new
    ElPandaPe\Sentinel\Pipeline\Stages\NormalizeData::class,
    ElPandaPe\Sentinel\Pipeline\Stages\MaskSensitiveData::class,
    ElPandaPe\Sentinel\Pipeline\Stages\EncryptSensitiveData::class,
    ElPandaPe\Sentinel\Pipeline\Stages\EnforcePolicies::class,
],
```

### A driver no longer answers a filter it never named

**Before:** a driver that does not implement `Contracts\DeclaresFilters` was assumed to translate
every case of `Enums\Filter`, whatever that enum grew to hold.

**After:** it is assumed to translate `Filter::assumed()` — the nine filters published with the
contract in `v0.9.0` — and nothing else. `whereTag()`, `whereFieldChanged()` and `whereVersion()`
are answered only by a driver that names them.

This is the safe direction: a driver written against `v0.9.0` never knew about the new filters, and
`ArrayQuery`-style resolution ignores a criterion it does not recognise, so the old assumption
would have had such a driver answering with entries nobody asked for. If your driver does translate
them, say so:

```php
final class RedisLedger implements DeclaresFilters, Ledger
{
    public function supportedFilters(): array
    {
        return Filter::cases();
    }
}
```

The contract suite changed with it. `publishedFilters()` now yields the `Filter` beside the closure,
and every filter expectation holds a driver to one of two answers — it translates the filter, or it
refuses it — so a driver declaring the narrower set still runs the suite unchanged.

### `get()` refuses a read it would have to truncate

**Before:** `get()` handed back at most `AuditQuery::DEFAULT_LIMIT` entries and said nothing about
having cut. A filter matching six hundred entries answered with five hundred, in exactly the shape
of a complete answer.

**After:** it asks for one more than the bound and throws `QueryException` when that one arrives.

```php
// was: five hundred entries, silently a prefix
$entries = Sentinel::audits()->get();

// now, pick one:
$entries = Sentinel::audits()->whereTag('billing')->get();        // narrow it
$entries = Sentinel::audits()->take(500)->get();                  // a prefix, on purpose
$page    = Sentinel::audits()->paginate(100);                     // walk all of it
```

### Two migrations, both additive

`sentinel_audit_tags` is new. The second one adds two indexes to `sentinel_audits` and no columns:
`(occurred_at, id)` and `(subject_type, subject_id, occurred_at, id)`, which are what keep the
timeline from sorting outside every index.

```bash
php artisan migrate
```

Existing entries come out unlabelled, and `whereTag()` does not return them. There is no backfill:
nobody knows which label an entry written before labels existed would have carried.

`payload_version` stays at `1`, labels are not part of the hashed payload, and every entry frozen
before this version reproduces its hash byte for byte.

**If you published the migrations** with `vendor:publish --tag=sentinel-migrations`, publish again
to pick up the two new files. Until `v0.10.0` the package loaded its migration directory only when
the audits migration was absent from yours — all or nothing on one filename — so a second migration
would never have reached you. That check is now made per file.

---

## v0.8.0 → v0.9.0

`v0.9.0` gives `Ledger::query()` something to answer. The signature has been on the contract since
`v0.2.0` and every driver in this package threw from it; now they all answer, and the published
contract suite holds them to answering the same thing.

**Nothing to migrate.** No new tables, no new indexes, no altered columns, no rewritten hashes.
`payload_version` stays at `1`, and every entry frozen before this version comes back through the
new surface reproducing the hash it was frozen with. The query plan of each published filter was
measured on MySQL 9 and PostgreSQL 16, and none of them needed an index that did not already exist.

If you do not implement `Contracts\Ledger` yourself, there is nothing here for you: the rest of this
section is about drivers.

### `query()` has to work now

**Before:** the method was on the interface and no driver in this package implemented it. A driver of
your own could throw from it and nothing noticed.

**After:** it takes an `AuditQuery` and returns the entries that match it, ordered by `created_at`
with the entry's identifier behind it — oldest first, or newest first when `newestFirst` is set —
bounded by `limit` and `offset` when they are set.

```php
public function query(AuditQuery $query): AuditCollection;
```

The criteria are plain readable properties: `subject` and `actor` (a `Support\Reference` with a type
and a key), `event`, `severity`, `source`, `tenantId`, `transactionId`, `traceId`, `period` (a
`Query\Period` with `from` and `to`, both ends inclusive, over `created_at`), `newestFirst`, `limit`
and `offset`.

If your store keeps entries in memory or in anything you can iterate, `Ledger\ArrayQuery` resolves
the whole thing for you:

```php
public function query(AuditQuery $query): AuditCollection
{
    return new AuditCollection($this->queries->resolve($this->entries, $query));
}
```

Extend `Testing\LedgerContractTestCase` and the fifteen new expectations run against your driver
without you writing any of them.

### A filter you cannot translate is declared, never dropped

If your backend cannot answer one of the filters, say so. The query then refuses it as it is added,
which is a readable exception at the call site instead of a result that quietly answers a different
question:

```php
use ElPandaPe\Sentinel\Contracts\DeclaresFilters;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Enums\Filter;

final class RedisLedger implements DeclaresFilters, Ledger
{
    public function supportedFilters(): array
    {
        return [Filter::Subject, Filter::Tenant];
    }
}
```

A driver that does not implement `DeclaresFilters` is taken to answer all nine. Dropping a filter you
cannot translate would hand back entries nobody asked for, and a trail that shows the wrong history
is worse than one that refuses to answer.

### `LedgerException::queryNotImplemented()` is gone

It existed to say the Query API had not arrived. It has.

---

## v0.7.0 → v0.8.0

`v0.8.0` ran the `Ledger` contract against a store with no transactions, no joins and no
autoincrement, and published the contract that survived it. Three of the changes below are the
corrections that came out of that; the rest is the shape a second and a third driver forced.

### `Contracts\Ledger` gains `append()`

Every implementation of the contract has to add one method. If you do not implement `Ledger`
yourself, nothing here applies.

```php
public function append(Audit $audit): Audit;
```

It stores an entry that arrives already sealed, exactly as it is: **no sequence is assigned and no
hash is recomputed**. It is how a fanout hands a secondary destination what the primary sealed, and
how an archive or a replica takes an entry it did not write. An implementation that reseals what it
is appended is wrong: two ledgers each numbering their own chain produce two different truths about
one fact.

A driver over a store that cannot store the entry verbatim should throw rather than adapt it.

### The contract states three guarantees it does not make

Nothing in your code has to change for these, but what your code is entitled to assume does.

- **`writeMany()` is not atomic.** It either returns everything that settled, or it throws having
  made a best effort to leave nothing behind. On a store with no rollback that effort is
  compensation, and compensation can be interrupted. `DatabaseLedger` still wraps the batch in a
  transaction and is still all-or-nothing — the contract simply stops requiring every driver to be.
- **No read is promised to see a write that just returned.** `find()` and `stream()` may not show an
  entry `write()` handed back a moment ago. `DatabaseLedger` and `MemoryLedger` both do; a driver
  over a search index does not, and it is still a valid driver.
- **Idempotency by `capture_id` belongs to the caller.** A ledger cannot deduplicate what it cannot
  reliably look up, and the lookup is exactly the read with no promise attached.

### `NullLedger` keeps nothing

**Before:** the `null` driver kept every entry it wrote on the instance, so `find()` and `stream()`
answered from memory for the rest of the request.

**After:** it still builds, seals and chains every entry — the code path an application measures is
unchanged, and `write()` still returns a real sealed entry — but it retains only the tail of each
stream and a version counter per subject, which are the two things the next entry is sealed with.
`find()` returns `null` and `stream()` walks nothing.

If you were reading entries back out of the `null` driver, switch to the new `memory` driver, which
is what that behaviour now is:

```php
// config/sentinel.php
'ledger' => [
    'default' => 'memory',   // was 'null'
],
```

Turning auditing off is not a reason to grow with the traffic it is refusing to record, which is
what the old behaviour did in a long-lived worker.

### The contract test suite moved into the package

**Before:** `ElPandaPe\Sentinel\Tests\Testing\LedgerContractTestCase`, inside this package's test
folder and unreachable from anywhere else.

**After:** `ElPandaPe\Sentinel\Testing\LedgerContractTestCase`, shipped in `src/`. Extend it, return
your driver from `ledger()`, and your driver is held to the same chain the ones in this package are.
PHPUnit and Testbench stay development dependencies and are declared in `suggest`.

Two expectations that queried the `sentinel_audits` table were dropped: they were about one driver,
not about the contract. Two hooks replaced them — `retains()` for a driver that keeps nothing, and
`settle()` for one whose reads are eventually consistent.

### `--tag=sentinel-factories` is gone

**Before:** `php artisan vendor:publish --tag=sentinel-factories` copied `AuditFactory` into
`database/factories`.

**After:** the tag does not exist. The copy declared a class in this package's namespace inside a
directory an application maps to its own, so what it produced was either never loaded or a
collision. `AuditFactory` ships with the package and is autoloaded from it: `Audit::factory()` works
with nothing published. To change what it builds, point `models.audit` at your own subclass.

If you published it, delete the copy.

### Nothing to migrate

No new tables, no altered columns, no rewritten hashes. `payload_version` stays at `1` and every
entry frozen before this version reproduces its hash byte for byte.
