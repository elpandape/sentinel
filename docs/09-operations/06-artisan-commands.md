# ⚙️ Artisan commands

> The eleven `sentinel:*` commands: what each one does, what it refuses to do, which are safe on a
> schedule and which destroy something.

**On this page:** [Exit codes](#the-exit-code-vocabulary) · [The matrix](#the-command-matrix) ·
[How options are read](#how-options-are-read) · [install](#sentinelinstall) · [show](#sentinelshow) ·
[verify](#sentinelverify) · [checkpoint](#sentinelcheckpoint) · [prune](#sentinelprune) ·
[flush](#sentinelflush) · [redact](#sentinelredact) · [rekey](#sentinelrekey) ·
[export](#sentinelexport) · [import](#sentinelimport) · [partitions](#sentinelpartitions) ·
[about](#php-artisan-about) · [Pitfalls](#️-pitfalls) · [Best practices](#-best-practices)

---

Every command is a driver, never the operation. Each resolves one service out of the container —
`Verifier`, `Checkpoints`, `Pruner`, `Redactor`, `Rekeyer`, `Export`, `Maintainer`, `Importer`,
`Flusher`, `AuditQuery` — calls one method on it, renders the result and turns it into an exit code.
Anything a command does is therefore reachable from application code, and no command adds behaviour
of its own.

`SentinelServiceProvider` registers the eleven commands inside `runningInConsole()` and nothing else.
**The package never puts itself on a scheduler**; every cadence here is something your application
writes in `routes/console.php` ([Scheduling](07-scheduling.md)). The command classes are `@internal`
— do not extend or type-hint them. What is frozen is the CLI: the eleven names, their options and
their exit codes ([API stability](../99-reference/09-api-stability.md)).

---

## The exit-code vocabulary

Three codes, one meaning each, the same across all eleven commands:

| Code | Constant | Means | What a cron should do |
|---|---|---|---|
| `0` | `Command::SUCCESS` | The ordinary outcome — **including having found nothing to do** | Nothing |
| `1` | `Command::FAILURE` | A bad finding from a run that actually happened | Wake a human |
| `2` | `Command::INVALID` | A run that could not happen at all | Retry, or page the operator |

Three and not two, because a broken chain and an unreachable database must not look the same to a
watchdog. A trail nobody has signed exits `0`: it is sound, and reporting otherwise would make
`sentinel:verify` useless on every installation that has not switched signing on. Five commands have
**no exit 1 at all** — `install`, `show`, `checkpoint`, `export`, `rekey` — so a watchdog written for
one command's vocabulary does not transfer unexamined to another's.

> ⚠️ **Warning.** The vocabulary is uniform but the *per-command* meaning of `1` is not
> interchangeable. `sentinel:redact` exit `1` is a permanent refusal — retrying will never help —
> while its exit `2` is the retryable one. That is the opposite of the intuition. Full table in
> [Exit codes](../99-reference/07-exit-codes.md).

---

## The command matrix

| Command | Destroys data | Dry run | Resumable | Safe to run twice | Can exit 1 |
|---|---|---|---|---|---|
| `sentinel:install` | no | — | — | yes | no |
| `sentinel:show` | no (read-only) | — | — | yes | no |
| `sentinel:verify` | no (read-only) | — | by `--stream`/`--from`/`--to` | yes | yes |
| `sentinel:checkpoint` | no (appends anchors) | — | interruption-safe | yes | no |
| `sentinel:prune` | **YES — removes rows** | `--dry-run` | by sequence, next run | yes | yes |
| `sentinel:flush` | no | — | re-run after a failure | yes | yes |
| `sentinel:redact` | **YES — destroys content** | `--dry-run` (see below) | one entry per run | yes | yes |
| `sentinel:rekey` | no (appends entries) | `--dry-run` (see below) | `--after` | yes | no |
| `sentinel:export` | no (read-only) | — | no | yes | no |
| `sentinel:import` | no (appends entries) | `--dry-run` | `--after`, or freely | yes | yes |
| `sentinel:partitions` | **YES with `--force`** | `--dry-run` | — | yes | yes |

> 📌 **Note.** A dry run suppresses the **acting**, never the **checking** — but only where the check
> happens before the action. `prune`, `partitions` and `import` can all exit `1` under `--dry-run`;
> `redact` and `rekey` structurally cannot, because both return before any check runs.

---

## How options are read

Four traits carry everything the commands share (`src/Console/Concerns/`). Three of their rules
change what a typo does:

**A numeric option whose value is not numeric is treated as absent, not as zero.**
`ReadsOptions::number()` asks `is_numeric()` first, so `--from=yesterday` on `sentinel:verify` means
*no lower bound* and `--limit=all` on `sentinel:show` means *the documented default of 50*. This is
silent: the run answers a wider question than the one you asked and exits `0`. **One exception** —
`sentinel:prune --batch` bypasses `number()` and casts raw, then `Cascade` clamps with `max(1, …)`,
so `--batch=abc` becomes one entry per `DELETE` statement rather than the configured default.

**A `type:id` option is split on the last colon.** `--subject=` and `--actor=` go through
`ReadsOptions::reference()`, so an identifier may contain a colon. An empty type, an empty id, or no
colon at all is refused rather than guessed at, and the type is taken **verbatim** rather than
resolved to a class — your morph map is what decides what a type means.

**Option help text is English; printed output is translated.** Everything a command prints comes from
`resources/lang/{en,es}/sentinel.php` under `commands.<name minus the "sentinel:" prefix>`, derived
from the command's own registered name in `Console\Concerns\Translates`. Option descriptions stay
English because options are built in the constructor, before the package has loaded its translations.

---

## `sentinel:install`

```bash
php artisan sentinel:install
```

No options. Publishes `config/sentinel.php` if it is not there, then reports which of the seven tables
the configured connection is missing. The copy is made by the command itself rather than handed to
`vendor:publish`, which is precisely why there is no `--force`: a configuration already in place is
left exactly as it was, edits and all, and running the command twice is the ordinary case.

The tables it checks are `audits`, `audit_tags`, `audit_relations`, `transactions`, `checkpoints`,
`archives` and `access_log`, resolved through `Config::table()` so `sentinel.tables.prefix` applies.
The connection is `sentinel.database.connection`, **not** the application default.

**It does not publish the migrations** — the provider loads the package's own until an application
takes a file over. It names the six tags it deliberately left unpublished (`sentinel-lang`,
`sentinel-migrations`, `sentinel-json-indexes`, `sentinel-partitioned-pgsql-range`,
`sentinel-partitioned-pgsql-tenant`, `sentinel-partitioned-mysql-range`) so none is discovered by
accident.

**Prints:** the publish line, then `All 7 tables are present.` or `N of 7 tables are not there yet:
<names>` plus a pointer to `php artisan migrate`, then the optional-tags line.

**Exit codes:** `0` whether it published or found the file already there, and `0` even when tables
are missing — that is the ordinary state between publishing and migrating. `2` only when the schema
could not be read at all, and the config publish is **not** rolled back when that happens.
**Run after:** `php artisan migrate`.

→ [Installation](../02-getting-started/01-installation.md)

---

## `sentinel:show`

```bash
php artisan sentinel:show {audit?} {--subject=} {--limit=50}
```

| Option | Default | What it does |
|---|---|---|
| `audit` (positional) | — | Read out one entry by its id |
| `--subject=` | — | Read out a subject's life instead, as `type:id` |
| `--limit=` | `50` | How many entries of a life at most, newest last |

Two mutually exclusive reads. With a positional id it finds one entry by primary key and prints
`AuditPresenter::entry()`. With `--subject=type:id` it reads that subject's life through the
[Query API](../06-reading/01-the-query-api.md) — `byOccurrence()->for(…)->take(…)->get()` — and prints
`AuditPresenter::timeline()`. Asking for both at once, or for neither, is **refused rather than
resolved**: they are two questions and the answer to one is not the answer to the other.

```bash
php artisan sentinel:show --subject="App\Models\Invoice:01JB…" --limit=20
```

**Exit codes:** `0` when it read an entry or a life out, and `0` when a subject has nothing recorded;
`2` for an unknown entry id, an unreadable `--subject`, both arguments at once, neither, or a life
query that threw. **No exit 1.**

> 🔒 **Security.** Under [compliance mode](../08-lifecycle/05-compliance-mode.md), `--subject=` leaves
> the two access records any Query API read leaves. The positional-id path does **not** — it goes
> through `Audit::find()` because the Query API has no filter on an entry's own id. It is the route
> and not the command that decides.

→ [Presenting and serializing](../06-reading/07-presenting-and-serializing.md)

---

## `sentinel:verify`

```bash
php artisan sentinel:verify {--stream=} {--from=} {--to=} {--depth=entries} {--projections}
```

| Option | Default | What it does |
|---|---|---|
| `--stream=` | every stream | Verify one stream instead of all of them |
| `--from=` | no bound | First sequence; needs `--stream` **and** `--depth=entries` |
| `--to=` | no bound | Last sequence; same two requirements |
| `--depth=` | `entries` | `entries`, `roots` or `anchors` |
| `--projections` | off | After the chain walk and never instead of it, re-check that the relation index still matches what the entries sealed. Opt-in because it reads a second table |

`--depth` is a claim about what was **proved**, not only about cost:

| Depth | Reads | Proves |
|---|---|---|
| `entries` | every entry, rehashed | Everything: content, links, sequence, signatures |
| `roots` | `(sequence, hash)` of each anchored range, folded again | No hash was rewritten or reordered — not that content matches its hash |
| `anchors` | only the anchors | The anchors are a contiguous chain — nothing about the entries under them |

```bash
php artisan sentinel:verify --depth=anchors                              # nightly, cheap
php artisan sentinel:verify                                              # weekly, full rehash
php artisan sentinel:verify --stream="tenant:acme" --from=1 --to=100000  # one range
```

**Prints:** a five-column table (Stream, Entries, Chain, Anchors, Signatures) then one summary line.
The Anchors column and the second signature tally only appear where there is one, so an installation
that has never anchored does not read as one whose anchors failed.
**Exit codes:** `0` for an intact chain — including a trail nobody has signed, and one whose only
finding is deliberate redactions. `1` for a real break: a hash that does not reproduce, a broken
link, a sequence gap nothing accounts for, a signature its own key does not verify, or a divergent
relation index under `--projections`. `2` for `--from`/`--to` without `--stream`, an unknown
`--depth`, a range on a depth that takes none, a ledger that cannot enumerate its streams, or any
thrown exception.

→ [Verification](../07-integrity/06-verification.md) · [The verification playbook](../07-integrity/07-the-verification-playbook.md)

---

## `sentinel:checkpoint`

```bash
php artisan sentinel:checkpoint {--stream=}
```

Anchors every complete window each stream still owes, in order, one transaction per anchor; without
`--stream` it walks every stream the ledger can name. Idempotent and written for a scheduler: a run
with nothing left to anchor emits nothing, says so and exits `0`. Interruption-safe rather than
resumable — an emission cut off halfway leaves anchors contiguous as far as they go, which is exactly
the state the next run carries on from.

> ⚠️ **Warning.** There is **no `--limit`**, and the first run over an existing trail is not like the
> ones after it: it anchors every window every stream owes, which on a trail that predates anchoring
> is all of them, so the first pass reads the whole trail. Run the first one by hand, off the
> schedule, before putting the command on `->hourly()`.

> 📌 **Note.** This command ignores `sentinel.integrity.checkpoints.enabled` completely — that flag
> governs only threshold emission on the write path and the compliance requirement check. It anchors
> either way, using `checkpoints.every` purely as the window size.

**Prints:** a four-column table (Stream, From, To, Root) then `Anchored N ranges.`, or
`Nothing left to anchor: no stream has a complete window the anchors do not already cover.`
**Exit codes:** `0` when it anchored and `0` when there was nothing to anchor; `2` for a run that
could not happen. **No exit 1.**

→ [Checkpoints and anchors](../07-integrity/05-checkpoints-and-anchors.md)

---

## `sentinel:prune` — ☠️ removes rows

```bash
php artisan sentinel:prune {--action=archive} {--stream=} {--batch=} {--dry-run}
```

| Option | Default | What it does |
|---|---|---|
| `--action=` | `archive` | `archive` writes the range out, reads it back and rehashes it before a row goes; `delete` removes it without writing it anywhere |
| `--stream=` | every stream | Prune one stream instead of all of them |
| `--batch=` | `sentinel.prune.batch` | How many entries one `DELETE` removes, for this run only |
| `--dry-run` | off | Count through the same code path and remove nothing |

Archive is the default because the action that loses nothing is the one an operator should get for
forgetting a flag. The unit is the **anchored window**, never the entry: a range leaves only when an
anchor covers it and every entry in it has been released. `sentinel.prune.windows` caps how many
anchored ranges one run examines, which is what makes an interrupted run resume on the next pass —
the prune is named by sequence, not by a cursor.

```bash
php artisan sentinel:prune --dry-run     # rehearse a new policy, read the Note column
php artisan sentinel:prune --batch=250   # the real thing, gentler on a busy table
```

**Prints:** a five-column table (Stream, Ranges, Entries, Rate, Note) then one summary line. The Note
column spells out, in a sentence, which of the four holds stopped a stream — nothing declared, no
anchors, the tail window, or an entry retention still keeps — so "nothing was removed" is never left
for you to guess at.

**Exit codes:** `0` when it removed something **and** `0` when it removed nothing. `1` when a range
no longer folds to the root its anchor recorded: the run stops there and the rows stay. `2` for an
unknown `--action`, a ledger that cannot enumerate streams, or any thrown exception — **including**
the compliance refusal of `--action=delete` over an unarchived range.

**Run before:** `sentinel:checkpoint`. An unanchored stream releases nothing, reports `unanchored`
and exits `0` having removed not one row.

→ [Retention and pruning](../08-lifecycle/01-retention-and-pruning.md) · [Cold archiving](../08-lifecycle/02-cold-archiving.md)

---

## `sentinel:flush`

```bash
php artisan sentinel:flush
```

No options. Settles everything the audit buffer is holding, on demand. It exists for the two cases
the automatic triggers cannot reach: a buffer that stopped receiving entries before either threshold
was met, and a process that died holding some.

`buffer.size` and `buffer.flush_interval` are evaluated **when an entry arrives**, so a quiet buffer
is never evaluated at all. Nothing inside PHP watches a clock between requests, which makes this
command the only ceiling on how long the last few entries wait. Two of these running at once is
safe: taking from the buffer is atomic, and every entry carries a `capture_id` the database will not
accept twice.

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('sentinel:flush')->everyMinute()->withoutOverlapping();
```

**Prints:** one line — `Settled :count entries from the buffer.`

**Exit codes:** `0` on any completed flush, including one that settled nothing. **`1`** when the
flush itself did not settle — deliberately `FAILURE` and not `INVALID`, unlike every other command's
caught throwable, because the run happened: the batch was taken, refused and put back at the head of
the buffer in order, and the answer is to run again, which is what `1` tells a cron. `2` only for a
mode other than `buffered`, the one thing that cannot run at all.

→ [The buffered mode](02-the-buffered-mode.md) · [Performance modes](01-performance-modes.md)

---

## `sentinel:redact` — ☠️ destroys content permanently

```bash
php artisan sentinel:redact {audit} {--reason=} {--actor=} {--dry-run}
```

| Option | Default | What it does |
|---|---|---|
| `audit` (positional) | — | The entry whose contents are to be destroyed |
| `--reason=` | — | **Required.** Why, kept on the entry and on the trail |
| `--actor=` | — | **Required.** Who ordered it, as `type:id` |
| `--dry-run` | off | Say what would be destroyed and destroy nothing |

Empties the entry's `context`, `before`, `after`, `changes`, `metadata` and `criteria` while leaving
its position, hash, link and sequence in place, and writes a **new** entry recording who ordered it
and why. `--reason` and `--actor` are both refused when absent, and that is not ceremony: nothing
resolves an actor in a console process, and the one entry whose whole purpose is to say who destroyed
a record cannot be the one with nobody's name on it.

```bash
php artisan sentinel:redact 01JB… --reason="erasure request 4711" --actor="App\Models\User:91"
```

**Prints:** one line, naming the entry, its sequence and its stream.
**Exit codes:** `0` for a redaction, for a dry run, and for an entry that was **already** redacted —
the service returns the existing tombstone. `1` for a deliberate refusal that will never succeed:
the entry is no longer in the hot table (it was archived, or the range was retired), or it no longer
reproduces its own hash. `2` for a run that could not happen: missing `--reason` or `--actor`, an
`--actor` that is not `type:id`, an unknown entry id, or anything else thrown.

> ⚠️ **Warning.** `--dry-run` here tells you **nothing** about whether the real run would be refused.
> It returns before any check. Only `prune`, `partitions` and `import` have a dry run that can find
> something.

> 💡 **Tip.** When the erasure request arrives through application code, drive `Redactor` directly
> instead: the command cannot hand back the tombstone, cannot take an Eloquent actor and cannot join
> the caller's transaction.

→ [Redaction and tombstones](../08-lifecycle/04-redaction-and-tombstones.md)

---

## `sentinel:rekey`

```bash
php artisan sentinel:rekey {--key=} {--tenant=} {--type=} {--limit=500} {--after=} {--dry-run}
```

| Option | Default | What it does |
|---|---|---|
| `--key=` | the current key id | Which key identifier to re-encrypt under |
| `--tenant=` | all | Only this tenant |
| `--type=` | all | Only this audit type |
| `--limit=` | `500` | How many entries at most |
| `--after=` | — | Resume behind this entry id, as reported by the pass before |
| `--dry-run` | off | Say how many would be re-encrypted, and re-encrypt none |

**Rotation writes; it never rewrites.** Each entry carrying protected fields gets a *new* entry
holding the same values under the new key and pointing back at the original through
`source_audit_id`. The original keeps its hash, its link and its sequence, and goes on verifying for
as long as its old key stays on the keyring — the opposite of a redaction, and why no path of this
command calls that one. A second pass writes nothing: the identity of a rotation is derived from
(source entry, target key) and checked against the ledger before anything is written.

> ⚠️ **Warning.** `--after` is the **only** way past `--limit`. The walk is oldest-first, so without
> a cursor every pass reads the identical prefix — safely, but a trail larger than `--limit` never
> rotates at all.

```bash
php artisan sentinel:rekey --key=rotated --limit=500
# → "The last entry read was 01JB…. Pass --after=01JB… to carry on behind it."
php artisan sentinel:rekey --key=rotated --limit=500 --after=01JB…
```

**Prints:** `Re-encrypted :entries of the :read entries read.` then the `--after=` line.
**Exit codes:** `0` on a rotation, on a dry run, and on a pass that rotated nothing. `2` for an
unresolvable key identifier, a failing ledger, or any thrown exception. **No exit 1.**

> 📌 **Note.** The Query API read happens **before** the `try`, so a read that throws escapes as an
> uncaught exception with a stack trace rather than as the command's own exit `2`.

**Run before:** add the new key to `sentinel.security.encryption.keys` and **keep the old one** —
the originals hold their own `key_id` and stop being readable when their key leaves the ring.

→ [Encryption and the keyring](../05-pipeline-and-security/03-encryption-and-the-keyring.md) · [Export and rekey](../08-lifecycle/06-export-and-rekey.md)

---

## `sentinel:export`

```bash
php artisan sentinel:export {--format=ndjson} {--disk=} {--path=} {--tenant=} {--type=} {--limit=500}
```

| Option | Default | What it does |
|---|---|---|
| `--format=` | `ndjson` | `json`, `ndjson` or `csv` |
| `--disk=` | — | Write to this filesystem disk instead of standard output |
| `--path=` | — | Where on the disk to write it |
| `--tenant=` | all | Only this tenant |
| `--type=` | all | Only this audit type |
| `--limit=` | `500` | How many entries at most |

Renders a narrowed range of the trail plus the manifest that proves it came from here. `csv` is lossy
and for people — nested columns become JSON strings inside cells. `ndjson` round-trips.

With **both** `--disk` and `--path` it writes two files: the body at `<path>`, and the manifest at
`<path>.manifest.json` carrying `format`, `entries`, `digest`, `signature` and `signature_key_id`.
The manifest travels beside the body because putting a digest into the bytes it digests is the one
shape that cannot work.

> ⚠️ **Warning.** With either of the two missing it prints the **whole rendered body** to standard
> output and exits `0`. `--disk=exports` on its own produces no file and no warning.

```bash
php artisan sentinel:export --tenant=acme --limit=5000 --disk=exports --path=acme-trail.ndjson
```

**Exit codes:** `0` on a successful render or write; `2` for a format it does not write, a disk it
could not reach, or a query that failed. **No exit 1.**

> 🔒 **Security.** Under compliance mode this leaves the same two access records any other read does,
> because it performs a Query API `get()` — an export is the largest read a trail ever serves, and
> that is the point rather than a side effect.

→ [Export and rekey](../08-lifecycle/06-export-and-rekey.md) · [The Query API](../06-reading/01-the-query-api.md)

---

## `sentinel:import`

```bash
php artisan sentinel:import {--from=} {--table=} {--connection=} {--actor=} {--size=500} {--after=} {--dry-run}
```

| Option | Default | What it does |
|---|---|---|
| `--from=` | none — required | `owenit` or `altek` |
| `--table=` | `audits` for `owenit`, `ledgers` for `altek` | Where the source history lives, if the application moved it |
| `--connection=` | the default | The connection the source lives on |
| `--actor=` | `user` | The prefix the source gives its two actor columns |
| `--size=` | `500` | How many source rows to read at a time |
| `--after=` | — | Skip every source row up to and including this key |
| `--dry-run` | off | Map every row, apply the pipeline, write nothing |

Idempotent by construction: every imported entry derives its `capture_id` from (origin, source row
key), so a second run offers the same identities, the ledger says it already has them, and they are
dropped before a hash is computed. Re-reading is therefore free — `--after` makes resuming *fast*
rather than merely *safe*.

For the length of the run the importer forces `sentinel.mode` to `sync` and removes the
`ResolveContext` pipeline stage, restoring both in a `finally`. Under the queued mode it would push a
job per batch and return having written nothing; and resolving context in a terminal would sign every
historical action with the name of whoever ran the migration.

```bash
php artisan sentinel:import --from=owenit --connection=legacy --size=1000 --dry-run
php artisan sentinel:import --from=owenit --connection=legacy --size=1000
```

**Prints:** a two-column table of the five outcomes (read from the source / written as entries /
already imported / refused by the pipeline / could not be read), a line per unreadable-reason group,
the summary, and the `--after=` line.
**Exit codes:** `0` **only** when every source row came across. `1` when the run happened but
something did not come with it — unreadable rows or pipeline discards — which a `--dry-run` can also
return. Repeated rows do *not* trigger it. `2` for a package it does not read, no `--from` at all, a
table that is not shaped like the history it was told to expect, or any thrown exception.

> 📌 **Note.** The chain starts at the import. What the other package recorded before it has no link,
> because nobody hashed those rows as they were written, and fabricating one backwards would
> manufacture the one thing this engine exists to make unmanufacturable.

→ [The import runbook](../12-migrating/03-the-import-runbook.md) · [From owen-it/laravel-auditing](../12-migrating/01-from-owen-it.md)

---

## `sentinel:partitions` — ☠️ drops partitions with `--force`

```bash
php artisan sentinel:partitions {--table=audits} {--ahead=3} {--retire=} {--force} {--dry-run}
```

| Option | Default | What it does |
|---|---|---|
| `--table=` | `audits` | `audits` or `access_log` — nothing else is accepted |
| `--ahead=` | `3` | How many months beyond this one to have ready |
| `--retire=` | — | Retire partitions whose month ended before this much time ago. **Without it nothing is retired** |
| `--force` | off | Retire a partition that still holds entries |
| `--dry-run` | off | Report and change nothing |

Idempotent by construction: what *should* exist comes from the clock, what *does* exist comes from
the catalogue, and only the difference is issued — a second run in the same minute creates nothing.
`--retire` takes a span (`18 months`, `1 year`, ISO 8601); a relative date is refused, because a
period that means a different span on a Thursday is not a retention period.

> ⚠️ **Warning.** `--force` drops a range of the trail as a catalogue operation: nothing archived,
> nothing recorded that it went. Under compliance mode it is silently inert — the compliance arm
> precedes the `--force` arm in `Maintainer::refusal()`, so the flag never reaches the drop and the
> run still exits `1`.

```bash
php artisan sentinel:partitions --ahead=6 --retire="18 months" --dry-run
```

**Prints:** a three-column table (Partition, Action, Note) then one summary line; the Note says why a
partition was kept — it still holds entries, or compliance mode will not let the range leave without
a copy of it existing first.
**Exit codes:** `0` when it maintained, when there was nothing to do, and when the table is not
partitioned. `1` whenever it kept **any** partition it was asked to retire. `2` for a `--table` it
does not maintain, a `--retire` it cannot read, or any thrown exception.

> ⚠️ **Warning.** That exit `1` fires on an ordinary correct state. Any partition behind the
> `--retire` cutoff that still holds rows is kept, and any kept partition makes the run exit `1`. A
> monthly schedule with `--retire` that runs before `sentinel:prune` has archived the range wakes a
> watchdog every month over nothing being wrong.

**Run before:** `sentinel:prune`, so the old months are empty by the time this looks at them.

→ [Partitioning](../10-database-engines/06-partitioning.md)

---

## `php artisan about`

Not a `sentinel:*` command: `About` adds a `Sentinel` section to Laravel's own `about`, registered in
the provider's `register()` unconditionally rather than behind `runningInConsole()`.

| Row | Where it comes from |
|---|---|
| Version | Whatever Composer recorded, or the literal `dev` for a path repository or checkout |
| Mode | `sentinel.mode` |
| Ledger | The configured default driver |
| Payload version | `EntryBuilder::PAYLOAD_VERSION` |
| Compliance mode | `ENABLED` (bold yellow) or `OFF`; a raw boolean under `about --json` |
| Telemetry | Same rendering |

```bash
php artisan about --only=sentinel
```

> 🔒 **Security.** It carries **no key, no key identifier and no signer configuration**, on purpose.
> `about` output is pasted into issues and captured by deploy logs, and signing material must never
> travel that way. It is safe to paste anywhere, which makes it the right first question of a support
> conversation. The six labels are English and untranslated, because every other row of that command
> is.

---

## 🐘 Engine notes

> 🐘 **Engine.** `sentinel:partitions` only acts on PostgreSQL and MySQL. On SQLite it exits `0` with
> `The table [sentinel_audits] is not partitioned, so there was nothing to maintain.` — and so does an
> unpartitioned table on either supported engine, because `Maintainer` reports `divided: false` when
> the catalogue lists no partitions for it. Every other command behaves identically on all three.

> 🐘 **Engine.** `sentinel:prune` reclaims no disk space on any engine and runs no reclaim command;
> what each engine leaves behind and what returns it is in
> [Retention and pruning](../08-lifecycle/01-retention-and-pruning.md#it-does-not-reclaim-disk-space).

> ⚠️ **Warning.** Setting `sentinel.ledger.default` to `null` to "turn auditing off" turns every
> scheduled maintenance command red: `checkpoint`, `prune` and `verify` need a ledger implementing
> `EnumeratesStreams` when given no `--stream`, and `NullLedger` is the one driver that does not
> implement it — so they exit `2` rather than no-op quietly. `memory` does implement it, and is worse
> in the other direction: it enumerates only the streams the current process wrote, which in a fresh
> console process is none, so every one of those commands exits `0` having looked at nothing. See
> [Turning auditing off](../02-getting-started/04-turning-auditing-off.md).

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `sentinel:export` exits `0` and dumps thousands of lines to the terminal, no file written | The write branch needs **both** `--disk` and `--path`; with either missing it falls through to standard output | Always pass both, and keep the `.manifest.json` beside the body |
| `sentinel:prune` reports "nothing was removed" on every run | The Note column says which hold applies. Most often `unanchored` — retention's unit is the anchored window | Run `sentinel:checkpoint` first, then prune |
| `sentinel:prune --dry-run` says "Would remove N entries", then the real run exits `2` | Under compliance mode the dry run returns before `refuseUnarchivedDelete()` is reached, so it never rehearses the `--action=delete` refusal | Do not use `--action=delete` under compliance mode; archive instead |
| `sentinel:prune` suddenly takes hours | `--batch` is cast raw, so a non-numeric value becomes `0` and is clamped to one entry per `DELETE` | Check the value; every other numeric option asks first, this one does not |
| `sentinel:partitions` exits `1` every month with nothing wrong | Any kept partition makes `Maintenance::refused()` true, and a partition behind the cutoff that still holds rows is always kept | Schedule `sentinel:prune` to empty the range before this runs |
| `sentinel:partitions --force` changes nothing and still exits `1` | Compliance mode's refusal arm precedes the `--force` arm | Archive the range with `sentinel:prune` — compliance forbids a range leaving with nothing to answer for it |
| `sentinel:rekey` reports the same count forever and never reaches older entries | The walk is oldest-first and `--limit` bounds it; without `--after` each pass re-reads the same prefix | Chain `--after=<the id it printed>` until it reports `0` read |
| `sentinel:verify --from=yesterday` verifies the whole stream and exits `0` | A non-numeric numeric option is treated as **absent**, not as `0`, and nothing says so | Pass a sequence number |
| `sentinel:redact --dry-run` exits `0`, the real run exits `1` | The dry run returns before any check; the refusals are computed inside `Redactor::redact()` | Treat `redact`'s and `rekey`'s dry runs as arithmetic, not as rehearsals |
| `sentinel:redact` exit `1` retried by a cron, forever | Exit `1` here means "this entry will never be redactable by this version" — archived, or no longer reproducing its own hash | Retry only on exit `2` for this command |
| `sentinel:show "" --subject=invoice:77` exits `2` with the ambiguity message | The life branch requires the positional argument to be absent; an explicitly passed empty string is still a string | Omit the positional argument entirely |
| `sentinel:install` exits `2` right after publishing the config | The schema check runs on `sentinel.database.connection`, and the publish is not rolled back | Fix the connection name and run it again — the published file is untouched |
| `sentinel:checkpoint` on a schedule takes an hour the first time | The first run anchors every window every stream owes, and there is no `--limit` | Run the first pass by hand before scheduling it |

---

## ✅ Best practices

✅ **Do** — anchor before you prune, in that order, always. Retention's unit is the anchored window,
so an unanchored stream releases nothing and reports success while removing not one row.

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('sentinel:checkpoint')->hourly();
Schedule::command('sentinel:prune')->dailyAt('03:20');
```

❌ **Don't** — schedule the prune on its own and conclude retention is broken when nothing goes. The
Note column will read `unanchored` on every stream and the exit code will be `0`.

```php
Schedule::command('sentinel:prune')->dailyAt('03:20'); // and no checkpoint anywhere
```

---

✅ **Do** — branch a watchdog on the three codes as a vocabulary: `0` fine, `1` a human looks at it,
`2` retry or page the operator.

```bash
php artisan sentinel:verify --depth=anchors
case $? in 0) ;; 1) page_a_human ;; 2) retry_or_alert_ops ;; esac
```

❌ **Don't** — treat any non-zero code as "failed" and retry it. Retrying a `sentinel:redact` exit
`1` retries a refusal that is permanent for this version.

```bash
php artisan sentinel:redact "$id" --reason=… --actor=… || retry   # never succeeds
```

---

✅ **Do** — carry the cursor forward when rotating a key, and keep the old key on the ring.

```bash
php artisan sentinel:rekey --key=rotated --limit=500
php artisan sentinel:rekey --key=rotated --limit=500 --after=01JB…
```

❌ **Don't** — loop the bare command and assume it is working through the trail. Since the rotation's
identity is derived, the second pass writes nothing — it also never reaches the entries behind the
first page.

```bash
for i in $(seq 1 20); do php artisan sentinel:rekey --key=rotated; done
```

---

✅ **Do** — pass both `--disk` and `--path` when you want a file, and ship the manifest with it.

```bash
php artisan sentinel:export --tenant=acme --disk=exports --path=acme.ndjson
# writes acme.ndjson and acme.ndjson.manifest.json
```

❌ **Don't** — pass `--disk` alone. You get the entire export on standard output, no file, no
warning, and exit `0`.

```bash
php artisan sentinel:export --tenant=acme --disk=exports
```

---

✅ **Do** — rehearse a new retention policy or an import against the real data first. Both dry runs
run the same checks the real run does, which is why both can exit `1`.

```bash
php artisan sentinel:prune --dry-run
php artisan sentinel:import --from=owenit --connection=legacy --dry-run
```

❌ **Don't** — rehearse a redaction or a rotation and read the result as a verdict. Both return
`SUCCESS` before any check runs, by design, with tests pinning it.

```bash
php artisan sentinel:redact "$id" --reason=… --actor=… --dry-run  # says nothing about refusal
```

---

✅ **Do** — schedule only the commands with the idempotent-and-quiet shape: `checkpoint`, `prune`,
`verify`, `partitions`, and `flush` in buffered mode.

```php
Schedule::command('sentinel:verify --depth=anchors')->dailyAt('04:00');
```

❌ **Don't** — schedule `export`, `redact`, `rekey`, `import`, `show` or `install`. Four are one-shot
operator actions, one is destructive, and `export` has a `--limit` with no cursor to advance past it.

```php
Schedule::command('sentinel:export --disk=s3 --path=trail.ndjson')->daily(); // the same 500 entries, forever
```

---

**See also:** [Exit codes](../99-reference/07-exit-codes.md) · [Scheduling](07-scheduling.md) ·
[Monitoring and troubleshooting](08-monitoring-and-troubleshooting.md) ·
[Configuration](../99-reference/02-configuration.md) · [Verification](../07-integrity/06-verification.md) ·
[Checkpoints and anchors](../07-integrity/05-checkpoints-and-anchors.md) ·
[Retention and pruning](../08-lifecycle/01-retention-and-pruning.md) ·
[Redaction and tombstones](../08-lifecycle/04-redaction-and-tombstones.md) ·
[Export and rekey](../08-lifecycle/06-export-and-rekey.md) · [Compliance mode](../08-lifecycle/05-compliance-mode.md) ·
[The import runbook](../12-migrating/03-the-import-runbook.md) · [Partitioning](../10-database-engines/06-partitioning.md) ·
[The buffered mode](02-the-buffered-mode.md) · [API stability](../99-reference/09-api-stability.md)
