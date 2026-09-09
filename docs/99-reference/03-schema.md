# 📚 Schema

> Every table Sentinel creates, every column in it, every index and the query it serves — and the things the schema deliberately does not have.

**On this page:** [The seven tables](#the-seven-tables) · [Naming and connection](#naming-and-connection) · [sentinel_audits](#sentinel_audits) · [Its indexes](#the-indexes-on-sentinel_audits) · [The six companion tables](#the-six-companion-tables) · [Relationships and the missing foreign keys](#relationships-and-the-missing-foreign-keys) · [Shortcuts, projections and evidence](#shortcuts-projections-and-evidence) · [Types per engine](#types-the-grammar-emits-per-engine) · [What is deliberately absent](#what-is-deliberately-absent) · [Publishable alternatives](#publishable-alternatives) · [Migration inventory](#migration-inventory)

---

## The seven tables

| Table (default name) | Rows are | Created by | Written by | Ever updated? |
|---|---|---|---|---|
| `sentinel_audits` | Audit entries — the evidence | The base migration | The ledger | Never through the model; the redaction and the prune go through the query builder |
| `sentinel_audit_tags` | One label on one entry | `create_sentinel_audit_tags_table` | The ledger, on write | No — inserted and deleted only |
| `sentinel_audit_relations` | One line of a relation operation | `create_sentinel_audit_relations_table` | The ledger, projected from `changes` | No |
| `sentinel_transactions` | The header of a business operation | `create_sentinel_transactions_table` | The transaction scope | **Yes** — opened, then completed |
| `sentinel_checkpoints` | An anchor over a range of a stream | `create_sentinel_checkpoints_table` | The checkpoint emitter | No |
| `sentinel_archives` | A range that left the hot table | `create_sentinel_archives_table` | **Only** the prune | No |
| `sentinel_access_log` | One read of the trail, projected | `create_sentinel_access_log_table` | `Compliance\AccessLog`, in compliance mode only | No |

All seven are created by every installation. `sentinel_access_log` stays empty until [compliance mode](../08-lifecycle/05-compliance-mode.md) is switched on, which is why turning it on is a config change and not a migration.

> 📌 **Note.** `php artisan sentinel:install` reports which of the seven are missing on the configured connection and exits `0` either way. The canonical list of config keys lives in `Console\InstallCommand::TABLES`.

---

## Naming and connection

Nothing hard-codes a table name. Every migration and every model calls `Support\Config::table()`, which is `tables.prefix` concatenated with `tables.<name>`:

```php
use ElPandaPe\Sentinel\Support\Config;

/** @var Config $config */
$config = app(Config::class);

$config->table('audits');      // 'sentinel_audits'
$config->connection();         // null — the application default
```

The seven accepted names are `audits`, `audit_tags`, `audit_relations`, `transactions`, `checkpoints`, `archives`, `access_log`. Anything else, or a missing key, throws `ConfigurationException::missing`.

The models override `getTable()` and `getConnectionName()` as **methods**, not as `$table` / `$connection` properties, so the value is read from configuration on every call rather than frozen at construction.

> ⚠️ **Warning.** `mergeConfigFrom` merges one level deep. If you publish `config/sentinel.php` and drop a key from the `tables` block, `Config::table()` has no code-side fallback for it — you get a `ConfigurationException` at runtime, not a default. Keep the whole block. See [Configuration](02-configuration.md).

> ⚠️ **Warning.** Rename tables through `sentinel.tables.*`, never through a `prefix` on the audit connection. The `create table` statements go through the engine grammar and would pick a connection prefix up, but the raw DDL pinned around them — the PostgreSQL `_default` partition, the tenant partitions and their indexes, everything in `Partitions\Grammar`, and the JSON-index stub's `create index` — interpolates the name from `Config::table()` alone. No test in the suite configures a prefixed connection.

---

## `sentinel_audits`

One row is one audit entry. Forty columns, created whole by one migration and never `ALTER`ed by a later version: seven of them were born empty in the first release for features that landed many minors later. `Support\AuditSchema::columns()` is the single definition of the shape, shared by the base migration and all three partitioned alternatives.

The forty columns fall into three bands, and the bands are not decorative — they are the three constants the archive format enumerates (`Archive\Line`), and together they are the whole table.

### The canonical payload — 27 columns

These are `Integrity\CanonicalPayload::COLUMNS`, the frozen definition of `canonical(core)` for `payload_version` 1. They are canonicalised (RFC 8785) and hashed. Changing what is in this list, or how any of it is rendered, is a [`payload_version`](../07-integrity/03-canonicalization.md) bump plus a backwards-compatibility test.

| Column | Declared as | Null | Filled by |
|---|---|---|---|
| `id` | `char(26)` | no | `Str::ulid()` in `Ledger\EntryBuilder` |
| `audit_type` | `string(32)` | no | The capture — one of nine: `model`, `relation`, `mass`, `custom`, `auth`, `transition`, `restore`, `security`, `access`. Never `transaction`: a business-transaction header is a row of `sentinel_transactions`, not an entry |
| `event` | `string(64)` | no | The capture — `created`, `updated`, a custom event name |
| `severity` | `string(8)` | no | The model's declaration; cast to `Enums\Severity` |
| `subject_type` | `string(255)` | yes | The morph type of the record the entry is about |
| `subject_id` | `string(64)` | yes | Its key — int, UUID or ULID all fit |
| `actor_type` / `actor_id` | `string(255)` / `string(64)` | yes | The actor resolver |
| `impersonator_type` / `impersonator_id` | `string(255)` / `string(64)` | yes | The impersonation resolver |
| `tenant_id` | `string(64)` | yes | The tenant resolver |
| `transaction_id` | `char(26)` | yes | The open business operation, if any |
| `request_id` | `string(64)` | yes | The request resolver |
| `trace_id` | `string(32)` | yes | The `traceparent` resolver |
| `span_id` | `string(16)` | yes | The same resolver |
| `source` | `string(16)` | no | The source resolver; cast to `Enums\Source` |
| `version` | `unsignedInteger` | yes | The ledger — the subject's nth entry; null when there is no subject |
| `context` | `jsonb` | **no** | Everything the resolvers returned |
| `before` / `after` | `jsonb` | yes | `Snapshot\SnapshotBuilder`, after the pipeline |
| `changes` | `jsonb` | yes | The diff (RFC 6902 entries) or the relation lines |
| `metadata` | `jsonb` | yes | Whatever the caller attached |
| `encryption` | `jsonb` | yes | The keyring, when a field was encrypted |
| `criteria` | `jsonb` | yes | The `where` clauses of a mass operation |
| `affected_rows` | `unsignedBigInteger` | yes | The row count a mass operation reported |
| `source_audit_id` | `char(26)` | yes | The entry a restoration was made from |
| `occurred_at` | `dateTime(6)` | no | Stamped at capture. **Never moves.** |

### The eight the chain seals around it

| Column | Declared as | Null | Default | Filled by |
|---|---|---|---|---|
| `stream` | `string(64)` | no | — | The stream strategy — `global`, `tenant:<id>`, … |
| `sequence` | `unsignedBigInteger` | no | — | The ledger, under a per-stream lock |
| `payload_version` | `unsignedSmallInteger` | no | `1` | `Ledger\EntryBuilder::PAYLOAD_VERSION` |
| `algorithm` | `string(16)` | no | `'sha256'` | `integrity.algorithm` |
| `previous_hash` | `char(64)` | yes | — | The hash of the previous entry of the stream; null at sequence 1 |
| `hash` | `char(64)` | no | — | `Integrity\Hasher` |
| `signature` | `text` | yes | — | The current signer; left null when the signer attests to nothing |
| `signature_key_id` | `string(64)` | yes | — | The same signer |

`payload_version`, `stream`, `sequence` and `previous_hash` are prefixed onto the hashed bytes, so all four are covered by `hash`. `algorithm` chooses the digest. `signature` covers `hash` and never the payload — which is what lets an external auditor verify a signature without recomposing the entry.

### The five the row needs to exist again

| Column | Declared as | Null | Filled by |
|---|---|---|---|
| `capture_id` | `char(26)` | yes | The queue and buffered modes, for retry idempotency |
| `created_at` | `dateTime(6)` | no | The ledger, when it settled the entry |
| `redacted_at` | `dateTime(6)` | yes | `Redaction\Redactor` |
| `redaction_reason` | `string(255)` | yes | The same |
| `redacted_hash` | `char(64)` | yes | The same — the hash of the row as the redaction left it |

None of these five is inside `canonical(core)`, so a redaction can write three of them without the entry's `hash` changing. That is also why `verifyIntegrity()` answers `false` on a tombstone: the row no longer reproduces the hash it still carries. `verifyContent()` is the method that tells a tombstone from tampering — see [Redaction and tombstones](../08-lifecycle/04-redaction-and-tombstones.md).

> 📌 **Note.** `subject_type`, `actor_type`, `impersonator_type` and `redaction_reason` are declared with no explicit length, so they take Laravel's `Schema::defaultStringLength()` — 255 unless your application changed it before the migration ran.

> 🔒 **Security.** `severity` is `varchar(8)` for the life of this schema. `critical` is exactly eight characters; a fifth case longer than that would need the `ALTER` the schema rule forbids.

---

## The indexes on `sentinel_audits`

Thirteen non-primary indexes. `tests/Database/AuditsTableTest.php` asserts the count and the exact column lists, so this is not a description — it is a gate.

| Index | Kind | The question it answers | Verified to seek? |
|---|---|---|---|
| `(stream, sequence)` | unique | The chain's own arbiter: no two entries share a position in a stream | Yes — the write path |
| `(capture_id)` | unique | A retried capture is refused rather than written twice | Yes |
| `(subject_type, subject_id, id)` | index | `for('invoice', 7)` — the history of one record | Yes |
| `(actor_type, actor_id, id)` | index | `by('user', 7)` — what one actor did | Yes |
| `(tenant_id, created_at)` | index | `forTenant('acme')`, narrowed by the ledger clock | Yes |
| `(transaction_id)` | index | `inTransaction($id)` — the entries of one business operation | Yes |
| `(request_id)` | index | Everything one HTTP request wrote | No published filter — query it directly |
| `(trace_id)` | index | `withTrace($id)` across process boundaries | Yes |
| `(audit_type, created_at)` | index | `whereType('transition')` | Yes |
| `(event)` | index | `whereEvent('invoice.approved')` | Yes |
| `(severity, created_at)` | index | `whereSeverity(Severity::Critical)` | Yes |
| `(occurred_at, id)` | index | The unfiltered timeline, ordered by the clock of the fact | SQLite yes; MySQL and PostgreSQL choose by cost |
| `(subject_type, subject_id, occurred_at, id)` | index | The timeline of one subject — the shape that actually gets run | Yes |

The last two arrive in a second migration, `add_occurrence_indexes_to_sentinel_audits_table`. Every index the table originally had ends in `id` — a ULID, so its lexical order is the ledger's order — and ordering by `occurred_at` therefore sorted outside every one of them.

The tail column of the two morph indexes is `id` and not `created_at`, for the same reason: a ULID sorts by mint time, and `id` is what the query surface uses as the total-order tiebreak (`orderBy($clock)->orderBy('id')` in `Ledger\DatabaseLedger::query()`).

> 📌 **Note.** No index begins with `source`, `version`, `created_at` alone, or anything inside `context` or `changes`. `whereSource()`, `whereVersion()`, `between()`, `whereFieldChanged()`, `whereIp()` and `whereRoute()` are **refiners**: correct on their own, but they scan. `tests/Query/QueryPlanTest.php` asserts exactly that, per engine. Put an indexed filter in front of them. See [Filters reference](../06-reading/02-filters-reference.md).

> 🧪 **Verify it.** An index count over this schema must exclude the primary key: SQLite materialises a non-integer primary key as a `sqlite_autoindex`, so every schema test filters `primary === false` before counting. "Thirteen indexes" means thirteen non-primary ones.

---

## The six companion tables

### `sentinel_audit_tags`

Two columns, no key of its own, no clock.

| Column | Declared as | Null |
|---|---|---|
| `audit_id` | `char(26)` | no |
| `tag` | `string(64)` | no |

Indexes: `unique(audit_id, tag)` — a labelled entry never repeats a label — and `index(tag, audit_id)`, which is what turns "find everything labelled `billing`" into a seek. The ledger inserts with `insertOrIgnore`, so a repeated label never reaches the retry loop that exists for the chain's unique index.

### `sentinel_audit_relations`

| Column | Declared as | Null |
|---|---|---|
| `audit_id` | `char(26)` | no |
| `relation` | `string(64)` | no |
| `operation` | `string(16)` | no |
| `related_type` | `string(255)` | yes |
| `related_id` | `string(64)` | yes |
| `pivot_before` | `json` | yes |
| `pivot_after` | `json` | yes |

Indexes: `(audit_id)`, `(related_type, related_id, audit_id)`, `(relation, audit_id)` — the three ways the history of a relation is asked for. Note the pivot columns are declared `json()`, not `jsonb()`: on PostgreSQL they are `json`, unlike every other JSON column in the schema.

Rows are projected from the entry's `changes` by `Ledger\RelationProjection`, which the verification also re-derives through — one mapping, so the projection and the evidence cannot drift.

### `sentinel_transactions`

| Column | Declared as | Null | Default |
|---|---|---|---|
| `id` | `char(26)` primary | no | — |
| `name` | `string(128)` | no | — |
| `actor_type` / `actor_id` | `string(255)` / `string(64)` | yes | — |
| `tenant_id` | `string(64)` | yes | — |
| `started_at` | `dateTime(6)` | no | — |
| `finished_at` | `dateTime(6)` | yes | — |
| `audits_count` | `unsignedInteger` | no | `0` |
| `metadata` | `jsonb` | yes | — |

Indexes: `(name, started_at)`, `(started_at)`, `(tenant_id, started_at)`.

This is the one table the package **updates**: the header is inserted when the scope opens — an operation that died halfway has to be findable — and completed when it closes. Its `id` is the `transaction_id` the entries carry, so no join table exists.

### `sentinel_checkpoints`

| Column | Declared as | Null |
|---|---|---|
| `id` | `char(26)` primary | no |
| `stream` | `string(64)` | no |
| `sequence_from` / `sequence_to` | `unsignedBigInteger` | no |
| `root_hash` | `char(64)` | no |
| `algorithm` | `string(32)` | no |
| `signature` | `text` | yes |
| `key_id` | `string(64)` | yes |
| `created_at` | `dateTime(6)` | no |

Indexes: `unique(stream, sequence_from)` — the last arbiter of the race that decides where an anchor starts — and `index(stream, sequence_to)`.

There is no column for the anchor before it. The ranges are contiguous windows, so the previous anchor is the row whose `sequence_to` is this one's `sequence_from` minus one.

### `sentinel_archives`

| Column | Declared as | Null |
|---|---|---|
| `id` | `char(26)` primary | no |
| `stream` | `string(64)` | no |
| `sequence_from` / `sequence_to` | `unsignedBigInteger` | no |
| `records` | `unsignedInteger` | no |
| `disk` | `string(64)` | yes |
| `path` | `string(512)` | yes |
| `checksum` | `string(160)` | yes |
| `compressed` | `string(32)` | yes |
| `created_at` | `dateTime(6)` | no |

Indexes: `(stream, sequence_from)` and `(stream, sequence_to)`. **Neither is unique**, deliberately: registering a range twice is legal, because a run interrupted between writing a batch and removing its rows finishes on the next pass, and a rehydrated range can be retired again.

The four cold columns are nullable because a range can leave without being written anywhere. `compressed` names the codec — `gzip` is the only one `Enums\ArchiveCodec` declares — rather than answering yes or no, because a boolean cannot say what to inflate a batch written two years ago with.

### `sentinel_access_log`

| Column | Declared as | Null |
|---|---|---|
| `id` | `char(26)` primary | no |
| `audit_id` | `char(26)` | no |
| `actor_type` / `actor_id` | `string(255)` / `string(64)` | yes |
| `tenant_id` | `string(64)` | yes |
| `query` | `jsonb` | no |
| `results` | `unsignedInteger` | no |
| `context` | `jsonb` | no |
| `created_at` | `dateTime(6)` | no |

Indexes: `(actor_type, actor_id, created_at)` — what has this person been reading — and `(audit_id)`.

`query` records the **shape** of the question, read off the query object's published properties, not a rendered SQL string: the ledger that answered may not have been a database.

> ⚠️ **Warning.** Nothing in the package ever deletes from `sentinel_access_log`. `sentinel:prune` removes entries, labels, relation lines and orphaned headers, and touches nothing else. In compliance mode this table grows one row per trail read, for as long as the installation runs.

---

## Relationships and the missing foreign keys

```
sentinel_audits ──1:N── sentinel_audit_tags        (audit_id)
                ──1:N── sentinel_audit_relations   (audit_id)
                ──N:1── sentinel_transactions      (transaction_id → transactions.id)
                ──lookup── sentinel_access_log     (access_log.audit_id → audits.id)

sentinel_checkpoints ── (stream, sequence_from..sequence_to) over sentinel_audits
sentinel_archives    ── (stream, sequence_from..sequence_to) over ranges that have left
```

**There is not one foreign key anywhere in this schema.** Not from labels, not from relation lines, not from the access log, not from the entries to the transaction header. That is a decision, and it has three reasons:

1. **Date partitioning.** A foreign key referencing a partitioned table is either impossible or expensive on both MySQL and PostgreSQL, and the whole partitioning story exists so that retiring a month is a catalogue operation.
2. **Batched pruning.** `Retention\Cascade` deletes labels, relation lines and entries by sequence range inside one transaction per slice, in that order. A cascade would make the engine repeat that work row by row, and would make an interrupted run leave rows nothing surviving could name.
3. **A row can outlive what it points at.** An `sentinel_access_log` row is still a truthful record of a read after the entry that proves it has been pruned. `AuditAccess::audit()` is therefore a `find()` returning `?Audit`, never an Eloquent relation.

The consequence is yours to hold: nothing at the database level stops a label from outliving its entry. The schema tests assert that on purpose (`it('keeps a label whose entry no longer exists')`). The prune is what cleans up, and it is the only thing that does.

> ⚠️ **Warning.** `AuditTag` and `AuditRelation` declare `audit_id` as their primary key, and it is **not** unique. Eloquent builds `save()` and `delete()` from the declared key, so `AuditTag::query()->find($id)->delete()` deletes *every* label of that entry, and a `save()` updates every row sharing the `audit_id`. Read them through `$audit->tags` / `$audit->relations`; write them through the ledger.

---

## Shortcuts, projections and evidence

Only one table is evidence. Knowing which of the other six can be rebuilt, and from what, is what tells you which ones a backup must not miss.

| Table | Status | Rebuildable from |
|---|---|---|
| `sentinel_audits` | **Evidence** — hashed and chained | Nothing |
| `sentinel_audit_relations` | Index over evidence | The entry's `changes`, which the chain seals |
| `sentinel_audit_tags` | Index, and the **only** copy | Nothing — labels are outside `canonical(core)`, so no hash covers them |
| `sentinel_transactions` | Mutable header, not evidence | Nothing, but it holds no fact the entries do not |
| `sentinel_access_log` | Mutable projection, not evidence | The entries with `audit_type = 'access'`, which are chained |
| `sentinel_checkpoints` | **Changes status** — see below | The entries, until they leave |
| `sentinel_archives` | **Changes status** — see below | Nothing, once a range has gone |

### The two that stop being shortcuts

**`sentinel_checkpoints`.** While the range an anchor covers is still in `sentinel_audits`, the anchor is a shortcut and nothing more: the root is derivable from the entries again, and a stream with no anchors verifies exactly the same way, only by reading all of it. **From the first retirement onward it is the evidence, and the only evidence.** Verification refuses an absence in the chain unless the manifest says the range left *and* the anchors reach past it — and it needs both, because the manifest is unsigned. Without the anchors, "delete the rows, then write one manifest row" would be a supported way of laundering a gap.

**`sentinel_archives`.** Empty and irrelevant until the first prune. **From the first retirement onward it is the map**, and unlike an anchor it cannot be derived again, because the entries it accounts for are precisely the ones that are no longer there. Losing it loses the map, not a shortcut.

> 🔒 **Security.** There is exactly one writer of `sentinel_archives`, and it is `Retention\Pruner`. A row there is read as licence by the verification when it meets an absence, so a second writer disarms that guard for every range it touches. Never insert into it yourself — not from a cold-storage job, not from an import.

---

## Types the grammar emits, per engine

The migrations declare Blueprint types; each engine's grammar decides the SQL. `tests/Database/AuditsTableTest.php` asserts the two that matter against the live catalogue.

| Blueprint call | PostgreSQL 16 | MySQL 9 | SQLite |
|---|---|---|---|
| `char('id', 26)` | `char(26)` | `char(26)` | `varchar` |
| `string('stream', 64)` | `varchar(64)` | `varchar(64)` | `varchar` |
| `text('signature')` | `text` | `text` | `text` |
| `unsignedBigInteger('sequence')` | `bigint` | `bigint unsigned` | `integer` |
| `unsignedInteger('version')` | `integer` | `int unsigned` | `integer` |
| `unsignedSmallInteger('payload_version')` | `smallint` | `smallint unsigned` | `integer` |
| `jsonb('context')` | `jsonb` | `json` | `text` |
| `json('pivot_before')` | `json` | `json` | `text` |
| `dateTime('occurred_at', 6)` | `timestamp(6) without time zone` | `datetime(6)` | `datetime` |

> 🐘 **Engine.** SQLite has no length limits and no unsigned integers, so every `char`/`string` becomes `varchar` and every integer becomes `integer`. It also declares plain `datetime` with no precision — microseconds survive anyway, because the models override `getDateFormat()` to `'Y-m-d H:i:s.u'` and SQLite stores the text as written.

> 🐘 **Engine.** PostgreSQL has no unsigned integers. `sequence` is a signed `bigint` there; the ceiling is 2^63−1 rather than 2^64−1, which no trail will reach.

> ⚠️ **Warning.** Neither MySQL `json` nor PostgreSQL `jsonb` preserves the key order you wrote. Values round-trip intact; order does not. That is the whole argument for canonicalising before hashing, and it is why `Audit::toArray()` re-orders what the package wrote and hands back untouched what it did not. If something you build depends on key order, canonicalise it yourself.

---

## What is deliberately absent

| Absent | Why | What you do instead |
|---|---|---|
| `updated_at` on `sentinel_audits` | The table is append-only. `Audit::UPDATED_AT = null`, and `updating` throws `ImmutableAuditException`. | Nothing updates an entry. A restoration writes a *new* entry pointing back through `source_audit_id`. |
| `deleted_at` anywhere | A soft delete is an update, and there are none. | A prune removes rows and records the range in `sentinel_archives`. |
| Any foreign key | Partitioning and batched pruning both live badly with a cascade. | `Retention\Cascade` deletes by range across the three tables in one transaction per slice. |
| Any auto-increment or database sequence | Identifiers have to survive a distributed environment and an export. | Every id the package mints is a ULID in `char(26)`; `sequence` is assigned by the ledger under a per-stream lock, not by the engine. |
| A maintained row total | Counting the rows a filter matches on a table that only grows is the one question in the read API whose cost is unbounded and that no index answers. | `AuditQuery` has no `total()`. A page asks for one row more than it hands back; that is `hasMore`. |
| A date or subject axis on `sentinel_archives` | Declared debt. | The partition guard asks "does this partition still hold rows", not "is this range archived". |
| A `request_id` filter | The column and its index exist; no `Enums\Filter` case does. | Query the column directly through the model. |

`sentinel_transactions.audits_count` is the one counter in the schema, and it is **not** decremented by a prune: it records what the operation captured when its scope closed, and a prune does not change what happened.

---

## Publishable alternatives

Four migrations ship unloaded, behind `vendor:publish` tags. The provider decides **per file** whether to load its own copy: `Support\PackageMigrations` strips the `YYYY_MM_DD_HHMMSS_` prefix and asks whether a file of that remaining name exists in `database/migrations`.

| Tag | What it does |
|---|---|
| `sentinel-migrations` | Copies all eight package migrations into the application. Optional — the package loads its own otherwise. |
| `sentinel-json-indexes` | **Adds** an index behind `whereIp()` and `whereRoute()`. Reversible. |
| `sentinel-partitioned-pgsql-range` | **Replaces** the base audits migration with a monthly `RANGE` partitioned table on PostgreSQL. |
| `sentinel-partitioned-pgsql-tenant` | **Replaces** it with a `LIST (tenant_id)` partitioned table on PostgreSQL. |
| `sentinel-partitioned-mysql-range` | **Replaces** it with `RANGE (TO_DAYS(created_at))` on MySQL. |

> ⚠️ **Warning.** The three partitioned stubs do not add a migration — they *replace* the base one, silently, by file name. The published file is called `…_create_sentinel_audits_table.php`, exactly what the package's own is called, so the package stops offering its own. Publish more than one and you keep whichever landed last. Rename a published copy and both run.

### What the JSON-index stub actually creates

The expression is not written in the stub. It comes from `Ledger\ContextPredicate::expression()` — the same object the driver asks when it compiles the filter, so an edit cannot leave the index serving a reading nobody issues.

| Engine | What lands |
|---|---|
| PostgreSQL | `create index … on … ((context->>'ip'))` — doubled parentheses, because PostgreSQL reads a single pair as a column list |
| SQLite | The same shape over `json_extract(case when json_valid(context) then context else '{}' end, '$.ip')` |
| MySQL | Two **real columns**, `context_ip` and `context_route`, `varchar(255)`, `VIRTUAL` and `INVISIBLE`, each with a hand-named index |

`VIRTUAL` because `STORED` would rewrite the table and widen every row. `INVISIBLE` because a generated column answering `select *` would ride along in the attributes of a re-read entry, and the next insert of that entry — a fanout, a rehydration — would be handing MySQL a value for a column it computes itself, which it refuses.

### What partitioning costs the schema

Both MySQL and PostgreSQL require every unique key of a partitioned table to carry the partitioning column. That is exactly where the chain's two uniques live.

| Flat table | Under a range stub | Consequence |
|---|---|---|
| `unique(stream, sequence)` | `unique(stream, sequence, created_at)` | The engine now rejects only a repeat that also matches `created_at`. A duplicate `(stream, sequence)` at a different instant is accepted. |
| `unique(capture_id)` | `unique(capture_id, created_at)` | Retry idempotency loses its database backstop. `Deduplicates::settled()` — a plain read — still runs in front of the retry. |
| `primary(id)` | `primary(id, created_at)` | — |

What still holds the chain is the ledger's own sequence assignment and `sentinel:verify`, which re-derives every hash. `tests/Database/PartitionedTrailTest.php` plants exactly that duplicate and asserts the verification catches it.

The `pgsql-tenant` stub is the one division that gives up nothing — but only because it gives up the primary key instead. It declares **no primary key at all**, just `index('id')`, and puts the two unique indexes *local to each partition*. A primary key would have to carry `tenant_id`, PostgreSQL promotes every primary-key column to `NOT NULL`, and an entry recorded with no tenant — a command, a queue worker, the scheduler — would stop being writable. Filling `tenant_id` with a placeholder is worse: it is inside `canonical(core)`, so an empty string where the hash was sealed over `null` makes the entry fail its own verification.

> ⚠️ **Warning.** Every partition you add by hand to a `pgsql-tenant` table needs its two unique indexes, or that tenant loses the guarantee the stub exists to keep. `sentinel:partitions` will not create them — it maintains date ranges, not tenant lists. See [Partitioning](../10-database-engines/06-partitioning.md).

---

## Migration inventory

Eight migrations ship loaded. They run in this order:

| # | File (timestamp stripped) | Creates / changes |
|---|---|---|
| 1 | `create_sentinel_audits_table` | The forty columns, `primary(id)`, the two uniques, nine indexes |
| 2 | `create_sentinel_audit_tags_table` | Two columns, one unique, one index |
| 3 | `add_occurrence_indexes_to_sentinel_audits_table` | `(occurred_at, id)` and `(subject_type, subject_id, occurred_at, id)` |
| 4 | `create_sentinel_audit_relations_table` | Seven columns, three indexes |
| 5 | `create_sentinel_transactions_table` | Nine columns, one primary, three indexes |
| 6 | `create_sentinel_checkpoints_table` | Nine columns, one primary, one unique, one index |
| 7 | `create_sentinel_archives_table` | Ten columns, one primary, two non-unique indexes |
| 8 | `create_sentinel_access_log_table` | Nine columns, one primary, two indexes |

Four more ship unloaded, in `database/stubs/`: one JSON-index migration and three replacements for #1.

Every migration reads its connection from `Config::connection()` through `getConnection()`, and every one has a `down()` that drops what it created. Migration #3 is the only additive one; its `down()` drops the two indexes and leaves the table.

> 📌 **Note.** No migration in this package `ALTER`s `sentinel_audits` to add a column, and none ever will. Seven columns were born empty for versions that landed much later. If you add a column of your own to that table you own it forever — the package's schema tests assert forty columns and thirteen indexes, and the archive format asserts that a batch line's key set is the entry's column set plus `tags`.

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `ConfigurationException: tables.checkpoints is missing` at runtime, on an installation that worked before an upgrade | You published `config/sentinel.php` before a table existed. The config merge is one level deep, so your `tables` array replaced the package's wholesale — and `Config::table()` has no code-side default. | Copy the whole current `tables` block into your published config. |
| A partitioned table exists but `sentinel_audits` was migrated twice, or the partitioned shape vanished | You renamed the published stub, so `PackageMigrations` no longer matched it and loaded the package's own migration as well. | Keep the published file named `…_create_sentinel_audits_table.php`. |
| `create index` fails, or a partition is created with the wrong name, on a connection that has a `prefix` | The Blueprint-compiled `create table` applies a connection prefix; the raw DDL around it interpolates `Config::table()` and does not. | Use `sentinel.tables.prefix`. Do not set `prefix` on the audit connection. |
| Deleting one `AuditTag` removed every label of that entry | `AuditTag::$primaryKey` is `audit_id`, and it is not unique. Eloquent built the `delete` from it. | Read through `$audit->tags`; write through the ledger. |
| `AuditAccess::create([...])` inserted a row with almost every column null | `AuditAccess` is the one model with no `getGuarded()` override, so Eloquent's default guard drops the attributes. | The package writes it with `forceFill()`. Do the same, or let compliance mode write it. |
| `sentinel:verify` reports a duplicate sequence on a partitioned table | Under a range stub `unique(stream, sequence)` gained `created_at`, so the engine accepted a repeat at a different instant. | This is the verification doing its job. Investigate the writer; the engine is no longer the backstop. |
| `sentinel:partitions` exits `2` (INVALID) against a tenant-partitioned table | The maintainer sees existing partitions, decides the table is divided, and issues a `RANGE` bound against a `LIST` parent. PostgreSQL rejects it. | Do not point the command at a `pgsql-tenant` table. Create tenant partitions by hand, with their two unique indexes. |
| `whereRoute('invoices.show')` returns entries on MySQL that PostgreSQL does not | MySQL's default collation `utf8mb4_0900_ai_ci` is case- and accent-insensitive. | Nothing — `ContextPredicate` already emits a second `collate utf8mb4_bin` clause that decides. The insensitive one is an index-servable superset. |
| A tombstoned entry answers `false` to `verifyIntegrity()` and you expected `true` | The row no longer reproduces the `hash` it carries, which is what that method has always meant. Calling it `true` would rest on `redacted_hash`, a column no signature covers. | Ask `verifyContent()`, which returns `Enums\ContentState` and tells a redaction from tampering. |
| The access log grew to millions of rows | Nothing in the package prunes it. Compliance mode writes one row per trail read. | Prune it yourself, or partition it and schedule `sentinel:partitions --table=access_log`. |

---

## ✅ Best practices

✅ **Do** — rename tables through the config block, keeping every key.

```php
// config/sentinel.php
'tables' => [
    'prefix' => 'audit_',
    'audits' => 'entries',            // -> audit_entries
    'audit_tags' => 'labels',
    'audit_relations' => 'relations',
    'transactions' => 'operations',
    'checkpoints' => 'checkpoints',   // keep every key, even unchanged ones
    'archives' => 'archives',
    'access_log' => 'access_log',
],
```

❌ **Don't** — set a prefix on the audit connection and expect the package to honour it. The partition and index DDL builds its names from `sentinel.tables` alone, so the `create table` lands prefixed and the `create index` beside it does not.

```php
// config/database.php — the package's raw DDL never sees this
'audits' => ['driver' => 'pgsql', 'prefix' => 'audit_', /* … */],
```

---

✅ **Do** — read labels and relation lines through the entry, and eager-load a page of them.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Models\AuditRelation;

$entries = Sentinel::audits()->forTenant('acme')->take(50)->get();
$entries->loadReferences();   // tags + subject + actor: one query per morph type

foreach ($entries as $audit) {
    $audit->relations->map(fn (AuditRelation $line): string => "{$line->operation} {$line->relation}");
}
```

❌ **Don't** — treat a label or a relation line as a standalone model. Its declared key is `audit_id` and it is not unique, so this deletes every label the entry carries.

```php
use ElPandaPe\Sentinel\Models\AuditTag;

AuditTag::query()->find($auditId)?->delete();   // removes ALL labels of that entry
```

---

✅ **Do** — extend the shipped model and name the subclass, so the configured table, connection, casts and immutability guard all come along.

```php
namespace App\Models;

use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Models\Audit as SentinelAudit;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;

final class Audit extends SentinelAudit
{
    #[Scope]
    protected function urgent(Builder $query): void
    {
        $query->where('severity', Severity::Critical->value);
    }
}

// config/sentinel.php
'models' => ['audit' => App\Models\Audit::class, 'transaction' => null],
```

❌ **Don't** — write your own model against the table, or `ALTER` the table to add a column you need. A hand-added column is invisible to the archive format, which asserts that a batch line's key set is the entry's column set plus `tags` — so your column silently fails to survive a retirement.

```php
Schema::table('sentinel_audits', fn (Blueprint $t) => $t->string('department'));  // never
```

---

✅ **Do** — decide about partitioning before the first entry, and publish exactly one stub.

```bash
php artisan vendor:publish --tag=sentinel-partitioned-pgsql-range
php artisan migrate
```

❌ **Don't** — publish two partitioned stubs, or publish one onto a table that already holds entries. Both land under the same file name, so you keep whichever was written last and have no way to tell which; and none of the three converts anything, because turning a large table into a partitioned one is a maintenance window.

```bash
php artisan vendor:publish --tag=sentinel-partitioned-pgsql-range
php artisan vendor:publish --tag=sentinel-partitioned-mysql-range   # overwrote the first
```

---

✅ **Do** — treat `sentinel_checkpoints` as evidence from the first prune onward, and back it up with the entries.

```bash
php artisan sentinel:prune --action=archive --stream=global
php artisan sentinel:verify --stream=global
```

❌ **Don't** — write `sentinel_archives` from anything but the prune. The verification reads a row there as licence for a gap in the chain, so a second writer disarms that guard for every range it touches.

```php
use ElPandaPe\Sentinel\Models\AuditArchive;

AuditArchive::query()->create([...]);   // disarms the absence guard for that range
```

---

✅ **Do** — publish the JSON-index stub only if you actually filter by address or route.

```bash
php artisan vendor:publish --tag=sentinel-json-indexes
php artisan migrate
```

❌ **Don't** — add it "just in case". Both filters answer correctly without it, by scanning; with it, MySQL gains two real columns on `sentinel_audits` and every write pays for maintaining them.

---

**See also:** [Configuration](02-configuration.md) · [Enums](04-enums.md) · [Serialization](08-serialization.md) · [The audit record](../01-concepts/02-the-audit-record.md) · [The hash chain](../07-integrity/01-the-hash-chain.md) · [Canonicalization](../07-integrity/03-canonicalization.md) · [Checkpoints and anchors](../07-integrity/05-checkpoints-and-anchors.md) · [Indexes and JSON](../10-database-engines/05-indexes-and-json.md) · [Partitioning](../10-database-engines/06-partitioning.md) · [A database of its own](../10-database-engines/07-a-database-of-its-own.md) · [Retention and pruning](../08-lifecycle/01-retention-and-pruning.md) · [Filters reference](../06-reading/02-filters-reference.md)
