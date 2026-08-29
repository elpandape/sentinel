# Changelog

All notable changes to `elpandape/sentinel` are documented here.

## v0.16.1 — Buffer and flush (2026-08-29)

The third mode. `v0.16.0` left the dispatcher with a strategy per mode and `writeMany()` ready
without a consumer; this adds `buffered` without touching the first and gives the second the
consumer it was missing.

With it comes what it drags in: a buffer with a contract of its own and two implementations, a flush
on two thresholds, one at the end of the request and one at worker shutdown, and the first artisan
command the package has ever shipped. And the trade this mode is defined by, written down rather
than implied — what a lost buffer costs, and why the chain cannot tell you about it.

### Added

- **The `buffered` mode.** Entries wait in Redis and settle in batches. The pipeline still runs in
  the request, exactly as in the other two, so nothing sensitive ever waits untransformed and the
  entry carries the context of the request that captured it rather than of whatever process vacates
  it. It is a strategy of the dispatcher `v0.16.0` built: nothing about the other two modes changed.
- **`Contracts\Buffer`**, with `Buffer\RedisBuffer` and `Buffer\MemoryBuffer`. Taking is
  destructive and atomic, because two flushes at once is the normal case rather than the edge one —
  a request terminating while the scheduled command runs.
- **A batch that could not settle goes back**, at the head, in order. A ledger briefly unreachable
  costs a retry on the next trigger and nothing else: what is lost is what a process dies holding,
  never what a write handed back.
- **`Dispatch\Settlement::settleBatch()`**, the consumer `writeMany()` was left without. Reading the
  tail of a stream is the part two writes cannot share, so doing it once for five hundred entries is
  the difference between a mode that scales and one that only defers. The cycle is still announced
  once per entry.
- **Four flush triggers**, and only two are thresholds: `buffer.size` and `buffer.flush_interval`
  when an entry arrives, `terminating` at the end of the request, and `WorkerStopping` — a worker
  never passes through `terminating` between jobs.
- **`php artisan sentinel:flush`**, the fifth, on demand. It is the first command the package ships,
  so it brings the console registration nothing needed until now. It reports how many entries
  settled, exits non-zero on a failure with the reason, and two of them running at once settle the
  same buffer exactly once. Its strings, including its own description, go through `en` and `es`.
- **`sentinel.buffer.connection`**, naming the Redis connection, with its default in code. `store`
  names the driver the way `ledger.default` does, and a store this release does not know is refused
  rather than served by the one that keeps everything on the instance.
- README: *The buffered mode, and what it can lose*, with the four triggers and the reason
  `verifyIntegrity()` cannot report a loss.
- Redis in the dev image, in compose, and in every CI job that runs tests — including the one that
  holds the coverage gate, which had no services at all.

### Changed

- Every mode now has a strategy, so the exception for one that had none is gone with the branch that
  raised it.
- Mutation testing covers the paths `v0.16.0` and this version added. They were writing the whole
  audit path and none of it was being mutated.

### Performance

Median of three passes, on the same machine and in the same run:

| | Per write (µs) | vs. `sync` |
|---|---|---|
| Not audited | 179 | — |
| `sync`, in the request | 2068 | — |
| `queue`, what the request pays | 1077 | −48 % |
| `queue`, what the worker pays to settle one | 1161 | — |
| `buffered`, what the request pays | 1194 | −42 % |
| `buffered`, what the flush pays per entry | 655 | — |

**`buffered` is the only mode that costs less end to end than `sync`** — about eleven per cent less,
against `queue`'s eight per cent more. The batch amortises what a single write cannot share: one
tail read, one transaction, one sequence assignment for five hundred entries. `queue` moves work off
the request and adds a little; `buffered` moves it and removes some. What it asks for in exchange is
the only thing it can lose.

### Upgrade notes

Nothing to migrate: no new columns, no new tables, and `payload_version` stays at `1`. The default
mode is still `sync`.

`sentinel.buffer.connection` is new. If you published `config/sentinel.php` before this tag you do
not have that key, which is fine — it defaults in code to your application's default Redis
connection. Add it if you want audits on a Redis of their own.

Before turning `mode` to `buffered`, read the section the README added: this mode can lose entries,
the two thresholds are what bounds that, and the chain will not tell you when it happens. Schedule
`sentinel:flush` if you need a ceiling on how long anything waits.

## v0.16.0 — Performance modes: the dispatcher, `sync` and `queue` (2026-08-29)

Where an entry settles becomes a setting. The layer the architecture always drew between the
pipeline and the ledger exists now, `queue` is a mode you turn on rather than a thing you build, and
a fact captured in one process and settled in another is still settled exactly once.

The `buffered` mode, the Redis buffer and `sentinel:flush` are `v0.16.1`, which is the other half of
this milestone.

### Added

- **`Dispatch\Dispatcher`, with one strategy per mode.** It is the only place that decides how an
  entry the pipeline approved reaches the ledger, so turning a mode on is configuration and not a
  change to any code that captures. It never assigns a sequence and never computes a hash: those
  stay in the ledger, inside the same operation as the write, in every mode. That is the rule that
  makes a tamper-evident chain and an asynchronous write compatible at all.
- **The `queue` mode.** `mode = queue` moves the write off the request into a job of its own, with
  `sentinel.queue.connection` and `sentinel.queue.queue` deciding where it waits. The pipeline still
  runs in the request — filters, redaction, hashing, encryption and your own policies — so nothing
  sensitive ever waits untransformed, and what travels is the finished entry rather than a model the
  worker would have to re-read.
- **`capture_id` on every captured entry.** The column has had a unique index since the schema was
  written and nothing filled it. It is stamped at the one door every capture goes through, and it is
  what makes a retry recognisable as the same unit of work rather than a second fact.
- **Settlement is idempotent.** A job the queue hands back settles the same capture once. The
  database enforces it; `Contracts\Deduplicates` is an opt-in capability that lets a driver be asked
  first, so a retry of something that already landed costs a query instead of a sealed chain the
  database throws away.
- **`Events\Audited`**, the tenth class of the cycle. It says the process that captured is done with
  the entry, and carries the entry only where capture and settlement are the same place. A `null`
  there means "settled elsewhere", never "not settled".
- **`AuditData::toPayload()` and `::fromPayload()`.** The entry as something that can cross a process
  boundary: unknown keys are dropped and missing ones take their defaults, so a worker on the
  previous release can read a payload the current one wrote. A payload naming its own `sequence`,
  `hash` or `previous_hash` is refused.
- README: *Performance modes*, with the trade-off table, the measured cost of each mode and what
  changes about the two clocks.

### Changed

- **`writeMany()` has a consumer.** A batch takes one sequence assignment and one tail read instead
  of one per entry, and produces the same chain as the same entries written one at a time.
- **`Context\Runtime` suspends and restores instead of assigning.** Artisan announces a command run
  from inside another exactly as it announces the outer one, so clearing on the way out told the
  outer command it had ended — after which every entry named no command and resolved the wrong
  source. `writingAuditEntry()` becomes the scope `whileWritingAudit()`, because it had no way back
  at all and is the first branch the source resolver takes.
- **An operation counts what it handed over** when the entry settles elsewhere.
  `sentinel_transactions.audits_count` would otherwise read zero for every operation under an
  asynchronous mode, since the header closes before the worker runs.
- **A mode this release has no strategy for is refused by name** rather than quietly served by the
  synchronous one. `mode = buffered` names `v0.16.1`.
- The retry that exists for the chain's unique index no longer replays a whole batch over a capture
  identifier. Each attempt drops what settled since the last one, and a batch with nothing left is
  handed back its own violation rather than a silence that reads as success.

### Fixed

- **A restoration asked the schema for its column list on every call.** It is the part of the restore
  cost that does not depend on how many fields are being put back, and on two of the three engines it
  is a round trip to `information_schema`. The answer is now held for the scope. Measured against the
  audited update it replaces: a whole-state restore goes from **+47.3 %** to **+38.2 %**, and one
  named field from **+46.3 %** to **+32.1 %** — the fewer the fields, the larger the share of the
  cost that was fixed overhead.

### Performance

Median of three passes, on the same machine and in the same run, SQLite with `synchronous` and the
journal off:

| | Per write (µs) | vs. `sync` |
|---|---|---|
| Not audited | 160 | — |
| `sync`, in the request | 1991 | — |
| `queue`, what the request pays | 1041 | **−48 %** |
| `queue`, what the worker pays to settle one | 1071 | — |

`queue` halves what the request pays and adds about six per cent to the total. Deferring moves work;
it does not remove it.

**`sync` is unchanged.** Three passes per side with `src/` swapped in the same session put it at
+1.2 %, with the ranges overlapping entirely — the dispatcher is a container resolution and one more
call per write, and what the recorder used to do another class does now.

### Upgrade notes

Nothing to migrate: no new columns, no new tables, and `payload_version` stays at `1`. The default
mode is `sync`, which is what the package already did, so an installation that changes nothing
behaves exactly as it did.

`capture_id` starts being written on new entries. It is outside the canonical payload, so no hash
changes and entries written before this tag keep verifying with the column empty.

Before turning `mode` to `queue`, three things are worth checking:

- **Anything ordering by `created_at` to rebuild a lifeline.** Under `queue` that column is the order
  entries settled, not the order things happened. `Sentinel::timeline()` and
  `Sentinel::audits()->byOccurrence()` read the clock of the fact and are unaffected.
- **Listeners on `AuditCreated`.** It is announced where the ledger assigns identity, which is now
  the worker. `Events\Audited` is the one that fires in the process that captured.
- **Code reading `RestoreResult::$entry`.** It is `null` under an asynchronous mode, the way it
  already was inside a transaction of your own.

**Drain the queue before deploying a change to what an entry means.** The job payload tolerates keys
it does not know and fills in ones it is missing, so a rolling deploy with both releases running is
safe for anything additive; it cannot rescue a payload whose fields have been reinterpreted.

`Context\Runtime::writingAuditEntry()` is gone, replaced by `whileWritingAudit()`. It is internal
plumbing for the source resolver and is not part of any documented surface.

## v0.15.0 — Lifecycle events and serialization (2026-08-29)

The whole life of an entry is observable from outside, and what an entry looks like as data stops
being something that can move under you. Nine event classes, one door out for anything that gets
stopped, one setting for what a failed write does to the request — and from this tag, the keys of
`toArray()` only ever grow.

### Added

- **`Events\Auditing`, cancellable.** The application's own say over an entry, at the end of the
  pipeline and before the ledger. A listener returning `false` stops it, and refusing there costs no
  `sequence`, so the chain the entry never joined has no gap. It comes **after** masking, hashing
  and encryption for the reason the policy stage is last: before them the entry holds the plaintext
  of every declared field, and for an entry a stage is about to drop that payload is never
  transformed at all.
- **`Events\AuditCreating` and `Events\AuditCreated`.** The ledger boundary, on both sides of it:
  the last word about an entry with no identity, and the first about one that has a stream, a
  sequence and a hash. Announced from the one door to the ledger rather than from a driver, so a
  fanout over three destinations is still one write and one pair of events. Inside a transaction
  they wait for the commit, so a rollback announces neither.
- **`sentinel.on_write_failure`**, `throw` or `log`, with `sentinel.log_channel`. Under `log` a
  failed write is recorded — identity and the exception, never the payload — and the request goes
  through. One default for every environment, and that default is `throw`, which is what the package
  already did: nothing changes until you ask for it. `compliance` overrules the setting.
- **`Http\Resources\AuditResource`**, the same shape over HTTP, adding no key and renaming none.
  The package mounts no routes for it: which entries a request may see is an authorisation question.
- **`source_audit_id` and `integrity.verified` in `toArray()`.** The first is what a restoration has
  written since it existed and nothing published. The second is three-valued and always `null` until
  `v0.18.0` — it means "not checked in this call" and never "failed".
- README: *Events* and *Serialization*.

### Changed

- **`toArray()` is a frozen public contract.** Its top-level keys and its `integrity` block can only
  grow from here: none is renamed, removed, or quietly reinterpreted. A snapshot test names every
  one of them and runs over the entries frozen since `v0.3.0`.
- **`RestoreResult` and `Enums\Omission` are frozen under the same rule**, and stay outside
  `toArray()`: they answer a call rather than describing an entry.
- **`Events\AuditRestored` is announced from a commit callback**, so its result always carries the
  entry that recorded the restoration — including inside a transaction of your own, where the call
  itself returns before that entry exists. A rollback now announces nothing.
- **Nothing the package publishes inherits the key order an engine stored a JSON column in.**
  Relation lines and their pivot maps come out in the order fixed at capture, and labels are ordered
  in PHP rather than by a collation.
- `Events\AuditWriteFailed` is now dispatched on the synchronous write as well as the deferred one.
- A restoration is recorded even inside `Sentinel::withoutAuditing()`.

### Fixed

- **A restoration sealed the masked or hashed form of its own explanation.** The summary it writes
  keyed omissions by field name, and the security stages match a protected field by key at any depth
  of `metadata` — so a model declaring a redacted field sealed `r****d_f****d` where the word
  `redacted_field` belonged, and a hashed one sealed a digest of it. It is inside the canonical
  payload, so nothing could correct it after the write: the entry verified, and what it said was
  wrong. The omissions now travel as a list of `{field, reason}` pairs.
- **A stage that wrote to an audited model closed the discard window of the pass it was running in**,
  after which the next stage asking to discard threw out of the application's own `save()` claiming
  the ledger had already assigned a sequence — which had not happened. A pass now suspends the one
  it began inside and hands it back intact.
- **A strict fanout rethrew before announcing**, so the policy that most needs `LedgerDestinationFailed`
  was the one that never received it, even though the primary had already sealed and stored the entry.
- **`toArray()` threw on a `changes` column it could read as neither diff entries nor relation
  lines**, taking the whole serialisation with it — including on one of the entries frozen to prove
  payload version one still means what it meant. Only a row this package did not write can be in
  that state, and it now goes back as it was found.

### Upgrade notes

Nothing to migrate: no new columns, no new tables, and `payload_version` stays at `1`.

Two changes are visible to code that reads what a restoration produced:

- `metadata.restore.skipped` inside a `restore` entry is now a list of `{field, reason}` objects
  instead of a map keyed by field. Entries written before this tag keep the old shape and still
  verify; the two are told apart by whether the value is a list.
- `RestoreResult::$skipped` in PHP is unchanged — still a map of field to `Omission`.

If you relied on a failed audit write never reaching your request, set
`SENTINEL_ON_WRITE_FAILURE=log`. The default, `throw`, is what the package already did.

## v0.14.0 — The Restore Engine (2026-08-28)

An entry stops being read-only. Point at any row of the trail and the record goes back the way that
row found it — all of it, some named fields, or one of its relations. Nothing is rewritten and
nothing is deleted: a restoration is one more entry, pointing at the one it came from.

### Added

- **`$audit->restore()`**, **`$audit->restore(['email', 'role'])`** and
  **`$audit->restoreRelationship('members')`**. An entry photographs the record at a moment, and
  restoring it goes back to that moment — the state on its `after`, and on its `before` only for a
  deletion, whose `after` is empty because there was no record left to photograph.
- **`Restore\RestoreResult`**, with what was applied, what was skipped and why, what refused the
  whole restoration, and the entry that recorded it. No method on it returns `bool`: a restoration
  that put back four fields out of six is neither a success nor a failure.
- **`Enums\Omission`**, thirteen reasons with a line in both languages. Five refuse the whole
  restoration — no record, a redacted entry, a tampered entry, an entry with no state, a listener
  that said no — and the rest refuse one key and let the others through.
- **The entry's hash is verified before anything is touched.** Restoring is the only thing the
  package does that writes into the business model out of what the ledger holds, so an entry that no
  longer reproduces its own hash restores nothing.
- **A masked value and a digest never go back**, and an encrypted one is read with the `key_id` the
  entry recorded — a key that has left the keyring skips the field rather than writing ciphertext
  into the record. The primary key is never restored either.
- **A field the schema no longer has is skipped and the rest still goes back**, with the reason on
  the result.
- **`audit_type = restore`**, with `source_audit_id` pointing at the entry it came from and a
  `metadata.restore` summary of what was applied and what was declined, sealed inside the canonical
  payload. One entry per call, however many fields or pivot rows it moved.
- **`Events\AuditRestoring`, cancellable, and `Events\AuditRestored`.** Returning `false` from a
  listener stops the restoration and the result says so. That is the whole answer to who may
  restore: the package imposes no gate on a write into your business model. `AuditRestored` follows
  after the commit, with the closed result.
- **A record in the recycle bin comes back out of it** on a whole restoration. A granular one does
  not: you named the fields you wanted.
- README: *Restoring state*.

### Changed

- **Whether an entry carries relation lines is asked of the lines, not of the entry's type.** The
  diff reader, the serialiser and the ledger's projection to `sentinel_audit_relations` all decided
  by `audit_type === 'relation'`; a restoration carries the same lines under a type of its own.
  Without the change the projection skipped it and `whereRelation()` stopped finding it. Behaviour
  for every existing entry is identical — the type was only ever a proxy for the shape.
- **`Capture\Recorder::record()` takes an optional callback** that is handed the entry on whichever
  path wrote it. With the deferral on, which is the default, a caller that opened the transaction
  itself would otherwise never learn what the ledger settled after its commit. No existing caller
  changes.

### Notes

- **A restoration is not a transition.** An entry with `audit_type = restore` does not appear in
  `Sentinel::transitions()`, even when it moves a column named in `$auditTransitions`, and the state
  machine does not govern it. A lifeline answers which states the workflow moved through, and a
  correction made by an operator is not one of them.
- **Restoring the same entry twice writes nothing the second time.** There is no movement to record,
  and an entry for it would be a link in the chain describing no change.
- **Auditing is paused for the save**, so the trail carries the restoration and not a restoration
  plus an `updated` describing the same movement backwards. The pause is released in a `finally`.
- **Restoring needs the snapshot.** A model with `$auditSnapshots = false` has no state to put back,
  and the result says so rather than half-restoring from a diff.
- **`event = 'restored'` is eloquent's** — a soft-deleted record coming back — while
  `audit_type = 'restore'` is this engine. Different facts, and the filters tell them apart.
- No migrations, no change to the canonical payload, and `payload_version` stays at 1. An entry
  frozen in `v0.3.0` still restores.

## v0.13.0 — State transitions (2026-08-28)

`draft → pending → approved → paid` is the question a trail gets asked about a document, and
answering it from a pile of `updated` entries meant mining every diff for one column. This version
makes a state change an entry of its own kind, with where it came from, where it went, why, and how
long the record had been where it was.

### Added

- **`Sentinel::transition($invoice, from: Status::Pending, to: Status::Approved)`**, with `on()`,
  `reason()`, `actor()`, `severity()`, `tags()`, `metadata()` and an explicit `record()` terminal.
  `audit_type = transition`, through the same pipeline and the same ledger as everything else.
- **`$auditTransitions = ['status']`**: an `update` that moves a declared column is written as a
  transition instead of the generic model entry. No call site changes, and a model that declares
  nothing audits exactly as before.
- **A backed enum, a pure enum and a plain string reach the entry as the same scalar**, read the way
  the snapshot builder reads them, so a transition and the snapshot of the same column never
  disagree.
- **The two states are filed as a diff line** under the column that moved, so
  `whereFieldChanged('status')` finds them with no new filter and no new index. The column is named
  by `->on()`, inferred from `$auditTransitions` when it declares exactly one, or taken from the new
  `transitions.attribute` config key, which ships as `status`. A model declaring more than one and a
  call naming none is refused, not guessed.
- **`Contracts\DeclaresTransitions`**, optional: a model that implements it can refuse a move it
  does not make. The refusal raises `Transitions\IllegalTransition` **before the save**, so neither
  the row nor the trail moves — refusing after the update was announced would leave the record
  holding a state the trail says never happened. Sentinel asks; it does not execute.
- **`Sentinel::transitions()`**, the lifeline: every state a record moved through, in order, with
  `from`, `to`, `reason`, `actor`, `occurredAt` and how long it had been in the state it just left.
  It composes `for()`, `by()`, `between()`, `latest()` and `take()`, and is always ordered by the
  clock of the fact.
- **`whereType()` and `Filter::Type`** on the query surface: the kind of entry, which no filter
  covered. An application is free to call its own stated fact `updated`, and only the type tells it
  apart from a model change. It rides the `(audit_type, created_at)` index the table already had.
- **The presenter and the timeline render a transition as `from → to`**, in both languages.
- **`LedgerContractTestCase` covers the new filter**, so a third-party driver that declares
  `Filter::Type` is held to it like every other published filter.
- README: *State transitions*.

### Changed

- **`Contracts\Auditable` gains `auditTransitions()`.** See the [upgrade guide](UPGRADE.md).
- **A state column has to be readable.** A column in `$auditTransitions` that is also excluded,
  redacted, encrypted, hashed, or left out of a declared `$auditInclude` raises a
  `ConfigurationException` the first time the model is audited. A lifeline the entry cannot show is
  not a lifeline.

### Notes

- **One save is one entry.** An `update` that moves the state and three other columns writes a
  single transition carrying the whole diff. Every prior art collapses an eloquent event to one
  record for the same reason, and splitting it would invent a second fact where there was one.
- **A record that had no state and acquires one is a transition from nothing**, not a non-event —
  otherwise a lifeline would always be missing its first step.
- **A column holding a structure is left to be the ordinary edit it is.** Nothing a person calls
  approved is an array.
- **The elapsed time is computed on read and stored nowhere.** No prior art persists it either: it
  is a fact about two entries rather than about either of them, and an entry carrying it would be
  wrong the moment an earlier one was archived away. It is computed in PHP rather than with a
  window function, because a driver that is not SQL has to answer the same question.
- **There is no `paginate()` on a lifeline**: the interval of a page's first row is the distance to
  an entry the page does not hold. `->entries()` drops back to the query underneath.
- **Transitions only exist from the moment you declare them.** Adopting `$auditTransitions` does not
  rewrite the `updated` entries that already described state changes, so a lifeline that starts late
  is exactly that and not a gap in the chain.
- **Cost.** Declaring a state column adds nothing measurable to an audited update that does not
  touch it (+0.3% and +0.8% over two runs). An update written as a transition costs +5.4% and +6.5%
  over the same update written as a change. A transition stated outright costs about 70% of an
  audited update: it carries no snapshot pair and no diff to compute.

No migration, no schema change, `payload_version` stays at `1`.

### Upgrade notes

`Contracts\Auditable` gains one method. Nothing to do if your models use the `Concerns\Auditable`
trait. If you implement the interface by hand, add `auditTransitions(): array` returning `[]`;
[UPGRADE.md](UPGRADE.md) has the snippet. Nothing else changes and there is no migration.

## v0.12.1 — Custom events and authentication events (2026-08-28)

`v0.12.0` gave a business operation a name. This one stops "auditable" from meaning "a model
changed": a fact the application states outright and a login it did not are entries like any other,
through the same pipeline and the same ledger.

### Added

- **`Sentinel::event('invoice.approved')`**, with `actor()`, `subject()`, `severity()`, `tags()`,
  `metadata()` and an explicit `record()` terminal. `audit_type = custom`, and the event name is
  whatever you called it. Nothing is written until `record()` is called, and it returns nothing —
  with the write waiting for a commit, the entry does not exist yet when the call comes back.
- **A fact with no subject stays subjectless.** Some things that happen are not about a record.
- **An actor named outright wins over the resolved one**, including `->actor('system', 'cron')` for
  an actor that is not a model.
- **`AuthenticationSubscriber`**, opt-in, over `Login`, `Logout`, `Failed`, `Lockout` and
  `PasswordReset`. `audit_type = auth`, the guard in `metadata`, the person recorded as both actor
  and subject so `->by()` and `->for()` both find it. The retention policy for `auth` finally has
  something to purge, and the timeline stops promising a type nobody wrote.
- **Severity defaults for the auth events**, in the `severity.events` section that already existed:
  `failed` is `warning`, `lockout` is `critical`, `password_reset` is `notice`. `login` and
  `logout` name no override and fall through to `severity.default`, which ships as `info`.
- README: *Custom events* and *Authentication events*.

### Notes

- **The credentials of a refused attempt are never captured.** `Failed` carries them and the
  subscriber does not look at them; not capturing is a stronger guarantee than capturing and
  redacting afterwards.
- **`Lockout` and `PasswordReset` are not fired by the framework** — there is no `new Lockout(`
  anywhere in `laravel/framework`. They come from your application skeleton or a starter kit, so a
  bare install gets three of the five. The README says which is which.
- Registering nothing writes nothing. A package that started recording who logs in the moment it
  was upgraded would be making that decision for you.
- An actor named outright is reapplied after the pipeline, because the context stage reassigns that
  column on every pass by design. The chain is unaffected: the hash is sealed after. Two
  consequences worth knowing: a resolved impersonator is dropped along with the resolved actor,
  because it stood in for that actor and not for the one you named; and a `Sentinel::filter()`
  policy sees the **resolved** actor, since policies run inside the pipeline and the swap happens
  after it.
- An event name longer than the 64 characters the column holds is refused at the call that wrote
  it, the way an over-long label already was. It has to be: the name is inside the hash, so an
  engine that truncated instead of raising would leave an entry that never verifies again.

No migration, no schema change, `payload_version` stays at `1`.

## v0.12.0 — Business transactions and deferral to the commit (2026-08-28)

A payment that touches an invoice, a payment record and two relations wrote four entries that only
shared a `request_id` — which groups a whole request, not an operation. And an entry captured inside
a `DB::transaction()` was written whether or not that transaction survived. This version names the
operation and makes the entry wait for it.

### Added

- **`Sentinel::transaction('invoice-payment', fn () => …)`**, which gives every entry captured
  inside it the same `transaction_id` and the operation a header of its own. It returns what the
  callback returned. It **correlates and does not atomise**: it opens no database transaction, and
  combining the two stays your decision.
- **`sentinel_transactions`**, the header: name, actor and tenant resolved exactly as an entry
  resolves them, the window it ran in, and how many entries it wrote. Opened *before* the operation
  runs, so one that died halfway is still findable, and closed after — including when the operation
  threw, with the class of the failure in `metadata`. The class and not the message: a header does
  not go through the pipeline, and an exception message is where a domain value ends up.
- **Nesting keeps the outer identifier** and opens no second header. An operation does not split
  because its implementation reuses code that already wrapped itself, and the inner name is kept in
  the header's `metadata` rather than lost.
- **`transactions.after_commit`, on by default**, finally has a reader. An entry captured inside a
  database transaction is written when it commits and never if it rolls back; a rollback to a
  `SAVEPOINT` discards only that level. Both are the framework's own behaviour rather than a second
  mechanism on top of it.
- **`Events\AuditWriteFailed`**, announced when a deferred write fails. Laravel runs commit
  callbacks in a bare loop, so an exception there would stop every later entry of the same
  transaction from even being attempted — an append-only engine silently losing the rest of an
  operation because the first entry hit a constraint.
- **`Models\AuditTransaction`**, replaceable through `models.transaction`, with `$audit->transaction`
  and `$operation->audits` walking between the two ends. `inTransaction()` now takes the operation
  itself as well as its identifier.
- README: *Business transactions*, and `sentinel_transactions` in *Schema & models*.

### Changed

- **What waits for the commit is the write, never the pipeline.** Redaction, encryption and context
  resolution run at capture, because the context is only true then: the actor can change before the
  commit, `Sentinel::withContext()` is restored the moment its callback returns, and the tenant
  decides which chain signs the entry. A rollback therefore still costs the pipeline work — that is
  cost, not correctness.
- **Outside a transaction nothing changes at all**, failures included. An installation that never
  opens one behaves exactly as it did.
- Both captures now reach the ledger through one door rather than two, which is what makes a
  correlation and a settlement decision something that can be true of every entry rather than of
  two out of two places.

### Notes

- Deferring is **honesty, not speed**: measured on the same machine, an audited write inside a
  transaction costs the same with the option on and off. What it buys is that a fact the database
  did not keep leaves no entry.
- `occurred_at`, and only `occurred_at`, numbers the fact. `created_at`, `sequence` and `version`
  number the settlement, and that order is sealed into the hash.
- Where the ledger shares the connection that rolled back, the database had already undone the
  entry. `after_commit` is what covers the case where it does not — a dedicated connection, a
  ledger that is not this database, a fanout to somewhere external — and it also stops the chain's
  stream lock from being held for the length of the business transaction.

### Upgrade notes

One new migration, `sentinel_transactions`. It is additive and reversible and does **not** touch
`sentinel_audits`, whose `transaction_id` column and index have existed since `v0.2.0`.

```bash
php artisan vendor:publish --tag=sentinel-migrations   # only if you publish them
php artisan migrate
```

Existing entries keep `transaction_id = null` and the new table starts empty. There is no backfill
and there will not be one: inferring operations over history already settled would be inventing
facts.

`payload_version` stays at `1` and the golden dataset verifies unchanged. No API was removed;
`inTransaction(string)` still compiles, it just accepts the header too. See [UPGRADE.md](UPGRADE.md).

## v0.11.1 — The parent side of a relation (2026-08-28)

`v0.11.0` records what happens to a pivot table. A `belongsTo` has none, so when an article changes
author the only thing Eloquent announces is the article's own update — and two parents lived a
change of relation that nothing wrote down. This version writes it.

### Added

- **`$auditParents`**, a map of the `belongsTo` relations whose parent gets an entry when this model
  changes hands, keyed by the relation on the child and naming the collection on the parent. A map
  and not a list: the entry hangs off the parent, so its line needs the name the parent gives that
  collection, and a list would have to invent it.
- **Two entries, not one.** A foreign key that moves leaves a `detached` on the parent it left and
  an `attached` on the parent it joined. One entry holds one subject, and a hand-over has two.
- **The line is the one `v0.11.0` froze** — same shape, same projection, same three filters, same
  `+ / -` render — with the child as the related record and `pivot_before`/`pivot_after` null,
  because there is no pivot. The `api` in `metadata` is `foreign_key`: there was no method to
  intercept, the fact is that the column changed.
- **A parent that has since been deleted still gets its entry**, because the foreign key is the
  name and nothing has to be read to write it. When the relation points at a column other than the
  parent's primary key the parent is read once per end, and an end that resolves to nobody is left
  unsaid.
- README: *When there is no pivot at all*, inside *Relationship auditing*.

### Changed

- **`Contracts\Auditable` gains `auditParents()`.** See the [upgrade guide](UPGRADE.md).
- **A declaration that is not a plain `belongsTo` is refused**, by name, rather than half-audited: a
  `morphTo` moves the type as well as the key, and that is out of scope until after `1.0`.

No migration, no schema change, `payload_version` stays at `1`. A model that declares nothing
audits exactly as it did before.

## v0.11.0 — Relationship auditing (2026-08-28)

Eloquent fires **no event** when a pivot table is touched: `attach()` inserts and `detach()` deletes
straight through the query builder. Every auditing package in the ecosystem treats that as a
documented limitation and asks you to call a different method. This version wraps the relation
instead, so `$team->members()->sync([...])` is written exactly as it always was and is audited.

### Added

- **The six pivot operations** — `attach`, `detach`, `sync`, `syncWithoutDetaching`, `toggle` and
  `updateExistingPivot` — on `belongsToMany`, `morphToMany` and `morphedByMany`. The trait overrides
  the two relation factories every many-to-many is built by; both polymorphic directions come
  through one of them.
- **One call, one entry.** A `sync()` that attaches two and detaches one writes a single entry with
  three lines. `sync()` and `toggle()` are built out of the other operations, and none of those
  inner calls writes anything of its own.
- **A `sync()` that changed nothing writes nothing**, and takes no sequence number with it, so the
  chain has no link claiming nothing happened.
- **`sentinel_audit_relations`**, the indexable projection of the lines, written in the same
  transaction that seals the entry and reachable as `$audit->relations`. `append()` projects too, so
  a secondary destination lands with the index the primary wrote.
- **`whereRelation()`, `whereRelated()` and `whereOperation()`**, plus
  `$model->relationHistory('members')`. The three narrow the same line, so an entry answers only
  when one of its lines satisfies all of them at once — asked separately, "when was Ada detached"
  would also be answered by the entry that attached Ada and detached somebody else.
- **A line says what happened, not what was called.** `operation` is `attach`, `detach` or `update`.
  Most attachments in a real application are made by `sync()`, and a filter for attachments that
  could not find them would answer the wrong question. The method called travels in the entry's
  `metadata`, which the hashed payload covers.
- **Pivot columns are protected by the parent**, with the `$auditRedact`, `$auditEncrypt` and
  `$auditHash` it already declares. Both sides of a changed pivot are covered, and the hash still
  runs over the ciphertext.
- **`AuditPresenter` renders the block**: the relation closes the sentence and the records go under
  it, `+` attached, `-` detached, `~` pivot changed, in `en` and `es`.
- **A relation entry in the golden dataset**, frozen with its canonical string and its hash.
- README: *Relationship auditing*.

### Changed

- **`Contracts\Auditable` gains `relationHistory()`.** See the [upgrade guide](UPGRADE.md).
- **A driver must declare the three new filters to answer them.** See the
  [upgrade guide](UPGRADE.md).
- **`$audit->diff()` reads a relation entry instead of failing on it** — attach as an addition,
  detach as a removal, a pivot change as a replacement, pointed at the relation and the record under
  it. That is presentation and never storage, and it is what lets one caller walk a mixed trail.
- **The unchanged filter tests for a comparison that came back empty**, rather than only for an
  update. A creation with no comparable fields is still kept.

### Upgrade notes

`sentinel_audit_relations` is created: additive, reversible, and `sentinel_audits` is untouched.
`payload_version` stays at `1`. Full steps in [UPGRADE.md](UPGRADE.md).

## v0.10.1 — The label plan gate (2026-08-28)

Nothing in `src/` changed. `v0.10.0` was tagged with a green local run and left `main` red: the five
jobs that run on SQLite failed on one assertion about a query plan, and the MySQL 9 and PostgreSQL 16
jobs passed.

### Fixed

- **The label plan gate asserted the name of an index instead of the seek it stands for.** Which
  index answers a label filter is the planner's call, and it is not the same one across versions of
  the same engine: SQLite rewrites the correlated `EXISTS` into a semi-join from **3.51** on and
  seeks `(tag, audit_id)`, while every version before it evaluates the `EXISTS` once per row with
  the entry id already fixed and seeks `(audit_id, tag)`. Both are a seek and both return the same
  entries. The dev container runs SQLite 3.53.2 and CI runs the 3.45.1 that Ubuntu 24.04 ships,
  which is the whole of why it passed in one place and failed in the other. The suite now asserts
  that the labels table is reached through an index and never walked, checked against the real plans
  of 3.45.1, 3.50.1, 3.51.0 and 3.53.2.

### Changed

- **A failed plan assertion now prints the plan it read.** The one that broke CI said only that
  false was not true, which names neither the engine's choice nor the statement it was made for.
- **Every CI job records the version of the engine it is about to run against**, before it runs
  anything.
- **README gains *Engines***: the versions that are run on every push, kept separate from the floor
  the emitted SQL needs — `jsonb` from PostgreSQL 9.4, `JSON_TABLE` from MySQL 8.0.4, JSON built in
  from SQLite 3.38. Only the first is a support claim.

## v0.10.0 — Field history, labels and timeline (2026-08-28)

### Added

- **Labels.** A model declares what its entries are classified as the way it already declares their
  severity (`protected array $auditTags = ['billing'];`), the `tags` configuration gives every entry
  the ones an installation wants, and a new pipeline stage gathers both before anything can discard
  the entry. They live in `sentinel_audit_tags`, keyed by the pair and indexed the other way round,
  so asking for a label is a seek rather than a pass over a JSON column.
- **`whereTag()` and `whereAnyTag()`** — every label named, or at least one of them. One criterion
  for both spellings, and asking twice accumulates: `whereTag('a')->whereTag('b')` and
  `whereTag(['a', 'b'])` are the same question.
- **`whereFieldChanged()`**, and the same reading exposed as a scope: `$user->audits()->field('email')`.
  Touching a field means the pointer or anything beneath it, which is what `$audit->diffFor()`
  already meant by it, so `whereFieldChanged('profile')` finds a change to `/profile/address/city`.
- **`Sentinel::timeline()`** — the whole trail in the order things happened, ordered by
  `occurred_at` with the entry's own identifier behind it. `byOccurrence()` is the criterion behind
  it and composes with every filter.
- **`AuditCollection::loadReferences()`**, which resolves subjects, actors and labels for a page of
  entries in a query per morph type rather than a query per line. A recorded type that names no
  class is left unresolved rather than fatal — an entry outliving its subject is the normal case.
- **`compare()` and `comparedTo()`**, which answer what changed between two versions of a subject
  that need not be adjacent. Both hand back a `Query\Comparison`: the two entries and the diff,
  because a diff has one way to say nothing and several ways to arrive there.
- **`whereVersion()`**, a refiner, and what `compare()` is built on.
- **`take()`**, a prefix asked for on purpose.
- **`Presentation\AuditPresenter`** — an entry, a field history and a timeline as text, in English
  or Spanish. An impersonated entry reads «Administrator #1 acting as User #100 changed
  Invoice #500» from its own language key, not from a clause bolted onto the plain one.
- **`$audit->toArray()`** in a first form. **Not a frozen contract**: keys can move in any minor
  until `v0.15.0` declares it stable.
- README: *Field history*, *Timeline*, *Labels*.

### Changed

- **`get()` refuses a read it would have to truncate.** See the [upgrade guide](UPGRADE.md).
- **A driver is assumed to answer only the filters published with the contract**, not every case the
  enum grows to hold. See the [upgrade guide](UPGRADE.md).
- **`Contracts\Auditable` gains `auditTags()`**, and the pipeline gains a stage a published
  configuration will not pick up on its own. See the [upgrade guide](UPGRADE.md).
- **Labels come back loaded from every read.** An unloaded subject reads as `null`, which is
  legible; an unloaded label list reads as an empty one, which is a claim the entry never made.
- **`Ledger::append()` stores the labels it was handed**, in its own transaction. An entry stored
  without the labels it arrived with is not the entry that arrived.
- **The package loads its migrations one at a time**, so an application that published the first one
  still receives the ones that came after it.
- The contract suite holds a driver to one of two answers for every published filter — it translates
  it, or it refuses it — instead of skipping the expectation for the ones it never claimed.

### Fixed

- **A malformed `changes` column no longer answers with fewer entries than it should.** SQLite
  stores it as bare text, and an unguarded JSON walk over something unparseable is answered by the
  driver with a partial result and no exception. Every engine now guards the column before walking
  it.

### Upgrade notes

Two additive migrations, four changed contracts, one read that now refuses. The whole of it is in
[UPGRADE.md](UPGRADE.md#v090--v0100).

## v0.9.0 — Query API (2026-08-27)

### Added

- **`Sentinel::audits()`**, which hands back an `AuditQuery`: a description of what you want, stated
  against the ledger contract instead of against Eloquent. Nine filters, each naming one indexed
  criterion — `for()`/`forModel()`, `by()`/`byActor()`, `whereEvent()`, `whereSeverity()`,
  `whereSource()`, `forTenant()`, `inTransaction()`, `withTrace()` and `between()` — plus `latest()`
  to turn the order around. Nothing returns a query builder and no method takes a column name, so
  every read goes through `Ledger::query()` and there is no shortcut around it.
- **The query is immutable.** Every method returns a new one, so a query handed to something else
  cannot be narrowed behind the caller's back.
- **`Ledger::query()` implemented by all four drivers.** `DatabaseLedger` compiles the criteria into
  a statement whose every value is a binding; `MemoryLedger` answers the same criteria by walking
  what it holds, with no database at all; `NullLedger` answers with nothing however narrow the query
  was; a fanout answers from its primary.
- **`paginate()`**, returning a `Query\AuditPage` with the entries, the page, its size and whether
  another page follows. One call to the ledger per page: it asks for one entry more than it hands
  back, and learns from that whether there is another. There is no total — counting what a filter
  matches on a table that only grows is the one question here whose cost is unbounded and that no
  index answers.
- **`Contracts\DeclaresFilters`**, optional. A driver over a store that cannot translate one of the
  filters declares the ones it can, and the query refuses the rest **as they are added**, not when it
  runs. A driver that does not implement it answers all of them.
- **`Support\Reference`**: `for()` and `by()` take a model, or the type and key the entry recorded.
  A hard-deleted subject has no model left to hand over, and its trail is exactly what outlives it.
- `LedgerContractTestCase` gains the whole query suite — every filter, the combinations, the order,
  a period bounded at both ends and paging. A third-party driver that extends it inherits all of it
  without writing a test.
- README: *Querying the trail*.

### Changed

- **`get()` is bounded** at `AuditQuery::DEFAULT_LIMIT` entries. A trail has no natural end, so a
  read with no bound is a read of the whole table.
- **`between()` bounds `created_at`**, the clock the ledger stamps the entry with, both ends
  inclusive — not `occurred_at`, which has no index and comes apart from it the moment writing stops
  being synchronous. It is a **refiner**, like `whereSource()`: it narrows a result, it does not find
  one, and on MySQL and PostgreSQL either one alone walks the table.
- **The order is `created_at` with the entry's ULID behind it**, oldest first unless `latest()` says
  otherwise. It is total on every driver: two entries stamped in the same microsecond still come back
  in the order they were written.
- `payload_version` stays at `1`. This version only reads: no entry is written, no hash recomputed,
  and the entries frozen in `v0.3.0` come back through the new surface reproducing the hashes they
  were frozen with.

### Removed

- `Exceptions\LedgerException::queryNotImplemented()`. The four drivers answer now, and one of them
  answering with nothing is an answer.

### Upgrade notes

Nothing to migrate: no new tables, no new indexes, no altered columns, no rewritten hashes. The
query plan of every published filter was measured on MySQL 9 and PostgreSQL 16 and none of them
needed one.

- **Implementations of `Contracts\Ledger` must implement `query()` for real.** The signature has been
  on the interface since `v0.2.0` and there was nothing to answer until now. A driver that cannot
  translate a filter should implement `Contracts\DeclaresFilters` and leave it out, rather than drop
  it silently: a trail that shows the wrong history is worse than one that refuses to answer.
- **`LedgerException::queryNotImplemented()` is gone.** Nothing could usefully call it.

## v0.8.0 — Ledger contract & drivers (2026-08-27)

### Added

- **`Contracts\Ledger::append()`**, which stores an entry that arrives already sealed, exactly as it
  is: no sequence assigned, no hash recomputed. It is how a secondary destination takes what the
  primary sealed, and how an archive or a replica takes an entry it did not write.
- **`Ledger\FanoutLedger`**, one entry to several destinations — a hot store plus a cold one, or
  either plus a search satellite. Only the first destination numbers the chain and seals the hash;
  the rest receive what it sealed, and reads go to the first. Two failure policies: `strict` fails
  the write if any destination refuses, `primary` fails only for the first and raises
  `Events\LedgerDestinationFailed` for every other refusal.
- **`Ledger\MemoryLedger`**, the whole contract over plain arrays, chained with the algorithm the
  database driver uses. A reference implementation and a test double, never a store: everything it
  holds dies with the instance, and the shipped configuration will not name it as the default.
- **`ElPandaPe\Sentinel\Testing\LedgerContractTestCase`, published as production code.** Extend it,
  return your driver, and your driver is held to the same chain the four in this package are. PHPUnit
  and Testbench stay development dependencies and are declared in `suggest`. A contract nobody
  outside this package can execute is a promise, not a verification.
- `ledger.ledgers.fanout` in the configuration: destinations in the order they are written, and a
  failure policy. A fanout that names itself is refused rather than looped.
- README: *Ledger drivers* rewritten around four drivers, and *Writing your own driver*.
- `UPGRADE.md`, which starts here.

### Changed

- **The contract states three guarantees it does not make**, because a store without transactions
  cannot honour the strong form and a contract nobody can implement is one that gets ignored:
  `writeMany()` is not atomic, no read is promised to see a write that just returned, and idempotency
  by `capture_id` belongs to the caller. `DatabaseLedger` still wraps a batch in a transaction and
  still reads its own writes — the contract simply stops requiring every driver to.
- **`NullLedger` keeps nothing.** It still builds, seals and chains every entry, so the code path an
  application measures is the same one, but it retains only the tail of each stream and a version
  counter per subject — the two things the next entry is sealed with.
- The published suite stops assuming SQL: the two expectations that queried `sentinel_audits` were
  about one driver, not about the contract, and the driver's own tests already covered them.
- `payload_version` stays at `1`. Nothing about how an entry is sealed changed, and every entry
  frozen before this version reproduces its hash byte for byte through both a driver with a table and
  one without.
- `make test-dbs` recreates the schema on both engines before it runs, instead of trusting whoever
  ran it last.

### Removed

- `--tag=sentinel-factories`. It copied a class in this package's namespace into a directory an
  application maps to its own, so what it produced was either never loaded or a collision. The
  factory ships with the package and is autoloaded from it.

### Upgrade notes

Full detail, with the before and the after of each change, in [UPGRADE.md](UPGRADE.md).

- **Implementations of `Contracts\Ledger` must add `append()`.** It stores the entry verbatim. An
  implementation that reseals what it is appended is wrong: two ledgers each numbering their own
  chain produce two different truths about one fact.
- **The `null` driver no longer answers `find()` or `stream()`.** If you were reading entries back
  out of it, the driver you want is `memory`, which is what that behaviour now is.
- **`Tests\Testing\LedgerContractTestCase` is now `Testing\LedgerContractTestCase`**, and `persists()`
  is gone. A driver that keeps nothing answers `false` to `retains()`; a driver whose reads are
  eventually consistent makes its writes visible in `settle()`.
- **`vendor:publish --tag=sentinel-factories` no longer exists.** If you published it, delete the
  copy: it never changed anything.
- Nothing to migrate. No new tables, no altered columns, no rewritten hashes.

## v0.7.0 — Pipeline & data security (2026-08-27)

### Added

- **The write pipeline.** Every entry now travels capture → pipeline → ledger. The `pipeline` config
  key is the ordered list of stages, not a set of flags: reorder them, replace one, or slot your own
  in between by implementing `Contracts\Transformer`. The shipped order is `FilterUnchanged` →
  `ResolveContext` → `NormalizeData` → `MaskSensitiveData` → `EncryptSensitiveData` →
  `EnforcePolicies`. The pipeline runs during the capture, inside the request, never behind the
  queue or the buffer — whatever gets queued has already been transformed.
- **Field-level protection**, four mechanisms declared per model and reaching five containers each:
  `before`, `after`, both sides of every entry in `changes`, `metadata` and `context`. Matching goes
  by key name at any depth, so one declaration covers a field wherever it surfaces. `$auditExclude`
  keeps the field out entirely, `$auditRedact` leaves a mask, `$auditHash` leaves a salted digest,
  `$auditEncrypt` leaves ciphertext. A protected field that changed still shows its path.
- **`Security\PartialMasker`**, the default masker: it keeps the shape and both ends of each run and
  masks a fixed width, so the length of a secret is not part of what survives. Replaceable for every
  field through `security.redaction.masker`, or one field at a time through
  `security.redaction.maskers`.
- **`Security\Keyring`**, keys addressed by identifier. Every encrypted entry records which key wrote
  it in `encryption`, which is what makes rotation possible without invalidating anything already
  written. `security.encryption.keys.default` falls back to `APP_KEY`.
- **`Security\Rekeyer`**, rotation that writes instead of rewriting. The original entry stays byte
  for byte where it was — same `hash`, `previous_hash` and `sequence` — and a new `rekeyed` entry
  carries the same values under the new key and points back at it with `source_audit_id`.
- **`Events\AuditDiscarded`**, carrying the subject, the event, the stage and the reason, and nothing
  else. `Sentinel::filter()` registers a policy that `EnforcePolicies` applies as the last word on
  whether an entry settles.
- **`security.redaction.fields`, `security.hashing.fields` and `security.encryption.fields`**, which
  add to what each model declared and are the only way to name a key no model owns — an address, a
  session identifier, a console argument.
- Second frozen golden entry, this one protected by all three mechanisms, with its ciphertext frozen
  literally and verified with an empty keyring.
- `make test-quiet` and `make verify` for automated callers; `Pipeline/` and `Security/` enter the
  nightly mutation run with a floor of 90, closing at 100 % and 92 %.

### Changed

- **An update whose comparison found nothing writes no entry.** A `touch()`, or a column that moved
  but is excluded from the snapshot, used to produce a row saying nothing happened. The discard
  consumes no `sequence`, so the chain it never joined has no gap.
- **The hash covers the ciphertext, never the plaintext.** `verifyIntegrity()` runs in an environment
  holding no key at all and still proves the row was not touched. The trade is stated rather than
  hidden: the chain proves the row is the one that was written, not what the value said. Because
  `encryption` is part of the canonical payload, altering the `key_id` of a stored row breaks its
  hash.
- **`payload_version` stays at `1`.** Protecting a value changes what is hashed, never how. Every
  entry frozen before this version reproduces its hash byte for byte.
- `ResolveContext` wraps the context engine rather than reimplementing it, and the capture stops
  calling it directly. The behaviour is identical; the position is now reorderable.
- `security.encryption.enabled` is gone. Declaring a field is what turns encryption on for it.
- `AuditEvent` gains `rekeyed`, shipping as a `notice`.
- `require` gains `illuminate/encryption`, which the package would otherwise use without asking
  for it.

### Upgrade notes

- **Empty updates stop being recorded.** If your application relies on an entry existing for an
  update that changed nothing audited, remove `FilterUnchanged` from the `pipeline` list. Only
  updates are filtered: a creation with no comparable fields and a restore whose one moved column is
  not audited both still write.
- **A published config keeps working without being republished.** `pipeline` published as an empty
  array falls back to the shipped order rather than to no pipeline at all, and every new key under
  `security` has its default in code.
- **`security.encryption.enabled` is ignored.** If you had it set to `true`, nothing changes: what
  turns encryption on is declaring fields. If you had it set to `false` and also declared fields,
  those fields now encrypt — which is what declaring them meant.
- **Protection is not retroactive.** Entries written before the upgrade keep whatever they stored, in
  the clear if that is how they were written. Rewriting them would break the chain; clearing history
  already written is a retention problem and lands in `v0.19.0`.
- **A field you redact or hash cannot be restored.** `v0.14.0` restores an entry into a model and can
  only restore what was written down. If a value has to come back, encrypt it.
- **The digest salt is stable by definition.** `security.hashing.salt` derives from `APP_KEY` when
  unset. Rotating either breaks no chain and makes every digest written before it incomparable with
  every digest written after.

## v0.6.0 — Context engine (2026-08-27)

### Added

- Ten resolvers under `Context\Resolvers\`, each final, stateless and replaceable one at a time from
  the `resolvers` section of the config: `Actor`, `Impersonator`, `Tenant`, `Request`, `Session`,
  `Trace`, `Source`, `Host`, `Job` and `Command`. A resolver implements one method and returns what
  it could resolve, or an empty array.
- `Context\ContextEngine`: the single invocable that runs the chain over an `AuditData` before it
  reaches the ledger, so the context is the one of the moment audited and travels inside the data
  object for the release that defers the write. One rule decides where a resolved key lands — the
  nine names matching a promoted column go to the column, everything else goes into `context` — and
  running it twice over the same entry produces the same entry, because every column is written on
  every pass, absent value included.
- `Context\Runtime`: what the process is doing right now, latched from events the framework already
  fires — the router reaching a request, artisan starting a command, a worker picking up a job, the
  scheduler running a task. Resolvers read a fact instead of inspecting the world, which is also why
  every source in the matrix has a test that produces it without a server behind it.
- `Http\Middleware\AssignRequestId`: opt-in and registered in no group. It honours an incoming
  identifier when it is printable and fits the column, generates one otherwise, and returns it in the
  response. The header is `X-Request-Id`, configurable.
- `Context\Identity`: how a person becomes two columns — the morph alias for a model, the class name
  for anything else.
- Sixth frozen entry in the golden dataset, this one with all nine context columns and a populated
  `context` payload.

### Changed

- Model entries carry their context. `source` stops being a default nobody decided: each of the eight
  values of the enum is produced by its own signal, in a declared order, and the entry says where it
  came from instead of where it did not.
- `payload_version` stays at **1**: the nine columns have been part of the canonical payload since
  `v0.3.0`, and filling declared fields changes what is hashed, not how. The five entries frozen
  before this version reproduce their hashes byte for byte.
- `AuditData` takes `occurred_at` fourth and `source` fifth with a default of `system`, so a data
  object that never meets the engine still carries a valid, honest value.
- `integrity.stream` ships as `tenant`. On its own it changes nothing — the stream falls back to
  `global` until a tenant actually resolves — and an installation that published its config keeps
  whatever value it has.
- `require` gains `illuminate/console`, `illuminate/http`, `illuminate/queue` and
  `illuminate/routing`. The package read a request, a command and a job without asking for any of
  them; it worked only because the framework metapackage happened to be installed.
- `mutation-nightly.yml` gains thresholds for `Context/` (95) and `Http/` (90). `Context/` entered
  this version at 80 % as an empty container and leaves it at 96 % with ten resolvers inside.
- `make bench` gains a row that isolates what the resolver chain costs per capture from the write it
  lands in.

### Upgrade notes

- **Activating a tenant partitions the chain.** `integrity.stream` ships as `tenant` for new
  installations, which behaves exactly like `global` until `resolvers.tenant` resolves a value. The
  moment one does, entries move to a `tenant:<id>` stream of their own, with `sequence` restarting at
  `1` and `previous_hash` at `null`. Older chains keep verifying with their own genealogy and no
  migration rewrites them, but they stop growing. Set `integrity.stream` explicitly before wiring a
  tenant if you do not want that partition.
- **A published config keeps working without being republished.** Every key under `resolvers` also
  has its default in code, which is what an installation that published the file while the subtree
  was still empty falls back to — the config merge is shallow, so the published empty array would
  otherwise win and leave that installation with no resolvers and no error to show for it.
- **Entries written before this version are untouched.** They keep their empty context and their
  `source` of `system`, and their hashes stay valid. Nothing backfills them; the integrity rule
  forbids it.

### Notes

- **Context carries sensitive data.** Addresses, user agents, urls, session identifiers and command
  arguments now sit in `context`, on entries that already duplicate the audited value in `changes`.
  `CommandResolver` masks any argument whose name matches `resolvers.command.redact` at a fixed
  length, so nothing about the secret leaks; general redaction and encryption still land in `v0.7.0`.
- A scheduled task Laravel runs in a subprocess reaches that child with no scheduler signal, so the
  child reports `cli`. The parent process and the scheduler's own commands are covered.
- No user-facing strings in this version. The new exceptions are developer-facing — an undefined
  guard, a tenant hook returning something no column can hold, a boundary that is neither a pattern
  nor a closure — and stay in plain English, as Laravel itself does.

## v0.5.0 — Diff engine (2026-08-26)

### Added

- `Diff\Diff`: `Diff::between($before, $after)` compares two structures and returns
  `{path, op, old, new}` entries, with `path` as an RFC 6901 JSON Pointer so a key holding a `.` or
  a `/` stays unambiguous. It depends on nothing else in the package and on nothing in
  `Illuminate\Database` — an arch test forbids it, and a second test runs the comparison in a php
  process that loads nothing but the autoloader.
- `old` travels next to `new`, which RFC 6902 cannot do. Interoperability is an export:
  `toJsonPatch()` emits strict RFC 6902 with an optional `test` guarding every `remove` and
  `replace`, and `Diff::fromJsonPatch()` reads one back. Without the tests the previous value is
  genuinely lost, so the rebuilt entry omits it rather than reporting `null` as if it were data.
- Lists are matched by identity when every element carries a unique scalar `id` or `uuid`, and by
  position otherwise: an insertion in the middle is one addition and a reordering is no change.
- `$audit->diff()` and `$audit->diffFor('profile.address.city')`, which takes dot notation or a
  literal pointer. An entry written before this version computes its diff on read from the states it
  stored; no row is rewritten.
- Fifth frozen entry in the golden dataset, this one carrying the diff of its own change.

### Changed

- Model entries populate `changes`. A creation is all additions, a deletion all removals, and an
  update that changed nothing writes `[]` — `null` still means the event had nothing to compare.
- `payload_version` stays at **1**: `changes` has been part of the canonical payload since `v0.3.0`,
  and populating a declared field does not change the format. The four entries frozen in `v0.4.0`
  still reproduce their hashes byte for byte.
- `$auditSnapshots = false` and `snapshots.enabled = false` now govern **retention**, not the
  comparison. The pair is built either way — without it there is nothing to diff — so such an entry
  keeps its diff and drops its two states. The flag saves storage, not time.
- `mutation-nightly.yml` gains a threshold for `Diff/` (90), and `make bench` gains a row that
  measures the comparator with nothing around it.

### Notes

- The diff **duplicates** sensitive data: what lives in `before` and `after` now also lives in
  `changes`. Redaction and encryption land in `v0.7.0`; `$auditExclude` is still the only lever.
- A round trip through mysql or postgres keeps every value of a diff but not the key order inside
  each entry — both engines reorder object keys. Nothing depends on that order: entries are read by
  key, and the chain hashes the canonical form, never the text the engine stored.
- No user-facing strings in this version. The new exceptions are developer-facing and stay in plain
  English, as Laravel itself does.

## v0.4.0 — Snapshots and the Auditable trait (2026-08-26)

### Added

- `Concerns\Auditable`: a model that uses the trait audits itself. No interface to implement and no
  observer to register — the trait registers the four eloquent events it needs when the model boots,
  and answers for every field policy the package asks about.
- `Capture\ModelObserver` and `Capture\ModelCapture`: the observer translates eloquent events into
  audit intent, and one class builds the `AuditData` and hands it to the ledger. Four events produce
  five kinds of entry: `created`, `updated`, `deleted`, `restored` and `force_deleted`. A force
  delete fires `deleted` on its way to `forceDeleted`, so the first is suppressed; a restore is
  derived from the update that clears the deletion mark, which is the only moment the state the
  record had in the bin is still reachable.
- `Snapshot\SnapshotBuilder` and `Snapshot\SnapshotPair`: the complete state before and after, with
  the model's own casts applied, keys ordered, lists kept as lists, and `null` told apart from an
  empty map. A date keeps the precision the audited model keeps — eloquent truncates on assignment
  when the model's date format carries no microseconds.
- Field policies on the model: `$auditInclude` (a whitelist that wins outright), `$auditExclude`,
  `$auditSeverity` (beats the configured default for the event) and `$auditSnapshots = false` (an
  entry with no payload, still chained and still verifiable).
- `snapshots.include_hidden`: attributes in `$hidden` are audited by default and this key drops them
  in bulk.
- `Support\AuditPolicy`: a trait cannot implement an interface, so one class accepts either shape —
  the concern or the `Auditable` contract — and returns the defaults for a model that is neither.
  Without it, a model using the trait would have its policies ignored in silence.
- `Exceptions\SnapshotException`: an attribute that cannot be represented in a snapshot fails loudly
  instead of writing something else.
- `benchmarks/` and `make bench`: the write-path baseline, four variants over a fixed dataset.
- Fourth frozen entry in the golden dataset, this one with `before` and `after` populated.

### Changed

- **Breaking (0.x):** `Contracts\Auditable` gains `auditSeverity(): ?Severity`. The observer programs
  against the contract, not against the trait, so a per-model severity override has to be visible
  there. A model that implements the contract by hand needs the new method; a model that uses
  `Concerns\Auditable` gets it for free.
- `mutation-nightly.yml` gains a threshold for `Snapshot/` (90) and a second job that mutates the
  ledger against MySQL 9 and PostgreSQL 16 — the advisory lock and the gap lock were invisible to a
  run on SQLite alone.

### Performance baseline

Every later version that writes entries reports its delta against this table.

| Variant | Writes | Per write (µs) | Δ vs plain |
|---|---|---|---|
| plain (not audited) | 2000 | 182.0 | — |
| audited, snapshots on | 2000 | 1591.9 | +774.7% |
| audited, snapshots off | 2000 | 1533.8 | +742.7% |
| audited, null ledger | 2000 | 912.0 | +401.1% |

Median of three runs. PHP 8.4.24 on SQLite with synchronous writes and the journal turned off — a
container's `fsync` is not information about this package — six columns per subject, 2000 iterations
after 200 warm-up writes, inside Docker on WSL2. The numbers are a report, not a gate: one that
depends on the machine cannot block a merge.

What the breakdown says: auditing a write costs about **1410 µs**, of which the snapshot itself is
only **58 µs** (snapshots on minus snapshots off). Canonicalising and hashing the entry account for
**730 µs** (null ledger minus plain), and the per-engine gate plus the insert for the remaining
**680 µs** (snapshots on minus null ledger). Those were the two numbers `v0.3.0` left owed.

### Notes

- No migrations. `sentinel_audits` was born complete in `v0.2.0`; this version only fills columns
  that already existed.
- `payload_version` stays at **1**. The three entries frozen in `v0.3.0` rehash byte for byte with
  `before` and `after` now carrying content: filling a nullable column is not a format change.
- No user-facing strings were added, so `resources/lang/en` and `resources/lang/es` are unchanged.
- Until `v0.6.0` every entry carries `source = system`; until `v0.7.0` there is no redaction and no
  encryption, and `$auditExclude` is the only lever.

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
