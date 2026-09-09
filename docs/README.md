# 📚 Sentinel Documentation

> The manual for `elpandape/sentinel`. The [README](../README.md) is the overview; this is the detail
> behind every claim it makes.

**92 pages · 14 sections.** Every claim here was written against the source and checked back against it.

---

## How to read this

Sentinel is an **append-oriented audit engine, not an activity logger**. Its unit is the audit record:
what happened, who did it, on whose behalf, what changed, what the state was and is, which transaction
and which request caused it, where it originated, and whether the record itself is intact.

That framing decides how these pages are written. Every one of them states what a mechanism does **and**
what it refuses to do, because in an audit trail the second half is what keeps you out of trouble.

### Start here

| If you are… | Read, in this order |
|---|---|
| **Evaluating the package** | [What Sentinel is (and is not)](01-concepts/01-what-sentinel-is.md) → [Choosing your setup](02-getting-started/05-choosing-your-setup.md) → [Choosing an engine](10-database-engines/01-choosing-an-engine.md) |
| **Trying it out** | [Installation](02-getting-started/01-installation.md) → [Your first audit](02-getting-started/02-your-first-audit.md) → [What a model declares](02-getting-started/03-what-a-model-declares.md) → [The Query API](06-reading/01-the-query-api.md) |
| **Taking it to production** | [Choosing your setup](02-getting-started/05-choosing-your-setup.md) → [Performance modes](09-operations/01-performance-modes.md) → [Scheduling](09-operations/07-scheduling.md) → [Retention and pruning](08-lifecycle/01-retention-and-pruning.md) → [Production readiness](13-best-practices/04-production-readiness.md) |
| **Reviewing it for security** | [The integrity model](01-concepts/04-the-integrity-model.md) → [Protecting sensitive data](05-pipeline-and-security/02-protecting-sensitive-data.md) → [Signing](07-integrity/04-signing.md) → [Security checklist](13-best-practices/03-security-checklist.md) |
| **Auditing a trail someone handed you** | [Verification](07-integrity/06-verification.md) → [The verification playbook](07-integrity/07-the-verification-playbook.md) → [Export and rekey](08-lifecycle/06-export-and-rekey.md) |
| **Extending it** | [Architecture](01-concepts/05-architecture.md) → [The Ledger contract](11-extending/01-the-ledger-contract.md) → [Writing a ledger driver](11-extending/03-writing-a-ledger-driver.md) → [The contract test suite](11-extending/04-the-contract-test-suite.md) |
| **Writing tests** | [Testing your integration](11-extending/07-testing-your-integration.md) |
| **Migrating an existing trail in** | [The import runbook](12-migrating/03-the-import-runbook.md) → the guide for [your package](12-migrating/) |
| **Debugging something** | [Monitoring and troubleshooting](09-operations/08-monitoring-and-troubleshooting.md) → [Anti-patterns](13-best-practices/02-anti-patterns.md) |
| **In a hurry and just want the rules** | [Do and don't](13-best-practices/01-dos-and-donts.md) |

---

## The sections

### 🧠 [Concepts](01-concepts/)

Read these before anything else. They are the model every other page assumes you already hold — what an audit record is, how one gets written, and what "provable" means here.

| Page | What it covers |
|---|---|
| [What Sentinel is (and is not)](01-concepts/01-what-sentinel-is.md) | The honest positioning page: what problem this package solves, what it refuses to solve, and the cases where the right decision is to install something else. |
| [The audit record](01-concepts/02-the-audit-record.md) | What one entry in `sentinel_audits` actually is: its forty columns, who fills each of them, the nine kinds of entry, the two clocks it carries, and the things it deliberately leaves out. |
| [The write path](01-concepts/03-the-write-path.md) | One entry, followed from the Eloquent save that caused it to the sealed row in the ledger — every component it passes through, everything that can drop it, and what changes when the write… |
| [The integrity model](01-concepts/04-the-integrity-model.md) | What Sentinel means by *tamper-evident*, what each of the three layers adds, and exactly what a verification result proves — and does not prove — at each depth. |
| [Architecture](01-concepts/05-architecture.md) | Where everything lives, what the container hands you and with what lifetime, which seams are meant to be replaced, and which lines the test suite refuses to let you cross. |
| [Glossary](01-concepts/06-glossary.md) | Every word this documentation uses as a term of art, with the column, class or config key behind it and the page that owns it. |

### 🚀 [Getting started](02-getting-started/)

From `composer require` to a chain you have verified yourself, plus the decisions that are cheap now and expensive later.

| Page | What it covers |
|---|---|
| [Installation](02-getting-started/01-installation.md) | What to require, what the installer writes, what it deliberately leaves alone, and how to prove the install is sound before you audit a single model. |
| [Your first audit](02-getting-started/02-your-first-audit.md) | Ten minutes from an unaudited model to a chain you have walked and verified yourself. |
| [What a model declares](02-getting-started/03-what-a-model-declares.md) | Every property the `Auditable` trait reads off a model, what each one produces in the entry, and whether it adds to a configuration list or replaces it. |
| [Turning auditing off](02-getting-started/04-turning-auditing-off.md) | The three ways to stop Sentinel writing, which of them leaks, and what an audit trail with a hole in it looks like to the person who has to read it later. |
| [Choosing your setup](02-getting-started/05-choosing-your-setup.md) | Seven installation profiles, what each one turns on, what each one must leave off, and the single mistake each one makes most often. |

### 📥 [Capture](03-capture/)

What produces an entry. One page per kind of fact the chain carries — model changes, pivot writes, mass statements, transactions, stated facts and state moves.

| Page | What it covers |
|---|---|
| [What gets audited](03-capture/01-what-gets-audited.md) | Which Eloquent events produce an audit entry, which produce nothing, and the writes Sentinel cannot see at all. |
| [Snapshots](03-capture/02-snapshots.md) | How a record's state is frozen into `before` and `after`: what goes in, how each type is written down, and what a snapshot can never give back. |
| [Diffs](03-capture/03-diffs.md) | The structured change list an entry carries: how it is computed, how a path is written, how it maps onto RFC 6902 JSON Patch, and how to read one off an entry. |
| [Relationship auditing](03-capture/04-relationships.md) | How Sentinel records pivot writes that Eloquent fires no event for, what a relation entry says, and what the hash does and does not cover when it says it. |
| [Mass operations](03-capture/05-mass-operations.md) | How Sentinel records `Builder::update()`, `Builder::delete()` and `Builder::upsert()` — the three statements Eloquent fires no model event for — and what each of the three recording modes… |
| [Business transactions](03-capture/06-business-transactions.md) | How to give a handful of entries one name and one identifier, and how to make sure none of them claims a fact the database rolled back. |
| [Custom and authentication events](03-capture/07-custom-and-authentication-events.md) | How to record a fact that no model change describes — an approval, a dispatch, a sign-in — and how to turn Laravel's authentication events into entries of the same trail. |
| [State transitions](03-capture/08-state-transitions.md) | How Sentinel records that a record moved from one state to the next — as an entry of its own kind, with a lifeline you read instead of a diff you have to mine. |

### 🧭 [Context](04-context/)

Everything an entry knows about the circumstances it was written in, where each fact comes from, and what to do when the usual source is not there.

| Page | What it covers |
|---|---|
| [Execution context](04-context/01-execution-context.md) | What an entry knows about the circumstances it was written in: which nine facts become columns, which land inside the `context` JSON, when they are resolved, and how far that answer travels. |
| [The ten resolvers](04-context/02-resolvers-reference.md) | One page per question the entry answers about its circumstances: what each shipped resolver reads, what it returns when it finds nothing, which of its keys becomes a column, and how it… |
| [Actor and impersonation](04-context/03-actor-and-impersonation.md) | Who did it, and on whose behalf. How the two pairs of columns are filled, what shape of key fits in them, and what an entry says when the thing that acted was not a person. |
| [Multi-tenancy](04-context/04-multi-tenancy.md) | How Sentinel learns which tenant an entry belongs to, and why turning that on also decides the shape of the hash chain. |
| [Queues, commands and schedulers](04-context/05-queues-commands-and-schedulers.md) | Attribution where nobody is logged in. What a worker, a console command and the scheduler each work out on their own, what none of them can, and the standard answer — the actor travels with… |
| [Distributed tracing](04-context/06-distributed-tracing.md) | How an audit entry becomes a locatable point inside a distributed trace: where `trace_id` and `span_id` come from, what Sentinel refuses to believe, and why the caller's `traceparent` is an… |
| [Writing your own resolver](04-context/07-writing-your-own-resolver.md) | The one-method contract behind every context column, what a resolver is allowed to do on the write path, how to register one, and what breaks when it misbehaves. |

### 🛡️ [Pipeline and security](05-pipeline-and-security/)

The seven stages between capture and ledger, and the four different promises you can make about a sensitive field.

| Page | What it covers |
|---|---|
| [The write pipeline](05-pipeline-and-security/01-the-write-pipeline.md) | The seven stages every audit entry passes through between capture and ledger — what each one receives, what it may change, what it must leave alone, and how to add, move or remove one. |
| [Protecting sensitive data](05-pipeline-and-security/02-protecting-sensitive-data.md) | The four mechanisms — exclude, redact, hash, encrypt — as a decision you make once per field: what each one promises, what it refuses to promise, and exactly how far into an entry it… |
| [Encryption and the keyring](05-pipeline-and-security/03-encryption-and-the-keyring.md) | The only reversible protection Sentinel offers: what the cipher does to a declared field, what the entry records so the value stays readable, why the chain hash covers the ciphertext… |
| [Hashing and the salt](05-pipeline-and-security/04-hashing-and-the-salt.md) | How a field is turned into a salted digest so two entries can be compared without either of them being readable — and the one setting that decides whether that comparison keeps working. |
| [Writing a masker](05-pipeline-and-security/05-writing-a-masker.md) | The one-method contract that decides what a redacted field looks like in an entry, the masker the package ships, and three replacements you would actually put in production. |
| [Discarding entries](05-pipeline-and-security/06-discarding-entries.md) | How an entry stops existing before it settles, why that is only legal before the ledger touches it, and how to tell a filter that removes noise from one that quietly removes evidence. |

### 🔎 [Reading the trail](06-reading/)

Getting answers back out. The query surface, the derived views over it, and the one operation that writes as a consequence of reading.

| Page | What it covers |
|---|---|
| [The Query API](06-reading/01-the-query-api.md) | How to read the trail: one entry point, an immutable query object stated against the ledger contract, and three terminals that actually go to the store. |
| [Filters reference](06-reading/02-filters-reference.md) | Every filter the query surface publishes, one row each: what it narrows, whether an index finds it or merely refines a set someone else found, how it behaves per engine, and what happens… |
| [Order, paging and walking the trail](06-reading/03-order-paging-and-walking.md) | How the trail is ordered and why, what `get()` refuses to do, how a page is asked for, and how to cross a whole trail — or a whole chain — without reading anything twice. |
| [Field history and comparing versions](06-reading/04-field-history.md) | The life of one attribute across a record's history: how to ask for it, the two numbers on screen and what each of them counts, and how to compare two versions that are not next to each… |
| [The timeline](06-reading/05-the-timeline.md) | Everything that happened, in the order it happened: what `Sentinel::timeline()` actually is, when its order and the chain's order disagree, which one answers which question, and how to… |
| [Labels](06-reading/06-labels.md) | The classification layer of the trail: one word hung off an entry so you can find it again — and the one part of an entry that the hash does not cover. |
| [Presenting and serializing](06-reading/07-presenting-and-serializing.md) | The two ways an entry leaves the package: as a sentence a person reads, and as a frozen array a machine consumes. What each contains, what neither contains, and why `verified` is `null`. |
| [Restoring state](06-reading/08-restoring-state.md) | How to put a record back into the state an entry photographs — the whole state, named fields, or a many-to-many relation — as one new append-only entry that points back at the source. |

### 🔐 [Integrity](07-integrity/)

What makes this a ledger rather than a log — and, stated precisely, the limit of what each layer proves.

| Page | What it covers |
|---|---|
| [The hash chain](07-integrity/01-the-hash-chain.md) | How Sentinel seals an entry, links it to the one before it, and exactly which kinds of tampering that link catches — and which it does not. |
| [Streams](07-integrity/02-streams.md) | A stream is one chain. This page covers the three shipped scopes, the trade each one makes between write contention and verification cost, and what happens to your history if you change the… |
| [Canonicalization](07-integrity/03-canonicalization.md) | Why the same entry has to produce the same bytes on every engine, in every process and in every year — and exactly which rules make that true. |
| [Signing the chain](07-integrity/04-signing.md) | What a signature adds on top of the hash chain, how the verifying half and the signing half are split, and what each of the four signature states obliges you to do. |
| [Checkpoints and anchors](07-integrity/05-checkpoints-and-anchors.md) | An anchor is a signed root over a fixed window of one stream, chained to the anchor before it. This page is what one is, how the fold is built, how to emit them without reading ten million… |
| [Verification](07-integrity/06-verification.md) | The three depths of a verification, exactly what each one proves and what it leaves unproved, how to read the three result shapes field by field, and what `sentinel:verify` hands back to a… |
| [The verification playbook](07-integrity/07-the-verification-playbook.md) | The operational runbook: what to run and how often, how to read a run, and — when one comes back broken — one triage block per reason code, ending in what you can honestly tell an auditor. |

### ♻️ [Lifecycle](08-lifecycle/)

A trail that only grows is a trail nobody can afford. What leaves, how it leaves provably, and what compliance mode demands in exchange.

| Page | What it covers |
|---|---|
| [Retention and pruning](08-lifecycle/01-retention-and-pruning.md) | How to declare that a class of audit record stops being kept, and what `sentinel:prune` actually removes when you do. |
| [Cold archiving](08-lifecycle/02-cold-archiving.md) | How a released range of the chain leaves the hot table as an NDJSON batch on a `Storage` disk, what the package proves before it removes a single row, and why the archive must never be the… |
| [Rehydration](08-lifecycle/03-rehydration.md) | Bringing an archived range back into the hot table exactly as it left — sequences, hashes, links, labels and operation headers included. |
| [Redaction and tombstones](08-lifecycle/04-redaction-and-tombstones.md) | Destroying the contents of one sealed entry while its position, its hash and its link to the next entry stay exactly where they were — and what that costs you. |
| [Compliance mode](08-lifecycle/05-compliance-mode.md) | What one boolean changes, why the application refuses to boot without signatures and anchors, and exactly which reads leave a record — and which do not. |
| [Export and rekey](08-lifecycle/06-export-and-rekey.md) | Handing the trail to someone who does not have your database, and changing the lock on a protected value without touching the seal on the entry that holds it. |

### ⚙️ [Operations](09-operations/)

Running it. Where entries settle, what fails and how loudly, what belongs on the scheduler, and what to watch.

| Page | What it covers |
|---|---|
| [Performance modes](09-operations/01-performance-modes.md) | One configuration key decides where and when an already-captured, already-transformed audit entry settles in the ledger — and what you give up in exchange for the latency you get back. |
| [The buffered mode](09-operations/02-the-buffered-mode.md) | The mode for a write path that cannot afford the ledger: entries wait in a Redis list and settle in batches. It is the only one of the three that can lose a fact outright, and this page… |
| [Running audits on a queue](09-operations/03-queues.md) | What `mode = queue` actually does: the job, the payload that crosses the process boundary, what the worker still has to resolve for itself, and what a failed settlement costs the trail. |
| [Events and listeners](09-operations/04-events-and-listeners.md) | The eleven events an entry's life announces, which of them you may refuse and until when, and how to write a listener that does not turn the audit engine into the reason a request fails. |
| [Failure policy](09-operations/05-failure-policy.md) | What happens to the request when an audit entry cannot be written — the one setting that decides it, the three places that setting cannot reach, and which of the resulting alarms deserve a… |
| [Artisan commands](09-operations/06-artisan-commands.md) | The eleven `sentinel:*` commands: what each one does, what it refuses to do, which are safe on a schedule and which destroy something. |
| [Scheduling](09-operations/07-scheduling.md) | What Sentinel needs on your scheduler, in which order, with which locks — and what silently does not happen if you schedule none of it. |
| [Monitoring and troubleshooting](09-operations/08-monitoring-and-troubleshooting.md) | What to watch on a Sentinel installation, what each signal means when it moves, and the checks to run — in order — for the eight ways an audit trail goes wrong. |

### 🐘 [Database engines](10-database-engines/)

The engine-specific half of the package. Which database to run this on, what each one gives up, and what it costs at volume.

| Page | What it covers |
|---|---|
| [Choosing an engine](10-database-engines/01-choosing-an-engine.md) | Which database to put an audit trail on, what each of the three supported engines gives up, why MariaDB is refused by name, and what a move between engines costs once a chain has been… |
| [PostgreSQL](10-database-engines/02-postgresql.md) | What the schema compiles to on PostgreSQL, the two things the package does there and nowhere else, and the operational work — indexes, partitions, vacuum, pooling — that is yours rather… |
| [MySQL](10-database-engines/03-mysql.md) | What Sentinel's schema, chain and query surface look like on MySQL 9 — the column types you actually get, the two clauses a route filter emits, what range partitioning takes away, how a… |
| [SQLite](10-database-engines/04-sqlite.md) | Where SQLite is the right ledger — tests, single-process tools, embedded installs — where it is not, and the one limit it imposes on every installation whether you run it or not. |
| [Indexes and JSON](10-database-engines/05-indexes-and-json.md) | Every index the package creates, the question it answers, and the filter that reaches it — then the JSON half: what lives inside `context`, which two filters read into it, and the opt-in… |
| [Partitioning](10-database-engines/06-partitioning.md) | The three shipped alternatives to the base audit migration, exactly what each one costs in keys and in query plans, how to keep a divided table supplied with months, and how to convert a… |
| [A database of its own](10-database-engines/07-a-database-of-its-own.md) | Putting the audit tables on their own connection: the one config key that moves them, what honours it, what it buys, and the three things it quietly takes away. |
| [Scaling playbook](10-database-engines/08-scaling-playbook.md) | What grows in an audit trail, in what proportion, and the order in which to reach for each lever as the table gets bigger. |

### 🧩 [Extending](11-extending/)

The seams. The Ledger contract and its conformance suite come first — they are the ones with a runnable definition.

| Page | What it covers |
|---|---|
| [The Ledger contract](11-extending/01-the-ledger-contract.md) | The six-method seam every audit entry passes through on its way to storage: what each method promises, what a driver must guarantee, and what the contract deliberately refuses to require. |
| [The shipped drivers](11-extending/02-shipped-drivers.md) | The five implementations of `Contracts\Ledger` that come in the box — what each one is for, what it can and cannot answer, and the one mistake people make with it. |
| [Writing a ledger driver](11-extending/03-writing-a-ledger-driver.md) | How to put the audit trail in a store this package has never heard of — the skeleton, how it is resolved, each contract method with the reasoning behind it, and the checklist to run before… |
| [The contract test suite](11-extending/04-the-contract-test-suite.md) | `Testing\LedgerContractTestCase` is the executable form of the Ledger contract: extend it, hand it your driver, and your driver is held to the same chain the shipped ones are. |
| [Fanout: writing to more than one place](11-extending/05-fanout.md) | One entry, several destinations — which one is allowed to seal it, what each of the two failure policies does, and the things a fanout deliberately is not. |
| [Swapping components](11-extending/06-swapping-components.md) | Every seam in the package, what ships behind it, how you replace it, and the three that will cost you a chain nobody can verify if you replace them anyway. |
| [Testing your integration](11-extending/07-testing-your-integration.md) | How to test an application that has Sentinel in it: which ledger to point the suite at, why the deferred write is not the trap it looks like, what to assert on the trail, and what never to… |

### 🔄 [Migrating in](12-migrating/)

Bringing a history written by another package across, and the honest limit of what an import can prove.

| Page | What it covers |
|---|---|
| [From `owen-it/laravel-auditing`](12-migrating/01-from-owen-it.md) | How `sentinel:import --from=owenit` reads that package's `audits` table, what it can carry across, what was already lost before Sentinel ever saw the rows, and why the chain starts at the… |
| [From altek/accountant](12-migrating/02-from-altek.md) | How `sentinel:import --from=altek` reads an `accountant` ledger, what the mapping gives you, and why a whole-record restore on anything it wrote is refused before it starts. |
| [The import runbook](12-migrating/03-the-import-runbook.md) | The operational procedure for `sentinel:import`, whichever package you are coming from: what to back up and freeze, how to read the report, what to do when a run stops halfway, and what… |

### ✅ [Best practices](13-best-practices/)

The judgement layer. These pages repeat material from elsewhere on purpose — they are indexed by decision, not by feature.

| Page | What it covers |
|---|---|
| [Do and don't](13-best-practices/01-dos-and-donts.md) | The judgement calls a team makes once and then lives with, in the order you meet them — each with the code on both sides, what actually goes wrong, and the page that explains why. |
| [Anti-patterns](13-best-practices/02-anti-patterns.md) | Thirteen shapes that pass code review and still cost you a trail you cannot use: what each one really does, how you find out (usually late), and the correct shape. |
| [Security checklist](13-best-practices/03-security-checklist.md) | A reviewable list of the security decisions Sentinel leaves to you, each with the check, the way to verify it, and what a failure costs — followed by the threat model in plain terms and the… |
| [Production readiness](13-best-practices/04-production-readiness.md) | The go-live checklist for a Sentinel installation, ordered the way a team works it: the decisions that cannot be taken back once the first entry is written, then configuration, schedule,… |

### 📚 [Reference](99-reference/)

Lookup pages. Complete, terse, and correct as of this tag.

| Page | What it covers |
|---|---|
| [The Sentinel facade](99-reference/01-facade-api.md) | Every method `ElPandaPe\Sentinel\Facades\Sentinel` publishes, what it returns, what it throws — and the four fluent objects it hands back, with their full method lists. |
| [Configuration](99-reference/02-configuration.md) | Every key in `config/sentinel.php`, in the order the file declares them, plus the three rules that govern all of them: where defaults come from, which subtrees take no options, and what… |
| [Schema](99-reference/03-schema.md) | Every table Sentinel creates, every column in it, every index and the query it serves — and the things the schema deliberately does not have. |
| [Enums](99-reference/04-enums.md) | Every enum the package declares, case by case: the value it stores, what that value means when you read it back, and which code writes it. |
| [Events](99-reference/05-events.md) | Every event Sentinel dispatches: what it carries, where it fires, whether you can stop it, and in what order. |
| [Exceptions](99-reference/06-exceptions.md) | Every exception class Sentinel throws: what it means, what raises it, whether you are meant to catch it, and what to do instead of catching it. |
| [Exit codes](99-reference/07-exit-codes.md) | The three codes every `sentinel:*` command speaks, what each one means for each of the eleven, and shell that branches on them without getting it wrong. |
| [Serialization](99-reference/08-serialization.md) | The exact shape an entry takes when it leaves PHP: every key, its type, its nullability and the column it came from — plus the two other serialized formats the package ships, and what a… |
| [API stability](99-reference/09-api-stability.md) | What this package promises not to change, what it explicitly reserves the right to change, and the one contract that binds tighter than semantic versioning does. |

---

## Conventions

Every page carries the same furniture, so you can skim one you have never opened before:

| | Means |
|---|---|
| **On this page:** | The section links, right under the title |
| `## ⚠️ Pitfalls` | A symptom → cause → fix table. The fastest way to check whether a page explains what is happening to you |
| `## ✅ Best practices` | Paired ✅ Do / ❌ Don't, with the code on both sides and what actually goes wrong |
| **See also:** | Where to go next, at the foot of the page |

And, in the prose:

| | Means |
|---|---|
| > 📌 **Note.** | A rule worth remembering |
| > ⚠️ **Warning.** | Data loss, silent failure, or a foot-gun |
| > 💡 **Tip.** | A shortcut or a better default |
| > 🔒 **Security.** | A threat-model statement |
| > 🐘 **Engine.** | Behaviour that differs by database engine |
| > 🧪 **Verify it.** | Something you can run to check the claim |

---

## Elsewhere in the repository

| File | What it is for |
|---|---|
| [README.md](../README.md) | The overview: what the package is, installation, quick start, and a link into every section here |
| [UPGRADE.md](../UPGRADE.md) | What to do when moving between versions |
| [CHANGELOG.md](../CHANGELOG.md) | What moved, and when — including the measured figures this documentation quotes |
| [MIGRATE_FROM_OWEN_IT.md](../MIGRATE_FROM_OWEN_IT.md) · [MIGRATE_FROM_ALTEK.md](../MIGRATE_FROM_ALTEK.md) | The reference mappings the [migration guides](12-migrating/) are built on |
| [CONTRIBUTING.md](../CONTRIBUTING.md) | How to work on the package itself |
| [SECURITY.md](../SECURITY.md) | How to report a vulnerability |

---

<p align="center">
  <sub>Know what happened. Know who did it. Prove the record.</sub>
</p>
