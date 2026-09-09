# ♻️ Retention and pruning

> How to declare that a class of audit record stops being kept, and what `sentinel:prune` actually
> removes when you do.

**On this page:** [What a policy is](#what-a-policy-is) · [Declaring retention](#declaring-retention) ·
[The unit is the anchored window](#the-unit-is-the-anchored-window-not-the-entry) ·
[Why a stream released nothing](#why-a-stream-released-nothing) ·
[Running the purge](#running-the-purge) · [The three knobs](#the-three-prune-knobs) ·
[What a purge does not touch](#what-a-purge-does-not-touch) ·
[It does not reclaim disk space](#it-does-not-reclaim-disk-space) ·
[Verifying a pruned trail](#verifying-a-trail-you-have-pruned) ·
[⚠️ Pitfalls](#️-pitfalls) · [✅ Best practices](#-best-practices)

---

## What a policy is

A retention policy is one line of `config/sentinel.php`, keyed by a **logical type** and valued with
a period:

```php
// config/sentinel.php
'retention' => [
    'model:App\Models\Invoice' => '7 years',
    'auth'                     => '90 days',
],
```

Three facts govern everything that follows.

**A policy releases entries; it does not remove them.** Releasing is a per-entry answer to "may this
be let go yet". Removing happens to whole anchored ranges, and the two are not the same question —
see [the anchored window](#the-unit-is-the-anchored-window-not-the-entry).

**What no policy names is kept forever.** `Retention\RetainedPredicate` compiles the *kept* set, not
its negation, and its last clause keeps every entry whose subject is named by no subject policy
**and** whose `audit_type` is named by no type policy. Turning retention on does not start deleting;
it starts deleting the one logical type you named.

**The clock is `created_at`.** That is when the record was written, not when the fact happened.
`occurred_at` is caller-settable and would stop being monotonic with `sequence`, so retention never
reads it. `Retention\Duration::cutoff()` computes `now - period`, and an entry is released once its
`created_at` is strictly older than that.

> 📌 **Note.** `Retention\Pruner`, `Retention\Schedule` and everything else under
> `ElPandaPe\Sentinel\Retention\` are marked `@internal`. The supported surface is the config block
> and the `sentinel:prune` command. Nothing on the [`Sentinel` facade](../99-reference/01-facade-api.md)
> prunes.

---

## Declaring retention

### The key is a logical type, never a table

| Key form | Example | Matched against | Notes |
|---|---|---|---|
| `model:<FQCN>` | `'model:App\Models\Invoice'` | `subject_type` | Resolved through Laravel's morph map before comparison |
| Bare FQCN | `'App\Models\Invoice'` | `subject_type` | Detected by the `\` in the string |
| Leading-backslash FQCN | `'\App\Models\Invoice'` | `subject_type` | Trimmed, then resolved the same way |
| Anything else | `'auth'` | `audit_type` | A plain string with no `\` and no `model:` prefix |

`Retention\Schedule::resolve()` treats the `model:` prefix — or a `\` anywhere in the key — as the
discriminator. This matters more than it looks: **`model` on its own is a legal `audit_type`**, the
one every Eloquent create/update/delete carries, so `'model' => '1 year'` is a *type* policy over all
model events and not a malformed subject key.

The morph-map step is the one that silently bites. If your application calls
`Relation::enforceMorphMap(['invoice' => Invoice::class])`, then `subject_type` holds `invoice`, and
an unresolved `model:App\Models\Invoice` would match nothing at all — keeping data forever rather
than losing it, which is exactly why nobody would notice. `Schedule` resolves the alias first.

The nine bare `audit_type` values Sentinel itself writes — these, and only these, are what a type
policy can name:

| `audit_type` | Written by |
|---|---|
| `model` | `Capture\ModelCapture` — `created`, `updated`, `deleted`, `restored`, `force_deleted` |
| `relation` | `Capture\RelationCapture` — attach, detach, sync, toggle |
| `mass` | `Mass\MassCapture` — `Builder::update()` / `delete()` under `auditing()` |
| `transition` | `Transitions\TransitionBuilder`, and an `updated` that `ModelCapture` recognised as a state move |
| `custom` | `Sentinel::event()` |
| `auth` | `Capture\AuthenticationSubscriber` |
| `restore` | `Restore\Restorer` |
| `security` | `Redaction\Redactor` and `Security\Rekeyer` |
| `access` | `Compliance\AccessLog`, under [compliance mode](05-compliance-mode.md) |

> ⚠️ **Warning.** `transaction` is **not** on that list, and `'transaction' => '30 days'` is a
> policy that will never match a row. `Transactions\TransactionScope` writes its header into
> `sentinel_transactions` — a table with no `sequence` and no `hash` — and stamps `transaction_id`
> onto the entries captured inside the scope; nothing with `audit_type = 'transaction'` is ever
> written. Headers are not governed by a policy at all: one goes only when its last entry goes,
> as [What a purge does not touch](#what-a-purge-does-not-touch) describes. See
> [Business transactions](../03-capture/06-business-transactions.md).

### Precedence: subject beats type

An entry about a subject that has its own policy is **never** governed by the type it happens to be.
`'model:App\Models\Invoice' => '7 years'` plus `'model' => '30 days'` keeps invoice entries for seven
years and everything else model-shaped for thirty. The rule is compiled twice — once into SQL in
`Retention\RetainedPredicate` and once in PHP in `Schedule::covering()` — and the two must agree.

### The period grammar

| Form | Examples | Accepted |
|---|---|---|
| Plain units | `'90 days'`, `'7 years'`, `'2 weeks 3 days'` | ✅ |
| ISO 8601 | `'P1Y'`, `'P90D'`, `'PT12H'` | ✅ |
| Relative date | `'tomorrow'`, `'next tuesday'` | ❌ `ConfigurationException` |
| Zero-length | `'P0D'`, `'0 days'` | ❌ `ConfigurationException` |

`Duration::of()` checks the grammar with its own two patterns *before* Carbon sees the string,
because Carbon's reader would otherwise fall back to relative-date parsing and turn `'next tuesday'`
into a period that means a different span depending on the day the cron ran.

Months are subtracted by the calendar, not as a fixed number of days: `subMonthsNoOverflow()` is used
so that `'1 month'` on the 31st of March does not land on the 3rd of March and release three days'
worth of entries early.

### Configuration errors, raised when the schedule is read

`Retention\Schedule` is a scoped binding, read and resolved once, the first time anything asks for it
— in practice, when `sentinel:prune` resolves its `Pruner`. All three of these are
`ElPandaPe\Sentinel\Exceptions\ConfigurationException`, and they abort the run before a single row is
looked at rather than being arbitrated at prune time:

| Declaration | Message names | Why it is refused |
|---|---|---|
| `'auth' => 'tomorrow'` | the key and the string | A relative date means a different span on a different day |
| `'auth' => 'P0D'` | the key and the string | It would release every entry it governs the moment one was written |
| `'model:App\Models\Invoice'` **and** `'App\Models\Invoice'` | both keys and the resolved target | Two periods for the same entries is a choice nobody made |

The ambiguity check is on the *resolved seat* (`subject:invoice`, `type:auth`), not on the literal
key, so two spellings of the same class collide even though the strings differ.

These three are thrown while the command's dependencies are being built, not inside its own
error handling, so the exception surfaces as an unhandled console failure rather than as one of the
command's own three exit codes. Nothing is examined and nothing is removed.

---

## The unit is the anchored window, not the entry

A range leaves the hot table only when **both** hold:

1. an [anchor](../07-integrity/05-checkpoints-and-anchors.md) covers it exactly, and
2. every entry inside it has been released by the policies.

The reason is the fold. An anchor records the root its window folds to, and
`Integrity\Checkpoints::refold()` recomputes that root only when the count of hashes it reads equals
the window's length. Take one entry out of the middle and the window can never reproduce its root
again — the anchor becomes unverifiable, permanently. So a window goes whole or it does not go.

The consequence is the single most important sentence about retention in this package:

> 📌 **Note.** The effective retention of a range is that of its **longest-lived entry**.

### Work the arithmetic

Take the shipped default `integrity.stream => 'tenant'` — one chain per tenant, so a stream mixes
every logical type that tenant produced — and the shipped `integrity.checkpoints.every => 1000`.

Anchor #12 of `tenant:acme` covers sequences `11001`–`12000`. Inside it:

| Entries | `audit_type` / subject | Policy | Released on |
|---|---|---|---|
| 998 | `auth` | `'auth' => '90 days'` | 90 days after each was written |
| 1 | `model`, subject `invoice`, written 2026-03-04 | `'model:App\Models\Invoice' => '7 years'` | 2033-03-04 |
| 1 | `restore` | *none declared* | never |

The window is offered when the **last** of those releases. With the `restore` entry in it, that is
never: one entry of a logical type no policy names pins its whole window forever, and the 999 others
go with it. Delete the `restore` line from the table and the answer becomes 2033-03-04 — the 998
`auth` entries live roughly seven years despite a ninety-day policy, because they share a window with
one invoice entry.

Two corollaries fall out of this:

- **Entry-level erasure is a different feature.** If you need one entry's contents gone now, that is
  [redaction](04-redaction-and-tombstones.md), which empties an entry in place and leaves its
  `sequence`, `hash` and `previous_hash` where they were.
- **A smaller `integrity.checkpoints.every` gives finer-grained pruning**, at the cost of more anchor
  rows and more folds. It is the knob that decides how coarse the unit is.

### Two rules the frontier enforces on top

**The window holding the stream's highest sequence is never offered.** `Retention\Frontiers` filters
on `sequence_to < max(sequence)`. The writer derives the next sequence from the last row of the
stream; a stream emptied to nothing would hand the next write sequence `1` and start a second chain
under the first one's name.

**Releasable windows are not a prefix.** A held window does not hold the ones behind it. Anchor #12
being pinned by an invoice entry does not stop anchors #13 and #14 from being retired, which is what
makes retiring a range in the middle possible at all.

---

## Why a stream released nothing

`sentinel:prune` prints one row per stream with a **Note** column, and when nothing was released the
note names one of exactly four reasons. All four are states a sound installation can be in; none of
them is a failure, and the command still exits `0`.

| Note | Meaning | What to do |
|---|---|---|
| *No retention policy is declared* | `config('sentinel.retention')` is empty | Nothing. This is the default and it keeps everything |
| *Stream … has no anchors* | No `sentinel_checkpoints` row for the stream | Run `php artisan sentinel:checkpoint` first |
| *Every anchored range … holds the entry the next write links to* | The only anchored windows are in the live tail | Wait for the stream to grow past them |
| *Retention still keeps `<held>` at sequence `<n>`* | A window is pinned by a specific entry | Read the sequence and the entry it names; usually correct, not broken |

The `retained` note names the *exact* entry holding the window — `model:invoice at sequence 2044`,
not "some policy somewhere". The label is taken from the entry itself: its `subject_type` prefixed
with `model:` when it has one, and its `audit_type` when it does not (`Frontiers::label()`). That is
the sequence to look up when a ninety-day policy appears to free nothing.

> ⚠️ **Warning.** The note is derived from the **first** anchored window of the stream only
> (`Frontiers::holding()`). On a stream whose first window is in the live tail but whose later
> windows are pinned by a policy, the note reads *tail*. It is one reason out of possibly several.

---

## Running the purge

```bash
php artisan sentinel:prune --dry-run                       # plan, touch nothing
php artisan sentinel:prune                                 # archive is the default action
php artisan sentinel:prune --action=delete                 # remove without writing anywhere
php artisan sentinel:prune --stream=tenant:acme --batch=200
```

| Option | Default | What it does |
|---|---|---|
| `--action` | `archive` | `archive` writes the window to cold storage and proves it before removing a row; `delete` removes it without writing it anywhere |
| `--stream` | every stream | Restricts the run to one chain, and skips the enumerate requirement below |
| `--batch` | `prune.batch` | Sequence span per `DELETE`, for this run only |
| `--dry-run` | off | Counts through the same code path and removes nothing |

`--action` defaults to `archive` deliberately: the action that loses nothing is the one you get for
forgetting a flag. It did *not* have a default while `delete` was the only action, because a default
that silently changed what a scheduled command did would have been worse than typing the flag.

Without `--stream`, the command asks the configured ledger for its chains. A ledger that does not
implement `Contracts\EnumeratesStreams` is refused rather than worked around, with a message naming
the class and telling you to pass `--stream`.

| Exit code | Meaning |
|---|---|
| `0` | Rows were removed, **or** nothing was released and the note says which of the four holds it |
| `1` | A range no longer folds to the root its anchor recorded — the run stopped on that stream and left that range, and every window behind it, in place |
| `2` | The run could not happen: unknown `--action`, a disk that refused the batch, a ledger that cannot enumerate streams, the compliance refusal of an unarchived `--action=delete` |

### What one run does, in order

For each stream, for each releasable window:

1. **Refold the window** against the root its anchor recorded. Two columns per row, no rehashing.
   A different root stops the run on that stream and returns exit `1`; a root that cannot be
   recomputed *at all* is excused only when a `sentinel_archives` row claims the range.
2. **Count** what would go (`Cascade::count()`). On `--dry-run` the run stops here and reports.
3. **Compliance guard.** Under [compliance mode](05-compliance-mode.md), `--action=delete` over a
   range with no archive batch throws.
4. **Record the range** in `sentinel_archives` — under `archive`, only after the batch has been
   written, read back, re-digested and every entry rehashed
   ([cold archiving](02-cold-archiving.md) has the four steps).
5. **Remove the rows**, one sequence slice at a time.

The order is the guarantee: the range is written down before a row goes, so an interruption leaves a
range recorded as gone whose entries are still there — which the next run finishes — rather than
entries gone with nothing accounting for them.

### The dry run is the same walk

`--dry-run` is not a second implementation. It walks the same windows through `Pruner::prune()` and
returns before removing, so the report and the run cannot describe different things. It also runs the
fold check, so **a dry run over a tampered range reports it and exits `1`**.

```
php artisan sentinel:prune --dry-run

 ------------- -------- --------- --------- ------------------------------------------------
  Stream        Ranges   Entries   Rate      Note
 ------------- -------- --------- --------- ------------------------------------------------
  global        3        3000      4,182/s   —
  tenant:acme   0        0         —         Retention still keeps model:invoice at
                                             sequence 2044 of tenant:acme. A range is
                                             retired whole or not at all, so the range
                                             around that entry stays.
 ------------- -------- --------- --------- ------------------------------------------------

Would write out and remove 3000 entries in 3 ranges across 2 streams. Nothing was touched.
```

The `Rate` column is entries per second for that stream, and `—` when nothing was removed — a zero
would read as a prune that crawled and an infinity as one that never ran.

---

## The three prune knobs

```php
// config/sentinel.php
'prune' => [
    'windows' => 100,
    'batch'   => 1000,
    'pause'   => 0,
],
```

| Key | Default | Floor | What it bounds | When to change it |
|---|---|---|---|---|
| `prune.windows` | `100` | `1` | Anchored windows examined **per stream, per run** | Raise to clear a large backlog faster; lower when the plan query itself is expensive on a stream where a long policy pins many windows |
| `prune.batch` | `1000` | `1` | The **span of `sequence`** one `DELETE` statement covers inside a window | Lower on a busy table to shorten each statement's lock |
| `prune.pause` | `0` | `0` | **Microseconds** slept after every batch | Set it when the prune competes with the writes it is making room for |

### `prune.windows` bounds the plan, not the backlog

A window a long policy holds is re-examined on every single run. Without a limit, an installation with
one seven-year policy would grow its plan query without bound as pinned windows accumulated. The
consequence is that clearing a large backlog needs repeated runs: 100 windows of 1000 entries is
100,000 entries per stream per run.

### `prune.batch` names a sequence span, not a row count

This is the one that surprises people, including via the option help text, which calls them entries.
`Retention\Cascade::purge()` walks a cursor from the window's `from` to its `to` in steps of `batch`,
and each step is a range delete:

```
window 5001–6000, batch 250

  DELETE … WHERE stream = ? AND sequence BETWEEN 5001 AND 5250
  DELETE … WHERE stream = ? AND sequence BETWEEN 5251 AND 5500
  DELETE … WHERE stream = ? AND sequence BETWEEN 5501 AND 5750
  DELETE … WHERE stream = ? AND sequence BETWEEN 5751 AND 6000
```

Two things follow. **A half-empty range issues the same number of statements as a dense one** — the
statements that find nothing are cheap, but they are still issued. And **an interrupted run resumes
by arithmetic**: kill the process after the second statement and sequences `5501`–`6000` remain. The
next run is offered the same window (it still has rows and nothing in it is retained), sees a
manifest row already claiming the range so it does not record it twice, and replays all four
statements — the first two matching nothing, the last two finishing the job.

That resumability is why the slice is named by `sequence` and not by a `LIMIT`. "The next N rows" is
not a name a restart can resume from, and a `LIMIT`ed `DELETE` is not one statement across SQLite,
MySQL and PostgreSQL — whereas a range on `(stream, sequence)` is one scan on the chain's own unique
index, and compiles to the same plan on all three.

> 🐘 **Engine.** The satellite tables (`sentinel_audit_tags`, `sentinel_audit_relations`) are cleared
> through a subquery over the entries of the same range, so a slice sends three placeholders whatever
> the batch size is — never a `whereIn` over ten thousand identifiers. Labels, relation lines and entries
> for one slice go in a **single database transaction**: interrupted between them, the labels of an
> entry that is gone would be rows nothing surviving could name again.

### `prune.pause` is microseconds, and it fires after the last batch too

`Sleep::usleep($pause)` sits inside the loop with no final-iteration check. A value tuned as if it
were milliseconds will stall a prune by three orders of magnitude: `2000` is 2 ms, not 2 seconds.

---

## What a purge does not touch

| Not touched | Why |
|---|---|
| **Anchors** (`sentinel_checkpoints`) | After the range is gone the anchor is the only thing standing behind it. Deleting one leaves every later anchor unverifiable, because each root chains the previous one |
| **Manifest rows** (`sentinel_archives`) | The row is the map to what left, and the only place `disk`, `path`, `checksum` and the codec exist |
| **`audits_count`** on a transaction header | It is what the operation captured when its scope closed; a prune does not change what happened |
| **A transaction header still carrying entries** | The header goes only when its last entry does, asked **across every stream** — one business operation can write into several chains |
| **`sentinel_access_log`** | Nothing in `Retention\Cascade` names that table. A policy on `'access'` prunes the chained `access` entries and orphans the projection rows |
| **Existing files on the archive disk** | The package only ever calls `put` and `get`; it never deletes from that disk. Object lifecycle is yours |
| **The audited models themselves** | Retention is about the record of a change, never about the row that changed |
| **Disk space** | See below |

The entries, their labels (`sentinel_audit_tags`), their relation lines
(`sentinel_audit_relations`) and any newly-orphaned transaction header are what a prune removes, and
that is the whole list.

> ⚠️ **Warning.** `sentinel_access_log` has no reaper in the prune. Give it its own plan from day one
> — it grows one row per recorded read under compliance mode, and the only shipped mechanism that
> shrinks it is [partition retirement](../10-database-engines/06-partitioning.md).

---

## It does not reclaim disk space

Removing rows from an append-only table leaves the pages behind. **`sentinel:prune` runs no
`OPTIMIZE TABLE` and no `VACUUM`** — there is no occurrence of either anywhere in the package. Both
are engine operations that take locks and belong in a maintenance window somebody scheduled.

| Engine | What a prune leaves | What reclaims it | Before you run it |
|---|---|---|---|
| MySQL / InnoDB | Freed pages stay in the tablespace, reusable by later inserts | `OPTIMIZE TABLE sentinel_audits` | It rebuilds the table. Check what your MySQL version and row format do about concurrent DML before scheduling it |
| PostgreSQL | Dead tuples for autovacuum to mark reusable | Autovacuum reuses the space in place; `VACUUM FULL` returns it to the filesystem | `VACUUM FULL` takes an `ACCESS EXCLUSIVE` lock on the table for its whole duration |
| SQLite | Freelist pages inside the database file | `VACUUM` | It rewrites the file, so it needs room for a second copy |

If reclaiming space is the actual goal rather than a side effect, the mechanism you want is
partitioning: a prune that lines up with partition boundaries becomes a partition drop, which returns
the space immediately. The order is `sentinel:prune --action=archive` first, then
`sentinel:partitions --retire`.

---

## Verifying a trail you have pruned

A pruned stream has holes in its sequence. `sentinel:verify` steps over an absence only when **two
independent things account for it at once**:

1. a `sentinel_archives` row says the range was retired, and
2. the anchors of the stream reach past the range.

Neither alone will do. Nothing in `sentinel_archives` is hashed or signed — it is a map, never
evidence — so on its own it would make "delete the rows, then insert one row" a supported way of
laundering a gap. `Integrity\Verifier::accountedFor()` asks the anchors first, because their reach is
already in hand, and only then pays for the manifest query.

When both hold, the entries stepped over are counted **apart** from the ones read. On the default
`--depth=entries` walk they appear in the `Entries` column, never folded into the count of what was
actually rehashed:

```
php artisan sentinel:verify --stream=tenant:acme

 ------------- ---------------------- -------- --------- ---------------
  Stream        Entries                Chain    Anchors   Signatures
 ------------- ---------------------- -------- --------- ---------------
  tenant:acme   8000 (+4000 retired)   intact   —         8000 unsigned
 ------------- ---------------------- -------- --------- ---------------
```

The summary line says `stepped over 4000 that are no longer here` and then, in the same breath,
that nothing was read about them either — what stands behind those is the anchor over them. The
`Anchors` column is `—` here because the entry walk reads entries, not anchors; run
`--depth=roots` and the same retired range shows up on the other side instead, as an anchor counted
`retired` rather than `anchored`, because its window no longer refolds and a manifest row accounts
for why.

The seam is not faked. The hash the first surviving entry links to left with the range, so the walk
sets `previous` to `null` and treats the point as the edge of a bounded range: **not checked and not
invented**. An entry nobody read is not an entry that verified, and the summary says so.

> 🧪 **Verify it.** Anchor, prune and verify in that order on a copy of production:
> `php artisan sentinel:checkpoint && php artisan sentinel:prune --dry-run && php artisan sentinel:verify`.
> If `sentinel:verify` reports a `sequence_gap` after a real prune, the range left without an anchor
> covering it — which the command cannot itself produce, because the frontier only ever offers
> anchored windows.

---

## Scheduling

Anchoring has to come first, and by a margin: nothing is ever released from a stream with no anchors.

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('sentinel:checkpoint')->hourly();
Schedule::command('sentinel:prune')->dailyAt('03:10');
```

> ⚠️ **Warning.** The **first** `sentinel:checkpoint` run over an existing trail is unbounded — it
> folds every complete window the stream owes, in one pass. Run it by hand, off the schedule, before
> putting it on `->hourly()`. See [Checkpoints and anchors](../07-integrity/05-checkpoints-and-anchors.md).

Note that `integrity.checkpoints.enabled` governs anchoring **on the write path** only.
`sentinel:checkpoint` issues anchors regardless of that flag, so a schedule like the one above works
with it left at its default of `false`.

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| A 90-day policy is declared and nothing is ever removed | The windows also hold longer-lived or unnamed entries; the unit is the whole anchored window | Read the `retained` note — it names the sequence and the entry holding it. Declare a policy for the pinning type, or shrink `integrity.checkpoints.every` |
| `Nothing was removed` and the note says *no anchors* | The stream was never anchored | Run `php artisan sentinel:checkpoint` before the first prune |
| The prune only ever clears part of the backlog | `prune.windows` caps the plan at 100 windows per stream per run | Run it more often, or raise `prune.windows` |
| Every `model:App\Models\Invoice` policy behaves as if it were absent | A morph map is declared and the alias is what lands in `subject_type` | Nothing — `Schedule` resolves the alias. If it still misses, the class is not in the map you think it is |
| The prune stalls for minutes between statements | `prune.pause` was tuned as milliseconds; the unit is **microseconds** | Divide by 1000 |
| `--batch=200` does not make a sparse range faster | The batch is a span of `sequence`, not a row count | Expected. The empty statements are cheap; the count is fixed by the window width |
| Exit `1` with *no longer folds to the root it recorded* | Rows inside a window were altered or removed outside the package | Do not re-run. The prune deliberately refuses to be what destroys the evidence — investigate the range first |
| Exit `2` with *Nothing was removed: The `<disk>` disk refused…* | `--action=archive` could not write or read back the batch | Fix the disk. Nothing was removed, and the message says so |
| A stream released nothing, and the note blames the tail, but later windows are clearly old | The note is computed from the **first** anchored window only | Treat it as one reason among possibly several; check later windows by hand |
| Disk usage is unchanged after removing millions of rows | A prune frees pages, it does not return them | `OPTIMIZE TABLE` / `VACUUM FULL` / `VACUUM`, in a maintenance window — or partition and drop |
| `sentinel_access_log` keeps growing despite `'access' => '1 year'` | The policy prunes the chained `access` entries; nothing in the prune touches the projection table | Partition `sentinel_access_log` and retire partitions |
| `--dry-run --action=delete` plans a delete that the real run refuses | The compliance archive guard sits after the dry-run early return | Under compliance mode, confirm the range has an archive batch before planning a delete |

---

## ✅ Best practices

✅ **Do** — anchor before you prune, and schedule the anchoring ahead of the prune. Nothing is
released from a stream with no anchors, and the report says `unanchored` rather than failing, which
is easy to skim past.

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('sentinel:checkpoint')->hourly();
Schedule::command('sentinel:prune')->dailyAt('03:10');
```

❌ **Don't** — schedule the prune on its own and assume the write path is anchoring for you.
`integrity.checkpoints.enabled` defaults to `false`, so a fresh installation has no anchors at all
and the prune will report `unanchored` forever without ever failing.

```php
Schedule::command('sentinel:prune')->daily();   // no anchors, so nothing is ever released
```

---

✅ **Do** — run `--dry-run` first and read the `Note` column before believing a policy is broken.
It walks the same windows through the same code and names the exact entry holding a stream.

```bash
php artisan sentinel:prune --dry-run
```

❌ **Don't** — respond to "nothing was removed" by shortening the period. A ninety-day policy that
frees nothing is usually being held by one long-lived entry in the same window, and shortening the
period changes nothing at all.

```php
'retention' => ['auth' => '7 days'],   // still frees nothing if the window holds an invoice entry
```

---

✅ **Do** — leave `--action` alone. The default writes the window out and proves it — read back,
re-digested, every entry rehashed — before a row is removed.

```bash
php artisan sentinel:prune
```

❌ **Don't** — reach for `--action=delete` for speed. It removes the range and writes it nowhere;
the `sentinel_archives` row it leaves has null `disk`, `path` and `checksum`, which is a truthful
record of a deletion and nothing you can restore from.

```bash
php artisan sentinel:prune --action=delete   # only when the content should not exist anywhere
```

---

✅ **Do** — use `--batch` for a hand-run prune somebody is watching, and leave the configured
`prune.batch` alone for the schedule.

```bash
php artisan sentinel:prune --stream=tenant:acme --batch=200
```

❌ **Don't** — edit `prune.batch` in config to get one smaller slice tonight. The smaller slice stays
for every future run, and a busy installation ends up issuing thousands of statements per window
forever.

```php
'prune' => ['batch' => 50],   // this is now every run, on every stream
```

---

✅ **Do** — declare retention one logical type at a time, and check which axis each key names.
Subject beats type, and `model` on its own is a type.

```php
'retention' => [
    'model:App\Models\Invoice' => '7 years',   // subject
    'model'                    => '18 months', // type: every other model event
    'auth'                     => '90 days',
],
```

❌ **Don't** — declare the same target twice in two spellings. The schedule is refused as it is read,
rather than arbitrated by array order at prune time, and the run stops before it looks at a row.

```php
'retention' => [
    'model:App\Models\Invoice' => '7 years',
    'App\Models\Invoice'       => '30 days',   // ConfigurationException: both keys govern one target
],
```

---

✅ **Do** — expect a prune to leave disk usage where it was, and plan the reclamation separately —
or partition the table so a retirement is a partition drop.

```bash
php artisan sentinel:prune --action=archive
php artisan sentinel:partitions --table=audits --retire="12 months"
```

❌ **Don't** — bolt `OPTIMIZE TABLE` or `VACUUM FULL` onto the scheduled prune. Both are whole-table
rebuilds that contend with the writes the prune exists to make room for, and the audit table is the
one every write in the application touches.

```bash
php artisan sentinel:prune && mysql -e 'OPTIMIZE TABLE sentinel_audits'   # a rebuild at 03:10, nightly
```

---

✅ **Do** — treat exit `1` as evidence, not as a transient failure. The prune refolds every window
before touching a row precisely so that it is never the thing that destroys the record of a
tampering, and it leaves every row in place when the fold disagrees.

```bash
php artisan sentinel:prune --dry-run --stream=tenant:acme
php artisan sentinel:verify --stream=tenant:acme --depth=entries
```

❌ **Don't** — delete a `sentinel_checkpoints` row to "clean up" after a prune, and don't insert a
`sentinel_archives` row by hand. After the first prune the anchor is the only thing standing behind
entries that are gone, and both the prune's tamper guard and verification read manifest rows as
licence.

```php
use ElPandaPe\Sentinel\Models\AuditCheckpoint;

AuditCheckpoint::query()->where('sequence_to', '<', 5000)->delete();   // every later anchor is now unverifiable
```

---

**See also:** [Cold archiving](02-cold-archiving.md) · [Rehydration](03-rehydration.md) ·
[Redaction and tombstones](04-redaction-and-tombstones.md) · [Compliance mode](05-compliance-mode.md) ·
[Checkpoints and anchors](../07-integrity/05-checkpoints-and-anchors.md) ·
[Verification](../07-integrity/06-verification.md) · [Streams](../07-integrity/02-streams.md) ·
[Partitioning](../10-database-engines/06-partitioning.md) ·
[Artisan commands](../09-operations/06-artisan-commands.md) ·
[Scheduling](../09-operations/07-scheduling.md) ·
[Configuration](../99-reference/02-configuration.md) · [Exit codes](../99-reference/07-exit-codes.md)
