# Changelog

All notable changes to `elpandape/sentinel` are documented here.

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
