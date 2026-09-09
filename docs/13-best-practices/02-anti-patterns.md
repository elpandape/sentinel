# ✅ Anti-patterns

> Thirteen shapes that pass code review and still cost you a trail you cannot use: what each one
> really does, how you find out (usually late), and the correct shape.

**On this page:** [Auditing everything](#1-auditing-everything-because-you-can) · [The trail as an event bus](#2-the-trail-as-an-event-bus) · [Reading on the hot path](#3-reading-the-trail-on-the-hot-path) · [Writing from a listener](#4-writing-to-the-ledger-from-a-listener) · [Labels as evidence](#5-labels-as-evidence) · [`created_at` as order](#6-treating-created_at-as-the-order-things-happened) · [Buffered with no loss window](#7-buffered-mode-without-a-written-down-loss-window) · [Late tenancy](#8-wiring-the-tenant-resolver-after-the-first-entry) · [Pruning without anchors](#9-pruning-without-anchors) · [Redaction as a plan](#10-redacting-instead-of-designing-what-is-never-captured) · [Swallowing a failure](#11-catching-and-swallowing-a-write-failure) · [Building on `@internal`](#12-building-on-an-internal-declaration) · [Verifying on request](#13-verifying-only-when-someone-asks)

---

## 1. Auditing everything because you can

**Looks reasonable.** The trait is one line, so it goes on every model.

```php
use ElPandaPe\Sentinel\Concerns\Auditable;

final class PageView extends Model
{
    use Auditable;   // and on Session, JobBatch, CacheEntry, Notification…
}
```

**What actually happens.** Every save of every one of those models runs the seven-stage pipeline
and writes a row that takes a sequence number in its stream. On the package's own write-path
benchmark — one create per iteration, a thousand iterations after two hundred warm-up writes,
median of three passes, SQLite, all rows in the same run — an unaudited create costs 179 µs and an
audited one in `sync` mode costs 2068 µs. `snapshots.include_hidden` ships as `true`, so an
attribute in `$hidden` is audited unless you say otherwise, and because the diff duplicates the
values, anything that reaches `before`/`after` is also in `changes`. Nothing a `retention` policy
does not name is ever removed.

**How you find out.** `sentinel_audits` becomes the largest table in the database, a
`whereEvent('updated')` read starts timing out — 32 589 ms over ten million entries on MySQL 9
flat, in the published volume report — and `sentinel:prune --dry-run` reports `undeclared` for
every stream because nobody wrote a policy.

**The correct shape.** Audit the models somebody will be asked to account for, narrow
[what is captured](../03-capture/01-what-gets-audited.md) on the wide ones, and declare
[retention](../08-lifecycle/01-retention-and-pruning.md) on the first day rather than the worst one.

```php
final class Invoice extends Model
{
    use Auditable;

    /** @var list<string> */
    protected array $auditInclude = ['status', 'total', 'customer_id'];
}

// config/sentinel.php
'retention' => [
    'model:App\Models\Invoice' => '7 years',
    'auth' => '90 days',
],
```

---

## 2. The trail as an event bus

**Looks reasonable.** The trail already holds every fact, so a worker reads it and reacts.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

$batch = Sentinel::audits()->whereEvent('order.placed')->after($cursor)->take(500)->get();

foreach ($batch as $entry) {
    ShipOrder::dispatch($entry->subject_id);
    $entry->update(['metadata' => ['shipped' => true]]);   // ← throws
}
```

**What actually happens.** `Models\Audit::booted()` registers `updating` and `deleting` hooks that
throw `ImmutableAuditException`, so there is nowhere to record that you handled a message — an
entry is a link in a hash chain and rewriting it breaks every entry after it. Worse, the bus loses
messages by design: `Pipeline\Stages\FilterUnchanged` drops an `updated` whose comparison came back
empty, so an entry can simply never exist; under the buffered mode what a dying process holds never
reaches the ledger at all, and the chain cannot report that, because an entry that never landed
consumed no sequence.

**How you find out.** Consumers quietly miss work. There is no error, no gap and no failed job —
the fact was never written, and `verifyIntegrity()` reports the shorter chain as intact, correctly.

**The correct shape.** Dispatch your own domain event or job where the fact happens, and let
Sentinel record it. Reacting to the trail is legitimate as a *notification*, never as a queue.

```php
use ElPandaPe\Sentinel\Events\AuditCreated;
use Illuminate\Contracts\Queue\ShouldQueue;

final class MirrorAuditToWarehouse implements ShouldQueue
{
    public function handle(AuditCreated $event): void
    {
        // $event->entry is a settled Audit: id, stream, sequence, hash.
    }
}
```

---

## 3. Reading the trail on the hot path

**Looks reasonable.** A sidebar on the record page shows recent activity.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

$recent = Sentinel::audits()->whereEvent('updated')->latest()->get();   // every request
```

**What actually happens.** `whereEvent()`, `whereType('model')` and `whereRoute()` select a
*category*: the index finds the rows and then everything matched has to be sorted. In the volume
report — ten million entries on MySQL 9 flat, with the JSON index stub published so `whereRoute()`
reaches one at all — `whereRoute()` took 5 118 ms and `whereEvent()` 32 589 ms; an entity filter in
front is what bounds the sort. A bare `get()` refuses rather than truncating once more than 500
entries match — see
[Order, paging and walking the trail](../06-reading/03-order-paging-and-walking.md#how-much-comes-back).
Rendering the result without `loadReferences()` is a query per line and throws under
`Model::preventLazyLoading()`. Under compliance mode every read through the Query API becomes a read
plus two writes — a chained `access` entry that consumes a sequence of the stream it audits, plus a
`sentinel_access_log` row — measured at 0.309 s versus 0.557 s over a hundred reads of fifty
entries, about +2.5 ms per read.

**How you find out.** p99 on an endpoint nobody thought contained a query, or a
`QueryException::unbounded` in production on the day the trail crossed five hundred matches.

**The correct shape.** Put an entity filter in front of the category one, bound the read, hydrate
the page in one pass, and move anything report-shaped off the request.

```php
$page = Sentinel::audits()
    ->for($invoice)          // an entity: reaches the index and bounds the sort
    ->whereEvent('updated')  // a category: it refines, it does not find
    ->latest()
    ->paginate(25);

$page->entries->loadReferences();   // tags + subject + actor, a query per morph type
```

---

## 4. Writing to the ledger from a listener

**Looks reasonable.** Every entry should also produce a summary entry.

```php
use ElPandaPe\Sentinel\Events\AuditCreated;
use ElPandaPe\Sentinel\Facades\Sentinel;
use Illuminate\Support\Facades\Event;

Event::listen(function (AuditCreated $event): void {
    Sentinel::event('audit.mirrored')->metadata(['of' => $event->entry->id])->record();
});
```

**What actually happens.** The mirrored entry is an ordinary entry, so writing it announces
`AuditCreated` again, which runs the listener again. Nothing in the package guards against that
re-entry: `Dispatch\Settlement` dispatches `AuditCreating` and `AuditCreated` around every write,
and `Capture\Recorder` has no latch. Even without recursion, all of Sentinel's own events are
dispatched inline on the write path — `Auditing` through `Dispatcher::until()` at the end of the
pipeline, the two ledger events from `Settlement` — so a listener that writes doubles what the
caller pays and doubles the sequence numbers consumed in that stream. A listener on `Auditing` that
writes re-enters the pipeline while the first entry is still in it.

**How you find out.** A stack overflow or an out-of-memory on the first audited save after deploy,
in the request that caused it.

**The correct shape.** Listeners announce; they do not write. Queue anything slow, and capture a
derived fact where the fact happens rather than from an event about another entry.

```php
use ElPandaPe\Sentinel\Events\Audited;

Event::listen(function (Audited $event): void {
    // $event->entry is the settled Audit under `sync`, and null under `queue`
    // and `buffered` — "settled elsewhere", never "not settled".
    Metrics::increment('audits.'.$event->audit->audit_type);
});
```

---

## 5. Labels as evidence

**Looks reasonable.** Label the entries that matter and count the labels in the report.

```php
final class Payment extends Model
{
    use Auditable;

    /** @var list<string> */
    protected array $auditTags = ['pci'];
}

$proof = Sentinel::audits()->whereTag('pci')->take(500)->get();   // "the evidence"
```

**What actually happens.** Labels live in `sentinel_audit_tags` and are outside
`Integrity\CanonicalPayload::COLUMNS` in both directions. That is deliberate, and it cuts both
ways: classifying an old entry does not break its hash — a taxonomy is not an integrity problem —
and equally, relabelling leaves no trace any verification can find. Anyone with write access to the
database can add or remove a label and `verifyIntegrity()` will not notice. A prune removes an
entry's label rows with the entry, in the same transaction.

**How you find out.** You do not. That is the whole problem with this one.

**The correct shape.** Treat [labels](../06-reading/06-labels.md) as operational classification.
Anything that has to be provable goes in `metadata`, which is one of the twenty-seven
[canonical columns](../07-integrity/03-canonicalization.md) the hash covers.

```php
Sentinel::event('payment.captured')
    ->subject($payment)
    ->tags(['pci'])                              // classification: outside the hash
    ->metadata(['scheme' => 'visa', 'psp' => 'acme'])   // fact: inside the hash
    ->record();
```

---

## 6. Treating `created_at` as the order things happened

**Looks reasonable.** The entries come back in order, so the default order is the timeline.

```php
$lifeline = Sentinel::audits()->for($invoice)->take(200)->get();   // orders by created_at
```

**What actually happens.** `occurred_at` is stamped at capture and never moves. `created_at`,
`sequence` and `version` are stamped in the ledger, at settlement. With
`transactions.after_commit` on — the shipped default — `occurred_at` and `created_at` already
differ by the length of the enclosing database transaction, and under `queue` or `buffered` they
differ by however long the worker or the flush took. `Sentinel::audits()` orders by `created_at`; `byOccurrence()` and
`Sentinel::timeline()` order by `occurred_at`. And `between()` always bounds `created_at`, even
under `byOccurrence()` — narrowing and ordering follow different clocks on purpose.

**How you find out.** Nothing errors. The lifeline quietly starts answering a different question,
usually the week somebody switches `SENTINEL_MODE` for unrelated reasons.

**The correct shape.** Name the clock you mean — the trade-off per mode is in
[performance modes](../09-operations/01-performance-modes.md) — and remember that the chain's own
order is neither of them.

```php
Sentinel::audits()->for($invoice)->byOccurrence()->take(200)->get();  // when it happened
Sentinel::timeline()->forTenant('acme')->paginate(50);               // the same, everywhere
Sentinel::audits()->for($invoice)->take(200)->get();                 // when it settled

$entry->sequence;   // the chain's order: dense and monotonic per stream, in every mode
```

---

## 7. Buffered mode without a written-down loss window

**Looks reasonable.** The buffered mode is the fastest, so it goes in `.env`.

```dotenv
SENTINEL_MODE=buffered
```

**What actually happens.** Both thresholds — `buffer.size` (500) and `buffer.flush_interval`
(60 seconds) — are evaluated only when an entry arrives, from `Dispatch\BufferStrategy` on push.
Nothing inside PHP watches a clock between requests, so a buffer that stops receiving entries stops
being checked; what bounds it then is the `terminating` hook, the `WorkerStopping` hook and
`sentinel:flush`. What a process dies holding is gone: those entries have no sequence, no hash and
no place in any chain, so `verifyIntegrity()` walks a shorter chain and reports it intact —
correctly. `buffer.store = memory` is bound `scoped` and keeps everything on the instance, so its
contents die with the request or job. And flipping the mode away from `buffered` strands whatever
is still waiting: both shutdown hooks return early unless the mode is `buffered`, and
`sentinel:flush` exits 2 naming the mode it found.

**How you find out.** An entry that should exist does not, months later, with nothing to point at.

**The correct shape.** Size `buffer.size` as the loss you can afford rather than as a throughput
knob, put a real ceiling under it, and subscribe to the only signal that names what was at stake.

```php
use ElPandaPe\Sentinel\Events\BufferFlushFailed;
use Illuminate\Support\Facades\Schedule;

Schedule::command('sentinel:flush')->everyMinute()->withoutOverlapping();

Event::listen(function (BufferFlushFailed $failed): void {
    logger()->critical($failed->message(), [
        'taken' => $failed->taken,
        'settled' => $failed->settled,
        'returned' => $failed->returned,   // back in the buffer: a retry, not a loss
        'skipped' => $failed->skipped(),   // deduplicated, or gone for good
    ]);
});
```

> ⚠️ **Warning.** Flush to zero *before* changing `mode`. Once it is no longer `buffered`, nothing
> in the package will touch the buffer again and nothing will report it.

---

## 8. Wiring the tenant resolver after the first entry

**Looks reasonable.** Tenancy arrives in month four, and the resolver is one config line.

```php
// config/sentinel.php — four months and two million entries after go-live
'resolvers' => [
    'tenant' => ['using' => fn () => Tenancy::current()?->getKey()],
],
```

**What actually happens.** `integrity.stream` ships as `tenant`, and `Integrity\Stream` maps that
to `'global'` while `tenant_id` is null and to `'tenant:'.$id` the moment one resolves. The stream
name is part of the hash prefix, so the old chain is not renamed — it stops growing. The new stream
has no tail, so `Ledger\StreamGate` hands back an empty one and the first entry gets `sequence` 1
with a null `previous_hash`. Everything written before keeps verifying under `global`, with its own
genealogy. You now have two independent chains where you thought you had one.

**How you find out.** `sentinel:verify` prints a table with more streams in it than you expected,
and `verifyIntegrity('global')` covers only the first four months.

**The correct shape.** Decide the scope of the chain before the first entry exists, and set it
explicitly rather than inheriting the default.

```php
'integrity' => [
    'stream' => 'global',   // one chain, whatever tenancy does later
    // or 'tenant' — chosen on purpose, before any tenant resolves
],
```

> 📌 **Note.** The same applies to a morph map under `'stream' => 'subject_type'`: the stream is
> named by the morph alias, so adding or changing an alias later forks the chain instead of
> continuing it.

---

## 9. Pruning without anchors

**Looks reasonable.** Retention is declared and the prune is on a schedule.

```php
Schedule::command('sentinel:prune')->dailyAt('03:10');   // and nothing else
```

**What actually happens.** `Retention\Frontiers` only ever offers *anchored* windows — the prune
unit is an anchor range whose every entry has been released, because a window folds to one root and
a partly emptied one could never reproduce it. With no `sentinel_checkpoints` rows the run releases
nothing, reports the hold as `unanchored` and exits 0. A cron that reads only the exit code cannot
tell that from a healthy run. The inverse is the expensive half: rows removed with no anchor over
them leave an absence `sentinel:verify` reports as `sequence_gap`, because it steps over one only
when the manifest accounts for the range **and** the anchors reach past it. Nothing in
`sentinel_archives` is hashed or signed, so a manifest row alone would make "delete the rows, then
insert one row" a way of laundering a gap.

**How you find out.** The table never shrinks and nobody notices for a quarter — or, after a
hand-run `DELETE`, `sentinel:verify` starts exiting 1.

**The correct shape.** Anchor first, on a schedule ahead of the prune, and read the `--dry-run`
report rather than the exit code.

```php
Schedule::command('sentinel:checkpoint')->hourly();
Schedule::command('sentinel:prune')->dailyAt('03:10');
```

```bash
php artisan sentinel:checkpoint          # run the FIRST pass by hand: no --limit exists,
                                         # and on an existing trail it reads the whole thing
php artisan sentinel:prune --dry-run     # the Note column names the hold: undeclared,
                                         # unanchored, tail, or retained (with the sequence)
```

> ⚠️ **Warning.** Never delete a `sentinel_checkpoints` row to tidy up. After the first prune the
> anchors are the only thing standing behind entries that are gone, and each root folds the
> previous one, so removing one leaves every later anchor unverifiable.

---

## 10. Redacting instead of designing what is never captured

**Looks reasonable.** Audit everything now, erase on request later.

```php
use ElPandaPe\Sentinel\Redaction\Redactor;

// "we will just redact it when someone asks"
app(Redactor::class)->redact($entry, 'erasure request', $actor);
```

**What actually happens.** A tombstone is not surgical. `Redaction\Redactor` empties all six
content columns of the canonical payload — `context`, `before`, `after`, `changes`, `metadata`,
`criteria` — and deletes the entry's labels and relation lines. `metadata` goes whole, which destroys
facts that are not personal data at all, a state transition's `reason` included. There is no
redact-by-key, deliberately. Afterwards `verifyIntegrity()` on that row answers `false` forever
(use `verifyContent()` to tell a tombstone from a tampering), and the entry's own hash and link are
kept so the chain still holds. An entry whose range has already been archived is refused by name,
and the supported answer is a round trip: `Archive\Rehydrator::restore()`, redact, re-prune. A
bucket with versioning keeps the previous, unredacted object because a batch path is a pure
function of its range. `sentinel_archives` is indexed by stream and range and never by subject, so
one person's history is answered range by range.

**How you find out.** When the request arrives and the estimate comes back in days.

**The correct shape.** Decide at capture what the trail is allowed to hold — see
[protecting sensitive data](../05-pipeline-and-security/02-protecting-sensitive-data.md). The
package's own authentication subscriber is the model: it never looks at the credentials, because
not capturing is a stronger guarantee than capturing and redacting.

```php
final class Patient extends Model
{
    use Auditable;

    /** @var list<string> */
    protected array $auditExclude = ['ssn'];        // never in the entry at all

    /** @var list<string> */
    protected array $auditRedact = ['phone'];       // present, masked, unrecoverable

    /** @var list<string> */
    protected array $auditEncrypt = ['diagnosis'];  // present, readable with the key, restorable
}
```

> 📌 **Note.** `security.redaction.*` in the config masks values as they are **captured**.
> `Redaction\Redactor` destroys the contents of an entry sealed long ago. They share a word and
> nothing else.

---

## 11. Catching and swallowing a write failure

**Looks reasonable.** The audit trail must never take the request down — so either the policy is
loosened, or the save is wrapped. Usually both.

```dotenv
SENTINEL_ON_WRITE_FAILURE=log
```

```php
try {
    $invoice->update(['status' => 'approved']);
} catch (Throwable) {
    // "auditing is best-effort" — dead code under `log`, and under `throw`
    // it discards the one signal the request was given
}
```

**What actually happens.** `Capture\WriteFailure` always announces `Events\AuditWriteFailed`, then
either rethrows (policy `throw`) or writes one line through `log_channel` (policy `log`) — the log
entry stands in for the exception nobody will catch. With `log` and no channel configured it goes
to the application default, and with nobody reading it the operation succeeds while the fact is
absent from the trail. Three more things the policy does not cover: a write deferred to a commit
never propagates whatever the policy says, so it is announced and recorded instead; under `queue`
the package deliberately announces nothing from inside the worker, so the failure policy there is
the queue's own retry and `failed_jobs`; and under `buffered` a failed threshold flush raises
`AuditWriteFailed` naming the entry that just arrived — by design the one entry that is *safe* —
while `BufferFlushFailed` is what names the batch that did not land.

**How you find out.** An entry is missing and the application never said a word.

**The correct shape.** Keep the default, and if you must swallow, make sure something is listening.

```php
use ElPandaPe\Sentinel\Events\AuditWriteFailed;

Event::listen(function (AuditWriteFailed $failed): void {
    logger()->channel('audit-alerts')->critical($failed->message(), [
        'audit_type' => $failed->auditType,
        'event' => $failed->event,
        'subject' => $failed->subjectType.'#'.$failed->subjectId,
        'exception' => $failed->failure,
    ]);
});
```

```php
'on_write_failure' => 'throw',      // compliance mode forces this regardless
'log_channel' => 'audit-alerts',    // a channel somebody is actually paged from
```

---

## 12. Building on an `@internal` declaration

**Looks reasonable.** The class exists, it is `final readonly`, and the container resolves it.

```php
use ElPandaPe\Sentinel\Integrity\Verifier;
use ElPandaPe\Sentinel\Support\Reference;

$result = app(Verifier::class)->verifyEntry($entry);   // @internal
$actor  = new Reference('user', '9');                  // @internal
```

**What actually happens.** The 1.0 freeze covers the surface a reader can reach, and `@internal` is
the boundary — held as data in the package's own surface test rather than as a promise in prose.
Whole namespaces are outside it (`Buffer`, `Dispatch`, `Ledger`, `Mass`, `Compliance`, `Console`,
`Import`, `Partitions`, `Retention`, `Jobs`, `Snapshot`), and so are named declarations inside
namespaces that are not — `Integrity\Verifier`, `Integrity\Checkpoints`, `Integrity\Hasher`,
`Integrity\Signers`, `Integrity\Stream`, `Diff\Pointer`, `Support\Reference`,
`Support\AuditPolicy`. Any of them can change shape in a patch release without a deprecation.

Two edges worth knowing. `Redaction\Redactor::redact()` is public and takes a `Support\Reference`,
which is marked `@internal` — build it with `Reference::to($model)` and keep the coupling to one
line, or drive a one-off through `sentinel:redact --actor=type:id`, where the command is the
published surface. And `Enums\CheckpointState` is `@internal` while its string values are the keys
of the public `StreamVerification::$anchors` tally: read `'anchored'`, `'archived'` and `'absent'`
as strings, never through the enum.

**How you find out.** A `composer update` inside 1.x, on a Tuesday.

**The correct shape.** Reach for the published surface — the facade, `Concerns\Auditable`,
`Models\Audit`, `Query\AuditQuery`, `Data\AuditData`, the contracts, the events, the commands and
their exit codes, the config keys, and the frozen `Audit::toArray()`.

```php
use ElPandaPe\Sentinel\Enums\ContentState;
use ElPandaPe\Sentinel\Facades\Sentinel;

$entry->verifyIntegrity();                          // instead of Integrity\Verifier
$entry->verifyContent() === ContentState::Redacted; // instead of Integrity\Content
Sentinel::verifyIntegrity('global');                // instead of walking it yourself
```

---

## 13. Verifying only when someone asks

**Looks reasonable.** Verification is expensive, so it runs before the audit meeting.

```bash
php artisan sentinel:verify   # twice a year, by hand
```

**What actually happens.** The chain is tamper-evident, not tamper-proof: nothing stops a write,
and `verifyIntegrity()` reports it afterwards. The model's immutability guard runs on Eloquent
events, so `Audit::query()->where(...)->update([...])` never passes through it — what catches that
is a verification run, later. Later is also more expensive: in the volume report the *walk* alone
took 351.5 s over ten million entries on PostgreSQL 16 flat, and the walk is only the floor — it
hydrates rows without canonicalising or hashing anything, which is the whole of what verifying an
entry is (measured separately at 257–311 µs an entry against the walk's 40 µs). And
`sentinel:verify` speaks three exit codes, not two: 0 is sound (including a wholly
unsigned trail, and one whose only finding is declared redactions), 1 is a bad finding from a run
that happened, 2 is a run that could not happen. A watchdog that cannot tell 1 from 2 will
eventually treat one as the other.

**How you find out.** At the worst possible moment, with no idea when the break happened.

**The correct shape.** Three depths on three cadences, alert on the exit code, and take the event
for anything that should not wait for a cron.

```php
Schedule::command('sentinel:verify --depth=anchors')->hourly();
Schedule::command('sentinel:verify --depth=roots')->dailyAt('03:00');
Schedule::command('sentinel:verify')->weeklyOn(7, '04:00');   // the entry walk
```

```php
use ElPandaPe\Sentinel\Events\IntegrityVerificationFailed;

Event::listen(function (IntegrityVerificationFailed $failure): void {
    logger()->critical($failure->message(), [
        'stream' => $failure->stream,
        'sequence' => $failure->sequence,
        'audit_id' => $failure->auditId,
        'reason' => $failure->reason->value,
    ]);
});
```

> 📌 **Note.** Only `--depth=entries` rehashes. A range under a valid anchor is reported
> `anchored`, never `intact`, and the report keeps `checked`, `covered` and `archived` apart so you
> can see which of the three a number came from. Never add them.

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `QueryException::unbounded` appears in production and never appeared in staging | `get()` with no `take()` refuses once the filter matches more than `AuditQuery::DEFAULT_LIMIT` (500); staging had 40 rows | `take(n)` for a prefix, `paginate()` to walk, `after($id)` for a background pass |
| `sentinel:verify` lists a stream you never configured | `integrity.stream` is `tenant` and a tenant resolved for the first time; the name is inside the hash prefix, so the old chain stopped rather than moved | Set `integrity.stream` explicitly before the first entry; both chains stay verifiable |
| `sentinel:prune` exits 0 every night and the table never shrinks | Only anchored windows are offered; with no anchors the hold is `unanchored`, which is not a failure | Schedule `sentinel:checkpoint` ahead of the prune; read the Note column of `--dry-run` |
| Entries vanished after a deploy and nothing was logged | `mode` was changed away from `buffered` with entries still waiting; both hooks and `sentinel:flush` refuse under another mode | `php artisan sentinel:flush` until it settles 0, *then* change the mode |
| `$audit->verifyIntegrity()` returns `false` on an entry you redacted yourself | Unchanged meaning: the row no longer reproduces its own hash. It errs towards the alarm on purpose | Ask `verifyContent()`: `ContentState::Redacted` is a declared redaction, `Altered` is a finding |
| A relabel of an old entry left no trace | Labels are outside `CanonicalPayload::COLUMNS` in both directions, so nothing verifies them | Put anything that must be provable in `metadata` |
| A listener that writes an entry never returns | Every write announces `AuditCreated`, and there is no re-entrancy guard | Do not write from a listener; queue it, or capture the fact where it happens |
| Missing entries under `queue`, with no Sentinel event anywhere | The package deliberately announces nothing from inside a worker; the failure policy there is the queue's | Watch `failed_jobs` and the queue's own events |
| `AuditWriteFailed` names an entry that is demonstrably present | A threshold flush under `buffered` reports against the entry that just arrived — the safe one | Read `BufferFlushFailed` for what was actually at stake |
| A read of the trail shows entries about reads of the trail | Compliance mode writes an `access` entry that consumes a sequence of the very stream it audits | Expected. Narrow with `whereType()`, or exclude `'access'` from the view |
| `whereFieldChanged('members')` finds nothing on a relation entry | A relation line carries `relation`/`operation`, not `path`; a relation is not an attribute | Use `whereRelation()` / `whereRelated()` / `whereOperation()` |
| `whereVersion(1)` returns two entries for one subject | A rehydrated entry brings its original `version` back, and `version` is inside the canonical payload so renumbering would break its hash | Treat `version` as per-era; `compare()` can legitimately pair two eras of one subject |
| A second bare `sentinel:rekey` run reports 0 re-encrypted and never reaches the rest of the trail | The walk is oldest-first with `--limit` defaulting to 500, and the rotation identity is derived, so the same prefix is re-read and nothing is written | Chain the passes with the identifier each run reports: `--after=<id>` |

---

## ✅ Best practices

✅ **Do** — name the clock in every query that draws a sequence of events. The default order is the
order entries *settled*, which stops being the order things happened the moment a write is
deferred.

```php
Sentinel::audits()->for($invoice)->byOccurrence()->take(200)->get();
```

❌ **Don't** — build a lifeline on the default order and then change `mode`. Nothing errors; the
page simply starts answering a different question.

```php
Sentinel::audits()->for($invoice)->take(200)->get();   // created_at: when it settled
```

---

✅ **Do** — put an entity filter in front of a category filter, and bound every read. `for()`,
`by()`, `forTenant()` and `inTransaction()` select an entity; `whereEvent()`, `whereType()` and
`whereRoute()` select a category and have to sort everything they matched.

```php
Sentinel::audits()->forTenant('acme')->whereEvent('updated')->latest()->paginate(50);
```

❌ **Don't** — hand a category filter the whole table. In the published volume report that is
32 589 ms for `whereEvent()` over ten million entries on MySQL 9 flat.

```php
Sentinel::audits()->whereEvent('updated')->latest()->get();
```

---

✅ **Do** — decide at capture what the trail may hold, using the four levers the model declares.
An entry is immutable once written, and the diff duplicates every value the snapshot holds.

```php
protected array $auditExclude = ['ssn'];        // never captured
protected array $auditRedact  = ['phone'];      // masked, unrecoverable
protected array $auditHash    = ['card_last4']; // comparable, unrecoverable
protected array $auditEncrypt = ['diagnosis'];  // recoverable with the key
```

❌ **Don't** — plan to fix it later with a tombstone. A redaction empties all six content columns
whole — `metadata` included, taking non-personal facts with it — and cannot reach a range that has
already been archived without a rehydrate/redact/re-prune round trip.

```php
app(Redactor::class)->redact($entry, 'we will sort it out later', $actor);
```

---

✅ **Do** — keep `on_write_failure` at `throw`, and make sure a listener exists for the cases the
policy cannot reach: a deferred write, a queue worker, a buffered flush.

```php
Event::listen(fn (AuditWriteFailed $f) => logger()->channel('audit-alerts')->critical($f->message()));
Event::listen(fn (BufferFlushFailed $f) => logger()->channel('audit-alerts')->critical($f->message()));
```

❌ **Don't** — set `log` and consider the problem closed. The log line stands in for the exception
nobody will catch, and with nobody reading the channel the operation succeeds while the fact is
absent from the trail.

```dotenv
SENTINEL_ON_WRITE_FAILURE=log     # and no log_channel, and no listener
```

---

✅ **Do** — schedule verification and anchoring, and act on the exit code rather than the text.
0 is sound, 1 is a bad finding from a run that happened, 2 is a run that could not happen.

```php
Schedule::command('sentinel:checkpoint')->hourly();
Schedule::command('sentinel:verify --depth=roots')->dailyAt('03:00');
Schedule::command('sentinel:verify')->weeklyOn(7, '04:00');
```

❌ **Don't** — treat a shallow depth as proof of content. `verifyAnchors()` opens no entry at all,
and `verifyRoots()` folds the stored `hash` column — both pass a range whose canonical column was
edited while `hash` was left alone.

```php
Sentinel::verifyAnchors('global')->isIntact();   // "nothing came back wrong", not "all read"
```

---

**See also:** [Do and don't](01-dos-and-donts.md) · [Production readiness](04-production-readiness.md) · [Security checklist](03-security-checklist.md) · [The buffered mode](../09-operations/02-the-buffered-mode.md) · [Failure policy](../09-operations/05-failure-policy.md) · [Events and listeners](../09-operations/04-events-and-listeners.md) · [Order, paging and walking](../06-reading/03-order-paging-and-walking.md) · [Streams](../07-integrity/02-streams.md) · [Checkpoints and anchors](../07-integrity/05-checkpoints-and-anchors.md) · [The verification playbook](../07-integrity/07-the-verification-playbook.md) · [Retention and pruning](../08-lifecycle/01-retention-and-pruning.md) · [Redaction and tombstones](../08-lifecycle/04-redaction-and-tombstones.md) · [API stability](../99-reference/09-api-stability.md)
