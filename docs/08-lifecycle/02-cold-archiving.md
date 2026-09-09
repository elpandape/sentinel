# ♻️ Cold archiving

> How a released range of the chain leaves the hot table as an NDJSON batch on a `Storage` disk, what
> the package proves before it removes a single row, and why the archive must never be the ledger you
> write to.

**On this page:** [What a batch is](#what-a-batch-is) · [The order](#the-order-nothing-goes-until-the-batch-reads-back) · [What a batch holds](#what-a-batch-holds) · [The path](#the-path-layout) · [The codec](#the-codec-is-a-name-not-a-flag) · [The manifest](#the-manifest-one-writer-one-row-per-range) · [A destination, not a ledger](#a-destination-not-a-hot-ledger) · [Choosing a disk](#choosing-a-disk) · [Losing the manifest](#losing-sentinel_archives) · [⚠️ Pitfalls](#️-pitfalls) · [✅ Best practices](#-best-practices)

---

## What a batch is

A **batch** is one anchored window of one stream, written out as newline-separated JSON to any disk
`Illuminate\Contracts\Filesystem\Factory` can reach, and then proved to be there before anything is
removed from `sentinel_audits`.

Archiving is not a separate command. It is what `sentinel:prune` does by default:

```bash
php artisan sentinel:prune                       # --action=archive is the default
php artisan sentinel:prune --action=archive --stream=tenant:acme
```

`--action=delete` removes the same window and writes it nowhere. The default is the action that
loses nothing, so forgetting the flag cannot lose content. Which windows a run is allowed to touch,
and why a policy may free nothing at all, is [retention and pruning](01-retention-and-pruning.md) —
this page is only about where the bytes go.

The whole configuration is four keys:

| Key | Default | What it does | When to change it |
|---|---|---|---|
| `ledger.ledgers.archive.disk` | `env('SENTINEL_ARCHIVE_DISK', 'local')` | The `Storage` disk a **new** batch is written to. A batch is always read back from the disk **its own manifest row names**, never from this one. | Point it at real cold storage. Moving it later leaves every old batch readable. |
| `ledger.ledgers.archive.path` | `'sentinel'` | Root prefix on that disk, trimmed of slashes. | To share a bucket with something else. |
| `ledger.ledgers.archive.codec` | `'gzip'` | How the bytes are written: `'gzip'` (needs `ext-zlib`) or `null` / `''` for plain NDJSON. Recorded per batch. | Set `null` when `ext-zlib` is absent or the storage compresses for you. |
| `ledger.ledgers.archive.batch` | `1000` | How many entries the **driver** accumulates before sealing a file. It does **not** bound what the prune writes. | Only when using `archive` as a fanout destination. |

```php
// config/sentinel.php
'ledger' => [
    'default'  => 'database',
    'ledgers'  => [
        'archive' => [
            'disk'  => env('SENTINEL_ARCHIVE_DISK', 'audit-cold'),
            'path'  => 'sentinel',
            'codec' => 'gzip',
            'batch' => 1000,
        ],
    ],
],
```

> 📌 **Note.** The prune writes **one file per anchor window**, sized by
> `integrity.checkpoints.every`. `archive.batch` governs only the driver used as a fanout
> destination. The two numbers are unrelated and the default of both being `1000` is a coincidence
> of defaults, not a coupling.

---

## The order: nothing goes until the batch reads back

`Archive\BatchWriter::write()` runs four steps, in this order and never another. Only a batch that
survives all four is handed back, and only then may the prune record it and remove a row.

| # | Step | What is checked | If it fails |
|---|---|---|---|
| 1 | Build every line, compress, and digest the bytes **about to be written** | — | — |
| 2 | `put()` the bytes on the disk | The disk did not return `false` | `ArchiveException::refused` — *"Nothing was removed."* |
| 3 | `get()` them straight back and digest again | The bytes that came back are the bytes that went out | `ArchiveException::unreadable` or `::corrupt` — *"Nothing was removed."* |
| 4 | Rebuild **every entry** out of what came back and rehash it | Each entry still reproduces the hash it is entitled to | `ArchiveException::unverifiable` — *"nothing was removed."* |

Steps 2 and 3 exist because `put()` returning true is the only thing the Filesystem contract
promises, and it does not promise the bytes landed. Reading them back and re-digesting is the only
proof that contract offers.

### Why step 4 exists

Step 4 is the one that looks redundant and is not. Step 3 already proves the file on the disk holds
the exact bytes that were written — so what could have changed?

The shape of the data, on the way in. Sentinel's canonicalizer sorts keys and dispatches on
`array_is_list()`. A PHP map whose keys happen to be exactly `{0 … n-1}` **out of order** — say
`['metadata' => [1 => 'b', 0 => 'a']]` — is not a list, so it is canonicalized as a JSON **object**;
`json_decode()` on the way back turns `{"0":"a","1":"b"}` into a **list**. The database round trip
preserves the distinction. A JSON file does not.

Without step 4 that entry is archived, pruned, and discovered years later to be unrestorable, when
there is nothing left to do about it. With step 4 the failure costs a batch nobody keeps:

```
The entry at sequence 4711 does not reproduce its own hash when read back out of
[sentinel/tenant-acme-9f2c1d3a/00000000000000004001-00000000000000005000.ndjson.gz],
so the batch was not accepted and nothing was removed.
```

The entry stays exactly where it is, in the hot table, and the run stops. See
[canonicalization](../07-integrity/03-canonicalization.md) for what the rule is and why it is frozen.

> 📌 **Note.** A redacted entry is archived, not refused. `Integrity\Content::holds()` asks whether an
> entry reproduces the hash it is *entitled* to — the original one, or `redacted_hash` once a
> tombstone has been written over it. See [redaction and tombstones](04-redaction-and-tombstones.md).

### And then, in this order

Only after those four steps does `Retention\Pruner` record the range in `sentinel_archives`, and only
after that does `Retention\Cascade` remove a row. An interruption anywhere in between leaves a file
nobody points at, or a range recorded as retired whose entries are still there — garbage the next run
finishes, never evidence loss. That asymmetry is deliberate: recording twice costs one row the schema
declares legal; recording nothing loses the record of a deletion.

---

## What a batch holds

Three kinds of line, each naming its own kind so a reader never infers it from position:

| Line | `kind` | Count | Fields |
|---|---|---|---|
| Header | `batch` | Exactly one, first | `format`, `stream`, `sequence_from`, `sequence_to`, `records`, `written_at` (ISO-8601 with microseconds) |
| Entry | `entry` | One per entry | The entry's forty columns — the 27 of `CanonicalPayload::COLUMNS`, the 8 the chain seals around them, the 5 the row needs to exist again — plus `tags` |
| Operation | `operation` | One per distinct `transaction_id` the window touches | `id`, `name`, `actor_type`, `actor_id`, `tenant_id`, `started_at`, `finished_at`, `audits_count`, `metadata` |

The rule for an entry line is one sentence: **a line's key set is an entry's column set plus
`tags`.** A test asserts it against the live schema, so a column added to `sentinel_audits` later is
a loud failure and never a silent loss on the way out.

Operation lines exist because no column of an entry holds an operation's *name*, and the prune
removes a transaction header once its last entry is gone. Without them, archiving would save the
entries of a business transaction and destroy what it was called.

### What a batch does not hold

- **`sentinel_audit_relations` rows.** The relationship projection is re-derived from `changes` when
  an entry is written back, and `changes` is inside the sealed payload. Storing the projection would
  be storing a second copy of something the chain already covers.
- **Anchors.** `sentinel_checkpoints` rows stay in the database, and they must: after the first
  prune, the anchor is the only thing standing behind entries that are gone.
- **Its own manifest row.** `disk`, `path`, `checksum` and the codec exist only in
  `sentinel_archives`.
- **Anything from another stream.** One batch is one stream and one contiguous range.
- **Plaintext for protected fields.** The entry model does not decrypt on attribute access, so a
  field encrypted at rest is written to the batch as ciphertext, with the `encryption` column naming
  the key that wrote it. See [encryption and the keyring](../05-pipeline-and-security/03-encryption-and-the-keyring.md).
- **Anything else in the clear, though.** `before`, `after`, `changes`, `context` and `metadata` go
  into the file exactly as the database holds them.

> 🔒 **Security.** A batch is the audit trail in a file. Give the archive a private disk. If a public
> disk is configured, every value the pipeline did not mask, hash or encrypt is served over HTTP under
> a path anyone can guess from a stream name.

---

## The path layout

```
<path>/<stream slug>-<first 8 hex of sha256(stream)>/<from>-<to>.ndjson[.gz]
```

```
sentinel/tenant-acme-9f2c1d3a/00000000000000005001-00000000000000006000.ndjson.gz
```

- The stream is slugged (`[^A-Za-z0-9._-]` → `-`) because a stream name is any string up to 64
  characters and a closure resolver can return one full of slashes or dots. The slug is a traversal
  guard.
- The slug alone is not enough in the other direction either — `tenant:acme` and `tenant-acme` slug
  alike — so eight hex characters of the digest of the *original* name disambiguate the directory.
- Both sequence ends are zero-padded to twenty characters, the width of the column they live in, so a
  plain listing of the disk sorts in chain order.
- The whole key is capped at 512 characters, the width of `sentinel_archives.path`. A prefix long
  enough to blow the cap raises `ConfigurationException::archivePathTooLong` **before** anything is
  written.

The path is a **pure function** of `(root, stream, from, to, codec)` and of nothing else. That is
what makes an interrupted run resumable: a pass that landed the object and died before the manifest
heard about it rewrites the same key next time, rather than leaving an orphan nobody can find. It is
also what a versioned bucket undoes — see the pitfalls.

---

## The codec is a name, not a flag

`sentinel_archives.compressed` stores `'gzip'` or `NULL`, never a boolean. A boolean cannot say what
to inflate a batch written two years ago with; a name can. It is the same reason an anchor records
`fold-sha256` rather than a flag.

The consequence is the useful one: **mixed-codec archives read back fine.** Switch `codec` from
`'gzip'` to `null` today and every batch written before today still inflates, because the reader
takes the codec from the batch's own row and not from the configuration.

`Enums\ArchiveCodec` has one case, `Gzip`. Gzip is the only codec core PHP offers without a package —
bz2 and zip are no more enabled by default, and zstd and brotli are extensions. `ext-zlib` is a
composer **`suggest`**, not a `require`.

> ⚠️ **Warning.** There is no guard for a missing `ext-zlib`. With `codec => 'gzip'` and the
> extension absent, the failure is PHP's `Call to undefined function gzencode()` out of a scheduled
> prune, not a readable configuration error. Set `codec => null` when you cannot install it; plain
> NDJSON needs nothing.

An unknown codec name is refused at config-read time, and so is the old key: `archive.compress` was
renamed to `archive.codec`, and a published config file still carrying `compress` throws
`ConfigurationException::renamedArchiveCodec` rather than being ignored — because Laravel's config
merge is one level deep and the shallow merge would otherwise write every batch in the clear, in
silence.

---

## The manifest: one writer, one row per range

`sentinel_archives` is the map of what left the hot table. One row per range:

| Column | Type | Meaning |
|---|---|---|
| `id` | ULID | — |
| `stream` | `string(64)` | Which chain |
| `sequence_from`, `sequence_to` | `unsignedBigInteger`, cast to `int` | The range, inclusive |
| `records` | `unsignedInteger` | How many entries it held |
| `disk` | `string(64)`, nullable | Where the batch went |
| `path` | `string(512)`, nullable | The object key |
| `checksum` | `string(160)`, nullable | `sha256:<hex>` over the **exact bytes written**, compression included |
| `compressed` | `string(32)`, nullable | The codec name |
| `created_at` | `datetime(6)` | `UPDATED_AT` is `null`; there is no updated timestamp |

**All four cold columns null means the range was deleted and written nowhere** — a truthful record of
a deletion, and what `--action=delete` leaves.

```php
use ElPandaPe\Sentinel\Models\AuditArchive;

$row = AuditArchive::query()
    ->where('stream', 'tenant:acme')
    ->where('sequence_from', '<=', 5200)
    ->where('sequence_to', '>=', 5200)
    ->first();

if ($row === null) {
    // Nothing accounts for sequence 5200. sentinel:verify reports a sequence_gap, correctly.
}

$row?->records;    // 1000
$row?->disk;       // 'audit-cold'   — null on all four means retired, not archived
$row?->path;       // 'sentinel/tenant-acme-9f2c1d3a/…-….ndjson.gz'
$row?->checksum;   // 'sha256:…'
$row?->compressed; // 'gzip' or null
```

Verify a downloaded object against the hex after the colon:

```bash
sha256sum 00000000000000005001-00000000000000006000.ndjson.gz
```

### It has exactly one writer

The prune — `Retention\Pruner` — is the only thing in the package that writes to `sentinel_archives`.
A cold fanout destination writes batches to the disk and **never indexes them**, deliberately: a row
means *"this range left the hot table"*, and a row for a range that is still hot would disarm two
guards at once. The prune's tamper guard reads a row as licence to excuse a root it cannot recompute,
and [verification](../07-integrity/06-verification.md) reads one as part of what lets it step over an
absence.

Re-archiving the same range updates the existing row in place rather than adding a second one. Two
rows for one range would be two answers to the same question with no tiebreak, and a restore would
take the range out of both files.

> 📌 **Note.** A manifest row **explains** an absence; an anchor **proves** it. Verification steps
> over a gap only when both hold. Nothing in this table is hashed or signed, so on its own it would
> make *"delete the rows, then insert one row"* a supported way of laundering a gap. See
> [checkpoints and anchors](../07-integrity/05-checkpoints-and-anchors.md).

---

## A destination, not a hot ledger

`archive` is also available as a ledger driver, and it is a **destination**: something entries are
copied to, never the thing that numbers them.

**It refuses to be `ledger.default`.** The `Ledger` binding is `scoped`, so the refusal lands the
first time anything resolves it — the first capture, or the first command that asks for a ledger:

```
Sentinel cannot use the archive driver as [sentinel.ledger.default]. It keeps the tail of a
stream on the instance, because the manifest holds no hash to recover one from, so a second
process would start a second chain under the same name. Name it as a fanout destination
instead, or let sentinel:prune write to it.
```

That is not a policy, it is arithmetic. A ledger has to read the tail of a stream — the last sequence
and the last hash — before it can build the next link. `sentinel_archives` holds neither, so the
driver keeps the tail in a property of a **scoped** instance. A second worker, a second request, a
second process resolves a fresh instance with an empty tail and starts the chain at sequence 1 again.

The correct shape is a fanout with `database` first:

```php
// config/sentinel.php
'ledger' => [
    'default' => 'fanout',
    'ledgers' => [
        'fanout' => [
            'destinations' => ['database', 'archive'],  // database FIRST — it is the primary
            'on_failure'   => 'strict',
        ],
    ],
],
```

The first destination is the primary: it assigns the sequence and seals the hash, and the rest are
handed what it sealed. See [fanout](../11-extending/05-fanout.md).

> ⚠️ **Warning.** The refusal covers `ledger.default` and **only** `ledger.default`. Putting
> `archive` **first** in `fanout.destinations` makes it the primary and is not refused — and it is
> exactly the second-chain problem the guard exists to prevent. Put `database` first.

Three more things the driver does not do:

- **It does not implement `Contracts\Deduplicates`.** Answering "has this capture already settled?"
  would be a scan with no index behind it, and the contract's rule is that a driver which cannot
  answer reliably must not claim to. A ledger without it cannot deduplicate a retried write.
- **Its `find()` and `query()` are scans of the batches this instance wrote.** The driver is bound
  `scoped`, so a fresh process finds nothing at all. It is not a query backend; reading the trail
  goes through the [Query API](../06-reading/01-the-query-api.md) against the hot table.
- **It never writes to `sentinel_archives`.** Which means batches it wrote are invisible to
  `Archive\Rehydrator`, whose only way in is the manifest. A cold fanout copy is a copy; it is not a
  restorable archive. Use it as a second pair of eyes, not as the thing you plan to restore from.

An open batch is sealed when it fills (`archive.batch`), when a read is asked of the driver, when
`seal()` is called, on `Application::terminating`, and on `WorkerStopping`. A process killed outside
those hooks loses whatever it was still holding.

---

## Choosing a disk

The archive speaks to nothing but `Illuminate\Contracts\Filesystem\Factory`. **No cloud backend is a
dependency of this package** — `composer.json` requires `php`, `composer-runtime-api`,
`illuminate/*`, `ext-mbstring` and `ext-openssl`, and nothing else. S3, R2 and MinIO work because
Laravel's filesystem does; configure the disk in `config/filesystems.php` with whatever Flysystem
adapter your application already installs, and Sentinel never learns any of them exist.

Two operational facts about that disk:

**The package never deletes from it.** The only two operations anywhere in the source are `get` and
`put`. Object lifecycle — expiry, transition to a colder class, versioning, object-lock — is entirely
yours, and it is the right place for it: a storage lifecycle rule is a policy your provider enforces
and Sentinel cannot undo.

**Versioning and object-lock cut both ways.** They are the strongest tamper resistance available for
a batch, because the package cannot rewrite history it cannot delete. They are also exactly what
keeps a **pre-redaction** copy of a batch alive after the rehydrate → redact → re-archive round trip,
since the new object lands on the same key. If you must be able to prove content is gone, prune the
old object versions at the storage level as a separate, deliberate step.

> 🐘 **Engine.** `sequence_from` and `sequence_to` come back from PDO as `int` on SQLite and as
> `string` on MySQL and PostgreSQL. `AuditArchive` casts both to `integer`, so the arithmetic that
> crosses an absence means the same thing on all three. Anything you write against these columns
> yourself should do the same.

---

## Losing `sentinel_archives`

This is the one table in the package that **cannot be derived again.**

An anchor can be thrown away and recomputed from the entries, because the entries are still there. A
manifest row accounts for entries that are **not** there. Drop the table and you lose, per range:

| What goes | What it costs |
|---|---|
| `disk`, `path`, `compressed` | Nothing can find or inflate the batch through the package. `Rehydrator::restore()` returns `batches: 0` for a range whose files are sitting on the disk. |
| `checksum` | No way to tell a batch that changed from one that did not. |
| The row itself | `sentinel:verify` reports every archived range as a `sequence_gap` — correctly, because nothing explains the absence any more. |

Back it up with `sentinel_audits`, in the same dump and on the same schedule. It is small — one row
per window — and it is the difference between a prune and an unexplained hole.

A batch's own header line does carry `stream`, `sequence_from`, `sequence_to` and `records`, so a
reconstruction by hand is possible in principle by reading every file on the disk. **Nothing in the
package does it**, and until the rows exist the verifier is right to refuse the gap.

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `ConfigurationException` the first time the ledger is resolved: *"cannot use the archive driver as [sentinel.ledger.default]"* | `ledger.default => 'archive'` | Name it as a **non-first** fanout destination, or leave it to `sentinel:prune` |
| Sequences restart at 1 in every worker; duplicate `(stream, sequence)` collisions | `archive` placed **first** in `fanout.destinations`, making it the primary. Its stream tail lives on a scoped instance and the `ledger.default` guard does not cover this | Put `database` first |
| `ConfigurationException` on the first archive write: *"[…archive.compress] became [codec]"* | A published `config/sentinel.php` still carrying the pre-rename key; the config merge is one level deep, so the old key would silently mean "write in the clear" | Replace with `codec => 'gzip'` or `codec => null` |
| `ConfigurationException`: *"longer than the 512 characters the manifest holds"* | `archive.path` prefix pushes the object key past the width of the `path` column | Shorten the prefix; a row pointing at a truncated path points at nothing |
| `Error: Call to undefined function gzencode()` out of a scheduled prune | `codec => 'gzip'` without `ext-zlib`, which is a `suggest` and has no readable guard | Install `ext-zlib`, or set `codec => null` |
| `ArchiveException`: *"does not reproduce its own hash when read back"*, and the prune stops | An entry whose JSON map keys are exactly `{0…n-1}` out of order: written as an object, read back as a list | Nothing was removed. The entry is the problem — see [canonicalization](../07-integrity/03-canonicalization.md) |
| `ArchiveException`: *"did not come back as the bytes that were written"* | The disk handed back different bytes than were `put` — a rewriting proxy, an eventually consistent read | Nothing was removed. Fix the disk and run again |
| `ArchiveException` on restore: *"is missing sequence N of the range it is recorded as holding"* | The window archived was not a contiguous run starting at `sequence_from`. The writer does not check contiguity; the reader does | Something other than the prune removed rows from inside an anchored window. Never `DELETE` from `sentinel_audits` by hand |
| `Rehydrator::restore()` reports `batches: 0` for a range whose files are on the disk | Those batches were written by a **cold fanout destination**, which never writes a manifest row | Only the prune indexes a batch. A fanout copy is not a restorable archive |
| A batch written months ago still inflates after `codec` was switched to `null` | The codec is recorded per batch and read from the row | Working as designed — mixed-codec archives are supported |
| A versioned bucket still serves unredacted content after a redact-and-re-archive round trip | The object key is a pure function of the range, so the re-archive overwrites and versioning keeps the previous object | Expire old object versions at the storage level, deliberately |
| A prune archived a window but the datafile did not shrink | Nothing in the package runs `OPTIMIZE TABLE` or `VACUUM FULL`; both take locks | Reclaim space in a DBA maintenance window |
| The manifest has no way to answer *"where is everything about this person?"* | `sentinel_archives` is indexed by `(stream, sequence_from)` and `(stream, sequence_to)`, never by subject | An erasure request over one subject is answered range by range |

---

## ✅ Best practices

✅ **Do** — give the archive a private disk you own the lifecycle of, and name it explicitly. A batch
is the audit trail in a file.

```php
// config/sentinel.php
'ledger' => ['ledgers' => ['archive' => [
    'disk'  => env('SENTINEL_ARCHIVE_DISK', 'audit-cold'), // private, versioned, not served
    'path'  => 'sentinel',
    'codec' => 'gzip',
]]],
```

❌ **Don't** — leave it on a disk your application serves over HTTP. Every value the pipeline did not
mask, hash or encrypt is then readable at a path anyone can guess from a stream name.

```php
'ledger' => ['ledgers' => ['archive' => [
    'disk' => 'public', // the trail, on a URL
]]],
```

---

✅ **Do** — let the prune write the archive, and leave `--action` alone. The action that loses nothing
is the one you get for forgetting the flag.

```bash
php artisan sentinel:prune --dry-run   # read the Note column first
php artisan sentinel:prune             # --action=archive
```

❌ **Don't** — schedule `--action=delete` to save storage. It removes the window and writes it
nowhere, and the only thing left is a manifest row with four null columns saying it happened.

```bash
php artisan sentinel:prune --action=delete   # content gone, everywhere, permanently
```

---

✅ **Do** — put `database` first when you fan out to cold storage. The primary is the destination that
assigns the sequence and seals the hash.

```php
'ledger' => [
    'default' => 'fanout',
    'ledgers' => ['fanout' => ['destinations' => ['database', 'archive']]],
],
```

❌ **Don't** — put `archive` first. It is not refused, and it makes a per-instance stream tail the
authority on chain numbering: every fresh process starts a second chain under the same name.

```php
'ledgers' => ['fanout' => ['destinations' => ['archive', 'database']]],
```

---

✅ **Do** — set `codec => null` when you cannot install `ext-zlib`, or when the storage compresses for
you. Plain NDJSON is a first-class option and the manifest records that choice per batch.

```php
'ledger' => ['ledgers' => ['archive' => ['codec' => null]]],
```

❌ **Don't** — leave `codec => 'gzip'` on a host without `ext-zlib` and find out during a nightly
prune. There is no configuration-time guard; the failure is PHP's.

```php
'ledger' => ['ledgers' => ['archive' => ['codec' => 'gzip']]], // ext-zlib absent
```

---

✅ **Do** — back up `sentinel_archives` alongside `sentinel_audits`, in the same dump. It is one row
per window and it is the only map to what is no longer in the table.

```bash
pg_dump --table=sentinel_audits --table=sentinel_archives --table=sentinel_checkpoints app
```

❌ **Don't** — truncate it to "clean up" old rows, or restore a backup that skipped it. Anchors can
be recomputed from the entries; this cannot, because its entries are the ones that left.

```php
AuditArchive::query()->where('created_at', '<', $cutoff)->delete(); // the map, deleted
```

---

✅ **Do** — check a downloaded batch against the checksum in its row before you trust it. The digest
covers the exact bytes written, compression included.

```php
$row = AuditArchive::query()->where('path', $path)->firstOrFail();

[$algorithm, $hex] = explode(':', $row->checksum, 2);   // 'sha256', '9f2c…'
```

❌ **Don't** — take a manifest row as proof that the range was legitimately retired. Nothing in that
table is hashed or signed; it explains an absence, and an anchor is what proves one.

```php
// "There is a row, so the gap is fine." — it is not, on its own.
AuditArchive::query()->where('stream', $stream)->exists();
```

---

**See also:** [Retention and pruning](01-retention-and-pruning.md) · [Rehydration](03-rehydration.md) · [Redaction and tombstones](04-redaction-and-tombstones.md) · [Compliance mode](05-compliance-mode.md) · [Checkpoints and anchors](../07-integrity/05-checkpoints-and-anchors.md) · [Verification](../07-integrity/06-verification.md) · [The shipped drivers](../11-extending/02-shipped-drivers.md) · [Fanout](../11-extending/05-fanout.md) · [Schema](../99-reference/03-schema.md) · [Configuration](../99-reference/02-configuration.md)
