# ✅ Do and don't

> The judgement calls a team makes once and then lives with, in the order you meet them — each with
> the code on both sides, what actually goes wrong, and the page that explains why.

**On this page:** [How to read this](#how-to-read-this) · [⚠️ Pitfalls](#️-pitfalls) · [✅ Best practices](#-best-practices) · [Designing what to audit](#designing-what-to-audit) · [Declaring on the model](#declaring-on-the-model) · [Configuring the pipeline](#configuring-the-pipeline) · [Handling sensitive data](#handling-sensitive-data) · [Choosing a mode](#choosing-a-mode) · [Querying the trail](#querying-the-trail) · [Integrity](#integrity) · [Retention and erasure](#retention-and-erasure) · [Operations](#operations) · [Extending](#extending)

---

## How to read this

This page repeats material that lives elsewhere, on purpose. It is not the index of features — it is
the index of judgement: the decisions that are cheap on the day you make them and expensive on the
day you undo them. Three things run through all of it.

**History is append-only** — nothing here is fixed by a migration later. **The failure modes are
silent** — most of what follows does not throw; it produces a trail that looks complete and answers a
slightly different question from the one you asked. **Every item is specific to Sentinel** — where an
item names a class, a config key or an exception, it was read out of the source, so read the linked
page before you disagree with it.

---

## ⚠️ Pitfalls

The thirteen that cost the most time, as the reader actually meets them.

| Symptom | Cause | Fix |
|---|---|---|
| Auditing stopped part-way through a request and nothing was logged | A bare `Sentinel::pause()` whose `resume()` was skipped by a throw. Only `resume()` clears the flag, and it lives for the rest of the container scope | Use `Sentinel::withoutAuditing()`, which restores the previous flag in a `finally` |
| `ConfigurationException` on the model's very first `create()`, mentioning a transition | A column in `$auditTransitions` is also excluded, redacted, hashed, encrypted, or missing from a declared `$auditInclude`. `Support\AuditPolicy` checks on every policy build, the insert path included | Take the column out of the protection list, or out of `$auditTransitions` |
| A model's `$auditRedact` does nothing for a `Sentinel::event()` entry | With no model subject, `Support\PolicyRegistry` returns the empty policy, so only the config lists apply | Name the key in `security.redaction.fields` — config and model lists are a union, not a fallback |
| A declaration computed in the constructor, or exposed through `__get()`, is ignored | `auditProperty()` guards on `property_exists()` and reads `$this->{...}`; the pipeline builds the model with `newInstanceWithoutConstructor()` | Declare real `protected` properties, or implement `Contracts\Auditable` |
| Encrypted fields stopped decrypting and every digest stopped matching, on one deploy | `APP_KEY` rotated while `security.encryption.keys.default` and `security.hashing.salt` still derived from it | Pin `SENTINEL_ENCRYPTION_KEY` and `SENTINEL_HASH_SALT` before the first protected entry |
| A restore skips a field with `Omission::RedactedField` or `HashedField` | `Restore\Planner` refuses both outright — neither a mask nor a digest reverses | Encrypt what has to come back; the planner decrypts with the `key_id` the entry recorded |
| Declaring `profile` as encrypted turned the whole sub-document into one opaque string | `Security\Fields::walk()` transforms a matched key's entire value and does not descend into it | Declare the leaf keys, never a container key |
| Entries stopped settling after `SENTINEL_MODE` moved away from `buffered` | `sentinel:flush` exits `2` under any other mode, and both shutdown hooks return early | Flush to zero, confirm, then change the mode |
| A timeline built on `created_at` shows the wrong order under `queue` or `buffered` | `occurred_at` is stamped at capture; `created_at` and `sequence` at settlement | `Sentinel::timeline()`, or `->byOccurrence()` |
| `QueryException::unbounded` from a `get()` that worked in staging | `get()` refuses past `AuditQuery::DEFAULT_LIMIT` (500) rather than handing back a prefix shaped like a whole answer | `take(n)`, `paginate()`, or narrow further |
| Changing `integrity.stream` produced two chains instead of one longer one | The stream name is inside the hash prefix, so it can never be renamed in place | Set it before there is data; existing chains keep verifying and stop growing |
| `sentinel:prune` names a stream and frees nothing | `Enums\RetentionHold` gives the four reasons: undeclared, unanchored, tail, retained. The unit is the anchored window, and a window leaves whole | Anchor first; expect a range's retention to be its longest-lived entry's |
| The application refuses to boot with `ComplianceException::incomplete` | `compliance => true` requires `integrity.signature.enabled` **and** `integrity.checkpoints.enabled`, checked at boot | Turn both on, or turn compliance off |

---

## ✅ Best practices

### Designing what to audit

✅ **Do** — put `Auditable` only on models whose history someone will be asked about. Every save on an
audited model runs the whole pipeline and extends a chain; there is no sampling.

```php
use ElPandaPe\Sentinel\Concerns\Auditable;

final class Invoice extends Model { use Auditable; }
```

❌ **Don't** — audit a high-write table and drop the entries with `Sentinel::filter()`.
`EnforcePolicies` is the *last* stage, so the entry has already paid context resolution, labelling,
masking and encryption before your predicate sees it.

```php
Sentinel::filter(static fn (AuditData $audit): bool => $audit->subject_type !== HeartbeatPing::class);
```

✅ **Do** — state a fact no model change describes with `Sentinel::event()`. It settles through the
same pipeline, ledger and chain as an update; `record()` is the terminal.

```php
Sentinel::event('invoice.approved')->subject($invoice)->metadata(['channel' => 'back-office'])->record();
```

❌ **Don't** — touch a column so an `updated` entry appears. `Stages\FilterUnchanged` discards an
`updated` whose `changes` is empty, and a column written only to leave a trace makes the snapshot say
something untrue.

```php
$invoice->update(['approved_flag' => true]);   // an audit trail is not a side effect of a column
```

✅ **Do** — wrap a multi-model business operation in `Sentinel::transaction()` so its entries share
one `transaction_id` and get a header row naming who ran it.

```php
Sentinel::transaction('invoice.approve', function () use ($invoice): void {
    $invoice->update(['status' => 'approved']);
    $invoice->payments()->create(['amount' => $invoice->total]);
});
```

❌ **Don't** — assume it opens a database transaction. It correlates; it does not atomise. Combining
the two is the application's decision and has to be spelled out.

```php
DB::transaction(fn () => Sentinel::transaction('invoice.approve', $work));   // both, explicitly
```

✅ **Do** — opt in per query for the statements Eloquent fires no model event for.

```php
Invoice::query()->where('status', 'draft')->auditing()->update(['status' => 'void']);
```

❌ **Don't** — look for a flag that audits every mass update globally. There is none, by design; and
`Mass\AuditedQuery::guard()` refuses a model that neither uses the trait nor implements
`Contracts\Auditable`, because the declarations are what say which columns may be written down.

```php
Invoice::query()->where('status', 'draft')->update(['status' => 'void']);   // silently unaudited
```

**Explained in:** [What gets audited](../03-capture/01-what-gets-audited.md) · [Mass operations](../03-capture/05-mass-operations.md) · [Business transactions](../03-capture/06-business-transactions.md) · [Custom and authentication events](../03-capture/07-custom-and-authentication-events.md)

### Declaring on the model

✅ **Do** — write the declarations as real declared properties.

```php
/** @var list<string> */
protected array $auditRedact = ['email'];
```

❌ **Don't** — compute them in a constructor or hang them off `__get()`. `Support\PolicyRegistry`
builds the model with `newInstanceWithoutConstructor()` for the masking, encryption and labelling
stages, so those declarations are invisible there — and nothing warns you.

```php
$this->auditRedact = $this->sensitiveColumns();   // not seen by the pipeline
```

✅ **Do** — keep a `$auditTransitions` column readable: inside a declared `$auditInclude`, and out of
all four protection lists.

```php
protected array $auditInclude     = ['status', 'total', 'signed_at'];
protected array $auditTransitions = ['status'];
```

❌ **Don't** — protect the state column. The refusal is `ConfigurationException::unreadableTransition`
or `::omittedTransition`, and it fires on the model's first write of *any* kind — `created` included
— not on the first transition.

```php
protected array $auditRedact      = ['status'];
protected array $auditTransitions = ['status'];   // a lifeline the entry cannot show
```

✅ **Do** — reach for `$auditRedact`, `$auditHash` or `$auditEncrypt` when the fact that a field
changed still matters. All three keep the key on the entry and make the value unreadable.

```php
protected array $auditHash = ['card_number'];   // proves it moved, gives back neither state
```

❌ **Don't** — use `$auditExclude` for that. Exclusion happens in `Snapshot\SnapshotBuilder`, before
the pipeline: the key never reaches the entry, so no diff, no transition and no restore can ever say
anything about it.

```php
protected array $auditExclude = ['card_number'];   // the change becomes invisible, not private
```

✅ **Do** — map a `belongsTo` in `$auditParents` when a child moving between parents should read as a
detach and an attach on the parents' collections.

```php
/** @var array<string, string> */
protected array $auditParents = ['author' => 'contracts'];
```

❌ **Don't** — name a `morphTo` there. `Capture\ParentCapture` refuses it with
`ConfigurationException::notAParent` **at capture time** — on a child update in production, not at
boot.

```php
protected array $auditParents = ['commentable' => 'comments'];   // throws on the next child save
```

**Explained in:** [What a model declares](../02-getting-started/03-what-a-model-declares.md) · [Snapshots](../03-capture/02-snapshots.md) · [Relationship auditing](../03-capture/04-relationships.md) · [State transitions](../03-capture/08-state-transitions.md)

### Configuring the pipeline

✅ **Do** — leave `pipeline` out of a published config unless you are changing it, and treat it as a
version-pinned list once you do.

```php
// config/sentinel.php — absent, or []: both mean the seven shipped stages, in order.
```

❌ **Don't** — set `'pipeline' => []` expecting the pipeline to be off.
`Support\Config::pipelineStages()` reads an empty list as the package default, so a shallow config
merge cannot leave an installation transforming nothing. A real removal means declaring the full list
without the stage.

```php
'pipeline' => [],   // this is the shipped list, not an empty one
```

✅ **Do** — put a stage that may discard *before* `MaskSensitiveData`. A discarded entry then pays
neither the mask nor the encryption, and it costs no `sequence`, so the chain gets no gap.

```php
'pipeline' => [
    ElPandaPe\Sentinel\Pipeline\Stages\FilterUnchanged::class,
    App\Sentinel\DropRoutineReads::class,
    // … the remaining shipped stages, in order
],
```

❌ **Don't** — try to discard after the ledger has numbered the entry.
`Pipeline\Discard::because()` throws `DiscardException::outsideThePipeline` there, because a hole in
the chain is exactly what `verifyIntegrity()` reports as tampering.

```php
Event::listen(fn (AuditCreated $e) => $discard->because('too late'));   // DiscardException
```

✅ **Do** — register a `Sentinel::filter()` policy once, from a service provider's `boot()`.
`Support\Policies` is a singleton and survives a queue worker's scope resets.

```php
public function boot(): void
{
    Sentinel::filter(static fn (AuditData $audit): bool => $audit->changes !== []);
}
```

❌ **Don't** — register one from a controller or a job handler. `add()` only appends and the facade
exposes no removal, so the closure accumulates on every pass for the life of the process.

```php
public function store(Request $request): Response { Sentinel::filter($policy); /* … */ }
```

✅ **Do** — read the diff a stage was handed. `FilterUnchanged` works in any position because it
reads `changes` and never re-compares values; the shipped position is first for cost, not
correctness.

```php
$audit->metadata = [...$audit->metadata ?? [], 'release' => config('app.release')];

return $next($audit);
```

❌ **Don't** — recompute `changes` from `before`/`after` after `EncryptSensitiveData`. Two
ciphertexts of the same value never match, so every field reports as changed and `FilterUnchanged`
stops filtering anything.

```php
$audit->changes = Diff::between($audit->before ?? [], $audit->after ?? [])->toArray();
```

**Explained in:** [The write pipeline](../05-pipeline-and-security/01-the-write-pipeline.md) · [Discarding entries](../05-pipeline-and-security/06-discarding-entries.md) · [Configuration](../99-reference/02-configuration.md)

### Handling sensitive data

✅ **Do** — pick exactly one of the four treatments per field: exclude when the value must not exist,
redact when a human must recognise it, hash when only "did it change" matters, encrypt when it has to
come back.

```php
protected array $auditExclude = ['remember_token'];
protected array $auditRedact  = ['email'];
protected array $auditHash    = ['card_number'];
protected array $auditEncrypt = ['national_id'];
```

❌ **Don't** — name a field in two lists. The stages run in order and the second sees the first one's
output: a redacted-and-hashed field is digested from its mask, and a redacted-and-encrypted one
stores an encrypted mask, with the plaintext unrecoverable even to the key holder.

```php
protected array $auditRedact  = ['national_id'];
protected array $auditEncrypt = ['national_id'];   // encrypts the mask, not the value
```

✅ **Do** — encrypt anything a restore may need. `Restore\Planner` decrypts with the `key_id` the
*entry* recorded, so yesterday's key still restores while it stays on the ring.

```php
protected array $auditEncrypt = ['iban'];
```

❌ **Don't** — redact or hash a field you may want back: the planner refuses both outright with
`Omission::RedactedField` and `Omission::HashedField`. Encryption is recoverable only while its key
stays on the ring — take a retired key off it and those entries skip with `Omission::KeyUnavailable`,
because `sentinel:rekey` appends a new entry rather than rewriting the old one.

```php
protected array $auditRedact = ['iban'];   // $audit->restore() will skip it, permanently
```

✅ **Do** — pin both secrets explicitly, in every environment that will outlive one `APP_KEY`, before
the first protected entry is written.

```dotenv
SENTINEL_ENCRYPTION_KEY=base64:…
SENTINEL_HASH_SALT=…
```

❌ **Don't** — leave them deriving from `APP_KEY` and then rotate it. One rotation costs every
encrypted value and every digest comparison at once, with no error, and `APP_PREVIOUS_KEYS` does not
help: `Security\Keyring` builds a single-key `Encrypter` per identifier.

```dotenv
APP_KEY=base64:new…   # with neither of the two above pinned
```

✅ **Do** — name keys no model owns in the `security.*.fields` lists. They are a **union** with what
every model declared, and the only lever that reaches an entry with no model subject.

```php
'security' => [
    'redaction' => ['fields' => ['ip', 'user_agent']],
    'hashing'   => ['fields' => ['session_id']],
],
```

❌ **Don't** — declare a name that is also a container key. `Security\Fields::walk()` transforms the
matched key's whole value and does not descend, so `profile` or `arguments` becomes one ciphertext or
one mask for the entire subtree.

```php
'fields' => ['profile'],   // the whole sub-document, not the sensitive leaves inside it
```

**Explained in:** [Protecting sensitive data](../05-pipeline-and-security/02-protecting-sensitive-data.md) · [Encryption and the keyring](../05-pipeline-and-security/03-encryption-and-the-keyring.md) · [Hashing and the salt](../05-pipeline-and-security/04-hashing-and-the-salt.md) · [Writing a masker](../05-pipeline-and-security/05-writing-a-masker.md)

### Choosing a mode

✅ **Do** — stay on `sync` until request latency is a measured problem. It is the only mode where the
caller can still be told the write did not work.

```php
'mode' => env('SENTINEL_MODE', 'sync'),
```

❌ **Don't** — reach for `buffered` because it sounds safer. What a process dies holding never reached
the ledger, took no `sequence` and leaves no gap — so `verifyIntegrity()` walks a shorter chain and
reports it intact, correctly.

```php
'mode' => 'buffered',   // cheaper end to end, and the one mode with a loss window
```

✅ **Do** — move every lifeline query to the clock of the fact before switching to an asynchronous
mode. `occurred_at` is stamped at capture and never moves.

```php
Sentinel::timeline()->for($invoice)->paginate(50);
```

❌ **Don't** — leave a query ordering by the ledger's clock. It keeps working and quietly starts
answering a different question.

```php
Sentinel::audits()->for($invoice)->get();   // the order entries settled, not the order things happened
```

✅ **Do** — schedule `sentinel:flush` under `buffered`. It is the only thing that puts a real ceiling
on how long an entry waits.

```php
Schedule::command('sentinel:flush')->everyMinute()->withoutOverlapping();
```

❌ **Don't** — rely on `buffer.flush_interval` alone. `Buffer\Flusher::due()` is called from exactly
one place — on push — so a buffer that stops receiving entries stops being evaluated.

```php
'buffer' => ['flush_interval' => 60],   // bounds nothing while there is no traffic
```

✅ **Do** — listen for `Events\BufferFlushFailed`. It is the only signal that names what was at
stake, and `taken = settled + skipped() + returned`, always.

```php
Event::listen(fn (BufferFlushFailed $e) => Log::channel('audit-alerts')->critical($e->message()));
```

❌ **Don't** — read the `AuditWriteFailed` raised by a threshold-triggered flush as naming what was
lost. `Dispatch\BufferStrategy` has only the arriving entry when the flush blows up — and that entry
is the one safely in the buffer.

```php
Event::listen(fn (AuditWriteFailed $e) => alert("lost {$e->event}"));   // names the wrong fact
```

**Explained in:** [Performance modes](../09-operations/01-performance-modes.md) · [The buffered mode](../09-operations/02-the-buffered-mode.md) · [Running audits on a queue](../09-operations/03-queues.md) · [Choosing your setup](../02-getting-started/05-choosing-your-setup.md)

### Querying the trail

✅ **Do** — bound every read you cannot prove is small. `take()` asks for a prefix on purpose;
`paginate()` costs one call to the ledger and answers `hasMore` for the price of one extra row.

```php
$page = Sentinel::audits()->for($invoice)->latest()->paginate(50);
```

❌ **Don't** — rely on a bare `get()`. It throws `QueryException::unbounded` once the filter matches
more than 500 entries, refusing rather than handing back a prefix — see
[Order, paging and walking the trail](../06-reading/03-order-paging-and-walking.md#how-much-comes-back).

```php
Sentinel::audits()->whereType('model')->get();   // QueryException::unbounded
```

✅ **Do** — put an indexed filter in front of a refiner. `whereSource()`, `between()`,
`whereFieldChanged()`, `whereVersion()` and `whereOperation()` reach no index of their own.

```php
Sentinel::audits()->for($patient)->whereFieldChanged('diagnosis')->take(100)->get();
```

❌ **Don't** — run a refiner alone on a large table, and treat `whereEvent()`, `whereSeverity()` and
`whereType('model')` the same way: they reach an index and then sort a category that is most of the
table.

```php
Sentinel::audits()->whereFieldChanged('email')->take(100)->get();   // a full pass on MySQL and PostgreSQL
```

✅ **Do** — walk a whole trail from a background process with `after()`, which compiles to `id > ?`
and costs the same at any depth. Guard the first pass: `after(string $id)` is not nullable, so the
`null` a walk starts with is a `TypeError`, and `''` throws `QueryException::noCursor()`.

```php
$query  = Sentinel::audits()->whereType('model')->take(1000);
$batch  = ($cursor === null ? $query : $query->after($cursor))->get();
$cursor = $batch->last()?->id ?? $cursor;
```

❌ **Don't** — combine `after()` with `latest()` or `byOccurrence()`. A cursor is cut from the
identifier and walks along it, forwards; beside a clock order it throws
`QueryException::cursorOffItsAxis()`, whichever was asked for first.

```php
Sentinel::audits()->latest()->after($cursor)->get();   // throws — a cursor is not a backwards walk
```

✅ **Do** — call `loadReferences()` before rendering a page, and write the three-way check on
`integrity.verified`.

```php
$page->entries->loadReferences();

$state = match ($audit->toArray()['integrity']['verified']) {
    true => 'verified', false => 'TAMPERED', null => 'not checked',
};
```

❌ **Don't** — render `$audit->impersonator` across a page (`loadReferences()` resolves subject, actor
and labels only), and never write `! $verified`: `toArray()` does not walk the chain, so `null` means
"not checked" and renders as "TAMPERED" in PHP and JavaScript alike.

```php
$label = ! $audit->toArray()['integrity']['verified'] ? 'TAMPERED' : 'ok';   // wrong for every entry
```

**Explained in:** [The Query API](../06-reading/01-the-query-api.md) · [Filters reference](../06-reading/02-filters-reference.md) · [Order, paging and walking the trail](../06-reading/03-order-paging-and-walking.md) · [Presenting and serializing](../06-reading/07-presenting-and-serializing.md) · [Indexes and JSON](../10-database-engines/05-indexes-and-json.md)

### Integrity

✅ **Do** — decide `integrity.stream` before there is data. It ships as `tenant`, which behaves like
`global` until a tenant actually resolves — and then entries move to a `tenant:<id>` chain with
`sequence` restarting at 1.

```php
'integrity' => ['stream' => 'global'],   // a single-tenant install that wants one chain
```

❌ **Don't** — change it on an installation with history and expect the chain to follow. Old rows keep
their old stream inside their hash prefix: you get two independent chains, not one continued one.

```php
'integrity' => ['stream' => 'subject_type'],   // on a live trail: the history forks here
```

✅ **Do** — sign, and rotate by moving `integrity.signature.key_id` while leaving the old key in
`keys`. Every row records the key that signed it.

```php
'signature' => [
    'key_id' => 'v2',
    'keys'   => ['v1' => env('SENTINEL_SIGNING_PUBLIC_V1'), 'v2' => env('SENTINEL_SIGNING_PUBLIC_V2')],
],
```

❌ **Don't** — remove a retired key, or read `Unsigned` and `UnknownKey` as failures. Removing a key
makes its history `UnknownKey` permanently; only `SignatureState::Invalid` is a defect, because an
unresolvable key is a verdict the verifier is not entitled to give.

```php
'keys' => ['v2' => env('SENTINEL_SIGNING_PUBLIC_V2')],   // every v1 entry becomes UnknownKey
```

✅ **Do** — act on the exit code of `sentinel:verify`, and read `checked`, `covered` and `archived` as
three separate facts. `0` is sound, `1` is a bad finding from a run that happened, `2` is a run that
could not happen.

```shell
php artisan sentinel:verify --depth=roots || notify "sentinel exited $?"
```

❌ **Don't** — sum the tallies, or read a range reported `anchored` as verified. `verifyAnchors()`
opens no entry at all, and `verifyRoots()` folds the stored `hash` column — both pass a range whose
canonical columns were edited while `hash` was left alone.

```php
$total = $report->checked() + $report->covered();   // two different facts, added
```

✅ **Do** — run the first `sentinel:checkpoint` by hand, off the schedule, and only then schedule it.

```shell
php artisan sentinel:checkpoint     # first pass, by hand
```

❌ **Don't** — put it straight on a schedule over a pre-existing trail. There is no `--limit` and no
`--range`: the first pass anchors every complete window at once, which is one read of the whole
trail.

```php
Schedule::command('sentinel:checkpoint')->hourly();   // on ten million entries, cold
```

**Explained in:** [Streams](../07-integrity/02-streams.md) · [Signing the chain](../07-integrity/04-signing.md) · [Checkpoints and anchors](../07-integrity/05-checkpoints-and-anchors.md) · [Verification](../07-integrity/06-verification.md) · [The verification playbook](../07-integrity/07-the-verification-playbook.md) · [Exit codes](../99-reference/07-exit-codes.md)

### Retention and erasure

✅ **Do** — anchor before you prune, and keep `sentinel_checkpoints` backed up alongside
`sentinel_audits` afterwards. A pruned range is stepped over by verification only when the manifest
accounts for it **and** the anchors reach past it.

```php
Schedule::command('sentinel:checkpoint')->hourly();
Schedule::command('sentinel:prune')->dailyAt('02:00');
```

❌ **Don't** — expect a prune to free anything on a stream nobody anchored. `Retention\Frontiers`
answers `RetentionHold::Unanchored`, and the command reports it rather than deleting.

```php
'retention' => ['auth' => '90 days'],   // and integrity.checkpoints never emitted anything
```

✅ **Do** — set retention expecting the unit to be the anchored window, not the entry. A window is
folded whole, so it leaves whole.

```php
'retention' => ['model:App\Models\Order' => '2 years', 'auth' => '90 days'],
```

❌ **Don't** — assume a short policy frees rows inside a mixed stream. The effective retention of a
range is that of its longest-lived entry: under the shipped `integrity.stream => 'tenant'`, one
seven-year entry keeps its whole window.

```php
'retention' => ['auth' => '90 days'],   // beside 'model:App\Models\Contract' => '7 years'
```

✅ **Do** — use `sentinel:redact` for an erasure request over history that is already sealed, and
always name who ordered it.

```shell
php artisan sentinel:redact 01JB9Z… --reason="erasure request 4192" --actor='App\Models\User:100'
```

❌ **Don't** — omit the actor (`ComplianceException::unattributed` under compliance mode), or confuse
redaction with rekeying. They are opposites: one destroys content, the other preserves it under a
different lock, and no path of either calls the other.

```shell
php artisan sentinel:redact 01JB9Z… --reason="gdpr"   # refused: nobody named
```

✅ **Do** — prune with `--action=archive`, the default: the batch is written, read back, rehashed
against each entry's sealed hash and recorded in `sentinel_archives` before a single row is removed.

```shell
php artisan sentinel:prune --action=archive --dry-run
```

❌ **Don't** — expect a prune to reclaim disk space. InnoDB does not return freed pages and PostgreSQL
leaves dead tuples; `OPTIMIZE TABLE` and `VACUUM FULL` both take locks, so the command runs neither.

```shell
php artisan sentinel:prune   # rows go; the table file does not shrink
```

**Explained in:** [Retention and pruning](../08-lifecycle/01-retention-and-pruning.md) · [Cold archiving](../08-lifecycle/02-cold-archiving.md) · [Redaction and tombstones](../08-lifecycle/04-redaction-and-tombstones.md) · [Export and rekey](../08-lifecycle/06-export-and-rekey.md)

### Operations

✅ **Do** — suspend auditing with `Sentinel::withoutAuditing()`. It saves the previous flag, pauses,
and restores in a `finally`, so it nests and survives an exception.

```php
Sentinel::withoutAuditing(fn () => $importer->run());
```

❌ **Don't** — call `pause()` without a `resume()` in a `finally`. It clears unconditionally and
nothing else takes the flag down: a throw in between leaves auditing off for the rest of the
container scope, with the entries simply absent and no error anywhere.

```php
Sentinel::pause();
$backfill->run();      // throws
Sentinel::resume();    // never reached
```

✅ **Do** — route audit write failures to a channel that is actually alerted on, and consume
`AuditWriteFailed` when you soften the policy.

```php
'on_write_failure' => 'log',
'log_channel' => 'audit-alerts',
```

❌ **Don't** — set `on_write_failure = log` and leave it there. `log` only exists on the in-request
branch, and a write deferred to a commit is *always* announced and recorded rather than thrown — so
with nothing listening, a failing ledger is a silent one.

```php
'on_write_failure' => 'log',   // and nothing listening to Events\AuditWriteFailed
```

✅ **Do** — read the trail through `Sentinel::audits()` when the read itself has to be evidenced. That
is the one path `Compliance\AccessLog` is wired into.

```php
Sentinel::audits()->for($patient)->take(50)->get();
```

❌ **Don't** — assume compliance mode records every read. The record hangs off `Query\AuditQuery::read()`,
so anything that does not go through the Query API leaves nothing behind — the relation on your own
model, raw Eloquent at the table (`Audit::query()`), a lookup by primary key, the `verify*` walks and
the lifecycle machinery (`sentinel:prune`, `Archive\Rehydrator::restore()`) among them.
[Compliance mode](../08-lifecycle/05-compliance-mode.md) carries the canonical list of every
unrecorded path, asserted by `tests/Compliance/ReadPathsTest.php`; do not work from a shorter one.

```php
$patient->audits()->get();   // no access entry, no sentinel_access_log row, compliance on or off
```

✅ **Do** — turn on signatures and checkpoints *before* turning on compliance mode.

```php
'integrity' => ['checkpoints' => ['enabled' => true], 'signature' => ['enabled' => true]],
'compliance' => true,
```

❌ **Don't** — set `compliance => true` on its own. `Compliance\Requirements::enforce()` refuses to
boot with `ComplianceException::incomplete`, naming whichever is missing — at boot, because the first
write that was supposed to be signed may be a year away.

```php
'compliance' => true,   // with signature.enabled and checkpoints.enabled still false
```

**Explained in:** [Turning auditing off](../02-getting-started/04-turning-auditing-off.md) · [Failure policy](../09-operations/05-failure-policy.md) · [Artisan commands](../09-operations/06-artisan-commands.md) · [Scheduling](../09-operations/07-scheduling.md) · [Compliance mode](../08-lifecycle/05-compliance-mode.md)

### Extending

✅ **Do** — implement `Contracts\DeclaresFilters` on any driver you write, and name the set honestly.
Refusing a filter is a supported answer, delivered as the method is called.

```php
/** @return list<Filter> */
public function supportedFilters(): array
{
    return [Filter::Subject, Filter::Tenant, Filter::Period];
}
```

❌ **Don't** — leave it off assuming the query surface will work. A driver that does not implement it
is assumed to answer only the nine filters of `Filter::assumed()`; the ten published after them are
refused with `LedgerException::cannotFilterBy`.

```php
final class RedisLedger implements Ledger {}   // whereTag(), whereType(), after() … all refused
```

✅ **Do** — bind your driver against `Contracts\Ledger` in your own provider, with the lifetime the
package chose.

```php
$this->app->scoped(Ledger::class, fn (): Ledger => new RedisLedger(/* … */));
```

❌ **Don't** — put its name in `sentinel.ledger.default`. `SentinelServiceProvider::driver()` is a
closed match over `archive`, `database`, `fanout`, `memory` and `null`. Nor may `archive` be the
default, or `fanout` appear inside its own destination list.

```php
'ledger' => ['default' => 'redis'],   // ConfigurationException::unknown
```

✅ **Do** — run your driver against the published contract suite, and hydrate entries with
`setRawAttributes()`.

```php
final class RedisLedgerTest extends ElPandaPe\Sentinel\Testing\LedgerContractTestCase
{
    protected function ledger(): Ledger { return new RedisLedger(/* … */); }
}
```

❌ **Don't** — hydrate raw column text with `forceFill()`. It runs the set casts, so a JSON column
that already arrives encoded is encoded a second time and the entry stops reproducing its own hash —
which verification then reports, correctly, as tampering.

```php
$audit = (new Audit)->forceFill($row);   // fails verifyIntegrity() forever
```

✅ **Do** — write the entries before advancing whatever tracks the tail of the stream, and declare
`Contracts\Deduplicates` only if the driver can genuinely answer.

```php
/** @param non-empty-list<string> $captureIds @return list<string> */
public function settled(array $captureIds): array { /* ids that already have an entry */ }
```

❌ **Don't** — advance the tail first: if the process dies in between, the sequence number is burnt
and the gap in the chain is permanent. And a driver that answers "no" when the answer is "yes" writes
the same fact twice — correctness rests on the unique index on `capture_id`, never on the
optimisation.

```php
$this->tail = $sequence;   // then the write fails: sequence lost, chain broken
$this->store($entry);
```

**Explained in:** [The Ledger contract](../11-extending/01-the-ledger-contract.md) · [Writing a ledger driver](../11-extending/03-writing-a-ledger-driver.md) · [The contract test suite](../11-extending/04-the-contract-test-suite.md) · [Swapping components](../11-extending/06-swapping-components.md) · [API stability](../99-reference/09-api-stability.md)

---

**See also:** [Anti-patterns](02-anti-patterns.md) · [Security checklist](03-security-checklist.md) · [Production readiness](04-production-readiness.md) · [What a model declares](../02-getting-started/03-what-a-model-declares.md) · [Choosing your setup](../02-getting-started/05-choosing-your-setup.md) · [The write pipeline](../05-pipeline-and-security/01-the-write-pipeline.md) · [Protecting sensitive data](../05-pipeline-and-security/02-protecting-sensitive-data.md) · [The Query API](../06-reading/01-the-query-api.md) · [The verification playbook](../07-integrity/07-the-verification-playbook.md) · [Retention and pruning](../08-lifecycle/01-retention-and-pruning.md) · [Performance modes](../09-operations/01-performance-modes.md) · [Failure policy](../09-operations/05-failure-policy.md) · [Restoring state](../06-reading/08-restoring-state.md) · [Configuration](../99-reference/02-configuration.md) · [Exceptions](../99-reference/06-exceptions.md) · [Enums](../99-reference/04-enums.md)
