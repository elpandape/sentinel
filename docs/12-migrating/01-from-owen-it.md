# 🔄 From `owen-it/laravel-auditing`

> How `sentinel:import --from=owenit` reads that package's `audits` table, what it can carry across,
> what was already lost before Sentinel ever saw the rows, and why the chain starts at the import.

**On this page:** [What the import is](#what-the-import-is) · [The command](#the-command) ·
[Field-by-field mapping](#field-by-field-mapping) · [What was already lost](#what-was-already-lost) ·
[Clean, approximate, impossible](#clean-approximate-impossible) ·
[Feature equivalence](#feature-equivalence) · [The Rector stub](#the-rector-stub) ·
[The chain starts here](#the-chain-starts-here) · [After the run](#after-the-run) ·
[⚠️ Pitfalls](#️-pitfalls) · [✅ Best practices](#-best-practices)

---

## What the import is

A one-way copy of somebody else's table. `sentinel:import` reads the source `audits` table forward
by its `id`, a batch at a time, turns each row into an audit entry through
`ElPandaPe\Sentinel\Import\Origins\OwenIt`, and settles the batch through Sentinel's normal write
pipeline and ledger. It is not an adapter, not a bridge, and not a compatibility shim: after the run
the source table is untouched and irrelevant, and nothing reads it again.

Two things travel with every imported entry and are worth knowing before anything else:

- `source` is `ElPandaPe\Sentinel\Enums\Source::Import`. It is the only `Source` case no resolver
  ever produces, and it sits inside the hashed canonical payload — so an imported entry cannot be
  relabelled as a native one without breaking its own hash.
- `capture_id` is derived, not minted: `Support\DerivedIdentity::of('owenit', $sourceRowId)` hashes
  `sha256('owenit' . "\x1f" . $id)` into 26 Crockford characters. `sentinel_audits.capture_id`
  carries a UNIQUE index, so a second run over the same rows offers the same identities, the ledger
  recognises them, and they are dropped before a hash is computed for any of them.

Migrating is two jobs, not one. The **data** is this command. The **code** — your models, your
listeners, your driver, your resolvers — is a manual rewrite, with a Rector stub that renames class
names and nothing else.

> 📌 **Note.** The mapping in `Import\Origins\OwenIt` is written against that package's v14 and its
> `audits` table has not moved a column since v10, so it covers v10 through v14. On v6–v9 the actor
> was a single `user_id` with no `user_type`. Read your own source migration before you trust the
> table below.

---

## The command

```bash
php artisan sentinel:import --from=owenit --dry-run
php artisan sentinel:import --from=owenit --size=1000
```

| Option | Default | What it does | When you change it |
|---|---|---|---|
| `--from=` | — | `owenit` or `altek`. Anything else exits `2` and names the two it reads. | Always required |
| `--table=` | `audits` | The source table name. | Your application moved it (`config('audit.table')`) |
| `--connection=` | the default connection | Where the source table lives. | The old history is on another database |
| `--actor=` | `user` | The **prefix** of the two actor columns, read as `<prefix>_type` / `<prefix>_id`. | Your source changed `audit.user.morph_prefix` |
| `--size=` | `500` | Source rows read per batch. A non-numeric value falls back to `500`; the importer clamps to `max(1, $size)`. | Lower it when `new_values` blobs are large — the batch is materialised in memory before it settles |
| `--after=` | none | Skip every source row **up to and including** this key (`where('id', '>', $after)`). | Resuming a run, using the key the command printed |
| `--dry-run` | off | Read and map everything, write nothing. | Always, first |

**Exit codes**, from `Console\ImportCommand` — `0` and `1` from its `report()`, `2` from `handle()`:

| Code | Meaning | What to do |
|---|---|---|
| `0` | Every row read became an entry or already was one. | Nothing |
| `1` | The run happened and something did not come across — unreadable rows, pipeline discards, or both. The entries that did come across **are** written and chained. | Read the report; decide per reason |
| `2` | Nothing was attempted: unknown `--from`, absent table, mis-shaped table — **or** an exception mid-run. | See the warning below |

> ⚠️ **Warning.** Exit `2` from a mid-run exception prints `Nothing was imported: <reason>`, and that
> message is wrong about the batches that already settled. `ImportCommand` catches every `Throwable`
> and reports it the same way, but an import is not atomic: it settles batch by batch with no
> transaction of its own. If a run dies at batch 500 of 2000, roughly a quarter of the trail is on
> disk. The correct recovery is to run the same command again — the derived identity makes the
> settled rows repeat instead of duplicating.

### The refusal that happens before a single row is read

`Import\Shape::verify()` asks the schema builder for the column listing and refuses if any of the
thirteen columns `OwenIt::columns()` requires is missing. That refusal — `ImportException`, exit `2`,
nothing read — is the feature. A mapping that is one column out does not fail: it succeeds, and
writes entries that mean something other than what they say.

> 📌 **Note.** `Shape` checks column **names** only. Laravel's schema builder says nothing about
> types, so a table with the right names and the wrong types passes and then degrades quietly — a
> `new_values` holding a JSON *list* instead of an object comes out as `after = null`, because
> `Import\Row::json()` answers null for anything that is not an object at the top level.

---

## Field-by-field mapping

Everything below is `Import\Origins\OwenIt::map()`.

| `audits` column | `sentinel_audits` | Transformation |
|---|---|---|
| `id` | `metadata.import.row` (and, hashed, `capture_id`) | Kept as a string. Also fed to `DerivedIdentity::of('owenit', $id)`, which is what makes a repeated run free. **A row with no `id` is refused.** |
| `auditable_type` | `subject_type` | Carried verbatim, as the *string* the old application wrote. Not resolved to a class |
| `auditable_id` | `subject_id` | Cast to string. **A row missing either is refused** |
| `event` | `event` | Verbatim, no vocabulary imposed. Defaults to `updated` when null |
| — | `audit_type` | Always the literal `model` |
| — | `severity` | `Config::defaultSeverity($event)` — the `sentinel.severity.events` override for that exact event string, else `sentinel.severity.default` |
| — | `source` | Always `Source::Import` |
| `old_values` | `before` | JSON-decoded. Anything not an object at the top level becomes `null`; `'[]'` becomes `[]` |
| `new_values` | `after` | Same rule |
| `old_values` + `new_values` | `changes` | **Recomputed**, never copied — `Diff::between($before ?? [], $after ?? [])`. The source never wrote a diff. Null only when both sides were null |
| `<prefix>_type` | `actor_type` | Prefix from `--actor`, `user` by default |
| `<prefix>_id` | `actor_id` | Same |
| — | `impersonator_type` / `impersonator_id` | Left null. That package has no impersonation concept to read |
| `url` | `context.url` | |
| `ip_address` | `context.ip` | |
| `user_agent` | `context.user_agent` | |
| `tags` | rows in `sentinel_audit_tags` | Split on `,`, trimmed, empties dropped |
| `created_at` | `occurred_at` | Parsed with `CarbonImmutable::parse()`. **A row with no parseable timestamp is refused** |
| `updated_at` | — | Dropped. That an audit row was updated is a fact about that table, not about the record |
| — | `tenant_id` | Left null. Nothing on the source row maps to a tenant |
| — | `sequence` / `hash` / `previous_hash` / `stream` | Assigned by the ledger at import time, exactly as for a native entry |
| — | `created_at` | The instant of the **import**, not of the fact |

Three keys of `context` and no more. `OwenIt::context()` runs `array_filter` over url/ip/user_agent
and drops the nulls, so a key that is absent means "nobody wrote it down" rather than "nothing came
from there". Everything else Sentinel's execution context normally holds — session, trace, host, job,
command — has no answer in the source and is left out.

A row that cannot be mapped is not an error and does not stop the run. `Import\Mapping::refused()`
carries the reason, the report groups by it, and the command prints one line per reason. There are
exactly three reasons for this origin:

| Refusal | Cause on the source row |
|---|---|
| `the row has no key of its own to be identified by` | `id` is null or empty |
| `the row does not say when it happened, and an invented instant is worse than no entry` | `created_at` is null or unparseable |
| `the row does not say what it is about` | `auditable_type` or `auditable_id` is null |

---

## What was already lost

This is the section teams discover three months later. None of it is a Sentinel limitation, and none
of it can be closed from this side — the gap is in the rows.

| What is missing | Why | Consequence after the import |
|---|---|---|
| **The whole record on an `updated` row** | That package writes `getDirty()`, so `old_values` / `new_values` hold the fields that moved and not the record | An imported update portrays a change, not a state. `created` and `deleted` rows do carry everything |
| **Attributes whose value was an array** | `audit.allowed_array_values` is `false` by default, and array-shaped values never reached the table | The attribute is absent from `before`/`after`, indistinguishable from one that did not exist |
| **A label that contained a comma** | `tags` is one comma-joined string with no escaping. The split happened when it was written | `OwenIt::tags()` splits on commas because that is the only thing that can be done with what is on the row |
| **Mass `Builder::update()` / `Builder::delete()`** | Eloquent fires no model events for them, so that package wrote nothing | Whole classes of change simply are not in the history. Sentinel captures these natively going forward — see [Mass operations](../03-capture/05-mass-operations.md) |
| **Pivot changes (`attach`, `detach`, `sync`, `toggle`)** | Same reason: no model event | Nothing to import. See [Relationship auditing](../03-capture/04-relationships.md) |
| **Who acted on whose behalf** | No impersonation concept exists in that package | `impersonator_type` / `impersonator_id` stay null forever for the imported period |
| **A tenant** | No tenant column | See the stream note below |
| **Anything about integrity** | Nobody hashed those rows as they were written | There is no chain to import. See [The chain starts here](#the-chain-starts-here) |

> ⚠️ **Warning.** A gap in the imported history does not mean nothing happened. It means nothing was
> recorded. Say that out loud to whoever will read the trail.

---

## Clean, approximate, impossible

| Maps cleanly | Maps approximately | Cannot map |
|---|---|---|
| `id` → `metadata.import.row` and the derived `capture_id` | `old_values` / `new_values` → `before` / `after`: real values, but not a guaranteed complete snapshot on an update | `previous_hash` — nothing links a source row to the one before it |
| `auditable_type` / `auditable_id` → `subject_type` / `subject_id` | `changes` — recomputed from two partial sides, so it describes what the source recorded moving, not everything that moved | `impersonator_*` — no source concept |
| `event` → `event`, verbatim | `tags` — split on a delimiter the source never escaped | `tenant_id` — no source column |
| `<prefix>_type` / `<prefix>_id` → `actor_type` / `actor_id` | `created_at` → `occurred_at` — parsed with **no timezone** (see pitfalls) | The rest of the execution context: session, trace, host, job, command |
| `url`, `ip_address`, `user_agent` → three `context` keys | `severity` — derived from the event *string*, which the source chose | The source's own `updated_at` |

The four standard event strings that package writes — `created`, `updated`, `deleted`, `restored` —
are all values of `ElPandaPe\Sentinel\Enums\AuditEvent`, so `whereEvent(AuditEvent::Deleted)` matches
imported rows. A custom event string does not: `event` is a plain `string(64)` and is never cast to
the enum, so filter it with `whereEvent('your_string')`.

---

## Feature equivalence

For someone who knows that package. **The right-hand column is what the code does, which is not
always a rename.**

| Laravel Auditing | Sentinel | Notes |
|---|---|---|
| `use OwenIt\Auditing\Auditable;` | `use ElPandaPe\Sentinel\Concerns\Auditable;` | Renamed by the Rector stub |
| `implements Contracts\Auditable` | Nothing to implement | Delete the `implements` clause by hand |
| `$model->audits()` | `$model->audits()` — still a `MorphMany`, eager-loading `tags` — or `Sentinel::audits()->for($model)` for the full query API | See [The Query API](../06-reading/01-the-query-api.md) |
| `$auditInclude` | `$auditInclude` | Same name, same idea |
| `$auditExclude` | `$auditExclude` | Same name, same idea |
| `$auditStrict` | No equivalent | Hidden attributes are governed globally by `sentinel.snapshots.include_hidden` (default `true`) |
| `$auditTimestamps` | No equivalent | Sentinel snapshots the record and lets the diff decide what moved |
| `$auditEvents` | **No equivalent.** The trait always observes `created`, `updated`, `deleted`, `forceDeleted` | Narrow with `$auditExclude`, a `Sentinel::filter()` policy, or `Sentinel::withoutAuditing()`. See [What a model declares](../02-getting-started/03-what-a-model-declares.md) |
| `generateTags(): array` | `$auditTags` on the model, or `Sentinel::event('name')->tags([...])` for a custom event | See [Labels](../06-reading/06-labels.md) |
| No equivalent | `$auditRedact`, `$auditEncrypt`, `$auditHash` | The write pipeline applies them — including to imported rows |
| No equivalent | `$auditTransitions`, `$auditParents`, `$auditSeverity`, `$auditSnapshots` | New declarations with no counterpart |
| `config('audit.resolvers')` + `config('audit.user.resolver')` | `config('sentinel.resolvers')` — ten named slots, one per resolved thing | See [The ten resolvers](../04-context/02-resolvers-reference.md) |
| `Contracts\AuditDriver` | `Contracts\Ledger` | A wider contract with a published test suite — see [The Ledger contract](../11-extending/01-the-ledger-contract.md) |
| `Drivers\Database` | `ledger.default = 'database'` | Configuration, not a class reference |
| `OwenIt\Auditing\Events\*` | Different events, different shape | Rewrite the listeners — see [Events and listeners](../09-operations/04-events-and-listeners.md) |
| `Auditor` facade | `Sentinel` facade | Different questions; not a rename |

---

## The Rector stub

`stubs/rector/owen-it.php` ships in the package and renames fully-qualified names and imports in
**your** application. It renames seven classes (the trait, the audit model and its contract, the
driver contract and the database driver, and both resolver contracts) and nothing else.

```bash
cp vendor/elpandape/sentinel/stubs/rector/owen-it.php rector-sentinel.php
vendor/bin/rector process --config=rector-sentinel.php --dry-run
```

It deliberately refuses three things, because there is nothing to rename them to: the source
`Contracts\Auditable` interface (Sentinel's trait implements none), that package's events (a
different lifecycle, not a different name), and the `Auditor` facade. Run it in `--dry-run` until you
have read every hunk. Every behavioural difference on this page is a manual change.

---

## The chain starts here

This is the sentence the whole page exists for.

**Sentinel will not fabricate a chain backwards.** The imported entries get a `sequence`, a `hash`
and a `previous_hash` from the ledger like any other entry, and they link onto whatever chain was
already there. But nothing links the *source rows* to each other, because nobody hashed them as they
were written. A `previous_hash` invented for them would be a proof that nobody touched data this
package never saw — the one claim an audit engine must never make.

The command says so on its way out: *"The chain starts here: what the other package recorded before
this has no link, because it never had one."*

### What that means for a compliance story

| Question a reviewer asks | Honest answer |
|---|---|
| "Is the whole trail tamper-evident?" | From the import forward, yes: `sentinel:verify` rehashes every entry and checks every link. Before the import, no — there was never a link to check |
| "Can you prove this 2023 entry has not been edited?" | You can prove it has not been edited **since the import**, because its hash and its position in the chain were sealed then. You cannot prove anything about the window between when it happened and when it was imported |
| "Where did this entry come from?" | `source = import`, `metadata.import.origin = 'owenit'`, `metadata.import.row = '<source id>'`. Every imported entry names its source row |
| "Why does the old history have no actor on some rows?" | Because the source row had none. Sentinel resolves no context during an import — see below |

Date the import and keep the run's output. The boundary between "recorded by another tool, copied in
and sealed on this date" and "witnessed and sealed as it happened" is the single most useful thing to
write down.

> 🔒 **Security.** The importer removes `Pipeline\Stages\ResolveContext` from the pipeline for the
> length of the run and puts it back afterwards. Without that, every historical action would be
> signed with the identity of whoever ran the migration in their terminal. Context must travel on the
> row or be absent — it is never resolved from the present.

### Two more things the importer forces

`Import\Importer::import()` sets `sentinel.mode` to `sync` and restores it in a `finally`. Under
`queue` the importer would push a job per batch and return having written nothing, so the report
would be a lie; under `buffered` it would fill a store nobody sized for a backfill. Every **other**
pipeline stage runs whole, which is why `$auditRedact` / `$auditEncrypt` / `$auditHash`, the global
`sentinel.security.*` field rules, label resolution and your own `Sentinel::filter()` policies all
apply to what comes in — and why `FilterUnchanged` and `EnforcePolicies` can discard imported rows.

---

## After the run

```php
use ElPandaPe\Sentinel\Enums\Source;
use ElPandaPe\Sentinel\Facades\Sentinel;

// Everything the import brought in, oldest fact first.
// Never order by sequence after an import: imported entries sit at the TAIL of the
// chain even though they are the oldest facts in it.
$imported = Sentinel::audits()
    ->whereSource(Source::Import)
    ->byOccurrence()
    ->take(50)
    ->get();

foreach ($imported as $entry) {
    $origin = $entry->metadata['import']['origin'] ?? null;  // 'owenit'
    $row    = $entry->metadata['import']['row'] ?? null;     // the source row's own id

    assert($entry->verifyIntegrity() === true);
}
```

```bash
php artisan sentinel:verify
php artisan sentinel:show --subject="App\Models\Invoice:77"
```

> 🧪 **Verify it.** `sentinel:verify` should come back sound. Frozen tests in the package assert
> exactly this end to end: every imported entry reproduces its own hash, the entries written before
> the import keep their original hashes, and imported entries link onto the existing chain.

Type the subject for `sentinel:show` exactly as the source wrote it into `auditable_type` — the
importer carried that string over untouched and did not resolve it to a class.

### Restoring from an imported entry

```php
use ElPandaPe\Sentinel\Enums\Omission;
use ElPandaPe\Sentinel\Models\Audit;

$entry = Audit::query()->findOrFail($id);

$whole = $entry->restore();   // refused on ANY imported entry

if ($whole->refused === Omission::EntryImported) {
    $named   = $entry->restore(['status', 'total']);
    $applied = $named->applied;   // list<string> — the field names that were put back
    $skipped = $named->skipped;   // array<string, Omission> — why each other field was not
}
```

`Restore\Planner` refuses a whole-record restore when `$audit->source === Source::Import` and
`$fields === null`, because an imported `before`/`after` is not guaranteed to be a complete snapshot.
It refuses uniformly — including on `created` and `deleted` rows that *do* carry everything — because
a rule that has to be reasoned about per event is a rule somebody will reason about wrongly. It
refuses by returning a `RestoreResult`; there is no exception to catch. See
[Restoring state](../06-reading/08-restoring-state.md).

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| A dry run says `Written as entries: 400` and `Refused by the pipeline: 0`; the real run writes 380 and exits `1` | `--dry-run` does **not** run the write pipeline. `Importer::walk()` computes `written = count(offered) - already` in rehearse mode and never calls the recorder, so `discarded` is structurally always `0` there | Treat the dry run's `Written` figure as an **upper bound**. It proves the shape, the reachability and the refusals; it cannot predict a pipeline discard |
| Every imported `occurred_at` is off by a fixed number of hours | `Import\Row::instant()` calls `CarbonImmutable::parse()` with no timezone, so a naive source timestamp is read in the importing application's `app.timezone` | Set `app.timezone` to the source database's timezone **before** the run. `occurred_at` is inside the hashed payload, so there is no fixing it afterwards short of deleting the range and re-importing |
| Report says `Written: 0` and attributes everything to `Refused by the pipeline`, yet the rows are in the table | The command ran inside an open transaction on the application's default connection — the one `Dispatch\Dispatcher` falls back to when no subject model names another. With `sentinel.transactions.after_commit = true` (the default) `dispatchMany()` defers to commit and returns an empty collection, so the importer counts nothing | Never run `sentinel:import` inside `DB::transaction()` or inside a migration |
| `$auditRedact` / `$auditEncrypt` / `$auditHash` did not apply to imported values | `Support\PolicyRegistry::resolve()` looks the policy up from the class named in `auditable_type`. If the migration renamed or moved that model first, the class no longer resolves and the policy is `none()` | Import **before** renaming models, or add a morph map alias for the old name. The global `sentinel.security.*.fields` rules apply either way |
| In a multi-tenant install the imported history is in a different stream from everything else | Imported entries carry no `tenant_id`, and under the default `sentinel.integrity.stream = 'tenant'` `Integrity\Stream` maps a null tenant to `global` | Expected and verifiable. Decide deliberately: `global` in a single-tenant install puts everything on one chain. See [Streams](../07-integrity/02-streams.md) |
| `ConfigurationException: Sentinel resolved the stream name […], longer than the 64 characters the column holds`, batch aborted | Under `integrity.stream = 'subject_type'` the name is `type:` + the morph alias of whatever the *old* application wrote into `auditable_type`, and `Integrity\Stream` refuses names over 64 characters | Add a morph map entry for the long class name, or import under `global` |
| Partitioning by `created_at` did not spread the imported history across historical partitions | `created_at` is the date of the **entry**, not of the fact. Everything imported lands in the current partition | Expected. Partition or query on `occurred_at` for facts. See [Partitioning](../10-database-engines/06-partitioning.md) |
| A second run created duplicates instead of recognising the rows | The identity is `DerivedIdentity::of('owenit', $id)`. A re-keyed or renumbered dump of the same source produces different identities | Never import from a renumbered dump. Removing the duplicates means deleting a range of the trail |
| The report shows a non-zero `Refused by the pipeline` with no reason | `Import\Report` carries reasons for origin refusals only. Pipeline discards announce themselves as an `AuditDiscarded` event carrying stage and reason, and nothing joins the two | Listen for that event during the run, or reason from `FilterUnchanged` and your own `Sentinel::filter()` policies |
| `--after=` re-read the whole table, or skipped a row you wanted | `--after` is **exclusive** and takes the **raw source key** (`where('id', '>', $after)`), not a `capture_id` or an audit id | Pass back exactly the key the command printed in its resume line |
| The run never finishes and `Could not be read` grows without bound | `Importer::walk()` advances the cursor with `$row->text('id') ?? $cursor`. A batch in which no row yields a key never moves the cursor and is read again | Only reachable on a source table whose `id` values are null or empty — `Shape` will not catch it, since the column is present |

---

## ✅ Best practices

✅ **Do** — dry-run against real production data first, and read every `Could not be read` line
before the real run. The three refusal reasons are all facts about the source and none of them is
repairable from this side.

```bash
php artisan sentinel:import --from=owenit --dry-run
```

❌ **Don't** — trust the dry run's `Written as entries` as a promise. It skips the pipeline
entirely, so any row `FilterUnchanged` or a `Sentinel::filter()` policy would refuse is counted as
written and the run exits `0` where the real one exits `1`.

```bash
# Not a rehearsal of the write. A rehearsal of the read and the mapping.
php artisan sentinel:import --from=owenit --dry-run   # then assume fewer entries land
```

---

✅ **Do** — read the source application's own configuration before the first run, and pass what the
importer cannot guess. `--actor` is an option with a default of `user`, never a detection.

```bash
# config('audit.table') and config('audit.user.morph_prefix') in the OLD application
php artisan sentinel:import --from=owenit --table=activity_audits --actor=operator
```

❌ **Don't** — leave `--actor` at its default when the source changed the prefix. If a column of the
default name happens to exist you get silently empty actors on the whole imported period; if it does
not, `Shape` refuses — which is the better of the two outcomes.

```bash
php artisan sentinel:import --from=owenit   # source used operator_type/operator_id
```

---

✅ **Do** — import before you rename or move models, so `auditable_type` still resolves and the
per-model protections apply to the values coming in.

```php
// App\Models\Invoice still exists under that name while the import runs
class Invoice extends Model
{
    use \ElPandaPe\Sentinel\Concerns\Auditable;

    protected array $auditEncrypt = ['bank_account'];
}
```

❌ **Don't** — rename the models first and import afterwards. `PolicyRegistry::resolve()` returns
`AuditPolicy::none()` for a class that no longer exists, and the values land in the trail exactly as
plainly as the source held them.

```php
// App\Models\Billing\Invoice — the source rows still say App\Models\Invoice.
// $auditEncrypt no longer applies to anything imported.
```

---

✅ **Do** — re-run the same command after any interruption, and use the printed key only to make the
re-run fast. Nothing duplicates: the derived `capture_id` plus the unique index settle it.

```bash
php artisan sentinel:import --from=owenit --size=1000 --after=418923
```

❌ **Don't** — read `Nothing was imported` after a mid-run failure as "the run was atomic". Batches
that settled before the exception are committed, and starting over from a clean slate is not what
happens.

```bash
# Wrong instinct: truncate and start again. Right instinct: run it again.
```

---

✅ **Do** — read a migrated history by `occurred_at`. After an import, `sequence` order and
`occurred_at` order disagree by design, because the oldest facts were written last.

```php
Sentinel::audits()->for($invoice)->byOccurrence()->get();
```

❌ **Don't** — order a migrated history by `sequence` and present it as a timeline. It is the order
entries were *written*, so the whole imported period appears after everything that happened since.

```php
Sentinel::audits()->for($invoice)->get();   // ledger order, not history order
```

---

✅ **Do** — name the fields when restoring from an imported entry, and handle
`Omission::EntryImported` as an expected outcome rather than an error.

```php
$result = $entry->restore(['status', 'total']);
```

❌ **Don't** — call `restore()` with no arguments on an imported entry and treat the refusal as a
bug. An imported `before`/`after` is not a guaranteed snapshot, and the guard does not distinguish by
event or origin on purpose.

```php
$entry->restore();   // always refused with Omission::EntryImported
```

---

✅ **Do** — take a backup before the real run, and leave `sentinel.compliance` off until the import
has been verified.

❌ **Don't** — turn compliance mode on first. Undoing an import means deleting a range of the trail,
and compliance mode refuses that without archiving it first. See
[Compliance mode](../08-lifecycle/05-compliance-mode.md).

---

**See also:** [The import runbook](03-the-import-runbook.md) ·
[From altek/accountant](02-from-altek.md) · [The hash chain](../07-integrity/01-the-hash-chain.md) ·
[Verification](../07-integrity/06-verification.md) ·
[The write pipeline](../05-pipeline-and-security/01-the-write-pipeline.md) ·
[Protecting sensitive data](../05-pipeline-and-security/02-protecting-sensitive-data.md) ·
[Restoring state](../06-reading/08-restoring-state.md) ·
[Artisan commands](../09-operations/06-artisan-commands.md) ·
[Exit codes](../99-reference/07-exit-codes.md) · [Enums](../99-reference/04-enums.md)
