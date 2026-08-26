# Changelog

All notable changes to `elpandape/sentinel` are documented here.

## v0.3.0 — Ledger, sequence and the integrity core (2026-08-26)

### Added

- `Ledger\DatabaseLedger`: writes an entry into its stream inside one transaction, assigning
  `sequence` and `version` at write time and never in the capture. Serializes writers per engine (an
  advisory lock on PostgreSQL, a row lock on MySQL and SQLite) and retries a bounded number of times
  against `unique(stream, sequence)`, the final arbiter when a stream starts out empty.
- `Ledger\NullLedger`: computes the exact same chain without touching storage, driven by the same
  contract suite as `DatabaseLedger`, so the two drivers cannot drift apart.
- `Integrity\JsonCanonicalizer`: RFC 8785 canonical JSON — members ordered by UTF-16 code unit and
  numbers written the way ECMAScript does, so a hash depends only on a payload's content, never on a
  `php.ini` directive.
- `Integrity\CanonicalPayload`: the twenty-seven columns of `payload_version = 1`, frozen in one
  constant.
- `Integrity\Hasher`: the chain link — a prefix of `payload_version`, `stream`, `sequence` and
  `previous_hash` hashed together with the canonical payload, always computed from the model so
  writing and verifying walk the same code.
- `Integrity\Stream`: names the chain an entry belongs to — `global`, `tenant:{id}`, `type:{alias}`,
  a closure or a `Contracts\StreamResolver` — and refuses a name the `stream` column cannot hold
  instead of truncating it.
- `Integrity\Verifier` and `Integrity\VerificationResult`: verify one entry, a whole stream or a
  bounded range, and report which of three breaks it found (`Enums\IntegrityBreak`): a row that no
  longer reproduces its own hash, a link that no longer points at the entry before it, or a hole in
  the sequence. A break is announced through `Events\IntegrityVerificationFailed`, never thrown.
- `Sentinel::verifyIntegrity()` and `Models\Audit::verifyIntegrity()`, the two entry points into
  verification.
- Immutability guards on `Models\Audit`: `save()`, `update()`, `delete()` and `destroy()` all throw
  `Exceptions\ImmutableAuditException` once an entry has been written.
- `Ledger\DatabaseStream` and `Ledger\ArrayStream`: keyset paging over `(stream, sequence)` so a long
  chain verifies without being loaded into memory, with a bounded `range()` on both.
- First translated strings of the package, for the integrity verification event, in English and
  Spanish.

### Changed

- `Contracts\LedgerStream` gains `range(int $from, ?int $to = null): static` — a breaking change
  within the 0.x cycle, needed for verification to bound a walk instead of reading a whole chain.

## v0.2.0 — Schema, `Audit` and contracts (2026-08-26)

### Added

- `sentinel_audits` migration: forty columns and eleven indexes, with the JSON and microsecond date
  types resolved by the engine grammar — `jsonb` on PostgreSQL 16, `json` on MySQL 9, text on
  SQLite; `datetime(6)` on MySQL and `timestamp(6)` on PostgreSQL. Publishable with
  `--tag=sentinel-migrations` and loaded automatically when no published copy exists.
- `Models\Audit`: ULID key, morphs for subject, actor and impersonator, JSON and enum casts, no
  `updated_at`, and table, connection and model class all resolved from the configuration.
- `Data\AuditData`: the mutable representation of an entry before it has an identity.
- `Contracts\Ledger`, `LedgerStream`, `Auditable`, `Resolver`, `Transformer`, `Signer` and
  `Canonicalizer`, plus the `Query\AuditQuery` and `Support\AuditCollection` their signatures
  require.
- `Database\Factories\AuditFactory`, publishable with `--tag=sentinel-factories`.
- `Support\Config::model()`: typed resolution of the `models.*` overrides.
- `composer validate --strict` and `composer audit` as gates in `make ci` and the quality workflow.

### Upgrade notes

This version introduces the first migration of the package.

```bash
composer update elpandape/sentinel
php artisan migrate
```

Publishing the migration is optional. If you publish it
(`php artisan vendor:publish --tag=sentinel-migrations`), the package stops loading its own copy, so
the migration never runs twice. Nothing writes to the table yet: entries start being written in
`v0.3.0`.

The `Ledger` contract is unstable until `v0.8.0` and may change between minor versions.

## v0.1.0 — Skeleton (2026-08-25)

### Added

- Package skeleton with the full quality toolchain: Pint, PHPStan (Larastan) at level `max`,
  Rector, and Pest 5 with coverage and type coverage gates at 100%.
- Docker + Make development environment: no PHP or Composer needed on the host.
- GitHub Actions for tests (PHP 8.4/8.5, Laravel 13, MySQL 9 and PostgreSQL 16), quality gates,
  nightly mutation testing and changelog updates.
- `config/sentinel.php` with every section the package will use through 1.0, defaults safe and
  future features off — publishing the config once is enough for the whole 0.x cycle.
- `Support\Config`: typed configuration reader that fails loudly on a wrong type or an unknown value.
- `Context\ExecutionContext`: request-scoped execution context with nestable, self-restoring scopes.
- `Sentinel` manager and facade: recording state, `pause()`, `resume()`, `withoutAuditing()`
  and `withContext()`.
- Enums `AuditEvent`, `Severity`, `Source` and `Mode`.
- English and Spanish translation namespaces wired and publishable.
- Architecture tests enforcing strict types, final classes, no mutable static state, no leftover
  debugging calls, and the project's comment conventions.
