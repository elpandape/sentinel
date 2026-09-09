<p align="center">
  <img src="https://repository-images.githubusercontent.com/1347516317/18a04ed7-87ef-40ec-bb74-ae11ea32c57d" alt="Sentinel" width="800">
</p>

<h1 align="center">Sentinel</h1>

<p align="center">
  <strong>Ledger-first audit &amp; integrity engine for Laravel</strong><br>
  Full snapshots, structured diffs, relationship auditing, execution context and a tamper-evident hash chain.<br>
  <em>Know what happened. Know who did it. Prove the record.</em>
</p>

<p align="center">
  <a href="https://packagist.org/packages/elpandape/sentinel"><img src="https://img.shields.io/packagist/v/elpandape/sentinel?include_prereleases&style=flat-square&color=blue" alt="Packagist Version"></a>
  <a href="https://packagist.org/packages/elpandape/sentinel"><img src="https://img.shields.io/packagist/dt/elpandape/sentinel?style=flat-square&color=green" alt="Total Downloads"></a>
  <a href="LICENSE.md"><img src="https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square" alt="License"></a>
  <a href="https://php.net"><img src="https://img.shields.io/badge/PHP-8.4+-777BB4?style=flat-square&logo=php" alt="PHP 8.4+"></a>
  <a href="https://laravel.com"><img src="https://img.shields.io/badge/Laravel-13-FF2D20?style=flat-square&logo=laravel" alt="Laravel 13"></a>
</p>

---

## Table of Contents

- [Features](#features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Documentation](#documentation)
- [What Gets Audited](#what-gets-audited)
- [Snapshots & Diffs](#snapshots--diffs)
- [Context & Actors](#context--actors)
- [Protecting Sensitive Data](#protecting-sensitive-data)
- [Querying the Trail](#querying-the-trail)
- [Relationships](#relationships)
- [Mass Operations](#mass-operations)
- [Transactions, Events & Transitions](#transactions-events--transitions)
- [Restoring State](#restoring-state)
- [Integrity, Signing & Anchors](#integrity-signing--anchors)
- [Retention, Archiving & Redaction](#retention-archiving--redaction)
- [Compliance Mode](#compliance-mode)
- [Performance Modes](#performance-modes)
- [Engines & Scale](#engines--scale)
- [Artisan Commands](#artisan-commands)
- [Ledger Drivers](#ledger-drivers)
- [Configuration](#configuration)
- [Migrating from Another Package](#migrating-from-another-package)
- [Stability](#stability)
- [Development](#development)
- [Credits & License](#credits--license)

---

Sentinel is not an activity log. Its unit is the **audit record**: an append-only entry that answers
what changed, who changed it, on whose behalf, from where, inside which business transaction, what
the state was before, what it is now — and whether the record itself can be proven untampered.

> **Status: release candidate.** The public API is **frozen** at this tag: between here and
> `v1.0.0` only bugfixes and documentation land. See [Stability](#stability).

---

## Features

| Feature | Description |
|---|---|
| **Full snapshots** | Every entry carries the complete state before and after — not just the delta. |
| **Structured diffs** | `$audit->diff()` answers *what changed*, with RFC 6902 JSON Patch import and export. |
| **Relationship auditing** | The six pivot operations recorded with zero changes to your code. No other package in this space covers it. |
| **Mass operations** | `Model::where(...)->auditing()->update(...)` audits the statement Eloquent fires no event for. Opt-in per query, free when unused. |
| **Execution context** | Actor, impersonator, tenant, request, trace, session, source, host, job and command — ten resolvers, all replaceable. |
| **Tamper-evident chain** | Every entry links to the one before it. Unconditional, no configuration, verifiable by someone holding none of your keys. |
| **Signatures & anchors** | HMAC or OpenSSL over the hash, plus signed roots that keep verification cheap and survive pruning. |
| **Field-level protection** | Exclude, mask, encrypt or hash a field — four mechanisms with four different promises, all documented. |
| **Query API** | Twenty-one filters over the ledger contract, so a driver over something that is not a table answers the same question. |
| **Restore engine** | `$audit->restore()` puts the record back — and appends an entry saying it did. |
| **Retention & cold archiving** | Prune by policy, archive to NDJSON on any disk, rehydrate a batch exactly as it left. |
| **Redaction** | Destroy the contents of an entry while its position, its hash and its link stay intact. |
| **Compliance mode** | Refuses to boot without signatures and anchors, logs every read, and names its own blind spots. |
| **Performance modes** | `sync`, `queue` or `buffered` — with the loss window of each written down rather than implied. |
| **Three engines** | PostgreSQL 16, MySQL 9 and SQLite, run on every push. Partitioned migrations shipped for two of them. |
| **Ledger drivers** | A published contract with a runnable conformance suite, shipped as production code. |
| **W3C Trace Context** | Read, propagated across the queue, and handed to an OpenTelemetry SDK when one is registered. |
| **Migration path** | `sentinel:import` from `owen-it/laravel-auditing` or `altek/accountant`, resumable and idempotent. |

---

## Requirements

| Requirement | Version |
|---|---|
| PHP | `^8.4` |
| Laravel | `^13.0` |
| Extensions | `ext-mbstring`, `ext-openssl` (required) · `ext-zlib` (gzip archive codec) |
| Engines | PostgreSQL 16 · MySQL 9 · SQLite 3.45+ — run on every push |

> **MariaDB is refused by name, not guessed at.** `whereFieldChanged()` has no dialect for it, so
> the package declines instead of answering with something that might not mean the same thing.

---

## Installation

```bash
composer require elpandape/sentinel:^1.0@RC
php artisan sentinel:install
php artisan migrate
```

A release candidate is not a stable release, so Composer will not install it unless you say so. The
`@RC` above is the narrow way to say it — it applies to this package and to nothing else in your
project. `"minimum-stability": "RC"` relaxes it for **every** package you require, and is the wrong
tool unless that is what you meant.

`sentinel:install` publishes the configuration if it is not already there, leaves it untouched if it
is, and tells you which tables are still missing. Run it again whenever you want to know where an
installation stands — that is what it is for.

Then add the trait to the models you want audited:

```php
use ElPandaPe\Sentinel\Concerns\Auditable;

class Invoice extends Model
{
    use Auditable;
}
```

→ [Installation guide](docs/02-getting-started/01-installation.md) ·
[Choosing your setup](docs/02-getting-started/05-choosing-your-setup.md)

---

## Quick Start

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

// Every created / updated / deleted / restored / forceDeleted writes a chained entry
$invoice->update(['status' => 'paid']);

$invoice->latestAudit()->before['status'];   // 'pending'
$invoice->latestAudit()->after['status'];    // 'paid'
$invoice->latestAudit()->diff();             // what changed, structured
$invoice->audits();                          // the whole trail

// Read across the trail
Sentinel::audits()->for($invoice)->byActor($user)->get();
Sentinel::timeline()->between($from, $to)->take(50)->get();

// State the facts no model change describes
Sentinel::event('invoice.approved')->subject($invoice)->record();
Sentinel::transition($invoice, 'pending', 'paid')->reason('Wire cleared')->record();

// Correlate an operation
Sentinel::transaction('month-end-close', fn () => $closer->run());

// Prove it
Sentinel::verifyIntegrity('global')->isIntact();
```

That is the whole setup — no interface to implement, no observer to register.

Auditing pauses on demand, and what happens while it is paused leaves no entry:

```php
Sentinel::withoutAuditing(fn () => $importer->run());

Sentinel::withContext(['reason' => 'Approved by finance'], function () {
    $invoice->approve();
});
```

> **`withoutAuditing()` is the one that survives an exception.** It restores the previous state in
> a `finally`, so it nests and cannot leak. `pause()` sets a flag and nothing takes it back down:
> throw between a `pause()` and its `resume()` and auditing stays off for the rest of the request,
> silently, with the entries that were supposed to be written simply absent.

→ [Your first audit](docs/02-getting-started/02-your-first-audit.md) ·
[Turning auditing off](docs/02-getting-started/04-turning-auditing-off.md)

---

## Documentation

The full documentation lives in [`docs/`](docs/). This README is the overview;
that tree is the manual.

| Section | What it covers |
|---|---|
| [Concepts](docs/01-concepts/) | What Sentinel is and is not · the audit record · the write path · the integrity model · architecture · glossary |
| [Getting started](docs/02-getting-started/) | Installation · your first audit · what a model declares · turning auditing off · choosing your setup |
| [Capture](docs/03-capture/) | What gets audited · snapshots · diffs · relationships · mass operations · transactions · custom and auth events · state transitions |
| [Context](docs/04-context/) | Execution context · the ten resolvers · actor and impersonation · multi-tenancy · queues and commands · distributed tracing · writing a resolver |
| [Pipeline & security](docs/05-pipeline-and-security/) | The write pipeline · protecting sensitive data · encryption and the keyring · hashing · writing a masker · discarding entries |
| [Reading the trail](docs/06-reading/) | The Query API · filters · order and paging · field history · the timeline · labels · serialization · restoring state |
| [Integrity](docs/07-integrity/) | The hash chain · streams · canonicalization · signing · checkpoints and anchors · verification · the verification playbook |
| [Lifecycle](docs/08-lifecycle/) | Retention and pruning · cold archiving · rehydration · redaction and tombstones · compliance mode · export and rekey |
| [Operations](docs/09-operations/) | Performance modes · the buffered mode · queues · events · failure policy · commands · scheduling · monitoring |
| [Database engines](docs/10-database-engines/) | Choosing an engine · PostgreSQL · MySQL · SQLite · indexes and JSON · partitioning · a database of its own · scaling |
| [Extending](docs/11-extending/) | The Ledger contract · shipped drivers · writing a driver · the contract test suite · fanout · swapping components · testing your integration |
| [Migrating in](docs/12-migrating/) | From owen-it/laravel-auditing · from altek/accountant · the import runbook |
| [Best practices](docs/13-best-practices/) | Do and don't · anti-patterns · security checklist · production readiness |
| [Reference](docs/99-reference/) | The facade · configuration · schema · enums · events · exceptions · exit codes · serialization · API stability |

> **New here?** Read [What Sentinel is (and is not)](docs/01-concepts/01-what-sentinel-is.md)
> first. It includes an honest section on when *not* to adopt this package.

---

## What Gets Audited

Adding the trait covers the five Eloquent events: `created`, `updated`, `deleted`, `restored` and
`forceDeleted`. A soft delete, a real delete and a restore are told apart, and each gets its own
event value.

```php
class Invoice extends Model
{
    use Auditable;

    protected array $auditExclude = ['updated_at', 'search_vector'];
}
```

> **Eloquent fires no model event for `Builder::update()` or `Builder::delete()`.** That is a
> limitation of the framework, not of this package, and every auditing package in this ecosystem
> documents it. Sentinel's answer is [mass operations](#mass-operations) — opt-in per query.

An update that changed nothing writes nothing: the `FilterUnchanged` stage discards it before the
ledger assigns a sequence, so the chain gets no gap.

→ [What gets audited](docs/03-capture/01-what-gets-audited.md) ·
[What a model declares](docs/02-getting-started/03-what-a-model-declares.md)

---

## Snapshots & Diffs

Every entry carries the **complete state**, not just the delta. The diff is derived, so you get both
without storing both twice.

```php
$audit = $invoice->latestAudit();

$audit->before;             // the whole record as it was
$audit->after;              // the whole record as it is
$audit->diff();             // the structured change
$audit->diffFor('total');   // one field's change
$audit->diff()->toJsonPatch();   // RFC 6902
```

Hidden attributes are audited by default — auditing is what the package is for. Turn that off with
`snapshots.include_hidden` when a hidden attribute is something you deliberately never want on
record.

> **Two audits, one comparison.** `$audit->comparedTo($other)` and
> `Sentinel::audits()->for($invoice)->compare(3, 7)` both answer *what is different between these
> two versions*, without replaying everything in between.

→ [Snapshots](docs/03-capture/02-snapshots.md) · [Diffs](docs/03-capture/03-diffs.md)

---

## Context & Actors

Ten resolvers fill in the circumstances an entry was written under. Every one of them is replaceable
by a class of yours, in config, with no subclassing.

| Resolver | Answers |
|---|---|
| `actor` | Who did it |
| `impersonator` | On whose behalf |
| `tenant` | For which tenant — and, by default, **which chain the entry belongs to** |
| `request` | Which request, via the `X-Request-Id` header or a generated id |
| `session` | Which session |
| `trace` | Which distributed trace and span |
| `source` | Which runtime: web, api, console, queue, scheduler |
| `host` | Which machine and IP |
| `job` | Which queued job |
| `command` | Which console command, with its arguments redacted |

```php
// config/sentinel.php
'resolvers' => [
    'tenant' => ['class' => App\Audit\TenantResolver::class],
    'actor'  => ['class' => null, 'guard' => 'admin'],
],
```

> **Wire the tenant resolver before the first entry is written.** The tenant is the default stream
> scope, so turning one on partitions the chain — a decision that does not apply retroactively to a
> trail that already exists.

→ [Execution context](docs/04-context/01-execution-context.md) ·
[The ten resolvers](docs/04-context/02-resolvers-reference.md) ·
[Multi-tenancy](docs/04-context/04-multi-tenancy.md)

---

## Protecting Sensitive Data

Four mechanisms, four different promises. Picking the wrong one is the most common security mistake
made with an audit trail.

| Mechanism | Recoverable | Comparable | What it is for |
|---|---|---|---|
| `$auditExclude` | No — never captured | No | The value must not exist in the trail at all |
| `$auditRedact` | No | No | The value is masked as it is written |
| `$auditEncrypt` | Yes, with the key | No | You will need to read it back |
| `$auditHash` | No | Yes | You only ever ask *is this the same value* |

```php
class Patient extends Model
{
    use Auditable;

    protected array $auditExclude = ['raw_import_payload'];
    protected array $auditRedact  = ['phone'];
    protected array $auditEncrypt = ['diagnosis'];
    protected array $auditHash    = ['national_id'];
}
```

Model declarations and the config-level lists in `security.*` **add up** — they never override. The
config list is the only way to name a key no model owns: an address, a console argument, a context
field.

> **The chain hashes the ciphertext, not the plaintext.** That is what lets an external auditor
> verify the whole trail while holding none of your encryption keys. It also means an encrypted field
> is opaque to verification: the chain proves the row was not altered, not that the plaintext is what
> you think it is.

→ [Protecting sensitive data](docs/05-pipeline-and-security/02-protecting-sensitive-data.md) ·
[Encryption and the keyring](docs/05-pipeline-and-security/03-encryption-and-the-keyring.md) ·
[Security checklist](docs/13-best-practices/03-security-checklist.md)

---

## Querying the Trail

`Sentinel::audits()` is the way in. It reads through the ledger contract, so a driver over something
that is not a table answers the same query.

```php
use ElPandaPe\Sentinel\Enums\Severity;

Sentinel::audits()
    ->for($invoice)
    ->by($user)
    ->whereEvent('updated')
    ->whereSeverity(Severity::Warning)
    ->whereTag('finance')
    ->whereFieldChanged('total')
    ->between($from, $to)
    ->latest()
    ->take(50)
    ->get();
```

Twenty-one filters ship, including `whereIp()` and `whereRoute()` — the two that live inside the
context JSON — plus the three relation filters and `whereVersion()`.

> **`get()` refuses above 500 rather than truncating.** A prefix shaped exactly like a complete
> answer is the one mistake a trail cannot afford, so the read throws instead. Use `take()`,
> `paginate()` or `after()` to walk it.

> **There is deliberately no `total()`.** Counting an append-only table of ten million rows is a
> table scan every time it is asked, and nobody needs the number badly enough to pay for it on every
> page render.

→ [The Query API](docs/06-reading/01-the-query-api.md) ·
[Filters reference](docs/06-reading/02-filters-reference.md) ·
[Order, paging and walking](docs/06-reading/03-order-paging-and-walking.md)

---

## Relationships

A pivot table changing under you is something Eloquent barely announces and no other package in this
ecosystem records. Sentinel covers the six pivot operations with no changes to your code.

```php
use ElPandaPe\Sentinel\Enums\RelationOperation;

$post->tags()->attach($tagId);
$post->tags()->sync([1, 2, 3]);
$post->tags()->detach($tagId);

$post->relationHistory('tags')->get();

Sentinel::audits()
    ->whereRelation('tags')
    ->whereOperation(RelationOperation::Attach)
    ->whereRelated($tag)
    ->get();
```

A child that changes hands leaves an entry on the parent it left **and** the parent it joined:

```php
class Invoice extends Model
{
    use Auditable;

    protected array $auditParents = ['customer'];
}
```

> **The relation projection is an index, not the evidence.** `sentinel_audit_relations` exists so
> you can ask questions quickly; the entry in `sentinel_audits` is what is hashed and what an auditor
> reads.

→ [Relationship auditing](docs/03-capture/04-relationships.md)

---

## Mass Operations

The blind spot every auditing package in this ecosystem documents as a limitation — closed, and
costing nothing until a query asks for it.

```php
Invoice::where('status', 'pending')
    ->auditing()                      // opt in, per query
    ->update(['status' => 'void']);

Invoice::where('created_at', '<', $cutoff)->auditing('individual')->delete();
```

| Mode | What it writes | Cost |
|---|---|---|
| `summary` | One entry for the whole operation | Constant — the default |
| `individual` | One entry per row, with its real `before` | Grows with the set |
| `hybrid` | Summary plus individuals while under `threshold` | Bounded |

The criteria are recorded **without their values** past a sample bound: a `whereIn` over five
thousand identifiers records the count and a sample, never the list.

> **`affected_rows` means what your engine says it means.** MySQL counts rows *changed*, not rows
> *matched*, unless configured otherwise. It is stored unnormalised on purpose — normalising it would
> be inventing a number no engine reported.

→ [Mass operations](docs/03-capture/05-mass-operations.md)

---

## Transactions, Events & Transitions

Three ways to record a fact that no single model change describes.

```php
// Correlate an operation — this does NOT open a database transaction
Sentinel::transaction('month-end-close', function () use ($closer) {
    $closer->run();
});

// State a fact nothing changed
Sentinel::event('invoice.approved')
    ->subject($invoice)
    ->actor($approver)
    ->metadata(['amount' => $invoice->total])
    ->record();

// Record a move between states — Sentinel says it moved, it never performs the move
Sentinel::transition($invoice, from: 'pending', to: 'paid')
    ->reason('Wire cleared')
    ->record();

Sentinel::transitions()->for($invoice)->get();   // the lifeline, with time spent in each state
```

Set `transactions.after_commit` and entries wait for the database commit, so a rollback leaves no
record of what never happened.

> **A transaction correlates; it does not atomise.** `Sentinel::transaction()` opens no database
> transaction. Wrap it in `DB::transaction()` yourself when you want both — keeping the two decisions
> separate is deliberate.

→ [Business transactions](docs/03-capture/06-business-transactions.md) ·
[Custom and authentication events](docs/03-capture/07-custom-and-authentication-events.md) ·
[State transitions](docs/03-capture/08-state-transitions.md)

---

## Restoring State

```php
$result = $audit->restore();                    // the whole record
$result = $audit->restore(['total', 'status']); // named fields only
$result = $audit->restoreRelationship('tags');  // a relation

$result->applied;             // the keys that came back
$result->skipped;             // key => Omission, for the ones that did not
$result->refused;             // set when nothing was even attempted
$result->reason('total');     // why this key is not in applied
$result->entry;               // the entry the restoration itself wrote
```

> **A restore is a write, and it is audited like one.** It appends an entry describing the
> restoration. It never deletes, rewrites or reorders what came before — that is the append-only
> invariant, and it has no override.

A tampered entry restores nothing. An entry imported from `altek/accountant` cannot be
whole-record restored, because altek never wrote a `before` to restore from.

→ [Restoring state](docs/06-reading/08-restoring-state.md)

---

## Integrity, Signing & Anchors

**Chaining is unconditional.** Every entry links to the one before it in its stream, with no switch
and no configuration. What is optional is signing it and anchoring it.

```php
Sentinel::verifyIntegrity('global');   // rehash every entry — proves what each one says
Sentinel::verifyAnchors('global');     // walk the checkpoints — cheap, proves the anchors hold
Sentinel::verifyRoots('global');       // refold every root — names a rewritten or reordered entry
Sentinel::verifyEverything();          // every chain the ledger holds
```

```bash
php artisan sentinel:verify --stream=global
```

```php
// config/sentinel.php
'integrity' => [
    'stream' => 'tenant',
    'checkpoints' => ['enabled' => true, 'every' => 1000],
    'signature'   => ['enabled' => true, 'signer' => 'openssl'],
],
```

> **What it proves, stated plainly.** The chain detects an edit, a reorder or a removal after the
> fact. A signature binds the chain to whoever holds the key. An anchor makes verification cheap and
> keeps the evidence after the entries are pruned. **None of it defends against a compromised
> application at capture time** — someone with application access produces a perfectly intact,
> perfectly signed chain of false statements. That tier is out of scope and is not claimed anywhere.

> **`keys` verifies, `private_key` signs.** Under `openssl` the verifying half is what an external
> auditor is given, and holding it is not enough to sign anything.

→ [The hash chain](docs/07-integrity/01-the-hash-chain.md) ·
[Signing](docs/07-integrity/04-signing.md) ·
[Checkpoints and anchors](docs/07-integrity/05-checkpoints-and-anchors.md) ·
[The verification playbook](docs/07-integrity/07-the-verification-playbook.md)

---

## Retention, Archiving & Redaction

```php
// config/sentinel.php
'retention' => [
    'model:App\Models\Invoice' => '7 years',
    'auth'                     => '90 days',
],
```

```bash
php artisan sentinel:prune --dry-run
php artisan sentinel:prune --action=archive
php artisan sentinel:redact 01JB7Q... --reason="Erasure request 4412" --actor="user:17"
```

Archiving writes NDJSON batches to any `Storage` disk, and **nothing is removed until the batch has
been read back and rehashed**. A batch goes back into the table exactly as it left it, headers
included.

Redaction destroys the contents of an entry while its position, its hash and its link stay — so the
chain still verifies around the hole, and the hole is visible.

> **Pruning does not reclaim disk space, and it says so.** Removing rows from an append-only table
> leaves the pages behind. Reclaiming them is an engine operation, documented per engine.

> **The unit of retention is the anchored window, not the entry.** The effective retention of a
> range is that of its longest-lived entry. Anything no policy names is kept forever.

→ [Retention and pruning](docs/08-lifecycle/01-retention-and-pruning.md) ·
[Cold archiving](docs/08-lifecycle/02-cold-archiving.md) ·
[Redaction and tombstones](docs/08-lifecycle/04-redaction-and-tombstones.md)

---

## Compliance Mode

```php
// config/sentinel.php
'compliance' => true,
```

It makes audits immutable, **refuses to boot without signatures and anchors**, forces archiving
before pruning, forces `throw` on a write failure, requires a redaction to name who ordered it, and
records every read through the Query API in `sentinel_access_log`.

```bash
php artisan sentinel:export --format=ndjson --tenant=acme --disk=s3 --path=audit/2026-q3
php artisan sentinel:rekey
```

> **Sentinel certifies nothing.** It ships technical primitives — a chain, signatures, anchors,
> tombstones, an access record. Whether a given regime is satisfied by them is a question for your
> auditor, and the documentation names the blind spots rather than hiding them.

→ [Compliance mode](docs/08-lifecycle/05-compliance-mode.md) ·
[Export and rekey](docs/08-lifecycle/06-export-and-rekey.md)

---

## Performance Modes

```php
'mode' => env('SENTINEL_MODE', 'sync'),   // sync | queue | buffered
```

| Mode | Where the entry settles | What it can lose | Reach for it when |
|---|---|---|---|
| `sync` | In the request | Nothing | **Always, until proven otherwise.** The default |
| `queue` | In a job | Nothing, if the queue is durable | Request latency is a measured problem *and* you cannot accept the buffered loss window |
| `buffered` | In Redis, flushed in batches | Everything a dying process was holding | Ingestion volume is the constraint and somebody owns the flush |

**Stay on `sync`.** It is the only mode in which the caller can still be told that the write did not
work — under the other two the request has returned before the ledger is touched. `queue` makes the
*request* about twice as fast and the *system* slightly slower, so it is rarely the answer to
"auditing is slow"; measure what is actually slow first.

> **Write down the buffered mode's loss window before you ship it.** It is bounded only by
> `buffer.size` and `buffer.flush_interval`, nothing in PHP watches the clock between requests, and
> **the chain cannot detect the loss** — a trail with a hole looks exactly like a trail of a system
> where nothing happened.

> **Under an asynchronous mode, `created_at` stops being the order things happened in.** Order
> comes from `(stream, sequence)`; `occurred_at` records when the fact happened. A retry is not a
> second entry: `capture_id` makes settlement idempotent.

→ [Performance modes](docs/09-operations/01-performance-modes.md) ·
[The buffered mode](docs/09-operations/02-the-buffered-mode.md)

---

## Engines & Scale

| Engine | Run on every push | What the emitted SQL needs |
|---|---|---|
| PostgreSQL | 16 | 9.4 — where `jsonb` and `jsonb_array_elements` arrive |
| MySQL | 9 | 8.0.4 — where `JSON_TABLE` arrives |
| SQLite | 3.45 | 3.38 — where JSON stops being a compile-time option |

Only the middle column is a support claim: this package does not declare compatibility it does not
run.

**Run a production trail on PostgreSQL 16.** It is the only engine where a partitioned table keeps
`unique (stream, sequence)`, the only one where the first write of a new stream is serialised on
purpose rather than incidentally, the one with the cheapest JSON path, and the one where retiring a
range is a catalogue operation instead of a batched delete. **MySQL 9 is a fully supported second
choice** — if your application already runs on it, there is usually no case for a second server.
**SQLite is for tests, single-process tools and trails that stay small.**

Three partitioned alternatives to the base migration ship as stubs — `mysql-range`, `pgsql-range`
and `pgsql-tenant` — along with a JSON-index stub and `sentinel:partitions` to keep partitions
supplied.

```bash
php artisan vendor:publish --tag=sentinel-migrations
php artisan sentinel:partitions --table=audits --ahead=3
```

> **SQLite has a ceiling, and it is not a version.** `SQLITE_MAX_VARIABLE_NUMBER` is a
> compile-time constant of `libsqlite3` and bounds how many placeholders one statement may carry. The
> package batches to fit the *narrowest* of the three engines rather than the widest.

→ [Choosing an engine](docs/10-database-engines/01-choosing-an-engine.md) ·
[Partitioning](docs/10-database-engines/06-partitioning.md) ·
[Scaling playbook](docs/10-database-engines/08-scaling-playbook.md)

---

## Artisan Commands

| Command | What it does |
|---|---|
| `sentinel:install` | Publishes config, reports which tables are missing |
| `sentinel:show` | Reads one entry, or a narrowed trail, from the terminal |
| `sentinel:verify` | Verifies a chain at one of three depths |
| `sentinel:checkpoint` | Emits anchors over the unanchored tail |
| `sentinel:prune` | Retires entries by policy — `--dry-run` first |
| `sentinel:flush` | Flushes the buffer |
| `sentinel:redact` | Destroys the contents of one entry, keeping its position |
| `sentinel:rekey` | Rotates an encryption key without rewriting what is hashed |
| `sentinel:export` | Hands a stream to someone else, resumable |
| `sentinel:import` | Imports history from another package |
| `sentinel:partitions` | Keeps partitions supplied |

All eleven share one exit-code vocabulary, so a cron can branch on the result rather than parse the
output. `php artisan about` carries a Sentinel section.

> **The package registers nothing on your scheduler.** Anchoring, pruning, flushing and partition
> maintenance are commands *your application* schedules. A team that assumes otherwise silently gets
> none of them.

→ [Artisan commands](docs/09-operations/06-artisan-commands.md) ·
[Scheduling](docs/09-operations/07-scheduling.md) ·
[Exit codes](docs/99-reference/07-exit-codes.md)

---

## Ledger Drivers

Five drivers ship: `database`, `memory`, `null`, `archive` and `fanout`. The contract is published,
and so is the conformance suite that proves an implementation of it — as production code, inside the
package, because a contract you cannot run against is a paragraph.

```php
'ledger' => [
    'default' => 'fanout',
    'ledgers' => [
        'fanout' => [
            'destinations' => ['database', 'archive'],
            'on_failure'   => 'strict',
        ],
    ],
],
```

```php
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Testing\LedgerContractTestCase;

final class ElasticLedgerTest extends LedgerContractTestCase
{
    protected function ledger(): Ledger
    {
        return new ElasticLedger(/* … */);
    }
}
```

> **`memory` is a reference implementation and a test double, never a store.** `archive` is a
> destination and must never be `ledger.default`.

→ [The Ledger contract](docs/11-extending/01-the-ledger-contract.md) ·
[Writing a ledger driver](docs/11-extending/03-writing-a-ledger-driver.md) ·
[The contract test suite](docs/11-extending/04-the-contract-test-suite.md)

---

## Configuration

`config/sentinel.php` ships every section the package uses, with the optional ones turned off. Read
it once and you know what is there.

**`tables` is why this README can write table names in prose and still be right about your
installation.** A prefix and one key per table; change either and every query, migration and command
follows, because nothing in the package writes a table name literally.

> **Every key also has a default in code.** Laravel merges a published config file one level deep,
> so an installation that published `sentinel.php` before a subtree existed would otherwise silently
> win over the package and end up with nothing configured at all.

> **A driver subtree that ships empty takes no options.** `database`, `memory` and `null` get an
> empty array each — the shape of a driver with nothing to configure, not an invitation. Anything put
> in one is ignored without a word.

→ [Configuration reference](docs/99-reference/02-configuration.md) ·
[Schema](docs/99-reference/03-schema.md)

---

## Migrating from Another Package

A history written by `owen-it/laravel-auditing` or `altek/accountant` has a way in. Start with the
dry run, which is the documented route and not a suggestion:

```bash
php artisan sentinel:import --from=owenit --dry-run
php artisan sentinel:import --from=owenit
```

> **The chain starts at the import.** What the other package recorded before it has no link,
> because nobody hashed those rows as they were written. Sentinel could fabricate one backwards and
> does not: that would be a proof nobody touched data this package never saw. Your trail is provable
> from the import forward, and honest about the part before it.

→ [From owen-it/laravel-auditing](docs/12-migrating/01-from-owen-it.md) ·
[From altek/accountant](docs/12-migrating/02-from-altek.md) ·
[The import runbook](docs/12-migrating/03-the-import-runbook.md)

---

## Stability

From `v1.0.0-rc.1` the public surface stops moving. Between this tag and `v1.0.0` only bugfixes and
documentation land; after `v1.0.0` the ordinary rules of semantic versioning apply.

**Frozen** — the `Sentinel` facade · the `Auditable` trait and every declaration a model makes with
it · `Contracts\` · `Data\AuditData` and `Models\Audit` including `toArray()` · the Query API ·
`RestoreResult`, `Tombstone` and the verification results · the eleven events · the eleven commands
and their exit codes · every key in `config/sentinel.php` · the serialised entry.

**Not frozen** — everything marked `@internal`. The rule is an invariant, not a count: every
declaration this package ships is either in the frozen list or carries the marker, and one that is
neither fails the build.

**What it takes to break it.** One question, asked before the change: does it correct something
incorrect, insecure or unverifiable, or only something uncomfortable? Correctness, security or
integrity breaks the freeze, and the release is renumbered `rc.N+1` with the feedback period starting
again from zero. Ergonomics waits for a `1.x` or a `2.0`.

> **Stricter than semver.** Anything touching `sequence`, `hash`, `previous_hash` or the canonical
> payload bumps `payload_version` and ships a backwards-compatibility test.

→ [API stability](docs/99-reference/09-api-stability.md)

---

## Development

No local PHP or Composer needed — everything runs through Docker:

```bash
make build      # build the dev image
make install    # composer install
make test       # run the suite
make coverage   # tests + 100% coverage gate
make types      # 100% type coverage gate
make stan       # PHPStan at level max
make lint       # Pint (check only)
make rector     # Rector (dry-run)
make ci         # everything CI runs
make test-dbs   # suite against MySQL 9 and PostgreSQL 16
make bench      # write-path baseline (a report, never a gate)
make shell      # a shell inside the container
```

See [CONTRIBUTING.md](CONTRIBUTING.md) before opening a pull request, and
[SECURITY.md](SECURITY.md) to report a vulnerability.

---

## Credits & License

Built by [Carlos Mayorga](https://carlosmayorga.me/).

The MIT License (MIT). See [LICENSE.md](LICENSE.md).

---

<p align="center">
  <sub>Know what happened. Know who did it. Prove the record.</sub>
</p>
