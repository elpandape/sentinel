# Sentinel

> Ledger-first audit & integrity engine for Laravel.
> **Know what happened. Know who did it. Prove the record.**

[![Version](https://img.shields.io/badge/version-v0.3.0-blue)](https://github.com/elpandape/sentinel/releases)
[![PHP](https://img.shields.io/badge/php-8.4%2B-777bb4)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/laravel-13-ff2d20)](https://laravel.com/)
[![Coverage](https://img.shields.io/badge/coverage-100%25-brightgreen)](#development)
[![PHPStan](https://img.shields.io/badge/phpstan-max-brightgreen)](#development)

Sentinel is not an activity log. Its unit is the **audit record**: an append-only entry that answers
what changed, who changed it, on whose behalf, from where, inside which business transaction, what
the state was before, what it is now — and whether the record itself can be proven untampered.

> **Status: alpha (0.x).** The public API may change between minor versions until `v1.0.0`.
> Not yet on Packagist — install from the repository while the 0.x cycle runs.

## Installation

```json
"repositories": [{ "type": "vcs", "url": "https://github.com/elpandape/sentinel" }],
"require": { "elpandape/sentinel": "v0.3.0" }
```

```bash
php artisan vendor:publish --tag=sentinel-config
```

## What's available

| Version | Feature |
|---|---|
| `v0.1.0` | Configuration, execution context, facade, enums, quality toolchain |
| `v0.2.0` | `sentinel_audits` schema, `Audit` model, `AuditData`, package contracts, factory |
| `v0.3.0` | `DatabaseLedger`, `NullLedger`, the hash chain, verification, immutability guards |

Everything else is on the roadmap: model auditing, snapshots and diffs, relationship auditing,
business transactions, custom events, state transitions, restore, advanced verification
(checkpoints and signatures), retention and compliance, performance modes and distributed tracing.

`v0.3.0` is the version that starts writing: every entry a `Ledger` records is chained as it lands.

## Quick start

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

Sentinel::withContext(['reason' => 'Approved by finance'], function () {
    $invoice->approve();
});

Sentinel::withoutAuditing(fn () => $importer->run());
```

## Configuration

`config/sentinel.php` ships every section the package will use through 1.0, with future features
turned off. Read it once and you know what is coming.

## Schema & models

`sentinel_audits` ships complete: forty columns and eleven indexes, created once and never altered
by a later minor. Most columns stay empty until the version that writes them lands — an empty column
in an empty table costs nothing, an `ALTER` on a table with ten million rows costs a maintenance
window.

| Group | Columns |
|---|---|
| Identity | `id`, `stream`, `sequence` |
| What happened | `audit_type`, `event`, `severity` |
| Who | `subject_type`/`subject_id`, `actor_type`/`actor_id`, `impersonator_type`/`impersonator_id`, `tenant_id` |
| Correlation | `transaction_id`, `request_id`, `trace_id`, `span_id`, `source`, `version` |
| Payload | `context`, `before`, `after`, `changes`, `metadata`, `criteria`, `affected_rows` |
| Integrity | `payload_version`, `encryption`, `algorithm`, `previous_hash`, `hash`, `signature`, `signature_key_id` |
| Lifecycle | `capture_id`, `source_audit_id`, `redacted_at`, `redaction_reason`, `redacted_hash`, `occurred_at`, `created_at` |

The JSON and date types come from the engine grammar: `jsonb` on PostgreSQL 16, `json` on MySQL 9,
text on SQLite; `datetime(6)` on MySQL and `timestamp(6)` on PostgreSQL.

`subject_id`, `actor_id` and `impersonator_id` are `string(64)`, so integer, UUID and ULID keys all
fit without a migration. There is no `updated_at`: the table is append-only.

Two things the schema does **not** promise. Order comes from `(stream, sequence)`, never from the
clock — `occurred_at` records when something happened, it does not sort the chain. And neither MySQL
`json` nor PostgreSQL `jsonb` preserves the key order you wrote; values round trip intact, order does
not. That is precisely why the integrity chain canonicalises before hashing.

```bash
php artisan vendor:publish --tag=sentinel-migrations
php artisan migrate
```

Publishing is optional: the package loads its own migration unless it finds a published copy, so the
migration never runs twice.

`--tag=sentinel-factories` publishes `AuditFactory` as a **reference copy**: it keeps the package
namespace, so your application autoloads the packaged one either way. The supported way to change
what the factory builds is `models.audit`, below.

Replace the model with your own subclass:

```php
// config/sentinel.php
'models' => [
    'audit' => App\Models\Audit::class,
],
```

## Integrity chain

Chaining is unconditional: every entry a `Ledger` writes links to the one before it in its stream,
regardless of configuration. `integrity.enabled` does not govern this — it will govern only the
advanced verification that ships later (checkpoints and signatures), which does not exist yet.

Each entry's `hash` covers a prefix and the canonical payload:

```
hash = algorithm(payload_version + SEP + stream + SEP + sequence + SEP + (previous_hash ?? '') + SEP + canonical)
```

`SEP` is the ASCII unit separator (`\x1f`), so the parts of the prefix cannot concatenate into one
another. `algorithm` is read from the row itself — `sha256` by default — never from the current
configuration, so a row keeps verifying under the algorithm it was written with even if the
configured default changes later. `canonical` is the RFC 8785 canonical JSON of the twenty-seven
columns frozen in `Integrity\CanonicalPayload::COLUMNS`. Writing and verifying both call
`Integrity\Hasher::hash()`, so they walk the exact same code.

`stream` is part of that prefix, which is why a stream is never renamed in place: changing the
stream strategy while a chain already holds data does not rewrite the old rows under the new name,
it starts a second chain under it — the history ends up split across two independent chains instead
of continuing as one.

Verify a whole chain, or a bounded slice of it:

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

$result = Sentinel::verifyIntegrity('global', from: 1, to: 500);

if (! $result->isIntact()) {
    // $result->reason, $result->sequence and $result->auditId locate the break
}
```

Or a single entry, from the model itself:

```php
use ElPandaPe\Sentinel\Models\Audit;

$audit = Audit::query()->firstOrFail();

$audit->verifyIntegrity(); // bool
```

A verification failure never throws. It dispatches `Events\IntegrityVerificationFailed` and reports
through `Integrity\VerificationResult`, for one of three reasons (`Enums\IntegrityBreak`):

| Reason | Means |
|---|---|
| `hash_mismatch` | The row no longer reproduces its own hash — its content changed. |
| `link_mismatch` | Its `previous_hash` no longer matches the entry before it — the order changed. |
| `sequence_gap` | A `sequence` is missing from the stream — an entry is gone. |

An entry is immutable through every path the model exposes: `save()`, `update()`, `delete()` and
`destroy()` all throw `Exceptions\ImmutableAuditException` once the row exists. That guard runs on
Eloquent's model events, so it only sees a change that goes through the model — an `update()` issued
through the query builder (`Audit::query()->where(...)->update([...])`) never fires those events and
does not pass through it.

## Ledger drivers

`Contracts\Ledger` ships two drivers, chosen by `ledger.default`:

```php
// config/sentinel.php
'ledger' => [
    'default' => env('SENTINEL_LEDGER', 'database'), // 'database' or 'null'
],
```

- `Ledger\DatabaseLedger` writes to `sentinel_audits`: it serializes the stream, reads its tail,
  builds the link and inserts, retrying if a concurrent writer claims the same `(stream, sequence)`
  first.
- `Ledger\NullLedger` computes the exact same chain but keeps it only on the instance that runs it —
  nothing reaches storage. It runs the same contract suite as `DatabaseLedger`, so the two drivers
  cannot drift apart.

> The `Ledger` contract is **unstable until `v0.8.0`**. It is declared now so every driver has one
> shape to answer to, and it gets tensioned against a non-SQL backend before the freeze.

## Development

The project runs entirely in Docker — no PHP or Composer needed on your machine.

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
make test-dbs   # suite against MySQL and PostgreSQL
make shell      # a shell inside the container
```

## Credits & license

Built by [Carlos Mayorga](https://carlosmayorga.me/).

The MIT License (MIT). See [LICENSE.md](LICENSE.md).
