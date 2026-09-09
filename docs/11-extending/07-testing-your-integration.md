# 🧩 Testing your integration

> How to test an application that has Sentinel in it: which ledger to point the suite at, why the
> deferred write is not the trap it looks like, what to assert on the trail, and what never to assert.

**On this page:** [Which ledger to test against](#which-ledger-to-test-against) ·
[The deferred write](#the-deferred-write) · [Asserting on the trail](#asserting-on-the-trail) ·
[Turning auditing off](#turning-auditing-off-in-a-test) · [The factory](#the-factory) ·
[The parts that need a real service](#the-parts-that-need-a-real-service) ·
[Testing your own extensions](#testing-your-own-extensions) ·
[What not to assert](#what-not-to-assert) · [Pitfalls](#️-pitfalls) ·
[Best practices](#-best-practices)

---

## Which ledger to test against

Three of the five names `ledger.default` accepts are the ones a test suite picks between. See
[The shipped drivers](02-shipped-drivers.md) for what each is in production; below is what each
gives you in a test.

| `ledger.default` | Class | Entries land in | Reads answer | Chain is real | Use it when |
|---|---|---|---|---|---|
| `database` | `Ledger\DatabaseLedger` | the `sentinel_audits` table | `Sentinel::audits()` **and** `Audit::query()` | yes | You are testing anything that reads the trail back, the schema, an index, or the engine |
| `memory` | `Ledger\MemoryLedger` | a PHP array on the instance | `Sentinel::audits()` only | yes | The suite only needs entries back and you do not want to pay for a table |
| `null` | `Ledger\NullLedger` | nowhere | nothing, ever | it is computed and then dropped | You are testing the code around auditing and want the write path exercised at no storage cost |

### `memory` is a test double, never a store

`Ledger\MemoryLedger` is `@internal`, and its own docblock says why it is not a production default:
*a ledger with no durability that looks like it works is worse than one that fails.* It keeps
everything it is given and nothing survives the instance — and since `Contracts\Ledger` is bound as
`scoped`, "the instance" means one per test.

It implements the whole contract plus **all three** capability interfaces —
`Contracts\DeclaresFilters`, `Contracts\Deduplicates`, `Contracts\EnumeratesStreams` — so every
`Enums\Filter` case is answerable, `Dispatch\Settlement` can deduplicate against it, and
`Sentinel::verifyEverything()` works. It chains with the same algorithm `DatabaseLedger` uses, so
`sequence`, `previous_hash` and `hash` are the real ones and verification passes.

What it does not do is persist. The entries it hands back are `Models\Audit` instances that were
never saved: `$entry->exists` is `false`, and `Audit::query()->count()` stays at `0` however many
writes you made.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Models\Audit;
use App\Models\Invoice;

it('records the two events an invoice goes through', function (): void {
    $invoice = Invoice::query()->create(['reference' => 'INV-1', 'status' => 'draft']);
    $invoice->update(['status' => 'paid']);

    expect(Sentinel::audits()->for($invoice)->get()->pluck('event')->all())->toBe(['created', 'updated'])
        ->and(Audit::query()->count())->toBe(0);
});
```

### `null` keeps the code path and drops the entry

`Ledger\NullLedger` builds, seals and chains the entry and then throws it away. What survives a
write is the tail of each stream and a version counter per subject, because a chain cannot be
continued without them — never one counter per entry. `find()` answers nothing, `stream()` walks
nothing, and a query comes back empty however narrow it was, so `$model->latestAudit()` is `null`
after a save that captured perfectly well.

It implements `Contracts\DeclaresFilters` and answers every filter with an empty result on purpose:
refusing one would claim it cannot translate it. It does **not** implement `Contracts\Deduplicates`
or `Contracts\EnumeratesStreams`, so `Sentinel::verifyEverything()` throws
`Exceptions\QueryException` — *cannot say which streams it holds* — rather than reporting an empty
universe.

### Choosing it in the right place

`Contracts\Ledger` is a **scoped** binding: it is built the first time anything resolves it, and one
`Sentinel::audits()` is enough. Set the driver where the application boots, not in the test body.

```php
// tests/TestCase.php — Testbench
protected function defineEnvironment($app): void
{
    $app['config']->set('sentinel.ledger.default', 'memory');
}
```

In a Laravel application, `<env name="SENTINEL_LEDGER" value="memory"/>` in `phpunit.xml` does the
same thing one level earlier.

> ⚠️ **Warning.** `config()->set('sentinel.ledger.default', …)` inside a test works only while
> nothing has touched the facade yet. One `Sentinel::audits()` before it and the whole test runs
> against the previous driver, with no error to show for it.

---

## The deferred write

`transactions.after_commit` defaults to `true`. Inside a database transaction, `Dispatch\Dispatcher`
does not write: it registers the settlement on the subject's connection with
`Connection::afterCommit()`, so a rollback leaves no record of what never happened. Everything
before that — the pipeline, the context, the tenant that decides the stream — has already run at
capture. See [The write path](../01-concepts/03-the-write-path.md).

The received wisdom is that this breaks under `RefreshDatabase`, because that trait wraps every test
in a transaction that is rolled back instead of committed. **On Laravel 13 it does not.**
`RefreshDatabase` and `DatabaseTransactions` both install
`Illuminate\Foundation\Testing\DatabaseTransactionsManager`, whose
`callbackApplicableTransactions()` skips one pending transaction per connection it is transacting.
With only the wrapping transaction open there is nothing applicable left, and `addCallback()` runs
the callback on the spot.

Measured on a Testbench application running Laravel 13 with `RefreshDatabase`, one capture per case
and SQLite underneath:

| Where the capture happens | `DB::transactionLevel()` | Entries visible at that moment |
|---|---|---|
| No transaction of your own | `1` (the wrapping one) | `1` — it settled immediately |
| Inside `DB::transaction()`, asserted **inside** the closure | `2` | `0` |
| Inside `DB::transaction()`, asserted **after** it returns | `1` | `1` |
| Inside a `DB::transaction()` that throws | — | `0` |
| `after_commit` set to `false`, asserted inside the closure | `2` | `1` |

> 🧪 **Verify it.** Paste this into your own suite before you design around the problem.
>
> ```php
> use Illuminate\Support\Facades\DB;
>
> it('holds a capture until my own transaction commits', function (): void {
>     DB::transaction(function (): void {
>         Sentinel::event('checked')->record();
>
>         expect(Audit::query()->count())->toBe(0);
>     });
>
>     expect(Audit::query()->count())->toBe(1);
> });
> ```

So the symptom is narrower than the folklore, and it is this: **an assertion written inside a
transaction you opened yourself sees nothing.** The `Events\Audited` event has not fired either —
`Dispatch\Dispatcher::handed()` announces it from the same deferred callback — so there is nothing
to listen for at that point in the test. The same applies to a test that calls
`DB::beginTransaction()` by hand and never commits it: that transaction *is* applicable, and the
callback dies with the test.

Four ways out, in the order you should reach for them:

| Way out | How | What it costs |
|---|---|---|
| Assert after the transaction returns | move the expectation out of the closure | Nothing. This is the honest test: the fact is not a fact until the commit |
| Turn the deferral off for that one test | `config()->set('sentinel.transactions.after_commit', false)` | The entry is written inside the transaction, so a rollback in the same test discards it too — unless the ledger is on another connection |
| Drop the wrapping transaction | `Illuminate\Foundation\Testing\DatabaseMigrations` instead of `RefreshDatabase` | A `migrate:fresh` per test. This package's own suite works this way |
| Assert the hand-off instead of the row | under `mode = queue`, `Bus::assertDispatched(...)` after the commit | You have proved dispatch, not settlement — see [below](#the-parts-that-need-a-real-service) |

---

## Asserting on the trail

Read the trail through the published surface — `Sentinel::audits()`, or the `audits()` relation the
`Concerns\Auditable` trait puts on the model. Both go through `Contracts\Ledger`, which is what
makes the same assertion true whichever driver the suite points at. See
[The Query API](../06-reading/01-the-query-api.md).

```php
use ElPandaPe\Sentinel\Enums\Severity;
use App\Models\Order;
use App\Models\User;

it('records who marked the order shipped and what moved', function (): void {
    $this->actingAs($operator = User::factory()->create());

    $order = Order::factory()->create(['status' => 'packing']);
    $order->update(['status' => 'shipped']);

    $entry = Sentinel::audits()->for($order)->latest()->take(1)->get()->firstOrFail();

    expect($entry->event)->toBe('updated')
        ->and($entry->severity)->toBe(Severity::Info)
        ->and($entry->actor_id)->toBe((string) $operator->getKey())
        ->and($entry->before['status'])->toBe('packing')
        ->and($entry->after['status'])->toBe('shipped');
});
```

### The diff, the context and the labels

`Models\Audit::diff()` is the structured change: RFC 6901 pointers with the operation and both
values. Prefer it to `before`/`after` when what you care about is *what moved*, because it does not
grow a line every time the model gains a column. `context` is what the resolvers produced beyond the
nine promoted columns, cast to an array. Labels are a loaded relation, not a column. See
[Diffs](../03-capture/03-diffs.md) and [Labels](../06-reading/06-labels.md).

```php
expect($entry->diff()->toArray())->toBe([
    ['path' => '/status', 'op' => 'replace', 'old' => 'packing', 'new' => 'shipped'],
])
    ->and($entry->diff()->toJsonPatch(false))->toBe([
        ['op' => 'replace', 'path' => '/status', 'value' => 'shipped'],
    ])
    ->and($entry->context)->toHaveKey('url')
    ->and($entry->tags->pluck('tag')->all())->toContain('billing')
    ->and(Sentinel::audits()->whereTag('billing')->get())->toHaveCount(1);
```

### The chain

Worth one assertion in the suite, not one per test. `Sentinel::verifyIntegrity()` rereads every
entry of the range and rehashes it; `$entry->verifyIntegrity()` does the same for one. Both work
against the `memory` ledger as well as the database one. See
[Verification](../07-integrity/06-verification.md).

```php
expect(Sentinel::verifyIntegrity('global')->isIntact())->toBeTrue();
```

With `integrity.stream` on its `tenant` default and no tenant resolved the stream is named `global`;
with a tenant it is `tenant:<id>`. Read the name off the entry rather than hard-coding it if your
suite resolves one.

One more thing a seeded suite meets before production does: `AuditQuery::get()` refuses an unbounded
read once more than `AuditQuery::DEFAULT_LIMIT` (500) entries match, rather than hand back a prefix.
Narrow it, `take()` it, or `paginate()` it — see
[Order, paging and walking the trail](../06-reading/03-order-paging-and-walking.md#how-much-comes-back).

---

## Turning auditing off in a test

Four switches, at four different levels. See
[Turning auditing off](../02-getting-started/04-turning-auditing-off.md).

| Switch | Scope | Entry is | Reach for it when |
|---|---|---|---|
| `Sentinel::withoutAuditing(fn)` | the closure | never captured | Seeding fixtures inside a test that then asserts on a clean trail |
| `Sentinel::pause()` / `Sentinel::resume()` | the rest of the test | never captured | A `beforeEach` that seeds, resumed before the act |
| `config()->set('sentinel.enabled', false)` | the rest of the test | never captured | The whole test is about the code around auditing |
| `ledger.default` = `null` | the whole suite | captured, sealed, dropped | You want the write path exercised — pipeline, context, hashing — at no storage cost |

> ⚠️ **Warning.** There is a fifth switch nobody meant to throw. `Event::fake()` with no arguments
> turns model auditing off and says nothing about it: the faked dispatcher records events instead of
> calling listeners, and `Concerns\Auditable` registers its capture through
> `Model::registerModelEvent()`, so `created`, `updated`, `deleted` and `forceDeleted` never reach
> `Capture\ModelObserver`. On the same application measured above, a create that normally writes one
> entry writes **zero** under a bare `Event::fake()`, and one under
> `Event::fake([OrderShipped::class])`. Name what you fake.

---

## The factory

`Database\Factories\AuditFactory` ships inside the package, autoloaded under `autoload` and not
`autoload-dev`. Nothing has to be published: `Audit::factory()` works out of the box, and it resolves
the model class from `models.audit`, so a subclassed audit model gets the same factory.
`definition()` fills the columns that have no sensible zero value:

| Column | Value |
|---|---|
| `stream` | `'global'` |
| `sequence` | a unique integer between 1 and 1,000,000 |
| `audit_type` · `event` | `'model'` · `'created'` |
| `severity` · `source` | `Severity::Info` · `Source::System` |
| `context` | `[]` |
| `payload_version` · `algorithm` | `1` · `'sha256'` |
| `hash` | `hash('sha256', (string) Str::ulid())` — a placeholder |
| `occurred_at` | `now()` |

Everything else is yours to pass. `before`, `after`, `changes`, `context` and `metadata` are cast to
arrays, so you hand them arrays; `subject` is a `MorphTo`, so Laravel's `for()` fills both halves,
and `diff()` is computed on read from whatever `before` and `after` you gave it.

```php
$entry = Audit::factory()->for($order, 'subject')->create([
    'event' => 'updated',
    'before' => ['status' => 'packing'],
    'after' => ['status' => 'shipped'],
]);
```

> ⚠️ **Warning.** The factory builds a **row**, not a chained entry: its `hash` is a placeholder, it
> sets no `previous_hash`, and its `sequence` is random, so `verifyIntegrity()` on a factory-made
> entry is `false` and always will be. Use it for entries a test only reads back — a presenter, an
> export, a filter, a policy. Anything asserting on the chain has to be written through the package.

---

## The parts that need a real service

### The buffered mode

`buffer.store` is a closed match over `redis` and `memory`, and `memory` is the same kind of object
the memory ledger is: a reference implementation and a test double, never a store. Point the mode at
it when the test is about the *mode* — thresholds, flush on shutdown, what a batch of one does.
`Buffer\Flusher` is `@internal`; the supported way to force a flush is the command.

```php
beforeEach(function (): void {
    config()->set('sentinel.mode', 'buffered');
    config()->set('sentinel.buffer.store', 'memory');
});

it('settles nothing until the buffer is flushed', function (): void {
    Order::factory()->create();

    expect(Audit::query()->count())->toBe(0);

    $this->artisan('sentinel:flush');

    expect(Audit::query()->count())->toBe(1);
});
```

If the test is about the *driver* — that entries survive the process, that two workers do not
double-flush — a real Redis is the only thing that proves it. This package covers `Buffer\RedisBuffer`
against a real server for that reason, on the same dataset as the memory one. See
[The buffered mode](../09-operations/02-the-buffered-mode.md).

### The queue mode

A fake queue proves the hand-off and nothing else. Under `mode = queue`, `Bus::fake()` records the
settlement job — `Jobs\SettleAudit`, `@internal` and named here only because `assertDispatched()`
needs a class — and never runs it. The measured entry count after a create is **zero**: the right
answer to "did the request stop paying for the write", and no answer at all to "did the entry land,
with the right sequence, on the right chain".

```php
use ElPandaPe\Sentinel\Jobs\SettleAudit;
use Illuminate\Support\Facades\Bus;

beforeEach(fn () => config()->set('sentinel.mode', 'queue'));

it('moves the write off the request', function (): void {
    Bus::fake();

    Order::factory()->create();

    Bus::assertDispatched(SettleAudit::class);
    expect(Audit::query()->count())->toBe(0);
});

it('settles it when the worker runs the job', function (): void {
    config()->set('queue.default', 'sync');

    Order::factory()->create();

    expect(Audit::query()->sole()->verifyIntegrity())->toBeTrue();
});
```

The `sync` connection is what turns a dispatch into a settlement in-process, and it costs you the
real job — which is the point: the context that travels with the payload, the capture identifier
that makes a retry idempotent, the microsecond clock across the boundary. See
[Running audits on a queue](../09-operations/03-queues.md).

---

## Testing your own extensions

For a ledger driver, do not write this yourself: extend `Testing\LedgerContractTestCase` and let the
package hold your driver to the chain. See [The contract test suite](04-the-contract-test-suite.md).
For everything else, one worked test apiece.

### A resolver

`Contracts\Resolver` has one method returning an array. Nine keys are promoted to columns; every
other key lands in the `context` payload. Assert on the entry, not on the resolver: what matters is
that the value reached a column. See
[Writing your own resolver](../04-context/07-writing-your-own-resolver.md).

```php
it('lands the tenant on the entry, and therefore on its chain', function (): void {
    config()->set('sentinel.resolvers.tenant.class', TenantFromHost::class);

    $this->get('https://acme.example.test/orders');
    Order::factory()->create();

    expect(Audit::query()->sole()->tenant_id)->toBe('acme')
        ->and(Audit::query()->sole()->stream)->toBe('tenant:acme');
});
```

### A pipeline stage

`Contracts\Transformer` takes the entry and the rest of the pipeline. Returning `null` discards the
entry — before the ledger has given it a sequence, so no gap is left behind. The `pipeline` config
key is a list in execution order, and adding a stage means declaring the list with it in. See
[The write pipeline](../05-pipeline-and-security/01-the-write-pipeline.md).

```php
use ElPandaPe\Sentinel\Contracts\Transformer;

it('discards an entry about a synthetic order and lets a real one through', function (): void {
    config()->set('sentinel.pipeline', [...config('sentinel.pipeline'), DropSyntheticOrders::class]);

    Order::factory()->create(['reference' => 'SYNTHETIC-1']);
    Order::factory()->create(['reference' => 'ORD-1']);

    expect(Audit::query()->sole()->after['reference'])->toBe('ORD-1');
});
```

### A masker

`Contracts\Masker::mask(string $field, mixed $value)` renders one redacted value, and it is a pure
function — but the test worth writing is the integration one, because it also proves the field is
declared. `security.redaction.masker` is the default and `security.redaction.maskers.<field>` wins
over it. See [Writing a masker](../05-pipeline-and-security/05-writing-a-masker.md).

```php
use ElPandaPe\Sentinel\Contracts\Masker;
use App\Models\Payment;

it('never lets a full card number reach the ledger', function (): void {
    config()->set('sentinel.security.redaction.maskers', ['card_number' => PanMasker::class]);

    Payment::factory()->create(['card_number' => '4111111111111234']);

    expect(Audit::query()->sole()->after['card_number'])->toBe('•••• 1234');
});
```

### A signer

`Contracts\Signer` has three methods — `sign()`, `verify()` and `keyId()` — and **no registration
point**: `Integrity\Signers::build()` is a closed match over `hmac`, `openssl` and `null`, so a
signer of your own is not something `integrity.signature.signer` can name. Test it as the standalone
class it is, against the property that matters. An empty string back from `sign()` means "no claim":
`Ledger\EntryBuilder` leaves both signature columns null rather than storing something that reads as
an attestation. See [Signing the chain](../07-integrity/04-signing.md).

```php
use ElPandaPe\Sentinel\Contracts\Signer;

it('verifies its own signature and refuses a hash it did not sign', function (): void {
    $signer = new KmsSigner(key: 'test-key');
    $hash = hash('sha256', 'an entry');

    $signature = $signer->sign($hash);

    expect($signer->verify($hash, $signature))->toBeTrue()
        ->and($signer->verify(hash('sha256', 'another entry'), $signature))->toBeFalse()
        ->and($signer->keyId())->toBe('test-key');
});
```

---

## What not to assert

| Do not assert | Why |
|---|---|
| A hash you computed by hand | The digest is taken over the canonical payload of the frozen column list, with the salt derived from `APP_KEY`. Reproducing it in a test means reimplementing `Integrity\JsonCanonicalizer` and `Integrity\Hasher`, and the copy rots on the first change to either. Assert `verifyIntegrity()` instead |
| A `sequence` as a literal, past the first entry | It is dense and monotonic **per stream**, and any other test that writes to the same stream in the same case moves it. Assert the shape — `[1, 2, 3]` for a chain you wrote yourself in that test — never `sequence === 7` |
| Ordering by `created_at` | Two entries in one request can share a clock reading. The trail orders by `id`, a ULID, which is total; `latest()` and `after()` are built on it for that reason |
| `previous_hash` against a value you kept | You are asserting that the ledger did what the ledger did. `verifyIntegrity()` is the assertion, and it is one line |
| `payload_version` as a constant you copied | It is load-bearing: a change to the canonical payload bumps it and ships a compatibility test. Reading it back is fine; pinning it in your suite makes your suite fail on an upgrade that was designed to be safe |
| Column names, table names, or the JSON layout of `changes` | `tables.prefix` and `tables.audits` move them. Go through `Sentinel::audits()` and `Models\Audit`, which read the config |
| The order of keys in `context` | Resolvers fill it and the set grows between versions. Assert `toHaveKey()` and the values you care about |
| Anything reached through `ElPandaPe\Sentinel\Ledger\*` | The whole namespace is `@internal`. Its classes are reachable, not supported: they change without a major version. See [Swapping components](06-swapping-components.md) |

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| Every audit assertion returns `0`, and only since you added `Event::fake()` | A bare `Event::fake()` replaces the dispatcher, so the Eloquent model events `Concerns\Auditable` registers never reach `Capture\ModelObserver` | Name the events: `Event::fake([OrderShipped::class])`. Capture keeps working |
| An assertion inside a `DB::transaction()` closure sees nothing | `transactions.after_commit` is `true`; the write is queued on the connection's after-commit callback, and `Events\Audited` waits with it | Assert after the closure returns, or set `sentinel.transactions.after_commit` to `false` for that test |
| `Audit::query()->count()` is `0` but `Sentinel::audits()->get()` has entries | `ledger.default` is `memory`; its entries are unsaved models held on the instance | Read through the facade, or switch that suite to `database` |
| Switching `ledger.default` in the test body changes nothing | `Contracts\Ledger` is a `scoped` binding and something already resolved it — one `Sentinel::audits()` is enough | Set it in `defineEnvironment()`, in `getEnvironmentSetUp()`, or as `SENTINEL_LEDGER` in `phpunit.xml` |
| `$entry->verifyIntegrity()` is `false` on an entry that looks fine | `AuditFactory` fills `hash` with a placeholder and sets no `previous_hash` | Write through the package for anything that asserts on the chain |
| `Sentinel::verifyEverything()` throws `QueryException` | `ledger.default` is `null`, and `NullLedger` does not implement `Contracts\EnumeratesStreams` | Name the stream, or point the suite at `memory` or `database` |
| `Sentinel::audits()->get()` throws `QueryException` in a seeded test | The read is unbounded and more than `AuditQuery::DEFAULT_LIMIT` (500) entries match | Narrow it, `take()` it, or `paginate()` it |
| Queue mode: `Bus::fake()` and then no entries | The job was recorded, not run | `Bus::assertDispatched()` for the hand-off; `config()->set('queue.default', 'sync')` for settlement |
| Buffered mode: nothing in the table at the end of the test | Nothing reached a threshold and no request ended | `$this->artisan('sentinel:flush')` |
| A cleanup helper throws `ImmutableAuditException` | `Models\Audit` refuses an update and a delete through its model events | `Audit::query()->delete()` goes through the builder and fires none |
| `subject_id` compares unequal against `$model->getKey()` | The column is a string; a factory-built entry also keeps whatever type you gave it until it is re-read | Compare against `(string) $model->getKey()` |
| Entries from one test appear in the next | The trail is on a connection `RefreshDatabase::connectionsToTransact()` does not cover — it returns `[config('database.default')]` | Declare `protected $connectionsToTransact = [null, 'audit'];` |
| Digest or masking assertions that move between runs | `APP_KEY` is regenerated per run, and `security.hashing.salt` is derived from it when null | Pin `app.key` in the test environment, as this package pins its own |

---

## ✅ Best practices

✅ **Do** — read the trail through the published surface, so the assertion survives a change of
driver, of table prefix, or of audit model.

```php
expect(Sentinel::audits()->for($order)->get()->pluck('event')->all())->toBe(['created', 'updated']);
```

❌ **Don't** — reach for the table. It moves with `tables.prefix` and `tables.audits`, it holds the
JSON as one engine happened to store it, and it answers nothing at all under the `memory` ledger.

```php
expect(DB::table('sentinel_audits')->count())->toBe(2);
```

✅ **Do** — let the package build the chain, and assert on the verifier's verdict.

```php
Order::factory()->create()->update(['status' => 'shipped']);

expect(Sentinel::verifyIntegrity('global')->isIntact())->toBeTrue();
```

❌ **Don't** — hand-write an entry and then assert on its integrity. The factory sets a placeholder
hash and no `previous_hash`, so this is `false` by construction and tells you nothing.

```php
expect(Audit::factory()->create()->verifyIntegrity())->toBeTrue(); // always fails
```

✅ **Do** — name the events you fake, so capture keeps running.

```php
Event::fake([OrderShipped::class]);
```

❌ **Don't** — fake the whole dispatcher in a test that also asserts on the trail. Model auditing
goes silent and the expectation fails for a reason that is nowhere near the code under test.

```php
Event::fake();

expect(Audit::query()->count())->toBe(1); // 0
```

✅ **Do** — silence auditing while you seed, so the act of the test is the only thing in the trail.

```php
Sentinel::withoutAuditing(fn () => Order::factory()->count(50)->create());
```

❌ **Don't** — seed loudly and delete afterwards. `Models\Audit` refuses a model delete outright, and
a builder delete that succeeds teaches your suite a habit the production trail forbids.

```php
Audit::query()->latest()->first()->delete(); // ImmutableAuditException
```

---

**See also:** [The contract test suite](04-the-contract-test-suite.md) ·
[The shipped drivers](02-shipped-drivers.md) ·
[Swapping components](06-swapping-components.md) ·
[Turning auditing off](../02-getting-started/04-turning-auditing-off.md) ·
[The Query API](../06-reading/01-the-query-api.md) ·
[Verification](../07-integrity/06-verification.md) ·
[Performance modes](../09-operations/01-performance-modes.md) ·
[Running audits on a queue](../09-operations/03-queues.md) ·
[Events and listeners](../09-operations/04-events-and-listeners.md) ·
[Configuration](../99-reference/02-configuration.md) ·
[Exceptions](../99-reference/06-exceptions.md)
