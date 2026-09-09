# 🧩 Fanout: writing to more than one place

> One entry, several destinations — which one is allowed to seal it, what each of the two failure
> policies does, and the things a fanout deliberately is not.

**On this page:** [What a fanout is](#what-a-fanout-is) · [The order is not negotiable](#the-order-is-not-negotiable) · [The destination list](#the-destination-list) · [The two failure policies](#the-two-failure-policies) · [The event a destination failure raises](#the-event-a-destination-failure-raises) · [Topologies that make sense](#topologies-that-make-sense) · [What a fanout is not](#what-a-fanout-is-not) · [Monitoring destinations](#monitoring-destinations) · [Recovering a destination that was down](#recovering-a-destination-that-was-down) · [⚠️ Pitfalls](#️-pitfalls) · [✅ Best practices](#-best-practices)

---

## What a fanout is

A fanout is a `Contracts\Ledger` that holds several other ledgers and writes one entry to all of
them. It is selected by a configuration string like every other driver — see
[The shipped drivers](02-shipped-drivers.md) — and never by naming a class:

```php
// config/sentinel.php
'ledger' => [
    'default' => 'fanout',
    'ledgers' => [
        'fanout' => [
            'destinations' => ['database', 'archive'],
            'on_failure' => 'strict',
        ],
    ],
],
```

`SentinelServiceProvider` resolves each name through the same `driver()` match every other
installation uses, hands the first one to `Ledger\FanoutLedger` as the **primary** and the rest as
**secondaries**, and binds the result to `Contracts\Ledger`. Nothing above that binding knows a
fanout is in play: the pipeline, the dispatcher, the query API and every Artisan command talk to the
contract.

Here is the whole of what the fanout does with the six contract methods:

| Method | Primary | Secondaries |
|---|---|---|
| `write()` | seals the entry — stream, `sequence`, `previous_hash`, `hash` | each gets `append()` with what the primary sealed |
| `writeMany()` | one batch, one tail read per stream | one `append()` per entry, after the batch returned |
| `append()` | takes the already-sealed entry | each gets the same entry |
| `find()` | answers | never asked |
| `query()` | answers | never asked |
| `stream()` | answers | never asked |

The three optional capabilities are delegated the same way. `FanoutLedger` declares
`Deduplicates`, `EnumeratesStreams` and `DeclaresFilters` unconditionally and forwards each to the
primary, falling back to `[]`, `[]` and `Filter::cases()` when the primary does not implement the
interface. A narrow secondary therefore never narrows what `Sentinel::audits()` can ask, and a
capable secondary never widens it.

> 📌 **Note.** `Ledger\FanoutLedger` is `@internal`, as is every driver class in the package. The
> published surface for this page is the `sentinel.ledger.ledgers.fanout.*` keys,
> `Enums\FanoutPolicy` and `Events\LedgerDestinationFailed`.

## The order is not negotiable

The first destination in the list seals the entry and the rest are handed what it sealed. That is
not a default you can invert with a setting, and the reason is arithmetic rather than taste.

`sequence` is a position in a chain. It is dense and monotonic inside one stream, and each entry's
`previous_hash` is the previous entry's `hash`. If two destinations each ran `write()` on the same
capture, each would read *its own* tail, take *its own* next position and compute *its own* hash
over a payload that includes that position. You would end up with two entries describing one fact,
sitting at different sequences in chains that disagree — and both of them would verify, because each
is internally consistent. There is no reconciliation for that afterwards; the only fix is to never
create it.

So `FanoutLedger::write()` does exactly one thing before it fans out:

1. `$this->primary->write($audit)` — the entry gets its identity here and nowhere else.
2. `append()` on each secondary, in list order, with that same `Models\Audit` object.

`append()` is the contract method that means *store this exactly as it arrived*: assign no sequence,
recompute no hash, leave `previous_hash` alone. Its obligations are spelled out in
[The Ledger contract](01-the-ledger-contract.md) and a driver that breaks them fails the
[contract test suite](04-the-contract-test-suite.md).

Reads follow the same logic. `find()`, `query()` and `stream()` all go to the primary because the
primary is the destination whose chain the sequence belongs to; a secondary holds copies of entries
it did not number, and asking it "what is at sequence 400 of `tenant:acme`?" is asking a question it
has no authority over.

> ⚠️ **Warning.** `sequence`, `hash`, `previous_hash`, `payload_version` and the canonical payload
> are load-bearing. A driver that renumbers, rehashes or re-serialises an appended entry does not
> produce a slightly different copy — it produces an entry that no longer reproduces its own hash,
> which `Sentinel::verifyIntegrity()` reports as tampering. See
> [The hash chain](../07-integrity/01-the-hash-chain.md).

## The destination list

`Support\Config::fanoutDestinations()` validates the shape of the list and nothing else.

| Value | Accepted as primary | Accepted as secondary | Notes |
|---|---|---|---|
| `database` | yes | yes | the only shipped driver designed to be a primary |
| `archive` | **yes, and it should not be** | yes | refused as `ledger.default`, not refused here — see the warning below |
| `memory` | yes | yes | keeps everything on the instance; a test double, never a store |
| `null` | yes | yes | seals and chains, keeps nothing |
| `fanout` | no | no | refused: composing a fanout into itself has no bottom |
| anything else | no | no | refused by `driver()`, naming the key that declared it |

The refusals you can actually hit, with the message you will read:

```text
# 'destinations' => ['database', 'fanout']
Sentinel configuration key [sentinel.ledger.ledgers.fanout.destinations] has unknown value
[fanout]. Accepted: a driver other than fanout.

# 'destinations' => ['database', 'elastic']
Sentinel configuration key [sentinel.ledger.ledgers.fanout.destinations] has unknown value
[elastic]. Accepted: archive, database, fanout, memory, null.

# 'destinations' => 'database'
Sentinel configuration key [sentinel.ledger.ledgers.fanout.destinations] must be a non-empty list
of ledger drivers, string given.
```

A published config file that predates the fanout block is not an error: a `null` destinations key
falls back to `['database']` and a `null` `on_failure` falls back to `strict`, both in code, so an
installation that never heard of the fanout still boots.

> ⚠️ **Warning.** Nothing refuses `'destinations' => ['archive', 'database']`. The guard that stops
> `archive` being `ledger.default` checks the configuration key it was resolved from, and a fanout
> resolves its destinations under a different key. An `ArchiveLedger` as the primary keeps the tail
> of each stream on the instance, so every request and every worker starts a fresh chain at sequence
> 1 under the same stream name, and every read is answered by a driver that can only read back the
> batches this instance wrote. The list is validated for shape, not for suitability. Put the
> durable, indexed driver first.

## The two failure policies

`sentinel.ledger.ledgers.fanout.on_failure` is parsed into `Enums\FanoutPolicy` and answers one
question: **does a secondary refusing the entry count as a failed write?**

| `on_failure` | Enum case | Event on a secondary refusal | Remaining secondaries | The write |
|---|---|---|---|---|
| `strict` (default, and the fallback when the key is `null`) | `FanoutPolicy::Strict` | dispatched, then the exception is rethrown | never offered the entry | fails |
| `primary` | `FanoutPolicy::Primary` | dispatched, the loop continues | still get the entry | settles |

Two things are true under **both** policies and are worth stating plainly:

- **The primary refusing fails the write.** It throws before the fanout loop is ever reached, so
  there is nothing to decide. `on_failure` is a question about secondaries only.
- **What a destination already took, it keeps.** The entry was sealed before it was handed out and
  nothing in the fanout can unseal it. A failed `strict` write leaves the entry present in the
  primary, present in every secondary before the one that refused, and absent from that one and
  everything after it. That is a reconciliation job, not corruption: the chain is intact everywhere
  it landed.

Both the policy and the destination list are read when the fanout is built — the first time
`Contracts\Ledger` is resolved in the scope — so a typo surfaces there rather than on the first
failure:

```text
Sentinel configuration key [sentinel.ledger.ledgers.fanout.on_failure] has unknown value
[best-effort]. Accepted: strict, primary.
```

`on_failure` and `sentinel.on_write_failure` are different questions and are frequently confused.
`on_failure` decides whether a refusal counts as a failed write at all; `on_write_failure` decides
what a failed write costs the request that caused it. Under `strict` the exception leaves the ledger
and lands in `Capture\WriteFailure`, which then applies `throw` or `log` — see
[Failure policy](../09-operations/05-failure-policy.md).

## The event a destination failure raises

`Events\LedgerDestinationFailed` is dispatched from inside the fanout loop, **before** the policy
decides. That ordering is deliberate. `strict` is the policy that most needs the announcement,
because it rethrows out of a primary that has already sealed and stored the entry; announced after
the throw, an operator would be told a write did not complete and never told which one did.

| Property | Type | What it holds |
|---|---|---|
| `destination` | `class-string` | the ledger class that refused — `App\Sentinel\SearchLedger`, `ElPandaPe\Sentinel\Ledger\ArchiveLedger` |
| `stream` | `string` | the chain the entry belongs to |
| `sequence` | `int` | its position in that chain |
| `auditId` | `string` | the entry's ULID — it exists in the primary, go and fetch it |
| `reason` | `Throwable` | whatever the destination threw |
| `message()` | `string` | the translated sentence, from `sentinel::sentinel.ledger.destination_failed` (English and Spanish ship) |

Those five carry no payload: no `before`, no `after`, no `changes`, no `metadata`. They are
coordinates, not content — which is what makes the event safe to log at full volume.

```php
use ElPandaPe\Sentinel\Events\LedgerDestinationFailed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

Event::listen(function (LedgerDestinationFailed $failure): void {
    Log::channel('audit')->critical($failure->message(), [
        'destination' => $failure->destination,
        'stream' => $failure->stream,
        'sequence' => $failure->sequence,
        'audit_id' => $failure->auditId,
        'exception' => $failure->reason,
    ]);
});
```

The rendered sentence reads:

```text
Destination App\Sentinel\SearchLedger did not take audit 01JQ… at sequence 412 of stream
tenant:acme: Connection refused
```

Listeners dispatch inline, on the write path, in the process doing the writing. Queue anything slow
— see [Events and listeners](../09-operations/04-events-and-listeners.md).

## Topologies that make sense

| Topology | `destinations` | Policy | What it buys | What it costs |
|---|---|---|---|---|
| Hot only (shipped default) | `['database']` | either | nothing — it is the `database` driver with a wrapper around it | one object |
| Hot + cold | `['database', 'archive']` | usually `primary` | a second copy on any `Storage` disk, written as the entry is sealed | the cold copy is batched, so it is not durable at the instant the write returns |
| Hot + search satellite | `['database', <your driver>]` | `primary` | a query-side index outside the audit table | you own the driver, and its outages are yours to backfill |
| Hot + write-once store | `['database', <your driver>]` | `strict` | an entry that is present in a store nobody can rewrite, or no entry at all | every outage of that store is an outage of your writes |

### Database plus archive

`archive` is the only shipped driver built to be a secondary. It writes NDJSON over the `Storage`
contract, so S3, R2 or MinIO work without the package knowing they exist:

```php
'ledger' => [
    'default' => 'fanout',
    'ledgers' => [
        'archive' => [
            'disk' => env('SENTINEL_ARCHIVE_DISK', 's3'),
            'path' => 'sentinel',
            'codec' => 'gzip',
            'batch' => 1000,
        ],
        'fanout' => [
            'destinations' => ['database', 'archive'],
            'on_failure' => 'primary',
        ],
    ],
],
```

One behaviour decides whether this topology does what you think it does: `ArchiveLedger` holds an
**open batch on the instance** and writes a file only when the batch fills (`archive.batch`,
default 1000), when a read is asked of it, or when somebody calls `seal()`. The package seals what
is open on `terminating()` and on `WorkerStopping`, and only if an `ArchiveLedger` was ever
resolved. So a request that writes three entries leaves nothing on the disk until the request ends.

> ⚠️ **Warning.** That end-of-process seal runs inside `rescue()`. A disk that refuses the batch
> *there* is reported through the application's exception handler and **never** through
> `LedgerDestinationFailed`, and the fanout policy does not see it — the request has already been
> answered. `strict` therefore covers the disk failures that happen while a batch is being filled,
> not the ones that happen when it is written out. Lower `archive.batch` if you want the two to
> coincide more often; understand that a batch of 1 means one object per entry.

More on cold storage, the manifest and what the prune does with it in
[Cold archiving](../08-lifecycle/02-cold-archiving.md).

### Database plus a search index

No search driver ships. You write one, hold it to the
[contract test suite](04-the-contract-test-suite.md), and wire it up yourself: `driver()` is a closed
match over the five shipped names and can never reach a class of yours, so a fanout with a custom
destination is one you build in your own service provider. Either way, two facts govern the design:

- The satellite is a **secondary**. It only ever sees `append()`, so it needs no sequence logic, no
  tail read and no hashing. Store what arrives.
- Adding it does **not** widen `Sentinel::audits()`. `supportedFilters()` comes from the primary. To
  query the satellite you query the satellite; see
  [Filters reference](../06-reading/02-filters-reference.md) for what the trail itself answers.

### Database plus a write-once store

This is the one case where `strict` is the right answer rather than the cautious-sounding one. If
the compliance claim is "every entry is also in the WORM bucket", then an entry that is only in the
database is a compliance failure, and a write that cannot make that claim true must not succeed.
Pair it with `on_write_failure = throw` and accept that the store's availability is now your
application's availability.

## What a fanout is not

### It is not replication

There is no failover and no repair. Every read goes to the primary, unconditionally. If the primary
loses a range that a secondary still holds, nothing in the package goes and gets it — not `find()`,
not `query()`, not `sentinel:verify`. A secondary is a copy you made, and using it is a manual
operation you write yourself.

### It is not a two-phase commit

The entry is sealed and stored in the primary *before* any secondary is offered it. There is no
prepare phase, no vote and no rollback. Under `strict`, a failure at destination three leaves the
entry in the primary and in destinations one and two. `writeMany()` makes this larger rather than
different: the primary settles the whole batch in one transaction and only then does the fanout walk
the entries one at a time, so a secondary that starts refusing halfway through a batch of 500 leaves
the earlier entries fanned out, the later ones never offered, and all 500 sealed in the primary.

There is no `appendMany()` in the contract, so a destination that could take a batch cheaply cannot
say so.

### A retry does not heal a destination

This is the one that surprises people. Every capture carries a `capture_id` with a unique index
behind it, and `Dispatch\Settlement` asks the ledger which identifiers already settled before
writing. `FanoutLedger::settled()` forwards that question to the **primary**. So:

1. A strict fanout write fails because a secondary was down. The primary already has the entry.
2. The queue retries the job, or the buffer's failed batch is put back and flushed again.
3. `Settlement` asks; the primary answers "already settled"; the capture is dropped.
4. `write()` is never called, so the fanout never runs, so the secondary never gets the entry.

The retry is correct — it must not write the same fact twice — and it is also not a repair. Getting
that entry into the destination that missed it is
[a separate operation](#recovering-a-destination-that-was-down).

### It does not make `verifyEverything()` safer

`FanoutLedger` implements `Contracts\EnumeratesStreams` unconditionally and returns `[]` when the
primary cannot enumerate. A bare driver that cannot list its chains is refused loudly by
`Sentinel::verifyEverything()` with `QueryException::cannotEnumerateStreams()`; the same driver
wrapped in a fanout is not, because the wrapper satisfies the interface. What comes back is an
`IntegrityReport` over zero streams — and an empty report answers `isIntact() === true` with
`checked() === 0`. Name the stream, or check `checked()` before you believe the verdict.

### Two more boundaries worth knowing

- **Verification verifies the primary.** `Sentinel::verifyIntegrity()` walks
  `Contracts\Ledger::stream()`, which is the primary's chain. It says nothing about whether a
  secondary holds the same entries. See [Verification](../07-integrity/06-verification.md).
- **Rehydration bypasses the fanout on purpose.** `Archive\Rehydrator` names `DatabaseLedger`
  directly rather than resolving `Contracts\Ledger`, because writing a restored range through a
  hot-plus-cold fanout would hand every entry back to the cold destination, which would write a
  fresh batch at the same deterministic key — overwriting the very file being read. See
  [Rehydration](../08-lifecycle/03-rehydration.md).

## Monitoring destinations

There is no built-in health check for a destination, and there is no counter of how far behind one
is. What you have is one event and one command.

| Signal | Where it comes from | What it means |
|---|---|---|
| `Events\LedgerDestinationFailed` | the fanout loop, before the policy decides | one named entry did not reach one named destination |
| `Events\AuditWriteFailed` | `Capture\WriteFailure`, on both branches | a write did not complete — under `strict` this fires *after* the primary sealed the entry |
| `php artisan about` | the `Sentinel` section | prints `Ledger: fanout`. It does not print the destinations or the policy |
| `php artisan sentinel:verify` | `Integrity\Verifier` | the primary's chains. Never the secondaries |

> 🧪 **Verify it.** `php artisan about` prints the resolved ledger name, so you can confirm from a
> deploy log that an environment is actually running a fanout rather than the default `database`.
> The destination list is not printed — read it from `config/sentinel.php`.

Two alarms are worth wiring separately, because they mean different things:

- **`LedgerDestinationFailed` with `on_failure = primary`** is a backlog forming. Nobody is being
  told; the writes are succeeding. Count these, and page on the rate, not the first one.
- **`LedgerDestinationFailed` with `on_failure = strict`** is an outage in progress. Every one of
  these is also a failed write, and what that costs depends on `on_write_failure`.

For the wider picture — which failures deserve a pager, what a broken chain looks like in the logs —
see [Monitoring and troubleshooting](../09-operations/08-monitoring-and-troubleshooting.md).

## Recovering a destination that was down

The package ships no backfill command. What it ships is everything you need to write one: the
primary holds the authoritative chain, `stream()->range()` walks it in sequence order, and
`append()` takes a sealed entry exactly as it is.

Two traps before the code.

**Labels ride on a loaded relation.** `Contracts\LedgerStream` walks entries without eager-loading
`tags`, and the contract says an entry whose `tags` relation is not loaded says nothing about its
labels — so a destination is right to store none. A backfill that does not load the relation writes
copies with their [labels](../06-reading/06-labels.md) missing. Load it.

**`append()` is not idempotent by contract.** `Ledger\DatabaseLedger::append()` inserts and does not
retry, so re-appending an entry a destination already holds raises a unique-constraint violation on
the primary key. Another driver may store it twice without complaint. Ask the destination what it
holds before you offer it anything.

```php
namespace App\Console\Commands;

use App\Sentinel\SearchLedger;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Models\Audit;
use Illuminate\Console\Command;

final class BackfillDestination extends Command
{
    protected $signature = 'audit:backfill {stream} {from} {to}';

    public function handle(Ledger $primary, SearchLedger $destination): int
    {
        $walk = $primary->stream((string) $this->argument('stream'))
            ->range((int) $this->argument('from'), (int) $this->argument('to'));

        foreach ($walk as $entry) {
            if ($destination->find($entry->id) instanceof Audit) {
                continue;
            }

            $destination->append($entry->load('tags'));
        }

        return self::SUCCESS;
    }
}
```

`$primary` here is the fanout, and `stream()` delegates to the real primary — which is the point:
you read through the contract and write to one named destination.

**Which range?** If you logged `LedgerDestinationFailed`, you have `stream` and `sequence` for every
entry that was refused, and under `strict` you know the failure stopped the write, so the range is
the one between the first and last event of the outage. If you did not log them, you are guessing,
which is the argument for logging them.

**For the shipped archive**, do not hand-roll this. The manifest-driven route is
`php artisan sentinel:prune --action=archive`, which writes a released range out, proves it and only
then removes it — see [Retention and pruning](../08-lifecycle/01-retention-and-pruning.md). A cold
copy written by a fanout destination is deliberately absent from `sentinel_archives`: a row there
means a range *left* the hot table, and a manifest row for a range that is still hot would disarm the
prune's tamper guard.

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| Every request starts a new chain at sequence 1, and reads come back empty | `archive` or `memory` is first in `destinations`, so it is the primary. The list is validated for shape, not suitability | Put `database` (or your durable driver) first |
| The write succeeded but nothing is in cold storage yet | `ArchiveLedger` holds an open batch until it fills (`archive.batch`, default 1000) or the process ends | Nothing is wrong. Lower `archive.batch` if you need the file sooner, or call `seal()` yourself |
| A disk outage produced no `LedgerDestinationFailed` and no failed write | The batch was written out by the end-of-process seal, which runs inside `rescue()` — outside the fanout, after the response | Watch the application's exception handler for `ArchiveException`, not only the fanout event |
| The queue retried the job and the destination still does not have the entry | The primary already holds the capture, so `Settlement` drops the retry before `write()` is reached | Backfill the range explicitly; a retry is deduplication, not repair |
| `verifyEverything()` reports intact over zero streams | The primary cannot enumerate its chains, and the fanout satisfies `EnumeratesStreams` on its behalf by answering `[]` | Verify a named stream, or check `checked()` before trusting `isIntact()` |
| Backfilled copies have no labels | `stream()` does not eager-load the `tags` relation, and an entry without it loaded says nothing about its labels | `->load('tags')` before `append()`, or read the entry with `find()` |
| A mid-batch strict failure left the destination holding part of a batch | `writeMany()` seals the whole batch in the primary, then fans out entry by entry; the throw stops the loop | Backfill from the sequence in the first `LedgerDestinationFailed` to the batch's last sequence |
| `ConfigurationException` naming `ledger.ledgers.fanout.destinations` at boot | A destination is not a known driver name, is empty, or is `fanout` itself | Use `database`, `archive`, `memory` or `null`, or register your own driver name |
| Restoring an archived range rewrote the batch file | Not possible through `Archive\Rehydrator`, which writes through `DatabaseLedger` by name — but it is what a hand-rolled restore through `Contracts\Ledger` would do | Use `Archive\Rehydrator::restore()`, never the configured ledger, to put an archived range back |
| A search satellite is in `destinations` but `Sentinel::audits()` still refuses its filters | `supportedFilters()` is delegated to the primary; a secondary never widens the query surface | Query the satellite directly, or make it the primary and hold it to the whole contract |

---

## ✅ Best practices

✅ **Do** — put the durable, indexed driver first. The primary seals every entry and answers every
read, so it must be the destination whose chain you would rebuild the trail from.

```php
'fanout' => [
    'destinations' => ['database', 'archive'],
    'on_failure' => 'primary',
],
```

❌ **Don't** — lead with a destination. `archive` as the first entry is accepted with no error, and
then every process starts a fresh chain at sequence 1 under the same stream name, because the
archive keeps its tail on the instance.

```php
'fanout' => [
    'destinations' => ['archive', 'database'],
],
```

---

✅ **Do** — choose `on_failure` from what a missing copy means. `strict` when the copy is part of the
compliance claim; `primary` when it is a convenience whose outage must not take the application
down.

```php
// The WORM bucket is the claim. No copy, no write.
'fanout' => ['destinations' => ['database', 'worm'], 'on_failure' => 'strict'],

// The search index is a convenience. Log the gap, keep serving.
'fanout' => ['destinations' => ['database', 'search'], 'on_failure' => 'primary'],
```

❌ **Don't** — reach for `strict` because it sounds safer. It makes every secondary's availability a
hard dependency of every audited write, and under `on_write_failure = throw` that is a hard
dependency of every audited business operation.

---

✅ **Do** — listen for `LedgerDestinationFailed` under **both** policies and record the coordinates.
Under `strict` it is the only thing that names the entry that did land, and under `primary` it is the
only record that a gap exists at all.

```php
use ElPandaPe\Sentinel\Events\LedgerDestinationFailed;
use Illuminate\Support\Facades\Event;

Event::listen(function (LedgerDestinationFailed $failure): void {
    DestinationGap::record($failure->destination, $failure->stream, $failure->sequence);
});
```

❌ **Don't** — treat `primary` as fire-and-forget. Nothing counts how far behind a destination is,
nothing retries it, and every read is answered by the primary — so an empty search index looks
exactly like a quiet one.

```php
// on_failure => 'primary', no listener registered.
// The gap is real, silent, and unbounded.
```

---

✅ **Do** — load the labels before appending a backfilled entry, and ask the destination first.

```php
use ElPandaPe\Sentinel\Models\Audit;

foreach ($primary->stream('tenant:acme')->range(412, 900) as $entry) {
    if (! $destination->find($entry->id) instanceof Audit) {
        $destination->append($entry->load('tags'));
    }
}
```

❌ **Don't** — replay a range blindly. `DatabaseLedger::append()` does not retry, so the second
insert of an entry the destination already holds raises a unique-constraint violation and stops the
backfill on the row it was already fine with.

```php
foreach ($primary->stream('tenant:acme')->range(1, 100_000) as $entry) {
    $destination->append($entry); // duplicate keys, and no labels
}
```

---

✅ **Do** — let `sentinel:prune` and `Archive\Rehydrator` handle archived ranges. They go through the
manifest and through `DatabaseLedger` by name, which is what keeps a restore from overwriting the
batch it is reading.

```php
use ElPandaPe\Sentinel\Archive\Rehydrator;

// php artisan sentinel:prune --action=archive --stream=tenant:acme
app(Rehydrator::class)->restore('tenant:acme', 1, 5000);
```

❌ **Don't** — write a restored range back through `Contracts\Ledger` while a cold destination is
configured. The archive's batch path is a pure function of the range, so the secondary writes a
fresh batch over the file the restore is reading — without its operation lines.

```php
foreach ($archived as $entry) {
    app(Ledger::class)->append($entry); // fans out to the archive, over itself
}
```

---

✅ **Do** — hold every destination you wrote to the published contract suite before you put it in the
list, and give it `settle()` if its reads are eventually consistent.

```php
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Testing\LedgerContractTestCase;

final class SearchLedgerContractTest extends LedgerContractTestCase
{
    private ?Ledger $ledger = null;

    protected function ledger(): Ledger
    {
        return $this->ledger ??= app(SearchLedger::class);
    }
}
```

❌ **Don't** — assume a secondary can get away with less because it only ever sees `append()`. It
still has to keep the sequence, the hash and the labels verbatim, and the suite is what proves it
does.

```php
public function append(Audit $audit): Audit
{
    $audit->sequence = $this->next(); // two truths about one fact
    return $this->store($audit);
}
```

---

**See also:** [The Ledger contract](01-the-ledger-contract.md) · [The shipped drivers](02-shipped-drivers.md) · [Writing a ledger driver](03-writing-a-ledger-driver.md) · [The contract test suite](04-the-contract-test-suite.md) · [Failure policy](../09-operations/05-failure-policy.md) · [Events and listeners](../09-operations/04-events-and-listeners.md) · [Cold archiving](../08-lifecycle/02-cold-archiving.md) · [Rehydration](../08-lifecycle/03-rehydration.md) · [Verification](../07-integrity/06-verification.md) · [Configuration](../99-reference/02-configuration.md)
