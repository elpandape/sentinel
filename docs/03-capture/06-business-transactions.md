# 📥 Business transactions

> How to give a handful of entries one name and one identifier, and how to make sure none of them
> claims a fact the database rolled back.

**On this page:** [Correlation is not atomicity](#correlation-is-not-atomicity) · [What the scope stamps](#what-the-scope-stamps) · [The header row](#the-header-row) · [Nesting](#nesting) · [When the callback throws](#when-the-callback-throws) · [What `audits_count` counts](#what-audits_count-counts) · [Reading an operation back](#reading-an-operation-back) · [Waiting for the commit](#waiting-for-the-commit) · [The three performance modes](#the-three-performance-modes) · [Pitfalls](#️-pitfalls) · [Best practices](#-best-practices)

---

## Correlation is not atomicity

A payment touches an invoice, writes a payment record and syncs a relation. That is one thing the
application decided to do, and without help it reaches the trail as three entries that happen to
share a request.

`Sentinel::transaction()` names it:

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

$reference = Sentinel::transaction('invoice-payment', function () use ($invoice, $payment, $reviewer): string {
    $invoice->update(['status' => 'paid']);
    $payment->save();
    $invoice->reviewers()->sync([$reviewer->getKey()]);

    return $payment->reference;
});
```

The signature is `transaction(string $name, Closure $callback): mixed`, and the return value is the
closure's own — the scope is transparent to the code it wraps.

**It opens no database transaction.** `Sentinel\Transactions\TransactionScope::run()` writes a header
row, runs your callback, and closes the header. There is no `DB::transaction()` anywhere in it and
no setting that adds one; the package's own test asserts `DB::transactionLevel()` is `0` from inside
the callback. Correlating and atomising are two decisions, and the second one stays yours:

```php
use Illuminate\Support\Facades\DB;

Sentinel::transaction('invoice-payment', function () use ($invoice, $payment): void {
    DB::transaction(function () use ($invoice, $payment): void {
        $invoice->update(['status' => 'paid']);
        $payment->save();
    });
});
```

That nesting order matters, and [`audits_count`](#what-audits_count-counts) explains why.

> 📌 **Note.** When auditing is off — `sentinel.enabled` is `false`, or you are inside
> `Sentinel::withoutAuditing()` — `transaction()` runs the callback and returns its value, writes no
> header, and correlates nothing. See [Turning auditing off](../02-getting-started/04-turning-auditing-off.md).

---

## What the scope stamps

Every capture that passes through `Capture\Recorder` while a scope is open gets its
`transaction_id` set, before the [write pipeline](../05-pipeline-and-security/01-the-write-pipeline.md)
runs. An entry captured outside any scope carries `null`.

Two consequences worth knowing:

- **The identifier is sealed by the chain.** `transaction_id` is one of the twenty-seven columns in
  `Integrity\CanonicalPayload::COLUMNS`, so it is inside the hashed payload. You cannot re-correlate
  an existing entry after the fact by updating the column: the entry would then fail its own hash
  and [verification](../07-integrity/06-verification.md) would report it as tampered. Correlation is
  a decision made at capture time or not at all.
- **The stamp happens before the pipeline, the count after it.** A capture the pipeline discards —
  an update that changed nothing audited, an entry a `Sentinel::filter()` policy refused — never
  becomes an entry, so it is neither stamped into the trail nor counted into the header.

Mass operations and state transitions inherit the same identifier. A
[mass operation](05-mass-operations.md) in `individual` or `hybrid` mode opens a scope of its own
named `mass.updated` or `mass.deleted` when it runs standalone, and keeps the outer identifier when
it runs inside one of yours.

---

## The header row

The scope writes one row in `sentinel_transactions` (prefixed, so `sentinel_transactions` with the
shipped `tables.prefix`). It is the one table in the package whose rows are **completed after they
are written** — opened when the scope opens, finished when it closes — and nothing in it is hashed.

| Column | Type | Written | What it holds |
|---|---|---|---|
| `id` | `char(26)` ULID, primary key | on open | The `transaction_id` every entry of the operation carries |
| `name` | `varchar(128)` | on open | The string you passed, verbatim |
| `actor_type` / `actor_id` | `string` / `string(64)`, nullable | on open | Resolved by the same context engine that names an entry's actor |
| `tenant_id` | `string(64)`, nullable | on open | Same resolver an entry uses |
| `started_at` | `datetime(6)` | on open | The instant the context engine stamped for the header |
| `finished_at` | `datetime(6)`, nullable | on close | Null while the operation runs — and null forever if the process died |
| `audits_count` | `unsigned int`, default `0` | on close | Hand-overs this process made, [not a row count](#what-audits_count-counts) |
| `metadata` | `jsonb`, nullable | on close | `nested` and/or `failed`; `null` when neither applies |

The model is `ElPandaPe\Sentinel\Models\AuditTransaction`. It has `$timestamps = false` — there is no
`created_at` and no `updated_at`, because `started_at` and `finished_at` already say when the
operation ran. Three indexes exist, on `(name, started_at)`, `(started_at)` and
`(tenant_id, started_at)`.

**The header is not evidence.** It says what the operation was called, who ran it and when. What
happened is in the entries, and that is what the [hash chain](../07-integrity/01-the-hash-chain.md)
covers. Nothing recomputes or verifies a header; `sentinel:verify` never reads this table. That is
precisely what allows the row to be completed when the scope closes, while `sentinel_audits` cannot
be touched at all.

**The header is opened before your callback runs.** An operation that dies halfway — a fatal error,
a killed worker — leaves a row with a name, a `started_at` and a null `finished_at`, which is how
you find it. Closing it is the second write.

> ⚠️ **Warning.** `name` is `varchar(128)` and the package does not check the length. On PostgreSQL
> an over-long name is an error out of `Sentinel::transaction()`; on MySQL in non-strict mode it is
> silently truncated. Use short, constant names.

> 🧪 **Verify it.** `php artisan tinker` →
> `Sentinel::transaction('smoke-test', fn () => null);`
> `ElPandaPe\Sentinel\Models\AuditTransaction::query()->latest('started_at')->first()->toArray();`

---

## Nesting

A nested call keeps the outer identifier and opens no header of its own. A business operation does
not split into two because its implementation reused a helper that already wrapped itself.

```php
Sentinel::transaction('close-the-month', function (): void {
    Sentinel::transaction('recalculate-balances', function (): void {
        // ...
    });
    Sentinel::transaction('recalculate-balances', function (): void {
        // ...
    });
});
```

That produces **one** row in `sentinel_transactions`:

```php
$header->name;      // 'close-the-month'
$header->metadata;  // ['nested' => ['recalculate-balances']]
```

The rules `TransactionScope::nest()` applies: names are appended in the order the scopes opened, a
repeat of a name already listed is not appended again, and a nested name identical to the outer
one is skipped. Nesting is not depth-limited.

---

## When the callback throws

The failure is re-thrown, unchanged, after the header is closed. The header keeps everything it had:

```php
try {
    Sentinel::transaction('invoice-payment', function () use ($invoice): void {
        $invoice->update(['status' => 'paid']);

        throw new PaymentDeclined('card 4111111111111111 declined');
    });
} catch (PaymentDeclined) {
}

$header->finished_at;   // set — the operation closed, it did not hang
$header->audits_count;  // 1 — what it managed to write before the fall is kept
$header->metadata;      // ['failed' => App\Exceptions\PaymentDeclined::class]
```

Two things this deliberately does **not** do:

- **It records the class of the failure, never its message.** A header does not go through the
  pipeline, so no [masker](../05-pipeline-and-security/05-writing-a-masker.md) would touch a domain
  value someone interpolated into an exception message. The card number above does not reach the
  header.
- **It does not roll anything back.** The entries captured before the throw stay in the trail,
  carrying the operation's identifier. History is append-only; an operation that failed halfway is a
  fact, and the trail says so.

If closing the header itself throws — the table is gone, the PostgreSQL connection is already
aborted — that second exception is swallowed and your own is the one that surfaces. The header is
then left with a null `finished_at`, which already reads as *an operation that did not close*, and
that says more than an audit-engine exception standing where the application's belongs. The scope
resets itself so the next call in the same request opens a fresh header.

---

## What `audits_count` counts

It is **the number of entries this process handed over to the ledger while the header was open**. It
is not a row count, and reading it as one is the most common mistake on this page.

`Dispatch\Dispatcher::handed()` increments it only when a hand-over was accepted. So it does not
count:

| Not counted | Why |
|---|---|
| A capture the pipeline discarded | It never became an entry |
| A write the ledger refused (a failure under `on_write_failure=log`) | Nothing settled |
| An entry a `DB::transaction()` rollback threw away | The deferred callback never ran |
| An entry a worker writes after inheriting the identifier | The worker holds no header instance |

And under an [asynchronous mode](../09-operations/01-performance-modes.md) it counts hand-overs
while the rows exist elsewhere or later:

```php
config()->set('sentinel.mode', 'queue');

Sentinel::transaction('close-invoices', function () use ($a, $b): void {
    $a->save();
    $b->save();
});

$header->audits_count;                    // 2 — two jobs were enqueued
Audit::query()->count();                  // 0 — no worker has run yet
```

> ⚠️ **Warning.** Wrapping the whole scope in a `DB::transaction()` gives you a header that claims
> `0`. With [`after_commit`](#waiting-for-the-commit) on, every write is deferred to the commit —
> which happens *after* the scope has already closed and set the count. The entries still carry the
> identifier and `inTransaction()` still finds them; only the number on the header is wrong. Open
> the database transaction **inside** the scope, not around it.

Pruning does not touch this number either: `Retention\Cascade` deletes a header when its last entry
is gone (asked across every stream, since one operation can write into several chains) and leaves
`audits_count` exactly as the operation left it. See
[Retention and pruning](../08-lifecycle/01-retention-and-pruning.md).

---

## Reading an operation back

Four ways in, and they answer the same question from different ends:

```php
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Models\AuditTransaction;

$header = AuditTransaction::query()->latest('started_at')->firstOrFail();

// The trail, narrowed to one operation — accepts the header or just its id.
Sentinel::audits()->inTransaction($header)->get();
Sentinel::audits()->inTransaction($header->id)->get();

// The relation, ordered by the entry id — the order the ledger settled them in.
$header->audits;

// And back the other way. Null for an entry that belongs to no operation.
Audit::query()->firstOrFail()->transaction?->name;
```

`inTransaction()` is one of the published [filters](../06-reading/02-filters-reference.md). A ledger
driver that does not declare `Filter::Transaction` throws `LedgerException::cannotFilterBy()` rather
than returning a wrong answer — see [The Ledger contract](../11-extending/01-the-ledger-contract.md).

> 📌 **Note.** `config('sentinel.models.transaction')` replaces the model the package constructs when
> it opens a header, but `AuditTransaction::audits()` and `Audit::transaction()` name their target
> classes directly. Walking either relation hands back the package's own class, not your subclass.

---

## Waiting for the commit

This is the second, entirely separate decision. `transactions.after_commit` ships as `true`:

```php
// config/sentinel.php
'transactions' => [
    'after_commit' => true,
],
```

With it on, `Dispatch\Dispatcher` asks the **subject's** connection whether a transaction is open. If
one is, the write is registered as an `afterCommit()` callback on that connection instead of running
now. An entry that names no subject waits on the default connection.

```php
use ElPandaPe\Sentinel\Models\Audit;
use Illuminate\Support\Facades\DB;

DB::transaction(function () use ($invoice): void {
    $invoice->update(['status' => 'paid']);

    Audit::query()->count();   // 0 — nothing has settled yet
});

Audit::query()->count();       // 1 — settled on the commit
```

A rollback leaves **nothing**. Not a partial entry, not a discarded one, not a gap in the chain —
the callback is discarded by the framework and no sequence number was ever spent. A savepoint
behaves the way savepoints do: rolling back an inner `DB::transaction()` drops that level's entries
and keeps the outer ones.

### What waits, and what does not

Only the write waits. The [pipeline](../05-pipeline-and-security/01-the-write-pipeline.md) runs at
capture, every time, and the package's own comments explain why:

| Runs at capture | Because |
|---|---|
| Context resolution (actor, tenant, request, trace) | The actor can log in before the commit; the entry must name whoever caused it |
| `Sentinel::withContext()` metadata | The callback that pushed it has returned long before the commit |
| Masking, hashing, encryption | Nothing sensitive may sit in a pending callback in the clear |
| The stream decision | The tenant decides which chain signs the entry, and it can change mid-transaction |

The cost of that is honest and worth stating: **a rollback throws away the pipeline work you already
paid for.** That is the price of never claiming a fact the database undid.

### What deferral buys, and what it does not

It does not buy speed. The package's own benchmarks do not distinguish it from writing in place, and
you should not adopt it or drop it as a performance setting. What it buys:

- **A ledger that is not the rolled-back database keeps nothing it should not.** On the default
  shared connection, turning `after_commit` off usually changes nothing observable — the database
  has already undone the entry itself. Where it bites is a dedicated `database.connection`, a
  non-database ledger, or a [fanout](../11-extending/05-fanout.md): those outlive the rollback and
  will happily keep an entry describing something that never happened.
- **The stream lock is held for the insert, not for the operation.** `Ledger\StreamGate::tail()`
  takes `pg_advisory_xact_lock()` on PostgreSQL and `lockForUpdate()` on MySQL, and both release
  when the transaction closes. Writing the entry *inside* a long business transaction therefore
  holds the chain's lock for the whole operation — with `integrity.stream` set to `tenant`, that
  serialises the tenant's audit writes behind your slowest transaction. Deferring reduces it to the
  window of the insert. See [PostgreSQL](../10-database-engines/02-postgresql.md) and
  [MySQL](../10-database-engines/03-mysql.md).

### Why a deferred failure is announced and not thrown

`Capture\WriteFailure` has two branches, and they differ in exactly one thing. A write that happens
in the request obeys `on_write_failure` and can propagate. **A write deferred to a commit never
propagates, whatever the policy says** — including under
[compliance mode](../08-lifecycle/05-compliance-mode.md), which forces the policy to `throw` for the
in-request branch and cannot reach this one.

Two reasons, both structural: the framework runs commit callbacks in a bare loop, so an exception
there would stop every later entry of the same transaction from even being attempted; and it would
surface out of a `DB::transaction()` that has already committed, reporting the failure of something
that succeeded.

What happens instead: the `Events\AuditWriteFailed` event is dispatched, and the failure is written
to `log_channel` as the exception nobody is going to catch. The event carries identity only —
`auditType`, `event`, `subjectType`, `subjectId`, `transactionId`, `failure` — never the payload.

```php
use ElPandaPe\Sentinel\Events\AuditWriteFailed;
use Illuminate\Support\Facades\Event;

Event::listen(function (AuditWriteFailed $failed): void {
    // $failed->transactionId names the operation whose entry never landed.
    report($failed->failure);
});
```

If you rely on `on_write_failure=throw` to notice broken audit writes, you are not covered inside
transactions. Wire the event. See [Failure policy](../09-operations/05-failure-policy.md).

---

## The three performance modes

`after_commit` decides *when* the hand-over happens; `sentinel.mode` decides *what* the hand-over is.
They compose, and neither replaces the other.

| Mode | What the commit triggers | What `audits_count` counts | When the entry exists |
|---|---|---|---|
| `sync` | The ledger write itself, in the request | Rows written in this process | Inside the commit callback |
| `queue` | Dispatching one `SettleAudit` job, itself marked `afterCommit()` | Jobs enqueued | When a worker runs the job |
| `buffered` | The push into the buffer, plus a flush if a threshold is now due | Pushes into the buffer | At the flush that carries it |

Notes on the two asynchronous modes:

- Under `queue`, `Dispatch\QueueStrategy` marks the job `->afterCommit()` whenever the package's own
  deferral is on, so the two never disagree; it covers the case of an entry captured against one
  connection while a second still has a transaction open. With `after_commit` off, the job is left
  unmarked, because an operator who turned that off asked for the entry to be written whatever the
  transaction decides.
- Under `buffered`, the hand-over is accepted the moment the entry is in the buffer. A flush that
  fails afterwards is announced through the same `AuditWriteFailed` route and does not cost the
  entry that triggered it. See [The buffered mode](../09-operations/02-the-buffered-mode.md).

> 📌 **Note.** A job **you** dispatch inside `Sentinel::transaction()` does not inherit the operation
> identifier by default. It rides in Laravel's own `Context`, and both `telemetry.enabled` (ships as
> `false`) and `telemetry.propagate_context` must be on for the scope to seal it into the payload.
> A worker that inherits it stamps entries with it but opens no header and counts into none. See
> [Queues, commands and schedulers](../04-context/05-queues-commands-and-schedulers.md) and
> [Distributed tracing](../04-context/06-distributed-tracing.md).

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `audits_count` is `0` but `inTransaction()` returns entries | The whole scope was wrapped in a `DB::transaction()`, so every deferred write landed after the header closed | Open the database transaction inside `Sentinel::transaction()`, not around it; or count with `inTransaction()` |
| `audits_count` is `2` but `sentinel_audits` is empty | Mode is `queue` or `buffered` — the count is of hand-overs, not of rows | Run the worker, or `php artisan sentinel:flush`; read the count as "handed over" |
| No entry at all after a `DB::transaction()` that threw | `after_commit` discarded the pending write, by design | Nothing to fix — the database kept nothing either |
| A test asserting on an entry from inside `DB::transaction()` sees zero | Same deferral | Assert after the commit, or set `sentinel.transactions.after_commit` to `false` in that test |
| A consumer test suite using `RefreshDatabase` or `DatabaseTransactions` never sees a deferred entry mid-test | Those traits replace Laravel's transaction manager and change when commit callbacks fire | Assert outside the transaction that produced the entry; Sentinel's own suite uses `migrate:fresh` and neither trait |
| A ledger write fails silently and the request succeeds, even with `on_write_failure=throw` or compliance on | Deferred writes never propagate | Listen for `Events\AuditWriteFailed` and watch `log_channel` |
| `Illuminate\Database\QueryException` out of `Sentinel::transaction()` before your callback runs | The header could not be inserted — migrations not run, or `tables.transactions` renamed after migrating | Run the migrations; the scope stays usable for the next call in the same request |
| The header name is truncated (MySQL) or errors (PostgreSQL) | `name` is `varchar(128)` and the package does not guard it | Use short constant names; never interpolate a subject into one |
| A nested call produced no header of its own | Nesting keeps the outer identifier by design; the inner name is in `metadata.nested` | Read `metadata.nested`, or call the inner block outside the outer scope if it really is a separate operation |
| Entries written by a job dispatched inside the scope are uncorrelated | `telemetry.enabled` ships as `false`, so the identifier is not sealed into the job payload | Turn on `telemetry.enabled` and `telemetry.propagate_context` |
| `$header->audits` returns `Models\Audit`, not the subclass named in `models.audit` | `audits()` and `transaction()` name their classes directly | Query your model explicitly with `inTransaction($header->id)` |
| `LedgerException` from `inTransaction()` | The active ledger driver does not declare `Filter::Transaction` | Filter in the driver that supports it, or declare the filter in your driver |

---

## ✅ Best practices

✅ **Do** — name an operation after the business fact, with a constant string. The name is indexed
with `started_at` and is how you find every run of the same operation.

```php
Sentinel::transaction('invoice-payment', fn () => $this->settle($invoice, $payment));
```

❌ **Don't** — build the name from the subject. It defeats the `(name, started_at)` index, and a long
one hits the 128-character column with no warning from the package.

```php
Sentinel::transaction("invoice-payment-{$invoice->id}-by-{$user->email}", $callback);
```

---

✅ **Do** — open the database transaction inside the scope when you want both. The commit fires while
the header is still open, so the entries are counted.

```php
Sentinel::transaction('invoice-payment', function () use ($invoice, $payment): void {
    DB::transaction(function () use ($invoice, $payment): void {
        $invoice->update(['status' => 'paid']);
        $payment->save();
    });
});
```

❌ **Don't** — wrap the scope in the transaction. Every deferred write lands after `audits_count` has
already been written, and the header claims zero.

```php
DB::transaction(function () use ($invoice, $payment): void {
    Sentinel::transaction('invoice-payment', function () use ($invoice, $payment): void {
        $invoice->update(['status' => 'paid']);
        $payment->save();
    });
});
```

---

✅ **Do** — leave `transactions.after_commit` on, and treat it as a correctness setting. It is what
stops the ledger asserting a fact the database undid.

```php
'transactions' => [
    'after_commit' => true,
],
```

❌ **Don't** — turn it off expecting a faster request. It is not a performance switch, and off it
buys you an entry describing a rollback on any ledger that is not the rolled-back database itself.

```php
// A dedicated audit connection plus this is a trail that records refunds that never happened.
'transactions' => ['after_commit' => false],
```

---

✅ **Do** — wire `AuditWriteFailed` if a lost entry matters to you. It is the only signal a deferred
write failure ever produces, and it names the operation it belonged to.

```php
Event::listen(fn (AuditWriteFailed $failed) => report($failed->failure));
```

❌ **Don't** — assume `on_write_failure => 'throw'` covers you. It governs the in-request branch only;
inside a transaction nothing propagates, compliance mode included.

```php
// This never fires for an entry written from a commit callback.
try {
    DB::transaction(fn () => $invoice->update(['status' => 'paid']));
} catch (Throwable $e) {
}
```

---

✅ **Do** — read an operation back through the identifier, from either end.

```php
Sentinel::audits()->inTransaction($header)->get();
Audit::query()->firstOrFail()->transaction?->name;
```

❌ **Don't** — reconstruct an operation from a time window. Two requests overlap, and the identifier
exists precisely so you never have to guess.

```php
Sentinel::audits()->between($header->started_at, $header->finished_at)->get();
```

---

✅ **Do** — let a helper open its own scope freely. Nesting is safe, keeps one identifier, and records
the inner name once in `metadata.nested`.

```php
Sentinel::transaction('close-the-month', fn () => $this->recalculate());  // itself wrapped
```

❌ **Don't** — expect a nested call to produce a second header, or read `audits_count` off one. There
is no inner header to read; the count lives on the outermost scope.

```php
Sentinel::transaction('outer', function (): void {
    Sentinel::transaction('inner', fn () => null);
});
AuditTransaction::query()->where('name', 'inner')->firstOrFail();   // ModelNotFoundException
```

---

**See also:** [Mass operations](05-mass-operations.md) · [State transitions](08-state-transitions.md) · [Custom and authentication events](07-custom-and-authentication-events.md) · [The write pipeline](../05-pipeline-and-security/01-the-write-pipeline.md) · [Performance modes](../09-operations/01-performance-modes.md) · [Failure policy](../09-operations/05-failure-policy.md) · [Filters reference](../06-reading/02-filters-reference.md) · [Schema](../99-reference/03-schema.md) · [Configuration](../99-reference/02-configuration.md) · [Events](../99-reference/05-events.md)
