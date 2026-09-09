# 🧩 The contract test suite

> `Testing\LedgerContractTestCase` is the executable form of the Ledger contract: extend it, hand it
> your driver, and your driver is held to the same chain the shipped ones are.

**On this page:** [Why it ships in src/](#why-it-ships-in-src) · [Wiring it up](#wiring-it-up) ·
[What it asserts](#what-it-asserts) · [The two hooks](#the-two-hooks-retains-and-settle) ·
[The fixtures](#the-fixtures-you-can-override) · [What it does not assert](#what-it-does-not-assert) ·
[Running it against the shipped drivers](#running-it-against-the-shipped-drivers) ·
[A full test file](#a-full-test-file-for-a-third-party-driver) · [Pitfalls](#️-pitfalls) ·
[Best practices](#-best-practices)

---

## Why it ships in `src/`

`src/Testing/LedgerContractTestCase.php` is autoloaded as production code under
`ElPandaPe\Sentinel\Testing`. It is not in `require-dev`, it is not in a separate package, and it is
not published as a stub you copy. The reason is written into the class docblock: a contract nobody
outside the package can execute is a promise rather than a verification.

The consequences of that decision are visible across the toolchain, and they are the reason the file
looks different from every other test in the repository:

| It is | Because |
|---|---|
| A `PHPUnit` test case extending `Orchestra\Testbench\TestCase` | It has to run inside *your* project's test suite, against *your* driver, without this package's `tests/` directory being installed. |
| `abstract`, and exempt from the "classes are final" architecture rule | Being extended is the whole point. `tests/ArchTest.php` asserts every class in `ElPandaPe\Sentinel\Testing` is abstract. |
| Written with native PHPUnit assertions (`assertSame`, `assertNull`) | Four Pest Rector rules are skipped for `src/Testing` in `rector.php` so the file keeps assertions a plain PHPUnit installation understands. |
| Forbidden from touching anything under `tests/` | An architecture test asserts `ElPandaPe\Sentinel\Testing` does not use `ElPandaPe\Sentinel\Tests`. Your project never installs that directory. |
| Part of the frozen public surface | `tests/SurfaceTest.php` lists `Testing/LedgerContractTestCase` among the declarations that stay reachable whatever else moves. The `ElPandaPe\Sentinel\Ledger` namespace — every shipped driver included — is classified `@internal` in the same file. |

> 📌 **Note.** The suite asserts the one thing `Contracts\Ledger` guarantees: within one stream the
> `sequence` is dense and monotonic, and every entry's `previous_hash` is the previous entry's
> `hash`. Nothing in it reaches for a table, so a driver over a document store, a queue or a log runs
> it unchanged. See [The Ledger contract](01-the-ledger-contract.md).

---

## Wiring it up

### The packages you need

The package declares two of them in the `suggest` block of `composer.json`, and neither is a hard
dependency — you install them in the project that owns the driver.

| Package | Constraint | What it is for | Without it |
|---|---|---|---|
| `orchestra/testbench` | `^11.0` (from `suggest`) | The base class extends `Orchestra\Testbench\TestCase`; it boots a Laravel application and registers `SentinelServiceProvider`. | The class cannot be loaded at all. |
| `phpunit/phpunit` | `^13.0` (from `suggest`) | It is a PHPUnit test case, driven by `#[DataProvider]`. | Same. |
| `pestphp/pest` | `^5.0` (the version this package develops against) | One case — `test_it_refuses_a_read_that_would_come_back_looking_complete` — calls `expect(…)->toThrow(…)`, which is Pest's global expectation function and not PHPUnit's. | That single case fails with `Error: Call to undefined function expect()`. Everything else passes. [Override it](#️-pitfalls) if you do not want Pest. |

```jsonc
// your driver package's composer.json
"require-dev": {
    "elpandape/sentinel": "^1.0",
    "orchestra/testbench": "^11.0",
    "pestphp/pest": "^5.0"
}
```

### The class you extend and the method you implement

One abstract method, `ledger()`. Everything else has a default.

```php
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Testing\LedgerContractTestCase;

final class DocumentLedgerContractTest extends LedgerContractTestCase
{
    private ?Ledger $ledger = null;

    protected function ledger(): Ledger
    {
        return $this->ledger ??= app(DocumentLedger::class);
    }
}
```

**Memoize it unless your store is external.** `ledger()` is called more than once per test — the
suite's `asking()` helper does `new AuditQuery($this->ledger())` to build every query, so a case that
writes and then reads calls it at least twice. A driver that keeps its chain on the instance and
returns a fresh object each time will read from an empty ledger half the time. Every in-package
subclass memoizes except the two that drive `DatabaseLedger`, whose state lives in a table.

### What the base class already does for you

- `getPackageProviders()` returns `[SentinelServiceProvider::class]`.
- `defineEnvironment()` sets `app.key` to a fixed value —
  `'base64:'.base64_encode(str_pad('sentinel-contract-key', 32, '0'))`. Testbench defines no key, and
  the package derives the digest salt and the default encryption key from it; a key that moved
  between runs would make every digest assertion compare a value to a different value.

Override either one and you must call the parent, or add back what it did:

```php
protected function getPackageProviders($app): array
{
    return [DocumentLedgerServiceProvider::class, ...parent::getPackageProviders($app)];
}

protected function defineEnvironment($app): void
{
    parent::defineEnvironment($app);

    $app['config']->set('sentinel.ledger.default', 'document');
}
```

The base class does **not** use `RefreshDatabase`. A driver that writes to a database adds the trait
itself; `SentinelServiceProvider` loads the package's unpublished migrations, so the tables exist
without publishing anything.

---

## What it asserts

29 test methods. One of them is driven by the `publishedFilters()` data provider, which has 19 rows,
so a driver runs **47 cases**. (Counted in `src/Testing/LedgerContractTestCase.php`; run the suite
and PHPUnit will tell you the same number.)

### The chain

| Case | Asserts | A failure means |
|---|---|---|
| `it_starts_a_chain_with_no_previous_link` | First entry: `sequence` 1, `previous_hash` null, `payload_version` 1 | You invented a zero-th link, or you built the entry yourself instead of through the package's builder — `payload_version` is stamped there |
| `it_links_every_entry_to_the_one_before` | Second entry: `sequence` 2, `previous_hash` equals the first entry's `hash` | Your write path does not read the tail of the stream before sealing |
| `it_numbers_each_stream_on_its_own` | A write on `beta` after a write on `alpha` gets `sequence` 1 and a null `previous_hash` | You number globally rather than per stream. See [Streams](../07-integrity/02-streams.md) |
| `it_consumes_consecutive_sequences_for_a_batch` | `writeMany()` of three returns sequences `[1, 2, 3]` | The batch path does not chain, or re-reads the tail per entry and loses positions |
| `it_writes_nothing_for_an_empty_batch` | `writeMany([])` returns an empty collection | The batch path assumes at least one entry |

### Taking an entry someone else sealed

`append()` stores an already-sealed entry verbatim: it assigns no sequence and recomputes no hash.

| Case | Asserts | A failure means |
|---|---|---|
| `it_stores_an_entry_it_did_not_seal` | The returned entry keeps its `sequence`, `hash` and `previous_hash` | You re-sealed it. Two ledgers numbering their own chain produce two truths about one fact |
| `it_gives_an_appended_entry_back_unchanged` | `find()` returns an entry with the same `hash` | Your store rewrote a column on the way in or out |
| `it_goes_on_numbering_a_subject_after_taking_an_entry_it_did_not_number` | Append an entry for a subject at `version` 3, then write for that subject → `version` 4 | You keep a per-subject counter and `append()` does not move it. The next write then hands out a number the appended entry already holds, permanently, with nothing to notice it by |

> ⚠️ **Warning.** That last case calls `append()` and then `write()` with **no `settle()` in
> between**. A driver that derives the next version by reading its own store *and* is eventually
> consistent will fail it. Move the counter inside `append()` — `max($current, $audit->version)` —
> rather than deriving it after the fact.

### Reading back what you kept

| Case | Asserts |
|---|---|
| `it_walks_a_stream_in_order` | Iterating `stream('global')` yields sequences `[1, 2, 3]` |
| `it_walks_a_bounded_range_of_a_stream` | `stream('global')->range(2, 3)` yields `[2, 3]` — both ends inclusive |
| `it_walks_an_empty_stream` | An unknown stream name iterates to `[]`, never null and never an error |
| `it_finds_what_it_wrote` | `find($id)` returns the entry with the same `hash` |
| `it_finds_nothing_for_an_unknown_id` | `find()` on a well-formed ULID nobody wrote returns null, not an exception |

### Filters and refusals

The data-provider case writes two captures — one plain, one built by `narrowedAuditData()` that every
published filter matches — and asserts the filter answers with **exactly** the narrowed one. Both
halves matter: a filter that stopped narrowing shows up as an extra id, not as a passing test.

Nineteen rows cover seventeen of the nineteen `Enums\Filter` cases:

| Row | Filter | Query it issues |
|---|---|---|
| subject · actor · event · severity · source · tenant · transaction · trace | `Subject` `Actor` `Event` `Severity` `Source` `Tenant` `Transaction` `Trace` | `for()` `by()` `whereEvent()` `whereSeverity()` `whereSource()` `forTenant()` `inTransaction()` `withTrace()` |
| every label · any label | `Tag` | `whereTag(['billing','refund'])` · `whereAnyTag(['refund','absent'])` |
| changed field · changed field beneath a parent | `FieldChanged` | `whereFieldChanged('total')` · `whereFieldChanged('profile')` — the second reaches `/profile/address/city` |
| version · kind of entry | `Version` `Type` | `whereVersion(1)` · `whereType('transition')` |
| address · route | `Ip` `Route` | `whereIp()` · `whereRoute()` — both read out of `context` |
| relation · related record · relation operation | `Relation` `Related` `Operation` | `whereRelation('members')` · `whereRelated('user', 7)` · `whereOperation(RelationOperation::Attach)` |

The two remaining cases get dedicated tests, because neither is a property of an entry:
`Filter::Period` in `it_bounds_a_period_by_both_of_its_ends` and
`it_narrows_to_one_subject_inside_a_period_newest_first`; `Filter::After` in
`it_resumes_a_walk_after_the_entry_it_was_given` and `it_answers_nothing_after_the_last_entry_it_holds`.

**Every one of them is held to one of two answers, never to neither.** If your driver declares the
filter through `Contracts\DeclaresFilters`, the case asserts the result set. If it does not, the case
asserts that `Exceptions\LedgerException` is thrown as the criterion is added. Silently dropping a
criterion — the third answer — is what the whole arrangement exists to forbid, and there is no
configuration that lets a driver take it.

Four more cases exercise combinations rather than single filters:
`it_narrows_to_an_entry_carrying_every_label_asked_for` (two chained `whereTag()` calls mean AND),
`it_answers_nothing_for_a_label_no_entry_carries`, `it_narrows_by_a_tenant_and_a_severity_at_once`,
and `it_walks_one_transaction_newest_first`. See
[Filters reference](../06-reading/02-filters-reference.md).

### Order, bounds and paging

| Case | Asserts | A failure means |
|---|---|---|
| `it_orders_by_the_clock_of_the_fact_when_asked_to` | Three entries written in the reverse of the order they happened come back in write order by default, and in occurrence order under `byOccurrence()` | You order by one clock only. `created_at` is when it was sealed, `occurred_at` is when it happened |
| `it_turns_the_clock_of_the_fact_around_on_request` | `byOccurrence()->latest()` reverses that | Your descending path ignores one of the two |
| `it_gives_the_oldest_entry_first_and_the_newest_first_on_request` | `latest()` is the exact reverse of the default order | Your tie-break is not the entry identifier |
| `it_answers_an_unnarrowed_query_with_everything_it_kept` | A query with no criteria spans **streams** — entries on `global` and `beta` come back together, in write order | You scoped `query()` to a stream. Only `stream()` is stream-scoped |
| `it_answers_one_page_at_a_time_and_says_whether_another_follows` | `paginate(2)` then `paginate(2, 2)` (per page, page number) split three entries, and `hasMore` is true then false | Your offset is applied after the limit, or you do not fetch one extra row to answer `hasMore` |
| `it_refuses_a_read_that_would_come_back_looking_complete` | With `AuditQuery::DEFAULT_LIMIT + 1` entries written, `get()` throws `QueryException` | Your `query()` ignores the probe's `limit`. A prefix shaped exactly like a complete answer is the one mistake a trail cannot afford |

> 💡 **Tip.** That last case issues 501 sequential `write()` calls. If your suite suddenly takes
> minutes, this is where the time goes — it is the only case that writes at volume.

### Labels on the entry the write returned

`it_hands_an_entry_back_carrying_the_labels_it_was_written_with` asserts that a capture with
`tags: ['billing', 'refund']` comes back with those two labels on the returned model, in that order.
This case is **not** gated on `retains()`: it inspects the object `write()` handed back, not a read,
so a ledger that stores nothing still has to satisfy it. Labels ride as a loaded `tags` relation and
never as attributes, which keeps them out of the canonical payload and out of the hash.

---

## The two hooks: `retains()` and `settle()`

They exist so a driver can say what it is rather than fail for being it.

```php
protected function retains(): bool
{
    return true;   // default
}

protected function settle(Ledger $ledger): void {}   // default: nothing
```

**`retains()` chooses which expectation applies, never whether one does.** A driver answering `false`
is held to keeping *nothing* as strictly as the others are held to keeping everything: the walk
assertions expect `[]`, `find()` is expected to return null, every filter case expects an empty
result set, and the unbounded-read case expects a count of zero instead of an exception. `NullLedger`
is the shipped example.

**`settle()` runs between a write and the read that checks it.** The contract explicitly does not
promise that a read sees a write that just returned, so a driver that needs this hook is honouring
the contract, not working around it. Two shapes need it: a store whose reads are eventually
consistent (refresh the index here), and a driver that buffers. `ArchiveLedger` is the second — it
holds entries in an open batch until the batch fills or somebody asks, so its subclass calls
`$ledger->seal()`.

> ⚠️ **Warning.** Do not reach for `markTestSkipped()` in a subclass to get past an expectation. An
> earlier version of `retains()` was written that way and had to be rewritten: a skipped case reports
> nothing, which is exactly the answer these hooks exist to prevent a driver from giving.

---

## The fixtures you can override

Four protected builders feed every expectation. Override one only if your store genuinely cannot take
the shape it produces.

| Method | Produces | Notes |
|---|---|---|
| `auditData(?string $stream, string $occurredAt)` | A plain `model` / `created` capture at `Severity::Info` with no subject, no actor and no labels | The suite varies only the stream and the occurrence clock, because the stream is the only field the chain is scoped by |
| `narrowedAuditData(Severity $severity)` | The capture every filter matches: subject `invoice:500`, actor `user:1`, tenant `acme`, a transaction id, a trace id, `context` with `ip` and `route`, two changed-field paths, a `RelationLine` and the labels `billing` and `refund` | Built so nothing in it matches the plain capture. Change it and the filter cases stop proving anything |
| `sealedElsewhere()` | An entry built through `Ledger\EntryBuilder` on stream `imported`, sequence 1, no previous hash | Fed to `append()`. Nothing about it belongs to the ledger under test — that is the point |
| `sealedForSubject(int $version)` | The same, over `narrowedAuditData()` and carrying a version | Fed to the subject-numbering case |

> 📌 **Note.** `sealedElsewhere()` and `sealedForSubject()` resolve `Ledger\EntryBuilder` out of the
> container, and the `ElPandaPe\Sentinel\Ledger` namespace is marked `@internal` while
> `Testing\LedgerContractTestCase` is published. Calling the builder through these two helpers is
> supported; naming `EntryBuilder` yourself is reaching into an internal. The position on which
> internals a driver may lean on is in [Writing a ledger driver](03-writing-a-ledger-driver.md).

---

## What it does not assert

This is the half of the page that saves you a production incident. The suite is a conformance floor,
not a test plan; everything below is still yours to cover.

| Not asserted | Why it matters |
|---|---|
| **That an entry reproduces its own hash** | The suite compares stored hash *strings* for equality. It never re-derives a hash from the payload, and `Integrity\Verifier` is never called. A driver that hydrates with `forceFill()` double-encodes its JSON columns, passes all 47 cases, and then reports `hash_mismatch` under `sentinel:verify`. Hydrate with `setRawAttributes()`. See [Verification](../07-integrity/06-verification.md) |
| **Concurrency** | One process, one instance, no parallel writers, no locking. The gap lock and the advisory lock the database driver relies on are exercised by tests outside the published suite |
| **Durability across instances or processes** | Every case writes and reads through the same object. Nothing restarts anything |
| **`writeMany()` atomicity** | The contract does not promise it, and no case fails a batch mid-flight to see what is left behind |
| **Idempotency by `capture_id`** | The string `capture_id` does not appear in the file. Duplicate suppression belongs to the caller and, in the shipped schema, to a unique index |
| **`Contracts\Deduplicates`** | `settled()` is never called. Declaring it is untested here, and a driver that answers "no" when the answer is "yes" writes the same fact twice |
| **`Contracts\EnumeratesStreams`** | `streams()` is never called. Without it, `Sentinel::verifyEverything()` refuses rather than reporting an empty, reassuring result |
| **That `append()` keeps the labels it arrived carrying** | It is a written obligation of `Contracts\Ledger::append()`, but `sealedElsewhere()` builds from the plain capture, which has no labels. Test it yourself |
| **`LedgerStream::name()` and `range()` immutability** | `name()` is never called; `range()` is called once, so "returns a new instance and leaves the original untouched" is not checked |
| **Mixed-stream batches** | `writeMany()` is only ever exercised over one stream |
| **`take()` and an explicit `limit`** | Only the default-limit probe and `paginate()` are exercised |
| **`exists` / `wasRecentlyCreated`** | Drivers differ. `DatabaseLedger` returns entries with `exists = true`; drivers that never touch Eloquent's insert path do not |
| **Stream naming** | The suite passes stream names straight through the capture. Resolution from `integrity.stream`, and the 64-character cap, are enforced elsewhere |
| **Engine behaviour** | It runs against whatever connection your Testbench application is configured with — SQLite in memory unless you say otherwise. See [Choosing an engine](../10-database-engines/01-choosing-an-engine.md) |
| **Anything before the ledger** | Snapshots, diffs, context resolvers, masking, encryption, signing, anchoring, retention and archiving are all upstream or downstream of `Contracts\Ledger` |

---

## Running it against the shipped drivers

Seven subclasses live in `tests/Testing/`. They are how the package proves its own drivers cannot
drift apart, and they are the reference for what a subclass is allowed to do.

| Subclass | Driver under test | What it adds |
|---|---|---|
| `DatabaseLedgerContractTest` | `DatabaseLedger` | `RefreshDatabase`. `ledger()` is *not* memoized — the chain lives in a table, not on the instance |
| `PartitionedLedgerContractTest` | `DatabaseLedger` over a divided table | `RefreshDatabase` plus the partitioning DDL. Skips on SQLite, which does not partition |
| `MemoryLedgerContractTest` | `MemoryLedger` | Memoized `ledger()` |
| `NullLedgerContractTest` | `NullLedger` | `retains(): false` |
| `FanoutLedgerContractTest` | `FanoutLedger` with a memory primary and a null secondary under `FanoutPolicy::Strict` | Builds the driver by hand rather than through config. See [Fanout](05-fanout.md) |
| `ArchiveLedgerContractTest` | `ArchiveLedger` | `Storage::fake('cold')`, points the archive disk at it, and implements `settle()` as `$ledger->seal()` |
| `NarrowLedgerContractTest` | A test fixture declaring `Filter::assumed()` minus `Filter::Period` | Exercises the *refusal* side of every expectation — a driver narrower than the assumed floor is allowed, and the suite has to hold for one |

> 🧪 **Verify it.** From the package root:
>
> ```shell
> make test ARGS=tests/Testing        # all seven, SQLite; the partitioned one reports skipped
> make test-mysql ARGS=tests/Testing  # the same suite against MySQL 9
> make test-pgsql ARGS=tests/Testing  # and against PostgreSQL 16
> ```
>
> `make test` takes a single path — paratest breaks on two.

> 🐘 **Engine.** A green run on SQLite says nothing about how your driver translates JSON predicates
> on another engine. If your driver goes near a database, run the suite on the engine you deploy on
> before you believe it.

---

## A full test file for a third-party driver

Everything above, assembled for a driver over a document store that is eventually consistent, keeps
its chain on the instance, and translates every published filter.

```php
<?php

declare(strict_types=1);

namespace App\Tests\Sentinel;

use App\Sentinel\DocumentLedger;
use App\Sentinel\DocumentLedgerServiceProvider;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Exceptions\QueryException;
use ElPandaPe\Sentinel\Query\AuditQuery;
use ElPandaPe\Sentinel\Testing\LedgerContractTestCase;

final class DocumentLedgerContractTest extends LedgerContractTestCase
{
    private ?Ledger $ledger = null;

    /**
     * Memoized: asking() resolves the ledger again for every query, and this driver
     * keeps its chain on the instance.
     */
    protected function ledger(): Ledger
    {
        return $this->ledger ??= app(DocumentLedger::class);
    }

    /**
     * Reads are eventually consistent, so what was just written is made visible here
     * rather than waited for inside find().
     */
    protected function settle(Ledger $ledger): void
    {
        if ($ledger instanceof DocumentLedger) {
            $ledger->refresh();
        }
    }

    /**
     * Without pestphp/pest the shipped version of this case cannot resolve expect().
     * Same expectation, PHPUnit's API.
     */
    public function test_it_refuses_a_read_that_would_come_back_looking_complete(): void
    {
        $ledger = $this->ledger();

        for ($written = 0; $written <= AuditQuery::DEFAULT_LIMIT; $written++) {
            $ledger->write($this->auditData());
        }

        $this->settle($ledger);

        $this->expectException(QueryException::class);

        $this->asking()->get();
    }

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [DocumentLedgerServiceProvider::class, ...parent::getPackageProviders($app)];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('sentinel.ledger.default', 'document');
        $app['config']->set('document.index', 'audits-testing');
    }
}
```

Two obligations the suite cannot check for you, so add them beside it:

```php
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Facades\Sentinel;

it('hands back an entry that still reproduces its own hash', function (): void {
    $written = app(Ledger::class)->write($capture);

    expect(Sentinel::verifyIntegrity($written->stream)->isIntact())->toBeTrue();
});

it('keeps the labels an appended entry arrived carrying', function (): void {
    $sealed = /* an entry sealed by another ledger, tags relation loaded */;

    $found = app(Ledger::class)->append($sealed)->fresh();

    expect($found->tags->pluck('tag')->all())->toBe(['billing', 'refund']);
});
```

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| One case dies with `Error: Call to undefined function expect()` | `it_refuses_a_read_that_would_come_back_looking_complete` uses Pest's global `expect()`; the other 46 cases use native PHPUnit assertions | Install `pestphp/pest`, or override that one method with `$this->expectException(QueryException::class)` |
| Roughly half the read expectations come back empty, and which half varies | `ledger()` returns a fresh instance every call, and the driver keeps its chain on the instance. `asking()` calls it again | `return $this->ledger ??= …` |
| Every case errors before it reaches your driver | `getPackageProviders()` was overridden without `SentinelServiceProvider`, so nothing resolves | Spread `parent::getPackageProviders($app)` into the returned list |
| Digest or encryption failures that move between runs | `defineEnvironment()` was overridden without calling the parent, so `app.key` went back to Testbench's empty default | Call `parent::defineEnvironment($app)` first |
| `it_goes_on_numbering_a_subject_after_taking_an_entry_it_did_not_number` expects 4 and gets 1 | Your per-subject counter is not moved by `append()` | `$this->versions[$key] = max($this->versions[$key] ?? 0, $audit->version)` inside `append()` |
| A filter case returns two ids where one was expected | You declared a filter in `supportedFilters()` that your backend does not actually translate | Remove it from the declaration. `AuditQuery` will then refuse it at the call site, which the same case asserts |
| `it_narrows_by_a_tenant_and_a_severity_at_once` or `it_walks_one_transaction_newest_first` throws an unexpected `LedgerException` | Those two cases, and the subject half of the subject-in-a-period case, issue filters without a declaration guard | Declare at least `Subject`, `Severity`, `Tenant`, `Transaction` and `Period`, or override the case |
| `it_finds_what_it_wrote` returns null although the write succeeded | Your store's reads do not see a write that just returned | Implement `settle()` — do not put a sleep or a retry loop in `find()` |
| The period cases fail although the filter works | The suite moves PHP's clock with `travelTo()`, and the entry's `created_at` is stamped in PHP by the package's builder | Store the `created_at` you were handed; do not let the store stamp its own ingest time over it |
| All 47 pass, then `sentinel:verify` reports `hash_mismatch` | The suite compares stored hash strings and never re-derives one. `forceFill()` re-runs the SET casts and encodes an already-encoded JSON column a second time | Hydrate with `setRawAttributes()` |
| The suite takes minutes on a remote store | `it_refuses_a_read_that_would_come_back_looking_complete` issues 501 sequential writes | Expected. Nothing else in the suite writes at volume |
| `PartitionedLedgerContractTest` reports skipped | SQLite does not partition | Run it under `make test-mysql` or `make test-pgsql` |

---

## ✅ Best practices

✅ **Do** — memoize `ledger()` whenever the driver holds state on the instance. The suite resolves it
several times per case, and a fresh instance means half the expectations read an empty chain.

```php
protected function ledger(): Ledger
{
    return $this->ledger ??= app(DocumentLedger::class);
}
```

❌ **Don't** — build a new one each time. The write goes into one object and the query is stated
against another, so the failures land on the read assertions and point at the wrong code.

```php
protected function ledger(): Ledger
{
    return new DocumentLedger(/* … */);
}
```

✅ **Do** — say what your driver is through `retains()` and `settle()`. Both change *which*
expectation applies; neither removes one.

```php
protected function retains(): bool
{
    return false;   // this ledger keeps nothing — and is held to keeping nothing
}
```

❌ **Don't** — skip your way past an expectation. A skipped case reports nothing, which is precisely
the answer the contract refuses to accept from a driver.

```php
public function test_it_finds_what_it_wrote(): void
{
    $this->markTestSkipped('our store is write-only');
}
```

✅ **Do** — declare exactly the filters you translate and let `AuditQuery` refuse the rest as the
criterion is added. The refusal is asserted by the same case that would otherwise assert the result.

```php
use ElPandaPe\Sentinel\Contracts\DeclaresFilters;
use ElPandaPe\Sentinel\Enums\Filter;

/** @return list<Filter> */
public function supportedFilters(): array
{
    return [Filter::Subject, Filter::Actor, Filter::Tenant, Filter::Transaction,
            Filter::Severity, Filter::Period, Filter::After];
}
```

❌ **Don't** — return `Filter::cases()` to make the provider rows go green. It does the opposite: the
assertion is an exact match, so a filter you do not really translate fails on the extra id — and if
it ever stopped failing, you would be shipping a driver that answers a different question than the
one it was asked.

```php
public function supportedFilters(): array
{
    return Filter::cases();   // nineteen promises, some of them untrue
}
```

✅ **Do** — add the two assertions the suite cannot make: that an entry read back out of your store
still reproduces its own hash, and that `append()` keeps the labels the entry arrived carrying.

```php
expect(Sentinel::verifyIntegrity($written->stream)->isIntact())->toBeTrue();
```

❌ **Don't** — read a green contract run as proof of integrity. It compares hash strings; it never
recomputes one, and `Integrity\Verifier` is not imported anywhere in the file.

✅ **Do** — run the suite on the engine and the service you deploy against, not only on the
in-memory default your test application boots with.

```shell
make test-pgsql ARGS=tests/Testing
```

❌ **Don't** — treat SQLite as a stand-in for a driver that translates JSON predicates. Each engine
has its own dialect, its own collation rules and its own placeholder ceiling; the suite exercises the
translation you wired up, on the engine you pointed it at, and says nothing about any other.

---

**See also:** [The Ledger contract](01-the-ledger-contract.md) ·
[Writing a ledger driver](03-writing-a-ledger-driver.md) ·
[The shipped drivers](02-shipped-drivers.md) · [Fanout](05-fanout.md) ·
[Filters reference](../06-reading/02-filters-reference.md) ·
[Verification](../07-integrity/06-verification.md) ·
[Exceptions](../99-reference/06-exceptions.md) ·
[API stability](../99-reference/09-api-stability.md)
