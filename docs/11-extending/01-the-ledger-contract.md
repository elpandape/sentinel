# 🧩 The Ledger contract

> The six-method seam every audit entry passes through on its way to storage: what each method
> promises, what a driver must guarantee, and what the contract deliberately refuses to require.

**On this page:** [The one guarantee](#the-one-guarantee) · [The six methods](#the-six-methods) · [Sequence and the tail](#sequence-assignment-and-the-tail) · [Walking a stream](#walking-a-stream) · [Capability interfaces](#the-capability-interfaces) · [What is not required](#what-the-contract-deliberately-does-not-require) · [Pitfalls](#-pitfalls) · [Best practices](#-best-practices)

---

## The one guarantee

`ElPandaPe\Sentinel\Contracts\Ledger` is where a capture stops being data the application produced
and becomes an entry in a chain. Everything before it — the observers, the resolvers, the write
pipeline — decides *what* the entry says. The ledger decides *where it sits*, and that is the only
thing it is judged on:

> 📌 **Note.** Within one stream, `sequence` is dense (no gaps) and monotonic, and every entry's
> `previous_hash` is the `hash` of the entry before it. The first entry of a stream has
> `previous_hash = null` — no zero-th link is invented.

That sentence is the whole contract. It is written in the docblock of `src/Contracts/Ledger.php`,
it is what `Testing\LedgerContractTestCase` asserts, and it is what `Sentinel::verifyIntegrity()`
walks. A driver that honours it over a document store, an append-only file or a message log is as
correct as `Ledger\DatabaseLedger`; a driver that breaks it while writing to PostgreSQL is not.

| Guaranteed by the contract | Explicitly **not** guaranteed |
|---|---|
| `sequence` is dense and monotonic per stream | `writeMany()` is atomic |
| Each entry links to the one before it by hash | A read sees a write that just returned |
| The first entry of a stream links to nothing | Idempotency by `capture_id` |
| `append()` stores what it was given, unchanged | Any two drivers produce the same `hash` for the same capture |
| Ordering and paging of `query()` results | That the store is a table, or has transactions |

The three withheld guarantees are withheld on purpose. A store with no rollback cannot make a batch
atomic; a near-real-time index cannot promise read-your-writes; a store with no reliable lookup
cannot deduplicate. A contract nobody can implement is a contract that gets ignored, so the contract
asks for the chain and nothing else.

### Where the contract sits

Capture builds a `Data\AuditData`. The write pipeline transforms it. `Dispatch\Settlement` then hands
it to the ledger — `writeMany()` for a batch, `write()` for a single entry — and that call is the
only place an entry acquires a stream, a sequence, a link and a hash. See
[The write path](../01-concepts/03-the-write-path.md).

The container binding is `scoped`, registered by `SentinelServiceProvider` against the
`sentinel.ledger.default` config string. The driver classes under `ElPandaPe\Sentinel\Ledger` are all
marked `@internal`: the published surface for this area is `Contracts\*`, `Enums\Filter`,
`Enums\FanoutPolicy`, `Events\LedgerDestinationFailed`, `Exceptions\LedgerException`,
`Testing\LedgerContractTestCase` and the config keys. Resolve `Contracts\Ledger`, never a concrete
driver. See [API stability](../99-reference/09-api-stability.md).

---

## The six methods

```php
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Contracts\LedgerStream;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Query\AuditQuery;
use ElPandaPe\Sentinel\Support\AuditCollection;

interface Ledger
{
    public function write(AuditData $audit): Audit;

    /** @param list<AuditData> $audits */
    public function writeMany(array $audits): AuditCollection;

    public function append(Audit $audit): Audit;

    public function find(string $id): ?Audit;

    public function query(AuditQuery $query): AuditCollection;

    public function stream(string $stream): LedgerStream;
}
```

Six, and no more. `tests/Contracts/ContractsTest.php` pins the method list so the surface cannot
drift under a driver that was written against it.

| Method | Assigns a sequence? | Computes a hash? | Reads the store? |
|---|---|---|---|
| `write()` | yes | yes | the tail of one stream |
| `writeMany()` | yes, one per capture | yes, one per capture | the tail of each touched stream |
| `append()` | **no** | **no** | nothing it has to |
| `find()` | no | no | yes |
| `query()` | no | no | yes |
| `stream()` | no | no | lazily, while walking |

### `write(AuditData $audit): Audit`

**Pre-conditions.** A fully populated `AuditData`. The pipeline has already run, so masked, hashed
and encrypted fields are already in their final form. The caller has decided nothing about the
chain — the stream, the sequence, the link and the hash all belong to the ledger.

**Post-conditions.** The returned `Audit` carries a freshly minted ULID `id`, its `stream`,
`sequence`, `previous_hash`, `hash`, `payload_version`, `algorithm` and its per-subject `version`,
plus its labels as a **loaded `tags` relation** — not as attributes, which is what keeps them out of
`getAttributes()` and out of the canonical payload the hash covers.

**Not promised.** That a `find()` issued immediately afterwards returns it.

`Ledger\DatabaseLedger` hands back an entry with `exists = true` and `wasRecentlyCreated = true`,
because the row went through Eloquent's insert path. `Ledger\MemoryLedger` and `Ledger\NullLedger`
hand back `exists = false`. Nothing in the contract picks a side, so application code must not branch
on `$audit->exists` after a write.

### `writeMany(array $audits): AuditCollection`

**Pre-conditions.** A `list<AuditData>`. No two captures in the batch may name the same `capture_id`:
a driver seals both and the unique index refuses them together, taking the whole batch down.
`Dispatch\Settlement` drops the repeat before the package's own path ever hands a ledger one.

**Post-conditions.** An `AuditCollection` holding the sealed entries **in the order the captures were
given**, even when the batch mixes streams. An empty batch returns an empty collection and writes
nothing.

**Not promised.** Atomicity. `DatabaseLedger` does better than the contract — one transaction, one
tail read per stream — but a driver is not held to that, and a caller must not assume it.

### `append(Audit $audit): Audit`

The method that makes fan-out, archiving, replication, import and rehydration possible without
producing two chains for one fact. It takes an entry **another ledger already sealed** and stores it
exactly as it arrived.

Three written obligations, all three asserted by the contract suite:

| Obligation | What goes wrong without it |
|---|---|
| Preserve `sequence`, `hash` and `previous_hash` verbatim | Two ledgers each numbering their own chain produce two truths about one fact |
| Keep the labels the entry arrived carrying | An entry stored without them is not the entry that arrived |
| Move whatever numbers a subject's next entry | The next `write()` for that subject hands out a `version` the appended entry already holds — permanently, with nothing to notice it by |

An entry whose `tags` relation is **not loaded** says nothing about its labels; storing none is the
correct answer there, not a bug.

The version obligation is free for a driver that derives the number from what it holds —
`DatabaseLedger` reads `max('version')` back out of the rows, so it heals itself. A driver keeping a
counter has to be told. `Ledger\SubjectVersions` exists for exactly this and exposes `next()` for a
write and `seen()` for an append:

```php
public function append(Audit $audit): Audit
{
    $this->entries[$audit->stream][] = $audit;
    $this->versions->seen($audit);   // max(existing, $audit->version)

    return $audit;
}
```

Inside the package, the only production caller of `append()` outside the drivers themselves is
`Archive\Rehydrator`, which puts an archived range back into the hot table.

### `find(string $id): ?Audit`

**Pre-conditions.** A ULID string.

**Post-conditions.** The entry, or `null`. An unknown id returns `null` — it must not throw; the
contract suite asserts it for a well-formed id nothing was ever written under.

Return the entry with its labels loaded if you can. `DatabaseLedger` eager-loads `tags` deliberately:
an unloaded subject reads as `null`, which is legible, but an unloaded label list reads as an empty
one, which is a claim the entry carries no labels.

### `query(AuditQuery $query): AuditCollection`

**Pre-conditions.** An `AuditQuery` constructed against *this* ledger. Its constructor snapshots
`Filter::answeredBy($ledger)`, so by the time a query reaches `query()` every criterion on it is one
this driver said it could translate.

**Post-conditions.** Everything the criteria match, ordered by `created_at` — or by `occurred_at`
when the query set `byOccurrence` — ascending unless `newestFirst`, with the entry identifier as the
tie-break. Because a ULID sorts by the instant it was minted, two entries stamped in the same
microsecond come back in write order on every driver. `offset` is applied before `limit`.

**Must not.** Silently drop a criterion it cannot translate. That is the one failure the whole
`DeclaresFilters` mechanism exists to prevent.

See [The Query API](../06-reading/01-the-query-api.md) and the
[filters reference](../06-reading/02-filters-reference.md).

### `stream(string $stream): LedgerStream`

**Post-conditions.** A `Contracts\LedgerStream` over that chain, in ascending `sequence`. A name the
ledger holds nothing under yields an **empty walk** — never `null`, never an exception. It is what
`Integrity\Verifier` reads.

---

## Sequence assignment and the tail

These are the two hard parts of writing a ledger, and they are hard for one reason: the hash covers
them.

`Integrity\Hasher` digests a prefix of `payload_version ␟ stream ␟ sequence ␟ previous_hash`,
followed by the canonicalized payload of the 27 frozen columns. The sequence and the link are
**inputs** to the hash, not columns filled in afterwards. So:

> ⚠️ **Warning.** No INSERT can compute its own link. The tail of the stream has to be read before
> the entry is built, which means every writer of a stream must be serialized across the gap between
> reading the tail and storing what it built.

### What the tail is

Two values: the `sequence` of the last entry in the stream, and its `hash`. A driver either derives
them from what it holds — `DatabaseLedger` reads the top row of the stream under a lock — or keeps
them in a small structure. `Ledger\StreamTail` is that structure: one per stream, `sequence 0` and a
null hash for a chain that has not started. `Ledger\NullLedger` keeps one even though it stores
nothing else, and `Ledger\ArchiveLedger` keeps one per stream on the instance.

Keeping the tail, not the entries, is the point: turning auditing off must not grow with the traffic
it is refusing to record.

### Serializing the writers

How a driver serializes is its own business; what it cannot do is skip it. The shipped SQL driver
shows the shape:

| Engine | How the stream is held | Why |
|---|---|---|
| PostgreSQL | `pg_advisory_xact_lock(hashtext(<stream>))` on the **name**, then read the tail | No row lock covers a stream with no rows yet |
| MySQL | `lockForUpdate()` on the tail row | InnoDB's gap lock covers the first write of a stream |
| SQLite | `lockForUpdate()` is emitted and ignored | The engine serializes writes to the whole database itself |

An advisory lock serializes the writers that ask for it, not the ones that insert on their own. That
is why the unique index on `(stream, sequence)` is the real arbiter and why `DatabaseLedger` retries
a bounded number of times on a unique violation rather than treating the lock as sufficient. See
[The hash chain](../07-integrity/01-the-hash-chain.md) and
[Choosing an engine](../10-database-engines/01-choosing-an-engine.md).

### The order that matters

> ⚠️ **Warning.** Store the entry **before** you advance whatever tracks the tail. The other order
> burns a sequence number if the process dies in between, and the gap it leaves in the chain is
> permanent — verification will report it forever, correctly.

### Resolving the stream name

The stream is resolved by `Integrity\Stream` from the capture, before the tail is read:

| `integrity.stream` | Name produced |
|---|---|
| `global` | `global` |
| `tenant` (default) | `tenant:{id}`, or `global` when the capture has no tenant |
| `subject_type` | `type:{morph alias}`, or `global` when the capture has no subject |
| a `Closure` | whatever it returns; incompatible with `config:cache` |
| a `Contracts\StreamResolver` class-string | whatever `resolve()` returns; survives `config:cache` |

An `AuditData` that already carries a `stream` overrides the strategy entirely. Whatever the source,
the name must be non-empty and at most **64 characters** — the column width. A longer name raises
`ConfigurationException::streamTooLong()`; it is never truncated.

`Contracts\StreamResolver` is the published, cacheable form:

```php
use ElPandaPe\Sentinel\Contracts\StreamResolver;
use ElPandaPe\Sentinel\Data\AuditData;

final readonly class RegionStream implements StreamResolver
{
    public function resolve(AuditData $audit): string
    {
        return 'region:'.($audit->context['region'] ?? 'unknown');
    }
}
```

Changing the strategy on a live installation does not rewrite anything: the name enters the hash
prefix, so history simply splits into independent chains from that point on, each numbered from 1.
See [Streams](../07-integrity/02-streams.md).

---

## Walking a stream

```php
use ElPandaPe\Sentinel\Contracts\LedgerStream;

interface LedgerStream extends IteratorAggregate
{
    public function name(): string;

    public function range(int $from, ?int $to = null): static;

    public function getIterator(): Traversable;   // yields Audit in ascending sequence
}
```

| Rule | Detail |
|---|---|
| Order | ascending `sequence`, always — not insertion order |
| Bounds | inclusive on both ends |
| `$to = null` | to the end of the chain |
| `range()` | returns a **new** instance; the original is left untouched |
| Unknown stream | an empty walk |

`range()` being non-mutating is asserted, not merely intended: a stream handed to a verifier and then
bounded by it must not narrow for whoever handed it over.

Sorting on construction rather than trusting insertion order is not pedantry. `append()` takes
entries another ledger sealed, and a rehydration restores a range in whatever order it reads it — a
walk that yielded them as inserted would read as a chain nobody wrote.

The two shipped implementations show the two shapes: `Ledger\ArrayStream` sorts a list it already
holds; `Ledger\DatabaseStream` pages the table in chunks, carrying a `sequence` cursor, so walking a
ten-million-entry chain does not load it.

---

## The capability interfaces

Three questions a SQL driver answers easily and a driver over something else may not be able to
answer at all. Each is a separate, opt-in interface rather than a method on `Contracts\Ledger`,
because adding a method to a published contract would break every driver that never needed it.

| Interface | Question | Who asks | Not declared ⇒ |
|---|---|---|---|
| `Contracts\DeclaresFilters` | Which published filters can I translate? | `Query\AuditQuery`, in its constructor | You are taken to answer `Filter::assumed()` — nine filters — and every other one is refused |
| `Contracts\Deduplicates` | Which of these capture ids have I already settled? | `Dispatch\Settlement`, `Import\Importer`, `Security\Rekeyer` | They skip the question and write; the unique index on `capture_id` still refuses the duplicate |
| `Contracts\EnumeratesStreams` | Which chains do I hold? | `Integrity\Verifier::verifyEverything()`, and any command run without `--stream` | They throw `QueryException::cannotEnumerateStreams()` naming your class |

Adding any of them to an existing driver is additive and breaks nothing.

### `DeclaresFilters` — say what you answer, refuse the rest

```php
use ElPandaPe\Sentinel\Contracts\DeclaresFilters;
use ElPandaPe\Sentinel\Enums\Filter;

/** @return list<Filter> */
public function supportedFilters(): array
{
    return [Filter::Subject, Filter::Actor, Filter::Tenant, Filter::Period];
}
```

The refusal lands **as the criterion is added**, not when the query runs:

```php
use App\Models\Invoice;
use ElPandaPe\Sentinel\Exceptions\LedgerException;
use ElPandaPe\Sentinel\Facades\Sentinel;

try {
    Sentinel::audits()->for(Invoice::class, 500)->whereTag('billing');
} catch (LedgerException $e) {
    // "App\Sentinel\SearchLedger cannot filter by tag,
    //  so whereTag() is not part of the query it answers."
}
```

> ⚠️ **Warning.** `Exceptions\LedgerException` extends `BadMethodCallException`, which is a
> `LogicException`. A `catch (RuntimeException)` around a query will **not** see a refused filter.

The nineteen published cases, and which nine a driver that declares nothing is taken to answer:

| `Filter` case | `AuditQuery` method | In `Filter::assumed()` |
|---|---|---|
| `Subject` | `for()` | ✅ |
| `Actor` | `by()` | ✅ |
| `Event` | `whereEvent()` | ✅ |
| `Severity` | `whereSeverity()` | ✅ |
| `Source` | `whereSource()` | ✅ |
| `Tenant` | `forTenant()` | ✅ |
| `Transaction` | `inTransaction()` | ✅ |
| `Trace` | `withTrace()` | ✅ |
| `Period` | `between()` | ✅ |
| `Tag` | `whereTag()` | ❌ |
| `FieldChanged` | `whereFieldChanged()` | ❌ |
| `Version` | `whereVersion()` | ❌ |
| `Relation` | `whereRelation()` | ❌ |
| `Related` | `whereRelated()` | ❌ |
| `Operation` | `whereOperation()` | ❌ |
| `Type` | `whereType()` | ❌ |
| `Ip` | `whereIp()` | ❌ |
| `Route` | `whereRoute()` | ❌ |
| `After` | `after()` | ❌ |

> 📌 **Note.** `Filter::assumed()` is the set as it stood in v0.9.0 and **it does not grow**. A driver
> written against that surface never named the filters published later, so assuming it can translate
> them would have it quietly dropping a criterion. Every filter published from v0.10.0 on is answered
> only by a driver that names it.

`Filter::Ip` and `Filter::Route` carry the key they read inside `context` as their value, so a driver
translating them into its own JSON dialect has the path without a second mapping. `Filter::After` is
not a criterion about an entry but a place in a walk: a driver that cannot order by the identifier
cannot honour it, which is why it is declared like the rest rather than assumed.

### `Deduplicates` — answering is not guaranteeing

```php
use ElPandaPe\Sentinel\Contracts\Deduplicates;

/**
 * @param  non-empty-list<string>  $captureIds
 * @return list<string>
 */
public function settled(array $captureIds): array;
```

It returns the subset already stored. What makes the write itself idempotent is the unique index on
`capture_id`; this exists so a retry costs one query instead of one sealed chain thrown away.

> 🔒 **Security.** Do not declare this if your store cannot look a capture up reliably. Answering
> "no" when the answer is "yes" writes the same fact twice. `Ledger\ArchiveLedger` refuses to declare
> it for exactly this reason — a capture-id lookup there would be a scan with no index behind it.

### `EnumeratesStreams` — a stable list, or a loud refusal

```php
use ElPandaPe\Sentinel\Contracts\EnumeratesStreams;

/** @return list<string> */
public function streams(): array;
```

The order is the ledger's own and only has to be **stable**: a report that lists the same streams in
a different order on every run is a report nobody can diff.

```php
use ElPandaPe\Sentinel\Exceptions\QueryException;
use ElPandaPe\Sentinel\Facades\Sentinel;

Sentinel::verifyIntegrity('tenant:acme');   // one named chain: any driver answers this

try {
    Sentinel::verifyEverything();            // every chain: only a driver that can list them
} catch (QueryException $e) {
    // "[App\Sentinel\SearchLedger] cannot say which streams it holds, so it cannot be asked
    //  to verify all of them. Name the stream to verify, or implement
    //  Contracts\EnumeratesStreams on the driver."
}
```

Refusing is deliberate. Answering "nothing is broken" about a list nobody could produce is the one
answer that would be read as reassurance and mean nothing. See
[Verification](../07-integrity/06-verification.md).

---

## What the contract deliberately does not require

The omissions are what make a driver over something that is not a table possible. Read them as
permissions, not as gaps.

| Not required | Consequence for you |
|---|---|
| A transaction, a rollback, or any atomicity | `writeMany()` may compensate instead — and compensation can be interrupted |
| Read-your-writes | Implement `settle()` in the contract test case rather than fighting it |
| A deduplicating lookup | Skip `Deduplicates`; the caller's unique index remains the arbiter |
| SQL, columns, or a query builder | `AuditQuery` names criteria, never columns; there is no `where(string $column, …)` and no way to reach past it |
| An `appendMany()` | There is none. A batch fanned out to a secondary arrives one `append()` at a time |
| A `count()` or a reverse walk on `LedgerStream` | Three methods, forwards, in sequence order |
| Any way to update or delete an entry | **There is no such method.** Retention, pruning and redaction address the tables directly and never go through this contract |
| Eloquent persistence | `$audit->exists` is `true` on `DatabaseLedger` and `false` on the array-backed drivers; nothing picks a side |
| Reproducing another driver's hash | `EntryBuilder` mints a fresh ULID per seal and `id` is inside the canonical payload, so two drivers sealing the same capture produce different hashes **by construction**. What is comparable across drivers is *verification*, not the digest |

The absence of an update and a delete is the append-only invariant expressed as a missing method.
Nothing a driver is asked to implement can rewrite or reorder history; a restore writes a new entry
describing the restoration. See [Restoring state](../06-reading/08-restoring-state.md).

> 📌 **Note.** `sequence` is assigned in the ledger and never at capture. That is precisely what makes
> the `queue` and `buffered` performance modes compatible with the chain: arrival order at the ledger is
> not the order of the facts, so `occurred_at` (when it happened) stays apart from `created_at` (when
> it was sealed), and the chain describes the order of sealing — the only order a ledger can prove.
> See [Performance modes](../09-operations/01-performance-modes.md).

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `whereTag()` throws before the query is ever executed | `AuditQuery` snapshots `Filter::answeredBy($ledger)` in its constructor and refuses at the call site | Declare the filter in `supportedFilters()`, or stop asking this driver for it |
| `catch (RuntimeException)` around a query never fires | `LedgerException` extends `BadMethodCallException`, a `LogicException` | Catch `LedgerException`, or `LogicException` |
| A driver returns a full result set on some filters and empty on others, with no error | It implements no `DeclaresFilters` and a caller assumed that meant "all of them" | Only `Filter::assumed()` — the nine — are assumed; name the rest explicitly |
| `verifyEverything()` throws `QueryException` on a working driver | The driver does not implement `EnumeratesStreams` | Implement it, or verify one named stream at a time |
| A permanent gap in the chain after a crash | The tail was advanced before the entry was stored | Store first, then advance the tail |
| The next `write()` for a subject reuses a `version` an appended entry already holds | `append()` did not move the per-subject counter | Call `SubjectVersions::seen()` (or `max(existing, $audit->version)`) inside `append()` |
| An entry read back out of a raw store fails its own hash, reported as tampering | JSON columns that arrived already encoded were run through the SET casts a second time by `fill()` / `forceFill()` | Use `setRawAttributes()` for values already in storage form; `forceFill()` is right only for decoded PHP values, which is why `Archive\Line::toAudit()` can use it |
| An appended range walks in the order it was inserted | The `LedgerStream` did not sort | Sort by `sequence` on construction, as `Ledger\ArrayStream` does |
| Code branching on `$audit->exists` after a write behaves differently per driver | Only Eloquent-backed drivers set it | Do not branch on it; the contract says nothing about it |
| Two drivers seal the same capture and produce different hashes | A fresh ULID per seal, and `id` is inside the canonical payload | Expected. Compare verification results, not digests |
| A stream name longer than 64 characters fails the write | The column width; `ConfigurationException::streamTooLong()` | Shorten what the resolver returns — it is never truncated |
| A batch fails wholesale on the unique index | Two captures in it named the same `capture_id` | Deduplicate before calling `writeMany()`; the driver seals both and the index refuses them together |

---

## ✅ Best practices

✅ **Do** — derive the next sequence from the tail's `sequence`, not from how many entries you hold.
The two agree only until `append()` brings in a range that does not start at 1.

```php
$tail = $this->tails[$stream] ?? StreamTail::empty();

$entry = $this->builder->build(
    $audit,
    $stream,
    $tail->sequence + 1,
    $tail->hash,
    $this->versions->next($audit),
);
```

❌ **Don't** — count what you are holding. Append a sealed entry with sequence 7 into an empty
stream and the next write is numbered 2, which is neither dense nor monotonic against what is there.

```php
$entry = $this->builder->build($audit, $stream, count($this->entries[$stream] ?? []) + 1, /* … */);
```

✅ **Do** — store the entry, then move the tail. A crash in that order loses a write, which the
chain shows as an absence with a reason.

```php
$this->entries[$stream][] = $entry;
$this->tails[$stream] = new StreamTail($entry->sequence, $entry->hash);
```

❌ **Don't** — reserve the number first. A crash between the two burns a sequence permanently, and
`sentinel:verify` reports the gap for the life of the chain.

```php
$this->tails[$stream] = new StreamTail($next, null);   // the number is spent
$this->entries[$stream][] = $this->builder->build($audit, $stream, $next, /* … */);
```

✅ **Do** — move the per-subject counter when you take an entry you did not number.

```php
public function append(Audit $audit): Audit
{
    $this->entries[$audit->stream][] = $audit;
    $this->versions->seen($audit);

    return $audit;
}
```

❌ **Don't** — treat `append()` as a plain insert. The next `write()` for that subject then hands out
a `version` the appended entry already holds, silently and forever.

```php
public function append(Audit $audit): Audit
{
    $this->entries[$audit->stream][] = $audit;   // the counter never learns

    return $audit;
}
```

✅ **Do** — declare exactly the filters your backend translates, and let `AuditQuery` refuse the
rest at the call site. A refusal is an answer; a wrong result is not.

```php
public function supportedFilters(): array
{
    return [Filter::Subject, Filter::Actor, Filter::Tenant, Filter::Period];
}
```

❌ **Don't** — return `Filter::cases()` because it makes the contract suite green. A criterion you
cannot translate and quietly ignore answers a different question than the caller asked, and a trail
that shows the wrong history is worse than one that refuses to answer.

```php
public function supportedFilters(): array
{
    return Filter::cases();   // …while whereFieldChanged() is ignored downstream
}
```

✅ **Do** — declare `Deduplicates` only when a capture-id lookup is a real, indexed read.

```php
public function settled(array $captureIds): array
{
    return $this->index->lookup('capture_id', $captureIds);   // one seek, not a scan
}
```

❌ **Don't** — fake it with a scan or an optimistic empty list. Saying "nothing has settled" when
something has writes the same fact twice; not declaring the interface at all is the correct,
supported answer.

```php
public function settled(array $captureIds): array
{
    return [];   // "answering" by refusing to look
}
```

✅ **Do** — resolve `Contracts\Ledger` from the container and pick the driver with the
`sentinel.ledger.default` config string.

```php
use ElPandaPe\Sentinel\Contracts\Ledger;

public function __construct(private readonly Ledger $ledger) {}
```

❌ **Don't** — type-hint or reach for a concrete driver. The whole `ElPandaPe\Sentinel\Ledger`
namespace is `@internal`; the configuration picks the implementation, so no caller ever names the
class.

```php
use ElPandaPe\Sentinel\Ledger\DatabaseLedger;

public function __construct(private readonly DatabaseLedger $ledger) {}
```

---

**See also:** [The shipped drivers](02-shipped-drivers.md) · [Writing a ledger driver](03-writing-a-ledger-driver.md) · [The contract test suite](04-the-contract-test-suite.md) · [Fanout](05-fanout.md) · [The hash chain](../07-integrity/01-the-hash-chain.md) · [Streams](../07-integrity/02-streams.md) · [The Query API](../06-reading/01-the-query-api.md) · [Filters reference](../06-reading/02-filters-reference.md) · [Exceptions](../99-reference/06-exceptions.md) · [API stability](../99-reference/09-api-stability.md)
