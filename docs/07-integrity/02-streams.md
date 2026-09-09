# 🔐 Streams

> A stream is one chain. This page covers the three shipped scopes, the trade each one makes between
> write contention and verification cost, and what happens to your history if you change the scope
> after entries exist.

**On this page:** [What a stream is](#what-a-stream-is) · [The shipped scopes](#the-shipped-scopes) · [Choosing a scope](#choosing-a-scope) · [Changing the scope later](#changing-the-scope-later) · [Sequence numbers are local to a stream](#sequence-numbers-are-local-to-a-stream) · [Enumerating the streams a ledger holds](#enumerating-the-streams-a-ledger-holds) · [Writing a StreamResolver](#writing-a-streamresolver) · [⚠️ Pitfalls](#️-pitfalls) · [✅ Best practices](#-best-practices)

---

## What a stream is

Every audit entry belongs to exactly one named chain, and the name lives in the `stream` column.
Within a stream the ledger assigns a dense, monotonic `sequence` and links each entry to the hash of
the one before it. Across streams there is no link at all: two streams are two independent
histories that happen to share a table.

The name is not a label. `Integrity\Hasher::hash()` puts it in the hash prefix, ahead of the
sequence and the previous hash:

```
hash = algorithm( payload_version ⑴ stream ⑴ sequence ⑴ (previous_hash ?? '') ⑴ canonical(core) )
```

`⑴` is the ASCII unit separator (`\x1f`). Because `stream` is inside the digest, **a stream is never
renamed in place**: an `UPDATE` that rewrites the column leaves every entry in it unable to
reproduce its own hash, and `sentinel:verify` reports `hash_mismatch` on the first one it reads.
See [The hash chain](01-the-hash-chain.md) for the formula in full and
[Canonicalization](03-canonicalization.md) for what `canonical(core)` covers.

Three things in the schema are shaped by streams (`src/Support/AuditSchema.php`, and the migration
in `database/migrations/`):

| Object | Shape | Why it matters |
|---|---|---|
| `sentinel_audits.stream` | `string(64)`, not nullable | The 64 characters are a hard ceiling, not a truncation point |
| `sentinel_audits` unique key | `(stream, sequence)` | The final arbiter of the chain, and the index `streams()` scans |
| `sentinel_checkpoints` unique key | `(stream, sequence_from)` | Anchors are per stream too — see [Checkpoints and anchors](05-checkpoints-and-anchors.md) |

The name is resolved by `Integrity\Stream::resolve()`, which is called from
`Ledger\DatabaseLedger::groupByStream()` **inside the sealing transaction**, once per entry, before
any stream gate is taken. It is not resolved at capture and it is not carried in the pipeline.

> 📌 **Note.** `Data\AuditData` has a `$stream` property, and when it is set it wins over the
> configured strategy outright — `Stream::resolve()` reads `$audit->stream ?? $this->fromStrategy($audit)`.
> None of the shipped capture paths ever set it, so in practice the strategy decides. The property
> exists so an entry that has already been assigned a stream keeps it across a queue or buffer
> round-trip.

## The shipped scopes

`integrity.stream` accepts five kinds of value, but only **three** of them are shipped scopes:
`Integrity\Stream::fromStrategy()` has a named arm for `global`, `tenant` and `subject_type`, and
nothing else. The remaining two are not scopes at all — they are the two ways of handing Sentinel a
scope of your own, and the naming is then entirely yours.

| Value | Shipped | Name produced | Falls back to | Survives `config:cache` |
|---|---|---|---|---|
| `'global'` | ✅ | `global` | — | Yes |
| `'tenant'` *(default)* | ✅ | `tenant:<tenant_id>` | `global` when `tenant_id` is null | Yes |
| `'subject_type'` | ✅ | `type:<morph alias>` | `global` when `subject_type` is null | Yes |
| a `Closure` | ❌ — yours | whatever it returns | — | **No** |
| a `Contracts\StreamResolver` class-string | ❌ — yours | whatever it returns | — | Yes |

Anything else — a string that is neither a mode nor a resolvable class, or a value that is not a
string or a closure at all — throws `ConfigurationException` naming `integrity.stream`.

### `global`

One chain for the whole installation. Every entry links to the previous entry, whoever wrote it and
whatever it was about.

```php
// config/sentinel.php
'integrity' => [
    'stream' => 'global',
],
```

### `tenant` — the shipped default

```php
'integrity' => [
    'stream' => 'tenant',
],
```

`tenant_id` is filled by `Context\Resolvers\TenantResolver`, which invokes the closure at
`resolvers.tenant.using` and does nothing else. The default for that closure is `null`.

> ⚠️ **Warning.** With no tenant closure configured, `tenant` behaves **exactly** like `global` —
> every entry falls back to the chain named `global`. The moment you wire a tenancy package into
> `resolvers.tenant.using`, entries start landing in `tenant:<id>` chains with `sequence` restarting
> at 1, and the `global` chain stops growing. Nothing is rewritten and nothing breaks, but the
> history has forked. If you do not want that, set `integrity.stream` explicitly **before** you wire
> the tenant resolver. See [Multi-tenancy](../04-context/04-multi-tenancy.md).

The second half of that trap is the one people hit in production: a tenant closure that reads from
the current request returns `null` in a queue worker, an artisan command and the scheduler. Those
entries go to `global` while the HTTP ones go to `tenant:acme`, so one tenant's trail lives in two
chains split by where the code ran. [Queues, commands and
schedulers](../04-context/05-queues-commands-and-schedulers.md) covers how to make the tenant travel
with the job.

### `subject_type`

```php
'integrity' => [
    'stream' => 'subject_type',
],
```

The name is `type:` plus `Relation::getMorphAlias($subject_type)`, cast to a string — the morph
alias when the model has one in the morph map, the fully-qualified class name when it does not.
Entries with no subject — authentication events, custom events recorded without a subject — have
`subject_type` null and fall back to `global`.

Those three are the whole shipped set. The two forms below are not scopes Sentinel ships; they are
the two hooks for a scope of your own. `Stream::fromStrategy()` names nothing for either — what you
return **is** the stream name, and the only thing done to it afterwards is the length guard.

### A closure

```php
use ElPandaPe\Sentinel\Data\AuditData;

'integrity' => [
    'stream' => static fn (AuditData $audit): string => 'region:'.($audit->context['region'] ?? 'unknown'),
],
```

The closure receives the fully resolved `AuditData` and must return a string; returning anything else
throws `ConfigurationException::expected('integrity.stream', 'a closure returning a string', …)`.

> ⚠️ **Warning.** A closure cannot be serialized into a cached config, and `php artisan config:cache`
> does not drop it quietly: the command fails with *"Your configuration files could not be serialized
> because the value at "sentinel.integrity.stream" is non-serializable."* — so the deploy step breaks
> rather than the first write. Use the class-string form —
> [Writing a StreamResolver](#writing-a-streamresolver) — on any installation that caches its
> configuration.

## Choosing a scope

The two costs pull in opposite directions.

**Write contention.** Every write reads the tail of its stream under a lock, because the hash covers
the previous hash and no `INSERT` can compute its own link. `Ledger\StreamGate` takes a PostgreSQL
advisory lock on the stream name, or leans on InnoDB's gap lock over `(stream, sequence)`. Writers
of *the same* stream are serialized; writers of different streams are not. Fewer, wider streams mean
more contention.

**Verification cost.** `verifyIntegrity()` walks one stream end to end. More streams mean each walk
is shorter, but `verifyEverything()` still reads everything, and every stream needs its own anchors
before any of it can be pruned. More streams also mean more rows in `sentinel_checkpoints` and more
trailing windows that are too short to anchor.

| Scope | Chains | Write contention | Per-chain verification | Use it when |
|---|---|---|---|---|
| `global` | 1 | Highest — every writer queues behind every other | One long walk | Single-tenant, moderate write rate, you want one linear history |
| `tenant` | 1 per tenant, plus `global` | One lock per tenant | Short walks, many of them | Tenants must be provable independently, or exported one at a time |
| `subject_type` | 1 per model type, plus `global` | One lock per type | Bounded by the busiest type | One or two types dominate the write rate |
| closure / resolver | Yours | Yours | Yours | None of the above matches how your data is actually partitioned |

> 🐘 **Engine.** On SQLite, `lockForUpdate()` is discarded and the engine serializes writes for the
> whole database on its own, so splitting streams buys nothing for contention there. On PostgreSQL
> and MySQL 9 it does. Anything touching the chain is verified on all three via `make test-dbs`.
> See [Choosing an engine](../10-database-engines/01-choosing-an-engine.md).

> 📌 **Note.** One batch that spans several streams takes each of their gates inside a single
> transaction, in the order the streams first appear in the batch. A buffered flush or a mass
> operation touching many streams therefore holds several stream locks at once. See
> [The buffered mode](../09-operations/02-the-buffered-mode.md).

## Changing the scope later

**Choosing the scope is a one-time decision made before the first entry is written.** Changing
`integrity.stream` afterwards rewrites nothing and repairs nothing. It changes where the *next*
entry goes, and that is all it can do — the name is inside the hash of every row already written.

There is no re-chain command. Rewriting `stream` on existing rows, or rehashing them under a new
name, destroys the one property the package exists to provide, and nothing in the package will do it
for you.

### What you see after a fork

Switching `tenant` → `global` on a trail that already has tenanted entries:

```
$ php artisan sentinel:verify

+---------------+---------+--------+---------+------------+
| Stream        | Entries | Chain  | Anchors | Signatures |
+---------------+---------+--------+---------+------------+
| global        | 1204    | intact | ...     | ...        |
| tenant:acme   | 41388   | intact | ...     | ...        |
| tenant:globex | 9011    | intact | ...     | ...        |
+---------------+---------+--------+---------+------------+
```

Concretely, from that point on:

- **Nothing is reported broken.** All three chains verify. `verifyEverything()` walks every stream
  the ledger names, so the stranded ones stay covered by the routine sweep and by
  `sentinel:verify`. A fork is invisible to verification, which is why you will not be told about it.
- **`sequence` restarts.** The new chain begins at 1 with `previous_hash` null. Any code that
  ordered by `sequence` alone, across the fork, is now ordering by a number that means two different
  things. Order comes from `(stream, sequence)`, never from `sequence`.
- **A tenant's trail lives in two chains.** `Sentinel::audits()->forTenant('acme')` still returns all
  of it — the Query API filters on `tenant_id`, not on `stream`, and has no stream filter at all.
  But proving that trail now means verifying two streams, and there is nothing linking one to the
  other. See [The Query API](../06-reading/01-the-query-api.md).
- **The stranded chains stop being anchorable at the seam.** `sentinel:checkpoint` anchors only
  *complete* windows of `integrity.checkpoints.every` entries, and only once the stream holds the
  entry that ends the window. A stream that will never receive another write keeps its trailing
  partial window unanchored forever.
- **That frozen tail can never be pruned.** The unit of retention is the anchored window
  ([Retention and pruning](../08-lifecycle/01-retention-and-pruning.md)). A stranded stream with
  fewer entries than one window reports `RetentionHold::Unanchored` — *"Stream :stream has no
  anchors. A range is only retired while an anchor still answers for it, so anchor the history
  before pruning it."* — and releases nothing, however old the entries are.

### What the recovery is

There are three honest options and no fourth.

1. **Accept the fork and record when it happened.** Both histories verify. Write down the date and
   the reason, because the split is otherwise indistinguishable from an installation that always had
   two chains.
2. **Reach further into a stranded tail by lowering `integrity.checkpoints.every`.** Existing anchors
   keep their own width — `sequence_to` records it — and emission stays contiguous, because the next
   window is derived as `max(sequence_to) + 1 .. + every`. A smaller window lets
   `sentinel:checkpoint` anchor most of what was left over, which in turn makes it prunable. The
   window holding a stream's **highest** sequence is still never offered for pruning, so the last
   `every` entries of a stranded stream stay where they are.
3. **Start over deliberately, and say so in the trail.** If the old chains have no further value,
   archive them ([Cold archiving](../08-lifecycle/02-cold-archiving.md)) and keep the anchors — after
   a prune they are the only thing that accounts for what is missing.

> 🔒 **Security.** Do not "fix" a fork with a migration that rewrites `stream` and recomputes
> hashes. A chain you can recompute at will is a chain that proves nothing, and the recomputed rows
> would verify perfectly — which is exactly the outcome an attacker wants. A format change bumps
> `payload_version` and ships a backwards-compatibility test; a stream change does neither, because
> it is not a format change.

## Sequence numbers are local to a stream

Sequence 2 981 in `tenant:acme` and sequence 2 981 in `tenant:globex` are unrelated entries written
at unrelated times. Three parts of the API follow from that:

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

// A range is a question about one stream, so the stream is required and comes first.
$slice = Sentinel::verifyIntegrity('tenant:acme', from: 1_000, to: 1_500);

// verifyEverything() takes no range: the same numbers would name different entries in each stream.
$report = Sentinel::verifyEverything();
```

On the command line the same rule is enforced with an exit code:

```bash
# Both of these exit 2 with a warning, not 1: the run could not happen, nothing was found wrong.
php artisan sentinel:verify --from=1000 --to=1500              # a range across every stream is a range across nothing
php artisan sentinel:verify --stream=global --from=1 --depth=roots  # the shallow depths cover whatever the anchors cover
```

`--from` and `--to` are accepted only together with `--stream` **and** only at `--depth=entries`.
See [Verification](06-verification.md) for the three depths and
[Exit codes](../99-reference/07-exit-codes.md) for the 0/1/2 vocabulary.

## Enumerating the streams a ledger holds

Listing chains is a declared capability, not part of the `Ledger` contract:
`Contracts\EnumeratesStreams` with a single `streams(): list<string>`.

| Driver | Declares `EnumeratesStreams` | How it answers |
|---|---|---|
| `DatabaseLedger` | Yes | One `distinct` scan of the leading column of `(stream, sequence)`, ordered by name |
| `MemoryLedger` | Yes | Sorted keys of what it holds on the instance |
| `ArchiveLedger` | Yes | The distinct streams of the batches written and pending, sorted |
| `FanoutLedger` | Yes | Delegates to the primary destination, or `[]` when the primary cannot enumerate |
| `NullLedger` | **No** | — |

A driver that does not declare it is refused rather than answered with an empty report: answering
"nothing is broken" about a list nobody could build reads as reassurance and means nothing.

```php
use ElPandaPe\Sentinel\Contracts\EnumeratesStreams;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Facades\Sentinel;

$ledger = app(Ledger::class);

$names = $ledger instanceof EnumeratesStreams
    ? $ledger->streams()          // ['global', 'tenant:acme', 'tenant:globex']
    : [];

// Or read them off a report, which carries one entry per stream in the ledger's own order.
foreach (Sentinel::verifyEverything()->streams as $verification) {
    $verification->stream();          // 'tenant:acme'
    $verification->chain->checked;    // entries read and rehashed
    $verification->covered;           // entries an anchor answered for — never added to checked
}
```

`Sentinel::verifyEverything()` throws `QueryException::cannotEnumerateStreams` when the configured
ledger cannot list its chains — *"[…] cannot say which streams it holds, so it cannot be asked to
verify all of them. Name the stream to verify, or implement `Contracts\EnumeratesStreams` on the
driver."* The same applies to `sentinel:verify`, `sentinel:checkpoint` and `sentinel:prune` when you
give them no `--stream`; they exit 2.

Walking one chain in order is `Ledger::stream()`, which returns a `Contracts\LedgerStream` — an
`IteratorAggregate` over `Models\Audit`, chunked, ordered by `sequence`:

```php
foreach (app(Ledger::class)->stream('tenant:acme')->range(1, 500) as $audit) {
    $audit->sequence;
    $audit->hash;
}
```

> ⚠️ **Warning.** A stream name nobody has ever written is not an error. `Sentinel::verifyIntegrity('tenat:acme')`
> walks zero entries, finds nothing wrong, and returns an intact result with `checked` of 0;
> `sentinel:verify --stream=tenat:acme` prints one row and exits 0. Take the names from
> `streams()`, never from a hand-typed string.

## Writing a StreamResolver

`Contracts\StreamResolver` is one method, and it is public API — covered by the 1.x stability
promise, unlike `Integrity\Stream` itself, which is `@internal`.

```php
namespace ElPandaPe\Sentinel\Contracts;

use ElPandaPe\Sentinel\Data\AuditData;

interface StreamResolver
{
    public function resolve(AuditData $audit): string;
}
```

A worked example: an installation where orders dominate the write rate and everything else is quiet,
so orders get a chain of their own per tenant and the rest share one.

```php
namespace App\Audit;

use App\Models\Order;
use ElPandaPe\Sentinel\Contracts\StreamResolver;
use ElPandaPe\Sentinel\Data\AuditData;

final readonly class OrdersApartStream implements StreamResolver
{
    public function resolve(AuditData $audit): string
    {
        $tenant = $audit->tenant_id ?? 'shared';

        // subject_type is getMorphClass(), so it is the alias when the model is in the morph map.
        return $audit->subject_type === new Order()->getMorphClass()
            ? 'orders:'.$tenant
            : 'main:'.$tenant;
    }
}
```

```php
// config/sentinel.php
'integrity' => [
    'stream' => \App\Audit\OrdersApartStream::class,
],
```

What the contract holds you to:

| Rule | Enforced by | What happens if you break it |
|---|---|---|
| Return a non-empty string | `Stream::guard()` | `ConfigurationException::streamEmpty()` — *"Sentinel resolved an empty stream name; every entry belongs to a named chain."* |
| Return at most 64 bytes | `Stream::guard()`, `Stream::MAX_LENGTH` | `ConfigurationException::streamTooLong()`. The name is **never truncated**, because it is inside the hash prefix |
| Be a class that exists and implements the contract | `Stream::fromResolver()` | `ConfigurationException::unknown('integrity.stream', …)` |
| Return the same name for the same entry, forever | Nothing | The chain forks silently — see [Changing the scope later](#changing-the-scope-later) |

Both exceptions are thrown on the write, inside the sealing transaction, so they are governed by
`on_write_failure`: `throw` propagates them into the request, `log` records them and lets the request
through. A write deferred to a commit can never propagate, so a deferred failure is always announced
and recorded instead. See [Failure policy](../09-operations/05-failure-policy.md).

Three practical notes about what the resolver is handed and when:

- **The `AuditData` is fully resolved.** `Pipeline\Stages\ResolveContext` has already run, so
  `tenant_id`, `actor_id`, `request_id`, `trace_id`, `source` and the `context` array are populated.
  See [Execution context](../04-context/01-execution-context.md).
- **The payload may already be masked or encrypted.** `MaskSensitiveData` and
  `EncryptSensitiveData` run before the ledger, so `$audit->before` and `$audit->after` can hold a
  mask or ciphertext by the time you see them. Build names from context and identity, never from
  payload values. See [The write pipeline](../05-pipeline-and-security/01-the-write-pipeline.md).
- **It is constructed through the container on every entry.** `Stream::fromResolver()` calls
  `$container->make()` per `AuditData`, inside the write transaction. Bind it as a singleton if it
  has dependencies worth building once, and never let it query the database.

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| A tenancy package goes live and every new entry appears under a `tenant:<id>` stream with `sequence` back at 1 | `integrity.stream` ships as `tenant`, which behaves like `global` until a tenant actually resolves | Decide the scope before wiring `resolvers.tenant.using`. Once forked, the old chain still verifies but stops growing |
| One tenant's entries are split between `tenant:acme` and `global` depending on where the code ran | The tenant closure returns `null` outside a request, so queue, command and scheduler entries fall back to `global` | Make the tenant travel with the job — see [Queues, commands and schedulers](../04-context/05-queues-commands-and-schedulers.md) |
| `php artisan config:cache` fails on deploy with *"the value at "sentinel.integrity.stream" is non-serializable"* | A `Closure` in `integrity.stream` cannot be written into a cached config | Move the closure into a `Contracts\StreamResolver` class and configure the class-string |
| `ConfigurationException` about 64 characters under `subject_type` | `type:` leaves 59 characters, and a fully-qualified class name with no morph alias overflows it | Declare a morph alias for the model. The name is never truncated |
| `sentinel:verify` reports `hash_mismatch` from sequence 1 of a stream after a "cleanup" migration | Something rewrote the `stream` column; the name is inside the hash prefix | Restore from backup. A stream cannot be renamed, only forked by writing new entries elsewhere |
| A retention policy is declared and one stream releases nothing, forever | That stream stopped receiving writes, so its trailing partial window is never anchored, and pruning's unit is the anchored window | Lower `integrity.checkpoints.every` so the frozen tail becomes complete windows, then re-run `sentinel:checkpoint` |
| `sentinel:verify` with no `--stream` exits 2 saying the ledger cannot say which streams it holds | The configured ledger does not declare `Contracts\EnumeratesStreams` — `NullLedger` does not | Name a stream with `--stream`, or point `ledger.default` at a driver that can enumerate |
| `verifyEverything()` returns an intact report over zero streams | `FanoutLedger` declares the capability but delegates to its primary, which returns `[]` when the primary cannot enumerate | Check that the first destination in `ledger.ledgers.fanout.destinations` is a driver that enumerates |
| `--from`/`--to` are ignored and the command exits 2 | A range needs `--stream` and `--depth=entries`; the same sequence numbers name different entries in different streams | Add `--stream`, or drop the range |
| A verification of a mistyped stream name reports "intact, 0 entries" and exits 0 | A stream nobody wrote to is empty, not missing; the walk finds nothing wrong because there is nothing to find | Feed names from `EnumeratesStreams::streams()`, not from a literal |

## ✅ Best practices

✅ **Do** — set `integrity.stream` explicitly at install, before the first entry exists, even when
you are choosing the default. The value that ships is `tenant`, and `tenant` changes behaviour the
day a tenant first resolves.

```php
// config/sentinel.php — single-tenant application, said out loud
'integrity' => [
    'stream' => 'global',
],
```

❌ **Don't** — leave it at the default and wire a tenancy package months later. Every tenant's
entries move to a chain of their own with `sequence` restarting at 1, nothing warns you, and both
chains verify.

```php
// config/sentinel.php — untouched
'integrity' => ['stream' => 'tenant'],

// AppServiceProvider, six months and 40 000 entries later
config(['sentinel.resolvers.tenant.using' => fn () => Tenancy::current()?->id]);
```

---

✅ **Do** — take stream names from the ledger, and verify each one you got back.

```php
use ElPandaPe\Sentinel\Contracts\EnumeratesStreams;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Facades\Sentinel;

$ledger = app(Ledger::class);

foreach ($ledger instanceof EnumeratesStreams ? $ledger->streams() : [] as $name) {
    $result = Sentinel::verifyIntegrity($name);

    if (! $result->isIntact()) {
        logger()->critical($result->message());
    }
}
```

❌ **Don't** — hard-code the names you expect. A stream that stopped receiving writes still holds
history, and a stream you forgot about is a stream nobody verifies.

```php
foreach (['tenant:acme', 'tenant:globex'] as $name) {
    Sentinel::verifyIntegrity($name); // 'global' holds every console entry, and is never read
}
```

---

✅ **Do** — configure a custom scope as a class-string implementing `Contracts\StreamResolver`, and
treat what it returns as frozen.

```php
'integrity' => [
    'stream' => \App\Audit\OrdersApartStream::class,
],
```

❌ **Don't** — use a closure on an installation that runs `config:cache`, and don't derive the name
from anything that can change — a display name, a slug, a value the pipeline may have masked.

```php
'integrity' => [
    // config:cache refuses to serialize it; and renaming the tenant re-chains the history.
    'stream' => static fn (AuditData $audit): string => 'tenant:'.Tenancy::current()?->slug,
],
```

---

✅ **Do** — order by `(stream, sequence)` whenever the order of the chain is what you mean, and by
`occurred_at` when the order of events is what you mean.

```php
foreach (app(Ledger::class)->stream('tenant:acme')->range(1) as $audit) {
    // sequence is dense and monotonic inside this stream, and means nothing outside it
}
```

❌ **Don't** — sort or compare by `sequence` across streams. Two entries with the same sequence in
different chains are unrelated.

```php
use ElPandaPe\Sentinel\Models\Audit;

Audit::query()->orderBy('sequence')->get(); // interleaves unrelated chains into a fake timeline
```

---

✅ **Do** — anchor every stream before you rely on retention, and keep `sentinel_checkpoints` backed
up alongside `sentinel_audits`.

```bash
php artisan sentinel:checkpoint          # anchors every complete window every stream still owes
php artisan sentinel:prune --dry-run
```

❌ **Don't** — expect a low-traffic stream to prune. A stream whose entries all sit in one
incomplete window has no anchors, reports `unanchored`, and releases nothing whatever the policy
says.

```bash
# 90-day policy declared, 400 entries in the stream, checkpoints.every = 1000 → nothing is ever pruned
php artisan sentinel:prune
```

---

✅ **Do** — split streams when a *measured* write-contention problem points at one chain, and size
`integrity.checkpoints.every` to the resulting per-stream volume.

❌ **Don't** — split "for scale" ahead of a measurement. Every extra stream is another set of
anchors, another incomplete tail that cannot be pruned, and another chain somebody has to remember
to verify.

---

**See also:** [The hash chain](01-the-hash-chain.md) · [Checkpoints and anchors](05-checkpoints-and-anchors.md) · [Verification](06-verification.md) · [Multi-tenancy](../04-context/04-multi-tenancy.md) · [Retention and pruning](../08-lifecycle/01-retention-and-pruning.md) · [The Ledger contract](../11-extending/01-the-ledger-contract.md) · [Configuration](../99-reference/02-configuration.md) · [Schema](../99-reference/03-schema.md)
