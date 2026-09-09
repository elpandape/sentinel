# 🐘 A database of its own

> Putting the audit tables on their own connection: the one config key that moves them, what
> honours it, what it buys, and the three things it quietly takes away.

**On this page:** [The one key](#the-one-key-that-moves-everything) ·
[What honours it](#what-honours-the-connection-and-what-does-not) ·
[What it buys](#what-a-separate-database-buys) ·
[No cross-database joins](#the-first-cost-no-cross-database-joins) ·
[Two transactions](#the-second-cost-two-transactions-instead-of-one) ·
[after_commit](#the-third-cost-after_commit-across-two-connections) ·
[Table names and the prefix](#table-names-and-the-prefix) ·
[Replacing the models](#replacing-the-audit-and-audittransaction-models) ·
[A landlord/tenant layout](#a-landlordtenant-database-layout) ·
[Backup and restore](#backing-up-an-append-only-table) ·
[⚠️ Pitfalls](#️-pitfalls) · [✅ Best practices](#-best-practices)

---

## The one key that moves everything

There is exactly one:

```php
// config/sentinel.php
'database' => [
    // Any connection name defined in config/database.php. Null uses the application default.
    'connection' => env('SENTINEL_DB_CONNECTION', 'audits'),
],
```

`Support\Config::connection()` reads it as a nullable string. An absent key is **not** an error — it
returns `null`, which every model and every migration reads as "the application's default
connection". A non-string value throws `ConfigurationException::expected` naming
`sentinel.database.connection`.

`Config` caches nothing. Every accessor re-reads `Illuminate\Contracts\Config\Repository` on each
call, so `config()->set('sentinel.database.connection', …)` takes effect on the very next query. That
is what makes a per-tenant connection possible at all — see
[A landlord/tenant layout](#a-landlordtenant-database-layout).

The connection you name must exist in `config/database.php` before anything reads it. It does not have
to be a different *server* — a second database on the same instance already gives you separate
schemas, separate grants and a separate backup cadence.

> 📌 **Note.** All seven Sentinel tables live on that one connection. There is no per-table connection
> key, and there cannot be: `Retention\Cascade::headers()` correlates `sentinel_transactions` against
> `sentinel_audits` inside a single `whereNotExists` subquery, and `Retention\Cascade::hanging()`
> selects entry ids from `sentinel_audits` inside the delete it issues against
> `sentinel_audit_tags` and `sentinel_audit_relations`.

---

## What honours the connection, and what does not

| Mechanism | Reads `database.connection`? | Where |
|---|---|---|
| The seven Eloquent models | Yes — `getConnectionName()` on each | `src/Models/` |
| The eight package migrations | Yes — each declares `getConnection()` | `database/migrations/` |
| The sealing write (entry + labels + relation lines) | Yes — the transaction is opened on the audit model's connection | `DatabaseLedger::chain()` |
| The stream tail read and its lock | Yes | `Ledger\StreamGate` |
| Every read the Query API issues | Yes | `Ledger\DatabaseLedger` |
| The prune cascade | Yes — `$this->audits->getConnection()` | `Retention\Cascade` |
| `sentinel:install`'s schema probe | Yes | `Console\InstallCommand` |
| `sentinel:partitions` | Yes | `Console\PartitionsCommand` |
| The after-commit deferral | **No** — the *subject's* connection | `Dispatch\Dispatcher::connection()` |
| `sentinel:import --connection=` | **No** — that names the **source** you are importing from | `Console\ImportCommand` |
| `queue.connection` / `queue.queue` | **No** — a queue connection, not a database one | `config/sentinel.php` |
| `buffer.connection` | **No** — a Redis connection | `config/sentinel.php` |
| `ledger.ledgers.archive.disk` | **No** — a filesystem disk | `config/sentinel.php` |

Because each migration declares its own `getConnection()`, `php artisan migrate` puts Sentinel's
tables on the audit connection with no flag. Laravel's `Migrator::runMigration()` resolves the
migration's connection per file; only the `migrations` bookkeeping table stays wherever the migrator
itself was pointed.

```bash
php artisan migrate                       # Sentinel's tables land on the audit connection
php artisan sentinel:install              # probes that connection and names what is missing
```

`sentinel:install` reads the schema of the configured connection and reports which of the seven
tables are absent. A connection it cannot open is an INVALID exit, printed as *"The configuration is
in place, but the schema could not be read: …"* — which is the fastest way to find out you named a
connection that is not in `config/database.php`.

> ⚠️ **Warning.** `php artisan migrate:fresh` wipes **one** connection — the one named by
> `--database`, defaulting to the application's. With audits elsewhere, it drops the `migrations`
> table, then re-runs every migration, and Sentinel's `Schema::connection('audits')->create(…)` hits
> a `sentinel_audits` that is still standing. The error you see is the engine's "table already
> exists". In local development, wipe both connections, or do not use `migrate:fresh` at all.

---

## What a separate database buys

**Write isolation.** The trail is the table that grows without bound. Its inserts, its per-stream
`lockForUpdate()` tail read and its retention deletes stop competing for the same buffer pool, WAL
and vacuum budget as the business tables.

**A retention and backup cadence of its own.** Audits are kept for years while application rows are
kept for a release; on one connection you back up both on whichever schedule is stricter.

**A smaller blast radius.** A `DROP` or a bad migration in the application database does not reach
the evidence, and the reverse is true too.

**Privilege separation that is actually enforceable.** The package issues a small, closed set of
statements, so you can grant the runtime role less than the maintenance role:

| Table | On the runtime write path | `UPDATE` only from | `DELETE` only from |
|---|---|---|---|
| `sentinel_audits` | `INSERT`, plus the `SELECT … FOR UPDATE` tail read | `Redaction\Redactor` | `Retention\Cascade` |
| `sentinel_audit_tags` | insert-or-ignore | — | `Cascade`, `Redactor` |
| `sentinel_audit_relations` | `INSERT` | — | `Cascade`, `Redactor` |
| `sentinel_transactions` | `INSERT` when a scope opens, `UPDATE` when it closes | `Transactions\TransactionScope` | `Cascade::headers()` |
| `sentinel_checkpoints` | `INSERT` when a range is anchored | — | — |
| `sentinel_archives` | — (written by the prune) | `Archive\Manifest`, for a range recorded twice | — |
| `sentinel_access_log` | `INSERT`, compliance mode only | — | — |

Nothing outside `Redaction\Redactor` ever updates `sentinel_audits`, and nothing outside
`Retention\Cascade` ever deletes from it. A web and worker role holding `SELECT, INSERT` on
`sentinel_audits` runs the whole capture path; `UPDATE` and `DELETE` on it are only needed by
`sentinel:redact` and `sentinel:prune`.

> 🔒 **Security.** Separating the grant does not make the trail tamper-proof. Anyone with the
> maintenance credential can still rewrite rows, and only the hash chain notices — see
> [The hash chain](../07-integrity/01-the-hash-chain.md) and [Signing](../07-integrity/04-signing.md)
> for what a signature does and does not add on top.

---

## The first cost: no cross-database joins

The package itself never joins. There is no `join()`, no `leftJoin()` and no `whereHas()` anywhere in
`src/`: every filter narrows by value. `AuditQuery::for()` resolves a subject to a
`subject_type`/`subject_id` pair through `Support\Reference` and passes both as bindings. So Sentinel's
own reads do not care which database the subject lives in.

Your reads might. Two things behave differently:

**`$model->audits()` keeps working.** The trait's `morphMany` targets the configured audit model,
which carries its own connection; Eloquent issues the relation query there and passes the parent key
as a binding. The same is true of `AuditCollection::loadReferences()`, which loads the `subject` and
`actor` morphs on each *related* model's own connection — one query per morph type, whichever
database that type lives in.

**`whereHas('audits')` stops working.** A `whereHas` compiles to a correlated subquery inside the
parent's own statement, and that statement is issued on the parent's connection against a table that
is not there. Same for any hand-written join between a business table and `sentinel_audits`.

```php
use App\Models\Invoice;
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Models\Audit;
use Illuminate\Database\Eloquent\Builder;

// ❌ One statement, two databases. The engine reports an unknown table.
Invoice::query()
    ->whereHas('audits', fn (Builder $entries): Builder => $entries->where('event', 'updated'))
    ->get();

// ✅ Ask the trail first, then let it hydrate the subjects it names.
$entries = Sentinel::audits()->whereEvent('updated')->take(500)->get();

$entries->loadReferences();   // one query per morph type, on each type's own connection

foreach ($entries as $audit) {
    /** @var Audit $audit */
    $audit->subject;          // the Invoice, from the application database
}
```

Note also that `AuditQuery` has no "every entry of this subject **type**" filter: `for()` takes a
subject *and* a key, and throws `QueryException::missingKey` when handed a class-string alone.
`whereType()` narrows the `audit_type` — `model`, `custom`, `transition` — not the subject's class.

> 💡 **Tip.** If you are reaching for a join, you usually want the trail as the outer query, not the
> inner one. Start from `Sentinel::audits()`, narrow there, and hydrate the subjects afterwards with
> `loadReferences()`. See [The Query API](../06-reading/01-the-query-api.md).

---

## The second cost: two transactions instead of one

`DatabaseLedger::chain()` opens a transaction on the audit model's connection and does the whole
sealing inside it: the tail read through `StreamGate`, the sequence assignment, the hash, the insert,
the labels and the relation-line projection. That transaction is atomic **with respect to the ledger**
in every layout.

On a shared connection it is also, in practice, folded into whatever transaction the business change
opened — same connection, so the same commit decides both. On a dedicated connection it is not. The
audit write commits or rolls back on its own, independently of the business write. Nothing in the
package tries to make one two-phase commit out of two connections, and nothing pretends to.

The practical consequence: **on a dedicated connection, the only thing tying the entry to the
business fact is `transactions.after_commit`.** Which brings us to the trap.

---

## The third cost: `after_commit` across two connections

`Dispatch\Dispatcher::dispatch()` asks the **subject** for its connection, never the audit one:

> *"The subject is asked for its connection and nothing else: the transaction that decides whether the
> fact happened is the one the subject was written in, not the one the ledger writes to."*

If that connection has an open transaction and `transactions.after_commit` is true, the write is
registered as an `afterCommit` callback and runs when the business transaction commits — and never
runs if it rolls back. If either condition is false, the entry is handed over on the spot.

Four combinations, and only one of them is a trap:

| Layout | `after_commit` | Business transaction rolls back | Result |
|---|---|---|---|
| Shared connection | `true` (default) | yes | No entry. The callback is discarded. |
| Shared connection | `false` | yes | No entry. The database undoes the insert with everything else. |
| **Dedicated connection** | `true` (default) | yes | No entry. The deferral hung off the business connection and was discarded. |
| **Dedicated connection** | **`false`** | yes | **The entry stands.** It was written on another connection that nothing rolled back. |

That last row is the whole reason to be careful here. With one database, turning `after_commit` off
costs you nothing visible, because the engine cleans up after you. With two, it produces a trail that
asserts a fact the application never kept — which is precisely the failure an audit engine exists to
prevent.

> ⚠️ **Warning.** Leave `transactions.after_commit` at `true` on a dedicated connection. Turning it
> off is supported and it means what it says: a ledger allowed to claim what a rollback undid.

Two more details that only surface with two connections:

**An entry with no subject waits on the application's *default* connection.** `Dispatcher::connection()`
falls back to `$this->database->connection()` when there is no subject model. A `Sentinel::event()`
raised inside a `DB::connection('reporting')->transaction(…)` — where `reporting` is not the default
— is therefore not deferred at all, and survives that transaction's rollback.

**The business-operation header does not wait either.** `Transactions\TransactionScope` saves the
`AuditTransaction` row directly when the scope opens, and saves it again when the scope closes. Neither
save is deferred. On a shared connection a rollback takes the header with it; on a dedicated one the
header survives while the entries it was meant to correlate never arrive, leaving a row that names an
operation nothing points at. The header is not evidence — nothing about it is hashed, and the entries
are what the chain covers — so this is untidy rather than unsound. See
[Business transactions](../03-capture/06-business-transactions.md).

---

## Table names and the prefix

Every table name in the package is composed at call time by `Support\Config::table()`:

```php
$this->string('tables.prefix').$this->string("tables.{$name}");
```

The accepted names are the seven config keys — `audits`, `audit_tags`, `audit_relations`,
`transactions`, `checkpoints`, `archives`, `access_log` — and nothing in `src/` or
`database/migrations/` writes a table name literally. Every occurrence of the string `sentinel_`
under `src/` is inside a comment.

```php
// config/sentinel.php
'tables' => [
    'prefix'          => 'audit_',
    'audits'          => 'entries',      // -> audit_entries
    'audit_tags'      => 'labels',       // -> audit_labels
    'audit_relations' => 'relations',
    'transactions'    => 'operations',   // -> audit_operations
    'checkpoints'     => 'checkpoints',
    'archives'        => 'archives',
    'access_log'      => 'access_log',
],
```

Two rules govern this block.

**Keep all eight keys.** Laravel's `mergeConfigFrom` is `array_merge` one level deep, so a published
`tables` array replaces the package's wholesale. `Config::table()` has no code-side default: a key you
dropped throws `ConfigurationException` reading *"Sentinel configuration key [sentinel.tables.checkpoints]
is not set."* at the moment something first touches that table — which may be months after the upgrade
that added it.

**Rename with `tables.prefix`, never with a `prefix` on the connection.** Eloquent's queries go
through the grammar, which applies a connection-level prefix; the package's raw DDL does not. Every
statement in `Partitions\Grammar`, the `_default` partition and tenant partitions in the partitioned
stubs, and the `create index …` in the JSON-index stub interpolate the name that `Config::table()`
returned, unprefixed by the connection. Setting both would give you one name for reads and another for
partition maintenance.

> 🐘 **Engine.** The default `tables.transactions` resolves to `sentinel_transactions`, which is one
> word away from an application table many schemas already have. On a shared connection the prefix is
> load-bearing; on a dedicated one you have the whole namespace to yourself and can shorten it.

---

## Replacing the Audit and AuditTransaction models

`Models\Audit` and `Models\AuditTransaction` are deliberately not `final`. Name a subclass and the
container returns it everywhere:

```php
// config/sentinel.php
'models' => [
    'audit'       => App\Models\Audit::class,
    'transaction' => null,   // keep the package's
],
```

`Config::model()` checks `class_exists` and `is_a` against the package class and throws
`ConfigurationException::invalidClass` otherwise. The binding is honoured by `app(Audit::class)`, by
the trait's `audits()` relation, and by `Database\Factories\AuditFactory::modelName()`.

```php
namespace App\Models;

use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Models\Audit as SentinelAudit;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;

final class Audit extends SentinelAudit
{
    #[Scope]
    protected function alarming(Builder $query): void
    {
        $query->whereIn('severity', [Severity::Warning->value, Severity::Critical->value]);
    }

    public function headline(): string
    {
        return "{$this->audit_type}.{$this->event}";
    }
}
```

### What you may safely add

| Addition | Why it is safe |
|---|---|
| Query scopes (`#[Scope]`) | They compose onto a builder the package already returns |
| Accessors, computed methods, presenters | They read attributes; nothing writes back |
| Extra relations to your own tables | Resolved on the related model's connection |
| Extra casts — **merged into** `parent::casts()` | The parent's casts stay in force |

### What you must not touch

| Member | What breaks if you override it |
|---|---|
| `getTable()`, `getConnectionName()` | The configured table and connection stop being read; `tables.*` and `database.connection` become dead keys |
| `getDateFormat()` | The columns are `datetime(6)`; anything but `'Y-m-d H:i:s.u'` truncates the microseconds the schema declares |
| `getGuarded()` | The package fills entries wholesale; a guard silently drops attributes |
| `booted()` without `parent::booted()` | You remove the `updating`/`deleting` guard that throws `ImmutableAuditException` — the model becomes editable |
| `toArray()` | It is a frozen shape: the top-level keys and the `integrity` block only ever grow, never rename, never get reinterpreted |
| `UPDATED_AT`, `$timestamps` | There is no `updated_at` column on `sentinel_audits` |

> ⚠️ **Warning.** `protected $table` and `protected $connection` on your subclass do **nothing**.
> `Audit::getTable()` and `getConnectionName()` are method overrides that read `Support\Config`
> directly and never consult those properties. The symptom is a rename that appears to be ignored.

> 📌 **Note.** `toArray()` is built key by key from named attributes. `$appends` and `$hidden` on a
> subclass do not reach it, which is the point: the serialised entry is a published shape, not a
> rendering of whatever the model currently holds. If you need a different shape, add a method — see
> [Presenting and serializing](../06-reading/07-presenting-and-serializing.md).

Subclassing is also the only supported way to *reach* the model. `Support\AuditPolicy`,
`PolicyRegistry`, `SnapshotBuilder`, the `Capture\` classes and `SentinelServiceProvider` are
`@internal` and outside the 1.0 contract. See [Swapping components](../11-extending/06-swapping-components.md).

---

## A landlord/tenant database layout

Because `Config` re-reads the repository on every call, a tenancy package that swaps the connection
per tenant works without any hook of Sentinel's own. The two usual shapes:

**One shared audit database, tenants separated by stream.** `integrity.stream` ships as `tenant`, so
each tenant's entries form their own chain named `tenant:<id>` in one table. This is the default and
the layout most installations want; see [Multi-tenancy](../04-context/04-multi-tenancy.md) and
[Streams](../07-integrity/02-streams.md).

**One audit database per tenant.** Point `sentinel.database.connection` at the same connection name
your tenancy package rewrites, and let it swap the credentials:

```php
// config/sentinel.php
'database' => ['connection' => 'tenant'],
```

Four things change, and all four are consequences of code above rather than options:

1. **Migrations run per tenant.** Each of the eight package migrations resolves `getConnection()` at
   run time, so `php artisan migrate` under your tenancy package's per-tenant context creates the
   seven tables in that tenant's database.
2. **Commands run per tenant.** No Sentinel command takes a `--connection` or `--database` option that
   points at the trail — not `sentinel:verify`, not `sentinel:prune`, not `sentinel:checkpoint`, not
   `sentinel:partitions`, and `sentinel:import --connection=` names the *source* it reads from. They
   read `sentinel.database.connection` like everything else, so each has to be invoked inside the
   tenant context, once per tenant.
3. **Keep `integrity.stream` at `tenant`.** `Integrity\Stream` maps a null `tenant_id` to the literal
   stream `'global'`. If your tenant resolver does not fill `tenant_id`, every tenant database ends up
   with a `global` stream numbered from 1 — see the hazard below.
4. **Verification is per database.** `Sentinel::verifyEverything()` enumerates the streams of the
   ledger it is bound to, which is the streams of the current connection. There is no cross-database
   report.

> ⚠️ **Warning — the consolidation hazard.** Two tenant databases whose chains share a stream name
> cannot later be merged into one table: `unique(stream, sequence)` collides on every row, and
> renumbering is impossible because `sequence` is inside the canonical hashed payload — changing it
> changes the hash and breaks the link to `previous_hash` for every entry after it. If a merge is
> conceivable, make sure the stream name is unique per tenant from the first entry: keep
> `integrity.stream = 'tenant'` **and** make sure a tenant is resolved.

---

## Backing up an append-only table

An audit database is not a normal one to restore. Two properties make it different: the chain, and
the fact that the keys that make some of its content readable are not in it.

### What a usable backup contains

| Piece | Where it lives | Why the backup is useless without it |
|---|---|---|
| The seven tables, as one consistent snapshot | The audit connection | A trail restored without `sentinel_checkpoints` loses the only evidence that can account for what was pruned; without `sentinel_archives`, cold batches can never be brought back |
| `security.encryption.keys` | `config/sentinel.php` / env | Encrypted field values are unreadable. The entries still verify — the hash covers the ciphertext — but the values are gone |
| `security.hashing.salt` (or `APP_KEY`, which derives it) | `config/sentinel.php` / env | Salted digests stop being comparable with anything written under the old salt |
| `integrity.signature.keys` | `config/sentinel.php` / env | Signatures stop verifying, and an unsigned chain is one anyone with write access can reissue |

> 🔒 **Security.** Keeping the keyring in the same backup as the ciphertext gives an attacker who
> steals the backup both halves. Back them up, but not together — see
> [Encryption and the keyring](../05-pipeline-and-security/03-encryption-and-the-keyring.md).

### What a restore can and cannot be caught doing

`Integrity\Verifier` walks a stream by ascending `sequence`. A missing entry **in the middle** raises
`IntegrityBreak::SequenceGap` — unless a `sentinel_archives` row and the anchors' reach both account
for exactly that range, which is how a legitimate prune is told apart from a loss.

A truncation **at the end** is a different matter. Nothing in the chain says how long a stream should
be, so a stream restored one hour short verifies perfectly and reports itself intact. The only thing
that gives a range a declared length is an anchor: `Integrity\Checkpoints::refold()` can only
recompute a root when it finds every hash of the range, and returns `null` when it cannot. Under
`sentinel:verify --depth=roots`, a `null` root is excused only by a `sentinel_archives` row covering
exactly that range; otherwise the range is walked entry by entry and the report names the entry, not
just the range.

The corollary is worth stating plainly: **entries written after the last anchor are the ones a restore
can lose without anyone finding out.** Turning `integrity.checkpoints.enabled` on and lowering
`integrity.checkpoints.every` shortens that window — see
[Checkpoints and anchors](../07-integrity/05-checkpoints-and-anchors.md).

```bash
php artisan sentinel:verify --depth=entries   # reads and rehashes every entry — the deep walk
php artisan sentinel:verify --depth=roots     # refolds every anchor root from stored hashes
php artisan sentinel:verify --projections     # also checks the relation index against the entries
```

> 🧪 **Verify it.** Run `php artisan sentinel:verify --depth=entries` immediately after any restore,
> before letting the application write again. It is the only walk that proves what an entry says.

### Restoring an older snapshot, and why it must be the last resort

`Ledger\StreamGate::tail()` reads `max(sequence)` for the stream and hands the next writer
`sequence + 1` and that row's `hash` as `previous_hash`. Restore a snapshot that is missing the last N
entries and the next write continues from the restored tail — minting sequence numbers that other
entries already held. If the lost entries ever turn up (a later backup, a replica, a rehydrated
batch), they cannot be put back: `unique(stream, sequence)` rejects them, and renumbering them is not
an option, because `sequence`, `previous_hash` and `payload_version` are all inside the canonical
payload that `hash` is taken over. Change any of them and the entry no longer reproduces its own hash,
and every entry after it fails its link check.

If you must restore an older audit snapshot, treat the lost tail as lost, record what happened in the
application's own change log, and run `php artisan sentinel:checkpoint` before resuming writes so the
chain from the restored tail onward has a declared length again.

> ⚠️ **Warning.** Never restore `sentinel_audits` on its own. A per-table restore that leaves
> `sentinel_audit_tags` and `sentinel_audit_relations` at a different point in time makes
> `sentinel:verify --projections` report a `ProjectionMismatch` that has nothing to do with tampering,
> and buries a real one in noise.

### Logical export

`sentinel:export --format=ndjson` writes entries out in a form that round-trips (`csv` is lossy and
for people). It is an export, not a backup tool: there is no `sentinel:import` for it —
`sentinel:import` reads other packages' tables — and the only put-back path in the package is
`Archive\Rehydrator::restore()`, which works from `sentinel_archives` manifest rows written by
`sentinel:prune --action=archive`. Use the engine's own dump for backups and NDJSON for handing
evidence to something else.

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| "Database connection [audits] not configured" from `sentinel:install`, exit 2 | `sentinel.database.connection` names a connection absent from `config/database.php` | Add the connection, or set the key back to `null` |
| `migrate:fresh` then "table sentinel_audits already exists" | `migrate:fresh` wipes one connection; the audit tables survived while the `migrations` table did not | Wipe both connections, or stop using `migrate:fresh` in that environment |
| `whereHas('audits', …)` reports an unknown table | The correlated subquery is issued on the parent's connection | Query the trail first with `Sentinel::audits()`, then hydrate subjects by key |
| Entries exist for a business change that was rolled back | Dedicated connection with `transactions.after_commit` set to `false` | Set it back to `true`; the deferral is the only thing tying the two databases together |
| A `Sentinel::event()` inside a transaction survives its rollback | The event has no subject, so the deferral hangs off the *default* connection, not the one the transaction is open on | Give the event a `->subject()`, or open the transaction on the default connection |
| `sentinel_transactions` rows whose entries do not exist | The header is saved directly, not deferred; on a dedicated connection a business rollback does not remove it | Expected. The header is not evidence; join from the entries to the header, never the other way |
| "Sentinel configuration key [sentinel.tables.checkpoints] is not set." months after an upgrade | A published `tables` block is missing a key; the config merge is one level deep | Restore the full eight-key `tables` block |
| A renamed table works in reads but partition maintenance targets the old name | A `prefix` was set on the connection instead of `sentinel.tables.prefix`; raw DDL does not apply it | Move the prefix into `sentinel.tables.prefix` |
| `protected $table` on an Audit subclass is ignored | `getTable()` is a method override reading `Support\Config`; it never consults the property | Set `tables.audits` in config |
| `App\Models\Audit::factory()` builds the package's `Audit` | `AuditFactory::modelName()` reads `models.audit`, not the class the call was made on | Set `sentinel.models.audit` to your subclass |
| An entry can suddenly be updated or deleted through the model | A subclass overrode `booted()` without calling `parent::booted()` | Call `parent::booted()` first |
| Two tenant databases cannot be merged; every row collides | Both chains are named `global` because `tenant_id` was never resolved | Resolve a tenant so streams are named `tenant:<id>`; a merge after the fact is not possible |

---

## ✅ Best practices

✅ **Do** — leave `transactions.after_commit` at `true` whenever audits are on their own connection.
It is the only mechanism that ties the entry to the business fact once the two databases are separate.

```php
// config/sentinel.php
'transactions' => ['after_commit' => true],
```

❌ **Don't** — turn it off "because the ledger is elsewhere now". On a shared connection that was
harmless; here it produces entries that survive rollbacks and assert facts the application never kept.

```php
'transactions' => ['after_commit' => false],   // dedicated connection: the trail can now lie
```

---

✅ **Do** — publish the complete `tables` block and keep all eight keys on every upgrade. The merge is
one level deep, so a key you drop is a runtime exception, not a fallback.

```php
'tables' => [
    'prefix' => 'sentinel_', 'audits' => 'audits', 'audit_tags' => 'audit_tags',
    'audit_relations' => 'audit_relations', 'transactions' => 'transactions',
    'checkpoints' => 'checkpoints', 'archives' => 'archives', 'access_log' => 'access_log',
],
```

❌ **Don't** — publish a trimmed block with only the names you changed. The missing keys throw the
first time something touches that table, which may be a prune at 3 a.m.

```php
'tables' => ['prefix' => 'audit_', 'audits' => 'entries'],   // six keys now missing
```

---

✅ **Do** — rename tables with `sentinel.tables.prefix`, and leave the audit connection's own `prefix`
empty. Eloquent applies a connection prefix; `Partitions\Grammar` and the published index stubs do not.

```php
// config/database.php — the audit connection
'audits' => ['driver' => 'pgsql', /* … */ 'prefix' => ''],
```

❌ **Don't** — set a `prefix` on the connection. Reads find one name and partition maintenance builds
another, and the mismatch only surfaces when `sentinel:partitions` runs.

```php
'audits' => ['driver' => 'pgsql', /* … */ 'prefix' => 'audit_'],
```

---

✅ **Do** — extend `Models\Audit` for scopes and accessors, and name the subclass in `models.audit` so
the container, the trait's relation and the factory all agree.

```php
use ElPandaPe\Sentinel\Models\Audit as SentinelAudit;

final class Audit extends SentinelAudit
{
    public function headline(): string
    {
        return "{$this->audit_type}.{$this->event}";
    }
}
```

❌ **Don't** — override `getTable()`, `getConnectionName()`, `getGuarded()`, `booted()` or `toArray()`.
Each one disables a mechanism the rest of the package relies on; `booted()` in particular removes the
immutability guard and makes entries editable.

```php
final class Audit extends SentinelAudit
{
    protected static function booted(): void {}   // ImmutableAuditException never fires again
}
```

---

✅ **Do** — restore all seven tables as one consistent snapshot, and verify before writing again.

```bash
php artisan sentinel:verify --depth=entries --projections
```

❌ **Don't** — restore `sentinel_audits` alone. The relation projection and the label table drift out
of step, `--projections` reports a mismatch that is not tampering, and a real one is now invisible.

```bash
pg_restore --table=sentinel_audits …   # leaves labels, relation lines, anchors and archives behind
```

---

✅ **Do** — back up the keyring and the salt separately from the database, and keep old encryption
key ids on the ring for as long as the entries written under them matter.

```php
// config/sentinel.php — security.encryption
'key_id' => 'v2',
'keys'   => [
    'v1' => env('SENTINEL_ENCRYPTION_KEY_V1'),   // still on the ring: older entries recorded it
    'v2' => env('SENTINEL_ENCRYPTION_KEY_V2'),
],
```

❌ **Don't** — assume a database dump is a complete backup. `security.encryption.keys`,
`security.hashing.salt` and `integrity.signature.keys` live in configuration, not in any of the seven
tables. Restore without them and every row still verifies — the hash covers the ciphertext — while the
protected values stay unreadable and the digests stop being comparable.

---

✅ **Do** — run each Sentinel command once per tenant in a database-per-tenant layout, from inside the
context that swaps the connection.

```php
use Illuminate\Support\Facades\Artisan;

foreach (['tenant_acme', 'tenant_globex'] as $connection) {
    config()->set('sentinel.database.connection', $connection);

    Artisan::call('sentinel:prune', ['--action' => 'archive']);
}
```

❌ **Don't** — look for a `--connection` or `--database` flag. No Sentinel command has one; they all
read `sentinel.database.connection` at the moment they run.

```bash
php artisan sentinel:prune --database=tenant_42   # no such option
```

---

**See also:** [Choosing an engine](01-choosing-an-engine.md) · [Partitioning](06-partitioning.md) ·
[Indexes and JSON](05-indexes-and-json.md) · [Scaling playbook](08-scaling-playbook.md) ·
[Installation](../02-getting-started/01-installation.md) ·
[Business transactions](../03-capture/06-business-transactions.md) ·
[Multi-tenancy](../04-context/04-multi-tenancy.md) · [Streams](../07-integrity/02-streams.md) ·
[The hash chain](../07-integrity/01-the-hash-chain.md) ·
[Checkpoints and anchors](../07-integrity/05-checkpoints-and-anchors.md) ·
[Verification](../07-integrity/06-verification.md) ·
[Retention and pruning](../08-lifecycle/01-retention-and-pruning.md) ·
[Cold archiving](../08-lifecycle/02-cold-archiving.md) ·
[Swapping components](../11-extending/06-swapping-components.md) ·
[Configuration](../99-reference/02-configuration.md) · [Schema](../99-reference/03-schema.md)
