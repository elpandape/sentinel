# 🧩 Writing a ledger driver

> How to put the audit trail in a store this package has never heard of — the skeleton, how it is
> resolved, each contract method with the reasoning behind it, and the checklist to run before you
> trust it with a chain.

**On this page:** [What a driver is judged on](#what-a-driver-is-judged-on) · [Picking a target](#picking-a-target-and-what-will-hurt) · [The skeleton](#the-skeleton) · [Resolving it](#resolving-your-driver) · [The six methods](#the-six-methods-one-at-a-time) · [Declaring capabilities](#declaring-capabilities) · [Failing correctly](#failing-correctly) · [Integrity without transactions](#integrity-when-the-store-has-no-transactions) · [Reading an entry back](#reading-an-entry-back-out) · [What stops working](#what-stops-working-when-the-trail-is-not-a-table) · [The checklist](#the-checklist)

---

## What a driver is judged on

`ElPandaPe\Sentinel\Contracts\Ledger` has six methods and makes exactly one promise, written into its
docblock: **within one stream, `sequence` is dense and monotonic, and every entry's `previous_hash`
is the previous entry's `hash`.** Everything else about your driver is your business.

Three guarantees a SQL driver could give are deliberately withheld, because a store without
transactions cannot honour them and a contract nobody can implement gets ignored:

| Not promised | What that means for you |
|---|---|
| `writeMany()` is atomic | You may return everything that settled, or throw having made a best effort to leave nothing behind. On a store with no rollback that effort is compensation, and compensation can be interrupted. |
| A read sees a write that just returned | `find()` and `stream()` may not show an entry `write()` handed back a moment ago. The contract suite has a `settle()` hook for exactly this. |
| Idempotency by `capture_id` | It belongs to the caller. A batch naming the same capture twice is a caller error; `Dispatch\Settlement` drops the repeat before the package ever hands you one. |

> 📌 **Note.** The whole `ElPandaPe\Sentinel\Ledger` namespace is marked `@internal` — every shipped
> driver included — because the configuration picks an implementation by a fixed string and no
> application ever names the class. The frozen surface you build against is `Contracts\Ledger`,
> `Contracts\LedgerStream`, the three capability interfaces, `Enums\Filter`, `Data\AuditData`,
> `Models\Audit`, `Query\AuditQuery`, `Support\AuditCollection` and `Testing\LedgerContractTestCase`.

There is a real seam here, and you should know about it before you start. A driver that **seals** its
own entries has to mint through `Ledger\EntryBuilder` and name its chain through `Integrity\Stream`,
and both are `@internal` — there is no published factory that does the job. The package's own contract
suite resolves `EntryBuilder` from the container for the same reason, so it crosses the same line. Use
them knowing they are outside the 1.0 freeze, or do not seal at all: a driver that only implements
`append()` meaningfully — a search satellite, a replica, a cold copy — never touches either class,
because it takes entries a primary already sealed. See [Fanout](05-fanout.md).

## Picking a target, and what will hurt

The worked example targets a **document index** (Elasticsearch, OpenSearch, a document database). An
object store — S3, R2, MinIO — is the same shape with different pain: reads become scans, and
`ArchiveLedger` already shows what that costs. See [The shipped drivers](02-shipped-drivers.md).

Three things are hard, and none of them is the part that looks hard:

**1. Assigning a monotonic sequence.** `DatabaseLedger` reads the tail under a write gate —
`lockForUpdate()` on the tail row, plus `pg_advisory_xact_lock(hashtext(stream))` on PostgreSQL,
which takes no row lock on a row that is not there yet — and the unique index on `(stream, sequence)`
is the final arbiter when two writers race anyway. A document store has neither. You need a store-side
operation that hands two concurrent callers two different positions: a compare-and-set on a
`streams/{name}` document holding `{sequence, hash}`, a conditional write with an `if-match` version,
or a sequence service. Optimistic retry without one is a duplicate sequence waiting to happen.

**2. Reading the tail under concurrency.** The hash covers the sequence and the previous hash, so the
tail has to be read *before* the row is built — no insert can compute its own link. On a
near-real-time store the tail you read may be stale by one entry, which is why the position and the
hash it links to must come back from the **same** conditional operation, not from a search.

**3. Answering a filter the store cannot index.** Nineteen filters are published. If your mapping
cannot narrow by, say, a changed-field JSON Pointer, you say so with `Contracts\DeclaresFilters` and
the query surface refuses that criterion at the call site. You never drop it quietly.

> ⚠️ **Warning.** A driver that silently ignores a criterion it cannot translate answers a different
> question than the one asked. A trail that shows the wrong history is worse than one that refuses to
> answer, which is why the contract suite holds every driver to one of exactly two behaviours per
> filter — translate it, or refuse it — and never to neither.

## The skeleton

`App\Search\Index` below is your own thin wrapper over the store — seven methods, of which exactly
two carry a correctness requirement:

| Method | Returns | Must guarantee |
|---|---|---|
| `claim(string $stream)` | `array{int, ?string}` | The next position and the hash it links to, from **one conditional update** on the stream document. Two concurrent callers never receive the same position |
| `put(string $id, array $document)` | `void` | Stores one document and **refuses an id that already exists** |
| `observe(string $stream, int $sequence, string $hash)` | `void` | Moves the recorded tail forward when an appended entry is beyond it |
| `document(string $id)` | `?array` | One stored document, or null |
| `documents(?string $stream = null)` | `iterable` | Every document, optionally of one stream |
| `streamNames()` | `list<string>` | The chain names, in a stable order |
| `captureIds(array $wanted)` | `list<string>` | Which of those capture identifiers are already stored |

The driver:

```php
namespace App\Sentinel;

use App\Search\Index;
use ElPandaPe\Sentinel\Contracts\DeclaresFilters;
use ElPandaPe\Sentinel\Contracts\Deduplicates;
use ElPandaPe\Sentinel\Contracts\EnumeratesStreams;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Contracts\LedgerStream;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Enums\Filter;
use ElPandaPe\Sentinel\Integrity\Stream;
use ElPandaPe\Sentinel\Ledger\ArrayQuery;
use ElPandaPe\Sentinel\Ledger\ArrayStream;
use ElPandaPe\Sentinel\Ledger\EntryBuilder;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Query\AuditQuery;
use ElPandaPe\Sentinel\Support\AuditCollection;

final class SearchLedger implements DeclaresFilters, Deduplicates, EnumeratesStreams, Ledger
{
    /** @var array<string, int> */
    private array $versions = [];

    public function __construct(
        private readonly Index $index,
        private readonly Stream $streams,
        private readonly EntryBuilder $builder,
        private readonly ArrayQuery $queries,
        private readonly EntryDocument $documents,
    ) {}

    public function write(AuditData $audit): Audit
    {
        $stream = $this->streams->resolve($audit);

        [$sequence, $previous] = $this->index->claim($stream);

        $written = $this->builder->build(
            $audit,
            $stream,
            $sequence,
            $previous,
            $this->nextVersion($audit->subject_type, $audit->subject_id),
        );

        $this->index->put($written->id, $this->documents->from($written));

        return $written;
    }

    /**
     * @param  list<AuditData>  $audits
     */
    public function writeMany(array $audits): AuditCollection
    {
        return new AuditCollection(array_map($this->write(...), $audits));
    }

    public function append(Audit $audit): Audit
    {
        $this->index->put($audit->id, $this->documents->from($audit));
        $this->index->observe($audit->stream, $audit->sequence, $audit->hash);
        $this->carryVersion($audit);

        return $audit;
    }

    public function find(string $id): ?Audit
    {
        $document = $this->index->document($id);

        return $document === null ? null : $this->documents->toAudit($document);
    }

    public function query(AuditQuery $query): AuditCollection
    {
        return new AuditCollection($this->queries->resolve($this->entries(), $query));
    }

    public function stream(string $stream): LedgerStream
    {
        return new ArrayStream($stream, $this->entries($stream));
    }

    /** @return list<string> */
    public function streams(): array
    {
        return $this->index->streamNames();
    }

    /**
     * @param  non-empty-list<string>  $captureIds
     * @return list<string>
     */
    public function settled(array $captureIds): array
    {
        return $this->index->captureIds($captureIds);
    }

    /** @return list<Filter> */
    public function supportedFilters(): array
    {
        return Filter::cases();
    }

    /** @return list<Audit> */
    private function entries(?string $stream = null): array
    {
        return array_map($this->documents->toAudit(...), [...$this->index->documents($stream)]);
    }

    private function keyOf(?string $type, ?string $id): ?string
    {
        return $type === null || $id === null ? null : $type.'|'.$id;
    }

    private function nextVersion(?string $type, ?string $id): ?int
    {
        $key = $this->keyOf($type, $id);

        return $key === null ? null : $this->versions[$key] = ($this->versions[$key] ?? 0) + 1;
    }

    private function carryVersion(Audit $audit): void
    {
        $key = $this->keyOf($audit->subject_type, $audit->subject_id);

        if ($key !== null && $audit->version !== null) {
            $this->versions[$key] = max($this->versions[$key] ?? 0, $audit->version);
        }
    }
}
```

`Ledger\ArrayQuery` resolves every published filter over entries you already hold, with the ordering
the contract suite expects; `Ledger\ArrayStream` sorts by `sequence` on construction and honours
`range()`. Both are `@internal` — reuse them knowingly, or reimplement the ordering rule below.

> 💡 **Tip.** Pulling every document into PHP to answer a query is fine for a first version and wrong
> at volume. Translate the criteria your store indexes into its own query language and use `ArrayQuery`
> only for the rest — or narrow `supportedFilters()`. A full scan pretending to be an index is the one
> answer that is not honest.

## Resolving your driver

`SentinelServiceProvider::driver()` is a closed `match` over five names. **`ledger.default` cannot
name your class** — an unrecognised value throws
`ElPandaPe\Sentinel\Exceptions\ConfigurationException` naming the accepted list.

| `ledger.default` | Resolves to | Notes |
|---|---|---|
| `database` | `Ledger\DatabaseLedger` | The default. |
| `fanout` | `Ledger\FanoutLedger` | Built from `ledger.ledgers.fanout.destinations`, which only accepts the other names here. |
| `memory` | `Ledger\MemoryLedger` | Reference implementation and test double. Never a production store. |
| `null` | `Ledger\NullLedger` | Builds, seals and chains; keeps nothing. |
| `archive` | — | Refused as `ledger.default`. It is a fanout destination or a prune target only. |
| anything else | — | `ConfigurationException`. |

So you bind the contract yourself, in your own service provider. The package registers
`Contracts\Ledger` as a **scoped** binding, and package providers register before the application's
own, so either of these wins:

```php
// app/Providers/AppServiceProvider.php — in register()
use App\Sentinel\SearchLedger;
use App\Sentinel\SearchSatellite;
use ElPandaPe\Sentinel\Contracts\Ledger;

// Replace the driver outright.
$this->app->scoped(Ledger::class, fn ($app): Ledger => $app->make(SearchLedger::class));

// …or keep whatever `ledger.default` resolved and wrap it, naming no internal class.
$this->app->extend(Ledger::class, fn (Ledger $primary, $app): Ledger => new SearchSatellite(
    $primary,
    $app->make(SearchLedger::class),
));
```

`extend()` is the route for a composite: the shipped `FanoutLedger` is built from configuration
strings and cannot be handed a class of yours, so a fan-out to a custom destination is a decorator you
write. `SearchSatellite` delegates every method to `$primary` and additionally calls `append()` on the
satellite — the same division of labour the shipped fanout enforces, where only the first destination
seals and every read goes to it.

> 📌 **Note.** Use `scoped()`, not `singleton()`. A driver that keeps a tail or a version counter on
> the instance must be forgotten between requests and between queue jobs, or a long-lived worker
> hands out numbers from a chain nobody else can see. The package binds its own ledger this way for
> that reason.

## The six methods, one at a time

### `write(AuditData $audit): Audit`

Resolve the stream, claim the next position, build the entry, store it. `EntryBuilder::build(AuditData
$data, string $stream, int $sequence, ?string $previous, ?int $version)` mints the ULID, stamps
`payload_version`, reads `integrity.algorithm` off configuration, hashes over the frozen canonical
payload, asks the signer for a signature and attaches the labels as a **loaded `tags` relation** —
never as attributes, so they stay out of `getAttributes()` and out of the hash.

The first entry of a stream carries `previous_hash = null`. Do not invent a zero-th link.

The `?int $version` argument is a counter per `(subject_type, subject_id)`, null when either half is
missing. `DatabaseLedger` derives it with `max('version')` over the rows and heals itself; a driver
that keeps a counter has to move it in `append()` too — see below.

### `writeMany(array $audits): AuditCollection`

Seal a batch. **Return the entries in the order the captures were given**, even when the batch mixes
streams: `DatabaseLedger` groups by stream to take one tail read each and then `ksort`s the result
back into arrival order. An empty batch returns an empty collection and writes nothing.

Atomicity is not required. `DatabaseLedger` does better than the contract — one transaction, one tail
read per stream, the rows split across statements at a 32 766-placeholder ceiling — but nothing holds
you to it.

### `append(Audit $audit): Audit`

Store an entry **another** ledger sealed, exactly as it arrived. Three written obligations:

1. **Assign nothing, recompute nothing.** `sequence`, `hash` and `previous_hash` are preserved
   verbatim. Two ledgers each numbering their own chain produce two truths about one fact.
2. **Keep the labels it arrived carrying.** They ride on the `tags` relation. An entry whose relation
   is *not* loaded says nothing about its labels, and storing none is the correct answer there.
3. **Move whatever numbers a subject's next entry.** A driver that derives it from what it holds gets
   this free; a driver keeping a counter that is not told hands the next `write()` a version the
   appended entry already holds — permanently, with nothing to notice it by. The contract suite has a
   case for it: append an entry at version 3, then write, and the answer must be 4.

`append()` may also be handed a range out of order — a rehydration restores whatever it reads first —
so anything you sort on read must sort by `sequence`, not by insertion.

### `find(string $id): ?Audit`

One entry by ULID, or null. Return it with its labels attached. `DatabaseLedger` eager-loads `tags`
deliberately: an unloaded subject reads as null, which is legible, while an unloaded label list reads
as an empty one, which is a false claim.

### `query(AuditQuery $query): AuditCollection`

`AuditQuery` is a description, not a builder: every criterion is a typed public property and no method
takes a column name, which is what lets a non-table driver answer the same query. Read the properties
you support and translate them.

The ordering is fixed by the contract suite and every driver must produce it:

| Aspect | Rule |
|---|---|
| Clock | `created_at`, or `occurred_at` when `$query->byOccurrence` is true |
| Direction | Ascending, unless `$query->newestFirst` |
| Tie-break | The entry identifier, in the same direction — a ULID sorts by the instant it was minted, so two entries stamped in the same microsecond still come back in write order |
| Window | `$query->offset` is applied **before** `$query->limit` |
| Cursor | `$query->after` narrows to identifiers greater than the cursor |

> ⚠️ **Warning.** `after()` combined with `latest()` narrows by `id > cursor` while walking
> newest-first, on `DatabaseLedger` and `ArrayQuery` alike. Nothing in the contract suite covers the
> combination and it is unlikely to be what a caller meant. Do not build a resumable descending walk
> on it.

`Query\AuditQuery::get()` refuses rather than truncating once more than `DEFAULT_LIMIT` (500) entries
match — the probe and the reasoning are in
[Order, paging and walking the trail](../06-reading/03-order-paging-and-walking.md#how-much-comes-back).
Your driver gets that behaviour for free by honouring `limit`; the contract suite checks it.

### `stream(string $stream): LedgerStream`

A walkable, bounded, resumable view of one chain in ascending `sequence` order. It is what
`Integrity\Verifier` reads, so it is the method the whole [verification](../07-integrity/06-verification.md)
path rests on.

`Contracts\LedgerStream` has three members: `name()`, `range(int $from, ?int $to = null): static` and
`getIterator()`. Bounds are inclusive on both ends, `$to = null` means "to the end", and **`range()`
must return a new instance and leave the original untouched**. The verifier calls `range()` with
bounds that may match nothing — including a single-entry range before the start of a chain — so an
empty walk is the right answer there, never an exception.

For a store you can page, `Ledger\DatabaseStream` is the shape to copy: a keyset cursor on `sequence`
that fetches a fixed chunk and stops when a short page comes back. A ten-million-entry chain must not
be materialised to be walked.

## Declaring capabilities

Three interfaces are opt-in. Adding one is always additive and never breaks a driver that does not
implement it.

| Interface | Method | Declare it when | If you do not |
|---|---|---|---|
| `Contracts\Deduplicates` | `settled(array $captureIds): array` | Your store can look a capture up **reliably** | `Dispatch\Settlement`, `Import\Importer` and `Security\Rekeyer` degrade gracefully — a retry costs a sealed chain thrown away instead of one query |
| `Contracts\EnumeratesStreams` | `streams(): array` | You can name your chains, in a **stable** order | `Sentinel::verifyEverything()` and any command run without `--stream` throw `QueryException::cannotEnumerateStreams()` rather than report a reassuring empty result |
| `Contracts\DeclaresFilters` | `supportedFilters(): array` | Your backend cannot translate all nineteen | You are taken to answer `Filter::assumed()` — the nine of v0.9.0 — and **that set never grows** |

`Filter::assumed()` is `Subject`, `Actor`, `Event`, `Severity`, `Source`, `Tenant`, `Transaction`,
`Trace`, `Period`. A filter published after that set is answered only by a driver that names it — so a
driver that really does answer everything must return `Filter::cases()` and say so, and a driver that
answers four says four:

```php
use ElPandaPe\Sentinel\Enums\Filter;
use ElPandaPe\Sentinel\Exceptions\LedgerException;
use ElPandaPe\Sentinel\Facades\Sentinel;

/** @return list<Filter> */
public function supportedFilters(): array
{
    return [Filter::Subject, Filter::Actor, Filter::Tenant, Filter::Period];
}

// The refusal lands as the criterion is added, not when the query runs:
try {
    Sentinel::audits()->forTenant('acme')->whereTag('billing');
} catch (LedgerException $e) {
    // "App\Sentinel\SearchLedger cannot filter by tag, so whereTag() is not part of
    //  the query it answers."
}
```

> 🔒 **Security.** Do not declare `Deduplicates` on a store whose lookup can miss. Answering "not
> settled" when the answer is "settled" writes the same fact twice. `ArchiveLedger` refuses to declare
> it for precisely this reason: a capture-id lookup over batch files is a scan with no index behind
> it. Answering is not guaranteeing — the unique index on `capture_id` remains the arbiter.

## Failing correctly

| Situation | What to do | What the caller sees |
|---|---|---|
| A criterion you never declared | Nothing — `AuditQuery` refuses it before it reaches you | `LedgerException`, which extends `BadMethodCallException` and is therefore **not** caught by `catch (RuntimeException)` |
| A criterion you declared but cannot answer on this backend | Throw `LedgerException::cannotTranslateOn()` from `query()` | A refusal at query time rather than at criterion time. `DatabaseLedger` does this for the JSON predicates on an engine that is not mysql, pgsql or sqlite |
| The store refuses the write | Let it out. Do not swallow it | The package's `on_write_failure` policy decides: propagate, or announce and log. Either way `Events\AuditWriteFailed` fires |
| A lost race on the sequence | Re-read the tail and take the next position, a **bounded** number of times | `DatabaseLedger` retries at most three times and then rethrows |
| A duplicate `capture_id` inside a retry | Drop the settled captures before replaying, never replay them | Replaying seals the same chain again. A batch with nothing left is handed back its own violation rather than a silence the caller reads as success |
| A stream name over 64 characters, or empty | Nothing — `Integrity\Stream` guards it | `ConfigurationException`, raised on the write path. The name is never truncated |

> 📌 **Note.** Exception families in this package are not what you would guess. `LedgerException`,
> `QueryException` and `ConfigurationException` are all in the `LogicException` family, while the
> failures of the moment — `ArchiveException`, `ImportException`, `EncryptionException`,
> `SignatureException` and the rest — extend `RuntimeException`. See
> [Exceptions](../99-reference/06-exceptions.md).

## Integrity when the store has no transactions

The chain is not protected by your store. It is protected by the order in which you do two things.

**Store the entry before you advance anything that tracks the tail.** If the process dies between the
two, the other order has burned a sequence number and the gap in the chain is permanent — and a gap is
reported by `sentinel:verify` as `sequence_gap`, forever, with nothing to explain it. Losing a write
is recoverable; burning a position is not.

**Claim the position and read the link in one operation.** A read-then-write pair is a race. The
shipped database driver survives that race only because a unique index exists to refuse the loser; you
have no such index, so the claim itself has to be conditional.

**Do not compensate by rewriting.** History is append-only through every driver: nothing in this
package deletes, rewrites or reorders an entry, and a restore writes a *new* entry describing the
restoration. A driver that "fixes" a chain by renumbering has destroyed the only property the package
sells.

**Anchoring is a separate opt-in and it is not yours to run.** `DatabaseLedger` issues an anchor
per touched stream after its sealing transaction commits, when `integrity.checkpoints.enabled` is
true. A driver over another store need not implement it; the trail is still chained, because chaining
is unconditional and has no off switch. See
[Checkpoints and anchors](../07-integrity/05-checkpoints-and-anchors.md).

> 🧪 **Verify it.** The one test that catches almost every hydration and ordering mistake at once:
> write a handful of entries, read them back through your own `find()`, and call
> `$audit->verifyIntegrity()` on each. `false` means the entry no longer reproduces its own hash, and
> the trail would be reported as tampered.

## Reading an entry back out

Your store hands back a shape; `Models\Audit` expects columns. The hazard is that Eloquent's set casts
encode `context`, `before`, `after`, `changes`, `metadata`, `encryption` and `criteria` as JSON, and
encoding an already-encoded value a second time breaks the hash — which verification then reports,
correctly, as tampering.

| Your store hands back | Use | Why |
|---|---|---|
| The exact column text a database would hold (JSON already a string) | `setRawAttributes($columns)` | It skips the set casts entirely |
| Decoded structures (a document store returning nested objects) | `forceFill($columns)` | The casts encode them once, which is what the column needs. `Archive\Line::toAudit()` does this |

Either way, finish the job the same way:

```php
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Models\AuditTag;
use Illuminate\Database\Eloquent\Collection;

final readonly class EntryDocument
{
    public function __construct(private Audit $model) {}

    /** @param array<string, mixed> $document */
    public function toAudit(array $document): Audit
    {
        $audit = $this->model->newInstance();
        $audit->forceFill(array_diff_key($document, ['tags' => null]));
        $audit->setRelation('tags', new Collection(array_map(
            static fn (string $tag): AuditTag => new AuditTag(['audit_id' => $audit->id, 'tag' => $tag]),
            $document['tags'] ?? [],
        )));
        $audit->exists = true;
        $audit->syncOriginal();

        return $audit;
    }
}
```

Its mirror, `from(Audit $audit): array`, writes the columns and the label list out again;
`Archive\Line::entry()` is the shape to copy, down to the assertion that a line's key set is an
entry's column set plus `tags`. Three details that are easy to miss:

- **Drop anything that is not a column** before the fill. `Audit::getGuarded()` is empty and there is
  no mutator for `tags`, so a stray key would have an insert name a column that does not exist.
- **Timestamps** are stored at microsecond precision: `Audit::getDateFormat()` is `'Y-m-d H:i:s.u'`,
  which is also `Integrity\CanonicalPayload::DATE_FORMAT`. Truncating to seconds changes the hash.
- **`exists` differs by driver.** `DatabaseLedger` returns entries with `exists` and
  `wasRecentlyCreated` true; `MemoryLedger` and `NullLedger` return `exists = false` because nothing
  went through Eloquent's insert path. Code that branches on it behaves differently per driver, so
  set it deliberately.

## What stops working when the trail is not a table

Not everything in the package goes through `Contracts\Ledger`. Know what you are giving up:

| Feature | Route | On a non-database driver |
|---|---|---|
| `Sentinel::audits()` and the timeline | `Ledger::query()` | Works — it is your `query()` |
| `sentinel:verify`, `verifyIntegrity()`, `verifyEverything()` | `Ledger::stream()`, `Ledger::streams()` | Works, given `EnumeratesStreams` for the whole-trail form |
| `sentinel:import`, `sentinel:rekey` | `Ledger::write()` / `settled()` | Works |
| `sentinel:prune` | The tables directly; the ledger only names the streams | **Does not see your entries** |
| Cold archiving and rehydration | The tables and the manifest | **Does not see your entries.** `Archive\Rehydrator` names `DatabaseLedger` on purpose, so a rehydration always lands in the hot table |
| `sentinel:show`, `sentinel:redact` | `Audit::newQuery()->find($id)` | **Does not see your entries** |
| Checkpoints and anchors | `sentinel_checkpoints` and the audits table | **Does not see your entries** |

This is the strongest argument for running a custom driver as a **secondary** rather than as
`ledger.default`: the database keeps the chain and the lifecycle tooling, your store gets a copy for
the queries it is good at. See [Fanout](05-fanout.md).

## The checklist

Run this before a single production entry lands anywhere but a table.

1. **The first entry has `previous_hash = null`** and `sequence = 1`, and the second links to the
   first. No invented zero-th link.
2. **Two streams number independently.** Writing to `alpha` then `beta` gives `beta` sequence 1.
3. **`writeMany()` returns entries in the order it was given them**, across mixed streams, and an
   empty batch writes nothing.
4. **A hostile concurrency run.** Two processes writing the same stream at once, a few thousand
   entries, then `Sentinel::verifyIntegrity($stream)` — dense, monotonic, no gaps, no duplicates. This
   is the test that finds a non-conditional claim, and it will not fail on one process.
5. **Kill a writer between the store and the tail advance.** The chain must be short, never gapped.
6. **`append()` preserves `sequence`, `hash`, `previous_hash` and labels**, and moves the subject
   version counter: append at version 3, write, get 4.
7. **Every entry you read back answers `true` to `$audit->verifyIntegrity()`.**
8. **Every published filter either narrows correctly or throws `LedgerException`** — never returns
   entries nobody asked for.
9. **`range()` returns a new instance**, is inclusive on both ends, and answers an out-of-range
   window with an empty walk.
10. **Run the published contract suite.** `Testing\LedgerContractTestCase` ships in `src/` as
    production code, not as a dev dependency, precisely so you can. It carries 29 test methods, one of
    them driven by a 19-row provider that covers seventeen of the nineteen published filters —
    `Filter::Period` and `Filter::After` get cases of their own.

```php
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Testing\LedgerContractTestCase;

final class SearchLedgerContractTest extends LedgerContractTestCase
{
    private ?Ledger $ledger = null;

    // Memoized: asking() builds a fresh AuditQuery from ledger(), so the suite calls this more
    // than once per test and a driver holding state must hand back the same instance.
    protected function ledger(): Ledger
    {
        return $this->ledger ??= app(SearchLedger::class);
    }

    // Called between a write and the read that checks it. Honouring the contract, not evading it:
    // the contract promises no read sees a write that just returned.
    protected function settle(Ledger $ledger): void
    {
        $this->index->refresh();
    }
}
```

It needs `phpunit/phpunit` and `orchestra/testbench`, both declared in the package's `suggest`. The
base class boots its own Testbench application, registers `SentinelServiceProvider` and pins `app.key`
so digest assertions are reproducible, and it touches nothing under the package's own `tests/`. Full
detail in [The contract test suite](04-the-contract-test-suite.md).

> 📌 **Note.** Two drivers sealing the same `AuditData` produce **different** hashes, by construction:
> `EntryBuilder` mints a fresh ULID on every seal and `id` is inside the canonical payload. What is
> comparable across drivers is verification, never the digest.

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `sentinel:verify` reports `hash_mismatch` on entries that were never touched | You hydrated with `forceFill()` from a store that hands back already-encoded JSON, so the casts encoded it twice | Use `setRawAttributes()` for raw column text; keep `forceFill()` for decoded structures |
| `sequence_gap` at a position nothing was ever written to | The tail was advanced before the entry was stored, and the process died in between | Store first, advance second. The gap is permanent once it exists |
| Two entries share a `sequence` in one stream | The position was claimed with a read-then-write instead of a conditional operation | Make `claim()` a compare-and-set, or add a unique constraint your store can enforce |
| `whereTag()` throws `LedgerException` on a driver you believe answers it | The driver does not implement `Contracts\DeclaresFilters`, so it is taken to answer only the nine of `Filter::assumed()`, which does not include `Tag` | Implement `DeclaresFilters` and name every filter you translate |
| `catch (RuntimeException)` around a query does not catch a refused filter | `LedgerException` extends `BadMethodCallException`, a `LogicException` | Catch `LedgerException`, or fix the query |
| `verifyEverything()` throws instead of returning a report | The driver does not implement `Contracts\EnumeratesStreams` | Implement it, or verify one named stream at a time |
| A subject's `version` repeats after a rehydration or an import | The driver keeps a counter and `append()` does not move it | Take `max(existing, $audit->version)` in `append()` |
| Half the contract suite reads from an empty ledger | `ledger()` returns a fresh instance each call, and it is called more than once per test | Memoize: `$this->ledger ??= …` |
| The contract suite passes but `sentinel:prune` prunes nothing | Retention, archiving, redaction and `sentinel:show` address the tables directly, not the contract | Run your driver as a fanout secondary with the database as primary |
| Labels vanish on entries taken from another ledger | `append()` stored the entry and dropped the `tags` relation | Persist the loaded relation; store none only when the relation is not loaded |
| An entry written with 500 more like it comes back as `QueryException` | `AuditQuery::get()` refuses once more than `DEFAULT_LIMIT` (500) match, rather than returning a prefix shaped like a complete answer | Use `take()` for a deliberate prefix or `paginate()` to walk past it |

## ✅ Best practices

✅ **Do** — claim the position and the link it hangs off in one conditional store operation, and store
the entry before anything that tracks the tail moves. A crash then costs a write, not a permanent gap.

```php
[$sequence, $previous] = $this->index->claim($stream);   // conditional: no two callers agree
$written = $this->builder->build($audit, $stream, $sequence, $previous, $version);
$this->index->put($written->id, $this->documents->from($written));   // stored, then tracked
```

❌ **Don't** — read the tail, then write, then advance a counter. Two writers read the same tail and
claim the same position, and a process that dies after the counter moves has burned a sequence number
that nothing will ever fill.

```php
$tail = $this->index->tailOf($stream);          // a read
$this->index->setTail($stream, $tail + 1);      // a burned position if the next line throws
$this->index->put($id, $this->documents->from($written));
```

✅ **Do** — declare exactly the filters you translate. A narrow, honest set gets a refusal at the call
site, which the developer sees immediately.

```php
/** @return list<Filter> */
public function supportedFilters(): array
{
    return [Filter::Subject, Filter::Actor, Filter::Tenant, Filter::Period, Filter::Tag];
}
```

❌ **Don't** — implement nothing and assume the query surface will figure it out. A driver with no
`DeclaresFilters` is taken to answer the nine of `Filter::assumed()`, and that set never grows — so
your `whereTag()` and `whereRoute()` are accepted by the query and then quietly ignored by you.

```php
final class SearchLedger implements Ledger   // no DeclaresFilters, so nine filters are assumed
{
    public function query(AuditQuery $query): AuditCollection
    {
        return new AuditCollection($this->narrowBySubject($query));   // $query->tags dropped in silence
    }
}
```

✅ **Do** — move the per-subject version counter when you take an entry you did not number. It is the
one obligation in `append()` that has no symptom until months later.

```php
$this->index->put($audit->id, $this->documents->from($audit));
$this->index->observe($audit->stream, $audit->sequence, $audit->hash);
$this->carryVersion($audit);   // max(existing, $audit->version)
```

❌ **Don't** — treat `append()` as a second `write()`. Assigning your own sequence or recomputing the
hash produces two chains for one fact, and neither of them is wrong in a way any verification can see.

```php
[$sequence] = $this->index->claim($audit->stream);   // a second chain starts here
$audit->sequence = $sequence;
$audit->hash = $this->hasher->hash($audit);
```

✅ **Do** — implement `settle()` in your contract-suite subclass when your reads are eventually
consistent, and answer `retains(): false` if your driver keeps nothing. Both are statements about what
your driver is.

```php
protected function settle(Ledger $ledger): void
{
    $this->index->refresh();   // make what was just written visible
}
```

❌ **Don't** — reach for `markTestSkipped()` to get past an expectation you do not like. `retains()`
and `settle()` change *what* is asserted; skipping changes *whether* anything is, and a driver that
answers `retains(): false` is then held to keeping nothing just as strictly.

```php
public function test_it_finds_what_it_wrote(): void
{
    $this->markTestSkipped('our index is async');   // the contract now covers nothing here
}
```

✅ **Do** — run a custom driver as a fanout secondary with the database as primary when you want the
package's lifecycle tooling to keep working. The primary seals and answers every read; your store gets
the copy it is good at querying.

```php
$this->app->extend(Ledger::class, fn (Ledger $primary, $app): Ledger => new SearchSatellite(
    $primary,
    $app->make(SearchLedger::class),
));
```

❌ **Don't** — point `ledger.default` at a custom name and expect it to resolve. The provider matches
five fixed strings and throws `ConfigurationException` on anything else; the binding is the extension
point, not the config value.

```php
// config/sentinel.php — throws: "archive, database, fanout, memory, null"
'ledger' => ['default' => 'search'],
```

---

**See also:** [The Ledger contract](01-the-ledger-contract.md) · [The shipped drivers](02-shipped-drivers.md) · [The contract test suite](04-the-contract-test-suite.md) · [Fanout: writing to more than one place](05-fanout.md) · [Swapping components](06-swapping-components.md) · [The hash chain](../07-integrity/01-the-hash-chain.md) · [Streams](../07-integrity/02-streams.md) · [Canonicalization](../07-integrity/03-canonicalization.md) · [Verification](../07-integrity/06-verification.md) · [The Query API](../06-reading/01-the-query-api.md) · [Filters reference](../06-reading/02-filters-reference.md) · [Exceptions](../99-reference/06-exceptions.md) · [API stability](../99-reference/09-api-stability.md)
