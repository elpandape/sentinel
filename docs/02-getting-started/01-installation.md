# 🚀 Installation

> What to require, what the installer writes, what it deliberately leaves alone, and how to prove the install is sound before you audit a single model.

**On this page:** [Requirements](#requirements) · [Requiring the package](#requiring-the-package) · [Why `@RC`](#why-rc-and-not-minimum-stability) · [`sentinel:install`](#what-sentinelinstall-does) · [Migrations](#migrations-are-loaded-not-published) · [Publish tags](#the-publish-tags) · [PHP extensions](#php-extensions) · [What ships](#what-ships-in-the-tarball) · [Engines](#the-engines-it-is-run-against) · [Verifying the install](#verifying-the-install) · [⚠️ Pitfalls](#️-pitfalls) · [✅ Best practices](#-best-practices)

---

## Requirements

| Requirement | Constraint in `composer.json` | Notes |
|---|---|---|
| PHP | `^8.4` | The test matrix runs 8.4 and 8.5. The Docker dev image and every other CI job are 8.4. |
| Laravel | `illuminate/*: ^13.0` | Console, contracts, database, encryption, http, queue, routing, support. **Laravel 12 is excluded** — the constraint is `^13.0` on each component, not on `laravel/framework`, so any application on the Laravel 13 component set resolves. |
| Composer runtime | `composer-runtime-api: ^2.2` | Read by `Console\About` so `php artisan about` can report the installed version. |
| `ext-mbstring` | `*` | Hard requirement. See [PHP extensions](#php-extensions). |
| `ext-openssl` | `*` | Hard requirement. See [PHP extensions](#php-extensions). |
| A relational database | — | SQLite, MySQL or PostgreSQL. See [Choosing an engine](../10-database-engines/01-choosing-an-engine.md). |
| Redis | — | Only for `mode => buffered`. Nothing else in the package touches it. See [The buffered mode](../09-operations/02-the-buffered-mode.md). |

Minimum server versions are set by the SQL the ledger emits, not by a policy: SQLite 3.38 (where JSON
stopped being a compile-time option), MySQL 8.0.4 (`JSON_TABLE`), PostgreSQL 9.4 (`jsonb`). What is
actually exercised on every push is newer than all three — see [the engines](#the-engines-it-is-run-against).

> ⚠️ **Warning.** MariaDB is not supported and is refused rather than guessed at. It is not one of
> the three driver names the SQL is written for, so `whereFieldChanged()` throws
> `LedgerException::cannotTranslateOn` there — naming the driver — instead of answering with a query
> that might not mean the same thing.

---

## Requiring the package

```bash
composer require elpandape/sentinel:^1.0@RC
```

Then, in the application root:

```bash
php artisan sentinel:install
php artisan migrate
```

That is the whole install. The service provider is auto-discovered (`extra.laravel.providers` in
`composer.json`) and so is the `Sentinel` facade alias, so there is nothing to register by hand.

---

## Why `@RC`, and not `minimum-stability`

Sentinel's current tag is a release candidate. Composer's default root stability is `stable`, so it
will not resolve an RC unless you say so — and there are two ways to say it, which are not equally
narrow.

| Way to say it | Scope | Verdict |
|---|---|---|
| `"elpandape/sentinel": "^1.0@RC"` | The stability flag rides on **this one constraint**. Every other package in the project keeps resolving at `stable`. | Use this. |
| `"minimum-stability": "RC"` at the root | Relaxes the floor for **every** package the project requires and every transitive dependency they pull. | Wrong tool, unless relaxing the whole project is what you meant. |

```json
"require": {
    "elpandape/sentinel": "^1.0@RC"
}
```

The API is frozen at the release candidate: between it and `v1.0.0`, only bugfixes and documentation
land. What "frozen" covers, and the one class of question that may break it, is in
[API stability](../99-reference/09-api-stability.md).

---

## What `sentinel:install` does

`Console\InstallCommand` does exactly two things and reports on a third.

1. **Copies `config/sentinel.php` if — and only if — it is not already there.** The copy is made by
   the command itself rather than delegated to `vendor:publish`, because `vendor:publish` would need
   `--force` to write anything and `--force` would then write over an edited file. Not overwriting is
   the whole promise of being able to run this command twice.
2. **Reads the schema on the configured connection** (`sentinel.database.connection`; null means the
   application default) and reports which of the seven tables are absent. The names come from
   `InstallCommand::TABLES` put through `Support\Config::table()`, so `tables.prefix` and any renamed
   table are honoured.
3. **Names the six optional publish tags** — `InstallCommand::OPTIONAL`. It does not run any of them.

```text
Published the configuration to config/sentinel.php.
7 of 7 tables are not there yet: sentinel_audits, sentinel_audit_tags, sentinel_audit_relations, sentinel_transactions, sentinel_checkpoints, sentinel_archives, sentinel_access_log
Run php artisan migrate to create them.
Publishable and not published by default, each a choice with a cost: sentinel-lang, sentinel-migrations, sentinel-json-indexes, sentinel-partitioned-pgsql-range, sentinel-partitioned-pgsql-tenant, sentinel-partitioned-mysql-range
```

Run it again after `php artisan migrate` and the middle two lines collapse to
`All 7 tables are present.` Re-running it is the ordinary case: it is how you find out where an
installation stands.

### What it touches, and what it does not

| Action | Does it? | Detail |
|---|---|---|
| Write `config/sentinel.php` | Yes, once | Skipped and reported as already configured when the file exists. Your edits are never touched. |
| Overwrite an existing config | **No** | There is no `--force`. A flag that has to be withheld for the promise to hold is one keystroke from breaking it. |
| Run migrations | **No** | `php artisan migrate` is a separate, deliberate step. |
| Publish migrations | **No** | The provider loads them from the package. See below. |
| Publish language files | **No** | They load from the package; `sentinel-lang` is opt-in. |
| Publish the JSON index or a partitioned stub | **No** | Each is a cost somebody chooses. |
| Create a database or a connection | **No** | It reads the connection; it does not create one. |
| Fail because tables are missing | **No** | Missing tables is the ordinary state between publishing and migrating. Exit code stays `0`. |

**Exit codes.** `0` in every normal case, missing tables included. `2` (`INVALID`) only when the
configured connection cannot be read at all — a bad connection name, a database that is not there —
in which case the message carries the underlying reason. See [Exit codes](../99-reference/07-exit-codes.md).

> 💡 **Tip.** If you would rather publish the configuration by hand,
> `php artisan vendor:publish --tag=sentinel-config` does the same one thing.

---

## Migrations are loaded, not published

The provider calls `loadMigrationsFrom()` with the output of `Support\PackageMigrations::unpublished()`,
which globs the package's own `database/migrations`, strips the `YYYY_MM_DD_HHMMSS_` prefix from each
file name, and asks `Support\PublishedMigration` whether the application's `database/migrations`
already holds a file ending in that name. **The decision is per file**, not all-or-nothing — a package
that decided on the first file would stop delivering every migration it ships afterwards to exactly
the installations that have the most data.

So a fresh install needs no `vendor:publish` for migrations at all. `php artisan migrate` picks up
all eight:

| Migration | Creates or alters |
|---|---|
| `…_create_sentinel_audits_table` | `sentinel_audits` — the entry table, forty columns, created whole |
| `…_create_sentinel_audit_tags_table` | `sentinel_audit_tags` — the label projection |
| `…_add_occurrence_indexes_to_sentinel_audits_table` | Two indexes so an ordering by `occurred_at` rides an index |
| `…_create_sentinel_audit_relations_table` | `sentinel_audit_relations` — the relation-line projection |
| `…_create_sentinel_transactions_table` | `sentinel_transactions` — business-operation headers |
| `…_create_sentinel_checkpoints_table` | `sentinel_checkpoints` — anchors over ranges of a stream |
| `…_create_sentinel_archives_table` | `sentinel_archives` — the register of retired ranges |
| `…_create_sentinel_access_log_table` | `sentinel_access_log` — the read projection, written only under compliance mode |

Every one of them resolves its table name through `Config::table()` and its connection through
`Config::connection()`, so renaming tables is a config edit and not a migration edit. The full column
list is in [Schema](../99-reference/03-schema.md).

> 📌 **Note.** The published file **name** is the switch, and the timestamp is not part of it.
> Publish `sentinel-migrations`, rename the copy, and the package starts loading its own again —
> which means the same migration runs twice.

---

## The publish tags

| Tag | Lands in | What you get | Cost of taking it |
|---|---|---|---|
| `sentinel-config` | `config/sentinel.php` | The full configuration file. `sentinel:install` already does this. | You now own the file. Laravel's config merge is **one level deep**, so a nested key you drop is a `ConfigurationException` at runtime, not a fallback. |
| `sentinel-lang` | `lang/vendor/sentinel/` | The `en` and `es` catalogues the commands, the presenter and user-facing exceptions print through. | You own the wording, including strings a later version adds. |
| `sentinel-migrations` | `database/migrations/` | The eight package migrations, as editable files. | The provider stops loading its own copy of each file you took. You own the schema from then on. |
| `sentinel-json-indexes` | `database/migrations/` | The additive index behind `whereIp()` and `whereRoute()`. | Measured at ~15 % per write on PostgreSQL 16 and ~21 % on MySQL 9, at the engine, over 200 000 writes. Both filters work without it — by scanning. On MySQL the stub adds two `VIRTUAL INVISIBLE` generated columns. |
| `sentinel-partitioned-pgsql-range` | `database/migrations/` | A monthly RANGE-partitioned `sentinel_audits` for PostgreSQL. | **Replaces** the base migration. New installations only. |
| `sentinel-partitioned-pgsql-tenant` | `database/migrations/` | A LIST-partitioned-by-tenant `sentinel_audits` for PostgreSQL. | Same replacement. Partitions are added by hand, with their two unique indexes. |
| `sentinel-partitioned-mysql-range` | `database/migrations/` | A `PARTITION BY RANGE (TO_DAYS(created_at))` `sentinel_audits` for MySQL. | Same replacement. Unique keys gain `created_at` and are enforced only within a day. |

> ⚠️ **Warning.** The three partitioned stubs are not additions — each lands under
> `…_create_sentinel_audits_table.php`, the same name the package's own migration carries, so the
> package stops offering its own. Publishing two of them leaves one file and no way to tell which.
> Read [Partitioning](../10-database-engines/06-partitioning.md) before publishing any of them, and
> decide before the first entry is written.

---

## PHP extensions

| Extension / package | Required? | What it is for | What breaks without it |
|---|---|---|---|
| `ext-mbstring` | **Yes** (`require`) | Deterministic canonical payloads, and multibyte-correct lengths on the capture and redaction paths. | `Integrity\JsonCanonicalizer` orders object members by UTF-16 code unit (RFC 8785) via `mb_convert_encoding()`. Lose it and the canonical form changes, so the hash changes, so the chain stops verifying — the one failure this package exists to prevent. It is also the `mb_strlen`/`mb_substr` behind `Security\PartialMasker`, the 64-character event-name cap in `Capture\PendingEvent` and the 64-character tag cap in `Pipeline\Stages\ResolveTags`, all of which would start counting bytes. |
| `ext-openssl` | **Yes** (`require`) | Asymmetric signing, and field-level encryption. | `Integrity\OpenSslSigner` cannot sign or verify, so RSA-signed entries and signed checkpoints are gone. `Illuminate\Encryption\Encrypter` is AES through OpenSSL, so `Security\Keyring`, `Pipeline\Stages\EncryptSensitiveData` and `sentinel:rekey` cannot write, read back or rotate an encrypted field. `HmacSigner` is unaffected — `hash_hmac` is core. |
| `ext-zlib` | No (`suggest`) | The gzip codec for cold-storage batches: `ledger.ledgers.archive.codec => 'gzip'`. | Set the codec to `null` and archived batches are written as plain NDJSON. Nothing else changes and no other feature is affected. The codec is recorded **by name** in the manifest, not as a boolean, so a batch written two years ago says what to inflate it with. |
| `open-telemetry/api ^1.10` | No (`suggest`) | Filling `trace_id`/`span_id` from the span the OpenTelemetry SDK already opened. | The package still reads and propagates W3C Trace Context on its own through `Telemetry\TraceParent`, so distributed tracing keeps working. It just cannot join an SDK span. |
| `orchestra/testbench ^11.0` | No (`suggest`) | Running `Testing\LedgerContractTestCase` against your own ledger driver — it boots a Laravel application. | You cannot execute the shipped contract suite. |
| `phpunit/phpunit ^13.0` | No (`suggest`) | Same: the contract case is a PHPUnit test case, and it ships in `src/` rather than `require-dev` on purpose. | Same. |

> 🔒 **Security.** `ext-openssl` is a hard requirement rather than a suggestion even though HMAC
> signing does not need it, because encryption and RSA signing are the two mechanisms an installation
> reaches for after data already exists. Discovering the extension is missing at that point means the
> entries you wanted protected were written unprotected.

---

## What ships in the tarball

`.gitattributes` decides, and the line is drawn at who the file is written for: a consumer reads the
README, the upgrade guide, the changelog, the licence and this documentation; a contributor reads the
rest, on the repository.

| In `vendor/elpandape/sentinel/` | Kept out (`export-ignore`) |
|---|---|
| `src/` — including `src/Testing/`, the contract suite a third-party driver runs against | `tests/`, `benchmarks/` |
| `resources/lang/` — what the package prints | `docker/`, `compose.yaml`, `Makefile` |
| `config/sentinel.php`, `database/migrations/`, `database/stubs/` | `.github/` |
| `stubs/rector/` — the Rector rules the migration guides tell you to copy | `phpstan.neon.dist`, `phpunit.xml.dist`, `pint.json`, `rector.php`, `testbench.yaml` |
| `README.md`, `UPGRADE.md`, `CHANGELOG.md`, `LICENSE.md`, `MIGRATE_FROM_OWEN_IT.md`, `MIGRATE_FROM_ALTEK.md`, `docs/` | `CONTRIBUTING.md`, `SECURITY.md`, `.editorconfig`, `.gitignore`, `.gitattributes` |

The split is asserted by the suite (`tests/ConventionsTest.php`, "keeps the workbench out of the
tarball and the package inside it"), so it does not drift silently.

There is **no committed `composer.lock`** — this is a library, and the version ranges in
`composer.json` are widened by hand when the support matrix moves.

---

## The engines it is run against

On every push to `main` and every pull request:

| Job | Engine / runtime | Matrix |
|---|---|---|
| `quality` | SQLite in memory, Redis 8 | PHP 8.4. Pint, PHPStan max, Rector dry-run, 100 % line coverage, 100 % type coverage, `composer validate --strict` + `composer audit`. |
| `test` | SQLite in memory, Redis 8 | PHP 8.4 and 8.5 × Laravel `13.*` (testbench `11.*`) × `prefer-lowest` and `prefer-stable` — four jobs. |
| `test-databases` | MySQL 9, PostgreSQL 16, Redis 8 | PHP 8.4, one job per connection. Both print the server version through PDO before running. |

Nightly, on a cron, mutation testing runs over the module list, including `src/Ledger` against real
MySQL and PostgreSQL — the advisory lock and the gap lock are invisible to a run on SQLite alone.

> 🐘 **Engine.** SQLite is a supported engine and is what the whole suite runs on by default, but
> it cannot partition (`Partitions\Grammar::divides('sqlite')` is false) and it ignores
> `lockForUpdate()`. It is fit for tests, single-process installs and small trails. It is not fit for
> a trail that grows. See [SQLite](../10-database-engines/04-sqlite.md).

---

## Verifying the install

Four commands, in this order. Each answers a different question, and none of them writes an entry.

> 🧪 **Verify it.**
>
> ```bash
> php artisan sentinel:install
> php artisan migrate
> php artisan sentinel:install
> php artisan about
> php artisan sentinel:verify
> ```

**1. `sentinel:install`, run a second time.** It should now say
`config/sentinel.php is already there, and nothing in it was touched.` and `All 7 tables are present.`
If a table is still named as missing, either the migration did not run or `sentinel.tables.*` names
something the migration did not create.

**2. `php artisan about`.** The `Sentinel` section carries six rows, and they are the six a support
conversation opens with:

| Row | Reads from | On a fresh install |
|---|---|---|
| Version | Composer's own record; `dev` for a path repository or a checkout | the tag you required |
| Mode | `sentinel.mode` | `sync` |
| Ledger | `sentinel.ledger.default` | `database` |
| Payload version | `Ledger\EntryBuilder::PAYLOAD_VERSION` | `1` |
| Compliance mode | `sentinel.compliance` | `OFF` |
| Telemetry | `sentinel.telemetry.enabled` | `OFF` |

No key, no key identifier and no signer configuration appears here — `about` output gets pasted into
issues and captured by deploy logs, and signing material must never travel that way.

**3. `sentinel:verify`.** On an empty install it walks nothing and says so:
`Verified 0 entries across 0 streams. The chain is intact.` That is a real answer, not a stub — it
proves the ledger is resolvable and the schema is readable. Its exit codes are three and not two, so
a watchdog can tell the cases apart:

| Exit | Meaning |
|---|---|
| `0` | The walk completed and nothing came back wrong. An unsigned trail exits `0`: it is sound, and the report says how many entries are unsigned. |
| `1` | The chain is broken. The failing entry is named with its sequence and stream. |
| `2` | The command could not run — an unknown `--depth`, a range without a `--stream`, or an error the ledger threw. Nothing was checked, which is not the same as nothing being wrong. |

**4. A first write.** Everything above proves the wiring. Proving that capture works needs one model
and one save — that is [Your first audit](02-your-first-audit.md).

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `composer require` fails with `could not find a version … matching your minimum-stability` | The tag is a release candidate and the project's root stability is `stable`. | Require it as `^1.0@RC`. Do not raise the project-wide `minimum-stability`. |
| `php artisan migrate` reports nothing to migrate, and the tables are absent | A file with the same trailing name already sits in `database/migrations` — usually a published copy — so `PackageMigrations` skipped the package's own, and the published one has already been run or was renamed. | Check `database/migrations` for `*_create_sentinel_*.php`. Either keep the published copies (and own them) or delete them so the package loads its own. |
| The same migration runs twice | A published copy was renamed, so the package no longer recognises it and loads its own alongside. | Restore the published file's trailing name exactly, or delete the published copy. |
| `sentinel:install` exits `2` with "the schema could not be read" | `sentinel.database.connection` names a connection that does not exist or cannot connect. | Fix the connection in `config/database.php`, or set `sentinel.database.connection` back to `null` to use the application default. |
| `ConfigurationException: Sentinel configuration key [sentinel.tables.checkpoints] is not set.` at runtime | A `config/sentinel.php` published before that key existed. Laravel's `mergeConfigFrom` is one level deep, so your `tables` array replaced the package's wholesale. | Re-sync the whole `tables` block against the package's `config/sentinel.php` after every upgrade. |
| Entries verify locally and fail on another host | `ext-mbstring` differs between the two. The canonical form orders members by UTF-16 code unit and needs it. | Install `ext-mbstring` everywhere the package runs, including workers and verification hosts. |
| `sentinel:verify` exits `2` on an install that has never written anything | The configured ledger driver cannot enumerate streams, or the connection is unreadable. | Check `sentinel.ledger.default`. The `null` and `memory` drivers are not stores; see [The shipped drivers](../11-extending/02-shipped-drivers.md). |
| Writes are suddenly ~20 % slower after a deploy | `sentinel-json-indexes` was published and migrated. | Keep it only if you actually call `whereIp()` or `whereRoute()`; both filters answer without it, by scanning. |

---

## ✅ Best practices

✅ **Do** — pin the stability flag to this one requirement.

```json
"require": {
    "elpandape/sentinel": "^1.0@RC"
}
```

❌ **Don't** — relax the whole project to reach one release candidate. Every transitive dependency
becomes eligible for a pre-release resolution, and the next `composer update` can move packages you
never meant to touch.

```json
"minimum-stability": "RC",
"prefer-stable": true
```

---

✅ **Do** — leave the migrations where they are and let the provider load them. You get every
migration a later version ships without doing anything.

```bash
php artisan sentinel:install
php artisan migrate
```

❌ **Don't** — publish `sentinel-migrations` "to be safe". From then on you own the schema, and a
migration a later version adds still arrives — but any file you took over stops tracking the package,
per file, silently.

```bash
php artisan vendor:publish --tag=sentinel-migrations   # only when you intend to edit them
```

---

✅ **Do** — re-run `sentinel:install` whenever you want to know where an installation stands. It
never overwrites, and the missing-table report is the point.

```bash
php artisan sentinel:install   # "config/sentinel.php is already there, and nothing in it was touched."
```

❌ **Don't** — reach for `vendor:publish --tag=sentinel-config --force` to "refresh" the config
after an upgrade. `--force` writes over the file with your edits in it. Diff the package's
`config/sentinel.php` against yours instead, and copy the new keys across by hand.

---

✅ **Do** — keep the whole `tables` block when you publish the configuration, and re-check it on every
upgrade.

```php
// config/sentinel.php
'tables' => [
    'prefix' => 'sentinel_',
    'audits' => 'audits',
    'audit_tags' => 'audit_tags',
    'audit_relations' => 'audit_relations',
    'transactions' => 'transactions',
    'checkpoints' => 'checkpoints',
    'archives' => 'archives',
    'access_log' => 'access_log',
],
```

❌ **Don't** — publish a trimmed `tables` block with only the keys you renamed. The config merge is
one level deep, so your array replaces the package's entirely and `Config::table()` throws
`ConfigurationException::missing` the first time something reads the key you dropped — at runtime, on
a write path.

```php
'tables' => ['prefix' => 'audit_'],   // every other table name is now missing
```

---

✅ **Do** — decide about partitioning before the first entry, and publish exactly one stub if you
decide for it.

```bash
php artisan vendor:publish --tag=sentinel-partitioned-pgsql-range
php artisan migrate
```

❌ **Don't** — publish two partitioned stubs, or publish one on a table that already holds entries.
Both stubs land under the same file name, so the second overwrites the first with no trace of which
you kept; and converting a populated table is a maintenance window, not a publish.

---

✅ **Do** — treat `ext-zlib` as a per-feature dependency and set the codec explicitly if you do not
have it.

```php
// config/sentinel.php
'ledger' => ['ledgers' => ['archive' => ['codec' => null]]],   // plain NDJSON, no zlib needed
```

❌ **Don't** — leave `codec => 'gzip'` on a host without `ext-zlib` and find out during the first
archive run. Nothing else in the package needs zlib, so the failure surfaces only when a batch is
written.

---

**See also:** [Your first audit](02-your-first-audit.md) · [Configuration](../99-reference/02-configuration.md) · [Schema](../99-reference/03-schema.md) · [Artisan commands](../09-operations/06-artisan-commands.md) · [Choosing an engine](../10-database-engines/01-choosing-an-engine.md) · [Partitioning](../10-database-engines/06-partitioning.md) · [Exit codes](../99-reference/07-exit-codes.md) · [The import runbook](../12-migrating/03-the-import-runbook.md)
