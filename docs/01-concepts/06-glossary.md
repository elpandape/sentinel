# 🧠 Glossary

> Every word this documentation uses as a term of art, with the column, class or config key behind it and the page that owns it.

**On this page:** [A to C](#a-to-c) · [D to L](#d-to-l) · [M to R](#m-to-r) · [S to V](#s-to-v) · [Words that are easy to confuse](#️-words-that-are-easy-to-confuse) · [Best practices](#-best-practices)

---

## A to C

### Actor

Who did it. Two columns, `actor_type` and `actor_id`, filled by `Context\Resolvers\ActorResolver`
from the guard named in `resolvers.actor.guard` — `actor_type` is the model's morph alias, or the
class name for a non-model `Authenticatable`. Both are inside the [canonical payload](#canonical-payload)
and both ride the `(actor_type, actor_id, id)` index. Nobody authenticated means both stay null, so
a console run, a scheduler tick or a queue worker records no actor unless you name one on the call
or replace the resolver. Manual [execution context](#execution-context) can never write them.
→ [Actor and impersonation](../04-context/03-actor-and-impersonation.md)

### Anchor

A signed root over a fixed range of one [stream](#stream), so a long chain can be checked without
being reread. Anchor and [checkpoint](#checkpoint) are the same row under two names: the schema, the
model and the command say *checkpoint*, the verification vocabulary says *anchor*
(`Sentinel::verifyAnchors()`, `--depth=anchors`, the `anchored` state). The root is a fold and not a
Merkle tree, and the previous anchor's root goes into the fold — contiguous integers are not
linkage — so reissuing one anchor obliges reissuing every anchor after it. Off by default.
→ [Checkpoints and anchors](../07-integrity/05-checkpoints-and-anchors.md)

### Audit record

The unit of this package, and what separates it from an activity log. One record answers what
happened, who did it, on whose behalf, what changed, what the state was and what it is now, which
business operation and which request caused it, where it originated, and whether the record itself
is intact. In the schema it is one row of `sentinel_audits`. Used interchangeably with
[entry](#entry) throughout these pages. → [The audit record](02-the-audit-record.md)

### Audit type

The `audit_type` column: what *kind* of entry this is, which is a different question from
[event](#event), the name of what happened. Nine values are written by the package —
`model`, `relation`, `mass`, `transition`, `custom`, `auth`, `restore`, `security` and `access` —
and `whereType()` narrows by it. It is not an enum on the model: the column is a string, and
`whereType('model')` matches almost everything a normal installation writes.
→ [Enums](../99-reference/04-enums.md)

### Canonical payload

The exact bytes the [hash](#previous-hash) is computed over. `Integrity\CanonicalPayload::COLUMNS`
freezes twenty-seven columns for [payload version](#payload-version) 1, and nothing else in the
package may enumerate them — a second list would be a second payload format. The values are rendered
through one normaliser (backed enums to their value, instants to `Y-m-d H:i:s.u`) and then encoded
as RFC 8785 JSON, which sorts keys by UTF-16 code unit. That is why an entry hashes identically on
SQLite, MySQL and PostgreSQL even though the stored JSON bytes differ.
→ [Canonicalization](../07-integrity/03-canonicalization.md)

### Capture id

An idempotency key on the entry, `char(26)`, carrying a unique index since the table was written. A
capture takes one when it is recorded, and a retry that reaches the ledger a second time is refused
by the index rather than by any process's memory. A ledger implementing `Contracts\Deduplicates` can
be asked which ids it already holds, which makes a retry cost one query instead of a sealed chain
thrown away; a driver that cannot answer is no less correct. Some writers derive the id from what it
stands for — an imported row, an entry plus the key it is being rotated to — so a second pass over
the same range writes nothing. It is deliberately absent from `toArray()`.
→ [Running audits on a queue](../09-operations/03-queues.md)

### Checkpoint

The row an [anchor](#anchor) lives in: `sentinel_checkpoints`, holding the stream, the range
(`sequence_from`, `sequence_to`), the root hash, the construction that produced it, and the
signature with the id of the key that made it. `Integrity\Checkpoint` is that row as something that
can leave the database — an application publishing an anchor to WORM storage or a third-party
timestamper is publishing a fact about a range, not a table row. Emitted by `sentinel:checkpoint`,
or on the write path when `integrity.checkpoints.enabled` is true.
→ [Checkpoints and anchors](../07-integrity/05-checkpoints-and-anchors.md)

### Cold archive

Entries written out of the hot table into NDJSON batches on any disk `Storage` can reach.
`Ledger\ArchiveLedger` speaks the filesystem contract and nothing else, so S3, R2 or MinIO work
without the package knowing they exist. A batch is written, read back, re-digested and every entry
rehashed against its sealed hash *before* any hot row is removed. The driver is refused as
`ledger.default` — it holds no hash to hand a tail back from — and can only be a
[fanout](#fanout) destination or the target of a prune. → [Cold archiving](../08-lifecycle/02-cold-archiving.md)

### Compliance mode

`'compliance' => true`. It refuses to **boot** unless `integrity.signature.enabled` and
`integrity.checkpoints.enabled` are both on — the first write that was supposed to be signed may be
a year away, so the check happens at boot and not at write time. It also forces the write-failure
policy to throw whatever `on_write_failure` says, requires an actor on a [redaction](#redaction), and
turns on the read access log. Sentinel certifies nothing: it ships primitives, and whether a regime
is satisfied is somebody else's question. → [Compliance mode](../08-lifecycle/05-compliance-mode.md)

## D to L

### Discard

Dropping an entry before it is written, by returning `null` from a pipeline stage or `false` from a
`Sentinel::filter()` policy or an `Auditing` listener. It is legal only while the pipeline pass is
open — that is, before the ledger has assigned a [sequence](#sequence) — so a discard leaves no gap
in the chain; `Pipeline\Discard::because()` called after that throws `DiscardException`. Every
discard leaves through one door, `Events\AuditDiscarded`, which carries the audit type, the subject,
the event, the stage and the reason and nothing else — never `before`, `after`, `changes` or
`metadata`, because the stage that discards most often runs before masking and encryption.
→ [Discarding entries](../05-pipeline-and-security/06-discarding-entries.md)

### Entry

One row of `sentinel_audits`; the same thing as an [audit record](#audit-record). Before it reaches
a [ledger](#ledger) it travels as `Data\AuditData`, a mutable object whose properties are named
after the columns precisely so that no translation layer sits between capture and hash. The ledger
is what turns it into an entry: it assigns the [stream](#stream), the [sequence](#sequence), the
link to the entry before it and the hash. → [The write path](03-the-write-path.md)

### Event

The `event` column: the name of what happened, as opposed to the [audit type](#audit-type), which is
the kind of entry. `Enums\AuditEvent` enumerates the names the package itself writes — `created`,
`updated`, `deleted`, `restored`, `force_deleted`, `attached`, `detached`, `synced`, `upserted`,
`transition`, `restore`, `rekeyed`, `read`, `redacted`, `custom` — but the column is a plain string
and a [custom event](#stated-fact) carries whatever name you gave it, capped at 64 characters because
the name is inside the hash. → [What gets audited](../03-capture/01-what-gets-audited.md)

### Execution context

The `context` JSON column, plus the scoped bag an application pushes into with
`Sentinel::withContext()` or `Sentinel::context()`. Every key a resolver returns that is not one of
the nine promoted column names lands here — hostname, environment, ip, user agent, url, route,
method, session id, command, arguments, job, queue, attempts, service name. Manual context is merged
*over* the resolved payload and can never reach a promoted column. `context` is inside the
[canonical payload](#canonical-payload), so anything pushed into it is hashed: do not put there what
you would not want written down. → [Execution context](../04-context/01-execution-context.md)

### Fanout

A composite [ledger](#ledger) that writes one entry to several places. The **first** destination is
the primary and the only one that assigns a sequence, seals a hash and answers every read; the rest
receive the sealed entry through `append()`, because two ledgers each numbering their own chain
would produce two truths about one fact. `on_failure` decides what a refusing secondary costs:
`strict` fails the write and stops at that destination, `primary` raises
`Events\LedgerDestinationFailed` and carries on. A fanout may not name `fanout` as a destination.
→ [Fanout](../11-extending/05-fanout.md)

### Impersonator

On whose behalf, as opposed to [actor](#actor), which is who did it. Two columns,
`impersonator_type` and `impersonator_id`, filled from the session key named in
`resolvers.impersonator.session_key` — `impersonated_by` by default, the convention the common
Laravel impersonation package uses. Two invariants hold: without impersonation both columns are
null and never a copy of the actor, and a session id equal to the actor's own is the same session
rather than a delegation. Neither column is indexed and neither has a query filter; the read paths
are the `Audit::impersonator()` morph relation and the presenter.
→ [Actor and impersonation](../04-context/03-actor-and-impersonation.md)

### Label

The prose word this documentation uses for a row of `sentinel_audit_tags`. The schema, the model and
the query methods say *tag* (`AuditTag`, `whereTag()`, `$auditTags`, `tags.default`); the prose says
*label*. They are the same thing — see [Tag (label)](#tag-label).

### Ledger

The seam every entry crosses on its way to storage: six methods (`write`, `writeMany`, `append`,
`find`, `query`, `stream`) judged on exactly one guarantee — within one [stream](#stream) the
[sequence](#sequence) is dense and monotonic and every entry links to the one before it. Three
guarantees are deliberately withheld because a store without transactions cannot honour them:
`writeMany()` is not atomic, no read is promised to see a write that just returned, and idempotency
by [capture id](#capture-id) belongs to the caller. Five drivers ship — `database`, `fanout`,
`memory`, `null` and `archive` — and the class names are internal: the config string is the
published surface. → [The Ledger contract](../11-extending/01-the-ledger-contract.md)

## M to R

### Manifest

`sentinel_archives`: the map of ranges that have **left** the hot table. One row says which stream,
which sequence range, how many records, and — when the range went to a [cold archive](#cold-archive)
rather than simply being retired — the disk, the path, the checksum and the codec. It has exactly
one writer, the prune, because a row here is read as licence by both the prune's tamper guard and
the verification: a cold copy of a range that is still hot would disarm both. Nothing in it is
hashed or signed, which is why a pruned gap is stepped over only when the manifest *and* the anchors
account for it at once. → [Retention and pruning](../08-lifecycle/01-retention-and-pruning.md)

### Mass operation

A statement Eloquent fires no model event for — `Builder::update()`, `delete()`, `upsert()` — audited
because you asked, per query, with `->auditing()`. It is a macro on the Eloquent builder rather than
a builder override, so a query that does not call it costs exactly what it cost before; Sentinel does
not intercept mass updates globally and there is no flag that makes it. Three modes decide how much
comes out: `summary` (one entry describing the criteria), `individual` (one entry per row), `hybrid`
(individual up to a threshold, summary past it). The entry carries `criteria` and `affected_rows`,
and `affected_rows` is stored exactly as the engine reported it. → [Mass operations](../03-capture/05-mass-operations.md)

### Payload version

The `payload_version` column, `1` for everything this release writes, and the first thing inside the
hash prefix. It exists so that the definition of the [canonical payload](#canonical-payload) can
change without invalidating history: an entry is verified under the definition it names. Anything
that changes which columns are hashed, how they are rendered or how they are separated bumps it and
ships a backwards-compatibility test. It is load-bearing — treat a change to it as a schema event,
not a refactor. → [The hash chain](../07-integrity/01-the-hash-chain.md)

### Pivot line

Also called a **relation line**, which is the word used for the row it becomes in
`sentinel_audit_relations`. One line of what a relationship operation did, as `Data\RelationLine`: the relation name, the
operation (`attach`, `detach` or `update`), the related record's type and id, and `pivot_before` /
`pivot_after` — where `null` means the pivot row did not exist and an empty map means it existed and
carried nothing. One call is one entry: a `sync()` touching three records writes one entry with
three lines. The lines live inside `changes`, so the chain seals them; the same shape is also
written to the [projection](#projection), where it is indexed. → [Relationship auditing](../03-capture/04-relationships.md)

### Previous hash

The `previous_hash` column: the `hash` of the entry before this one in the same [stream](#stream).
The first entry of a stream has `null` here — no zero-th link is invented. The hash itself is
computed over `payload_version`, stream, sequence and `previous_hash`, joined with a `\x1f`
separator so that two different prefixes cannot concatenate into the same bytes, followed by the
canonical payload. Both are load-bearing: a change to either is a change to what every stored entry
means. → [The hash chain](../07-integrity/01-the-hash-chain.md)

### Projection

An index over the evidence, not the evidence. `sentinel_audit_relations` holds one row per
[pivot line](#pivot-line) so that `whereRelation()`, `whereRelated()` and `whereOperation()` can be
answered by an index; the lines themselves live inside the entry's `changes`, which the chain seals.
Deleting a projection row therefore leaves `verifyIntegrity()` untouched and is reported by
`sentinel:verify --projections` as its own kind of defect — rebuilding an index must never be
indistinguishable from tampering. `sentinel_access_log` is a projection of the same shape over
compliance-mode reads. → [Schema](../99-reference/03-schema.md)

### Redaction

The one sanctioned write over an entry that is already sealed. `Redaction\Redactor` empties the six
content columns — `context`, `before`, `after`, `changes`, `metadata`, `criteria` — seals a second
hash over what is left, deletes the entry's labels and relation lines, and writes a chained trail
entry naming who ordered it and why. It destroys content; it does not transform it, which is what
separates it from masking, and it does not remove the row, which is what keeps the chain walkable.
It refuses an entry that no longer reproduces its own hash, and one whose range has been archived or
pruned. → [Redaction and tombstones](../08-lifecycle/04-redaction-and-tombstones.md)

### Refiner

A query filter that narrows a result rather than finding one: no index serves it, so on its own it
walks the table. `whereVersion()` says so in its own docblock — "the counter is not indexed and this
narrows a set that another filter has already found" — and the filters reference marks every other
one. The rule is the same for all of them: put an indexed filter (subject, actor, tenant,
transaction, trace) in front. `whereTag()` is not a refiner but is selectivity-dependent, and
`whereType('model')` earns its keep beside another filter and not alone.
→ [Filters reference](../06-reading/02-filters-reference.md)

### Rehydration

Putting an archived range back into the hot table exactly as it left, headers first.
`Archive\Rehydrator` writes through the database ledger by name rather than through the configured
one, so a hot-plus-cold [fanout](#fanout) does not rewrite the very batch it is reading. It is
idempotent and **not** atomic: a row already present with the same hash is skipped, a
[sequence](#sequence) held by a different hash is refused, and an interruption leaves a prefix a
second pass finishes. The [manifest](#manifest) row stays, because it is the only place the disk,
path, checksum and codec exist. → [Rehydration](../08-lifecycle/03-rehydration.md)

### Restore

Putting a subject back to the state one entry photographed — the state in `after`, or in `before`
for a deletion whose `after` is empty. It is a new entry of `audit_type = 'restore'` pointing back
at its source; nothing is rewritten, reordered or deleted. Redacted and hashed fields never come
back, an encrypted one is decrypted with the key id **the entry recorded**, and the primary key is
never restored because it identifies rather than describes. The result is a `RestoreResult` with
`applied` and `skipped` rather than a boolean, because a partial restore is neither success nor
failure. → [Restoring state](../06-reading/08-restoring-state.md)

### Retention window

The unit a prune actually works in, and the reason a ninety-day policy can free nothing. A range
leaves only when an [anchor](#anchor) covers it *and* every entry inside it is released, because a
window is folded whole — so the effective retention of a range is that of its longest-lived entry.
`Enums\RetentionHold` names the four reasons a stream released nothing: `undeclared`, `unanchored`,
`tail` (the window holding a stream's highest sequence is never offered) and `retained`. None of
them is a failure. Entry-level removal is a [tombstone](#tombstone), not a prune.
→ [Retention and pruning](../08-lifecycle/01-retention-and-pruning.md)

## S to V

### Sequence

The entry's position in its [stream](#stream): dense, monotonic, assigned by the
[ledger](#ledger) and by nothing else. That it is assigned at settlement and not at capture is what
makes the asynchronous modes compatible with the chain — arrival order at the ledger is not the
order of the facts, so `occurred_at` (when it happened) stays apart from `created_at` and `sequence`
(when it was sealed). `(stream, sequence)` is unique and is what `verifyIntegrity()` walks. It is
load-bearing: a gap is reported as tampering. → [The hash chain](../07-integrity/01-the-hash-chain.md)

### Snapshot

The complete state of the subject before and after the change, in the `before` and `after` columns —
not the dirty attributes, and with the model's own casts applied. `null` and `{}` are different
answers: nothing recorded, versus recorded and empty. Keys are sorted and lists are kept as lists so
the columns round-trip on all three engines. `$auditSnapshots = false` drops both columns and keeps
the entry, the chain and the [diff](#diff), which means the flag saves storage and not time.
→ [Snapshots](../03-capture/02-snapshots.md)

### Source

Where the entry originated, as an `Enums\Source` case decided top to bottom from framework signals
the `Context\Runtime` latched: `queue` (Sentinel settling one of its own entries), `job`,
`scheduler`, `api` or `http`, `cli`, `system` under the unit-test runner, `console`, then `system`.
A ninth case, `import`, is never produced by a resolver — only `sentinel:import` writes it, because
the difference between a fact this trail witnessed and one it was told about is worth a value of its
own. `whereSource()` is a [refiner](#refiner): no index covers the column.
→ [The ten resolvers](../04-context/02-resolvers-reference.md)

### Span

The `span_id` column: half of a W3C [trace](#trace) context, the other half being `trace_id`. With
an OpenTelemetry SDK registered, it is the id of the active span. **Without** one, it is the
parent-id read out of the incoming `traceparent` header — that is, the *caller's* span, not the
identity of any local operation. It is neither indexed nor queryable. Telemetry is off by default,
and with it off no header is parsed and the column stays null.
→ [Distributed tracing](../04-context/06-distributed-tracing.md)

### Stated fact

Called a **custom event** throughout this documentation; *stated fact* is the same thing said another
way. An entry the application writes outright, because no model change describes it: an approval, a
dispatch, a decision. `Sentinel::event('invoice.approved')->subject(…)->record()` writes it as
`audit_type = 'custom'` through the same pipeline, ledger and chain as an update, with no shortcut
and no way to tell it apart afterwards. The name is capped at 64 characters and refused at the call
site, because the name is inside the hash. Note that a custom event with no model subject gets no
model-level protection: only the config-level field lists reach it.
→ [Custom and authentication events](../03-capture/07-custom-and-authentication-events.md)

### Stream

The named chain an entry belongs to, and the scope inside which [sequence](#sequence) is dense. It
is resolved from `integrity.stream`: `global`, `tenant` (`tenant:{id}`, falling back to `global`
when no [tenant](#tenant) resolves), `subject_type` (`type:{morph alias}`), a closure, or a class
implementing `Contracts\StreamResolver`. The name is capped at 64 characters — the column width — and
a longer one fails loudly rather than being truncated. It is inside the hash prefix, which is why
changing the strategy opens a second chain rather than renaming an existing one.
→ [Streams](../07-integrity/02-streams.md)

### Subject

What the entry is about: `subject_type` (a morph alias) and `subject_id`, which is `string(64)` so
an integer, a UUID and a ULID key all fit. Some entries have none — a login failure naming nobody, a stated
fact recorded without `->subject()` — and a [mass operation](#mass-operation) summary has a
`subject_type` with no `subject_id`, because it is about a set. The pair is what
`Sentinel::audits()->for($model)` narrows by, and a pipeline stage may not change it: the pipeline
restores both in a `finally`, because they decide which chain signs the entry.
→ [The audit record](02-the-audit-record.md)

### Tag (label)

Operational classification, written to `sentinel_audit_tags` inside the transaction that seals the
entry. Labels come from the model's `$auditTags`, from whatever the caller put on the entry and from
`tags.default`, unioned without repeats; a label longer than 64 characters is refused. They are
**outside** the hash, deliberately, and it cuts both ways: reclassifying an old entry does not break
the chain, and relabelling is not tamper-evident. What has to be provable goes in `metadata`, which
is inside the [canonical payload](#canonical-payload). → [Labels](../06-reading/06-labels.md)

### Tenant

The `tenant_id` column, filled by the closure at `resolvers.tenant.using` or a resolver class of
your own. The package is deliberately ignorant of any tenancy library: it asks the application for
the current key. Turning it on partitions the chain while `integrity.stream` is `tenant` — entries
move to a `tenant:<id>` [stream](#stream) with `sequence` restarting at 1 and `previous_hash` null,
and existing chains keep verifying but stop growing. Pin `integrity.stream` to `global` first if you
do not want that. → [Multi-tenancy](../04-context/04-multi-tenancy.md)

### Tombstone

What a [redaction](#redaction) leaves behind, returned as an object rather than a boolean so the
caller of an erasure request can say exactly what was destroyed and when: the entry id, the stream,
the [sequence](#sequence) (unchanged — the entry stays in its place), the instant, the reason, the
second hash over the remains, and the chained trail entry. Redacting an already-redacted entry hands
back the same tombstone and writes no second trail. `Enums\ContentState` reports the row as `sealed`,
`redacted` or `altered`. → [Redaction and tombstones](../08-lifecycle/04-redaction-and-tombstones.md)

### Trace

The `trace_id` column, read from a W3C `traceparent` header, from an active OpenTelemetry span, or
from a root trace this process opened — in that precedence, decided by `Telemetry\Tracer`. A header
that does not parse is treated as absent; a trace is never invented to fill the hole. `trace_id` is
correlation and never identity or proof: it comes from a value the caller chooses, and the column is
indexed, so turn `telemetry.trust_incoming_header` off at a public edge. What proves the record is
the [hash chain](#previous-hash). → [Distributed tracing](../04-context/06-distributed-tracing.md)

### Transaction header

One row of `sentinel_transactions`: what a business operation was called, who ran it, for which
tenant, when it opened and closed, and how many entries it handed over. Its key is the
`transaction_id` every entry of the operation carries, so a header and its entries find each other
without a join table. Unlike an entry it is mutable — the row is written when the scope opens, so an
operation that died halfway is findable, and completed when it closes — and nothing in it is hashed.
`Sentinel::transaction()` correlates; it opens no database transaction.
→ [Business transactions](../03-capture/06-business-transactions.md)

### Transition

A record moving from one state to the next, as its own kind of entry (`audit_type = 'transition'`)
rather than an update a reader has to recognise. Two ways in: `Sentinel::transition()`, or declaring
the column in `$auditTransitions` so that an update moving it is written as a transition. Sentinel
says it moved; it never performs the move. A column declared as a transition must stay readable in
the entry — it may not also be excluded, redacted, hashed or encrypted, and the refusal fires on the
model's first write of any kind. `Contracts\DeclaresTransitions` is the optional hook that lets a
model refuse a move before the row is written. → [State transitions](../03-capture/08-state-transitions.md)

### Verification depth

Which of three walks `sentinel:verify --depth=` runs, and what each one is entitled to claim.
`entries` (the default) reads and rehashes every entry — the only depth that proves what an entry
*says*. `roots` folds every anchor's range again from the hashes the entries carry, so it names the
entry when a hash was rewritten or reordered but rehashes nothing. `anchors` reads only the anchors
and the unanchored tail. The two shallow depths take no `--from`/`--to` and report a covered range
as `anchored`, never as `intact` — and switching anchoring on never makes an installation verify
less than it did the day before. → [Verification](../07-integrity/06-verification.md)

---

## ⚠️ Words that are easy to confuse

| These two | Are not the same thing |
|---|---|
| `audit_type = 'restore'` and `event = 'restored'` | The first is this engine putting a subject back from an entry. The second is Eloquent's soft-delete revival. A restoration never appears in `Sentinel::transitions()`. |
| [Anchor](#anchor) and [checkpoint](#checkpoint) | The same row. The schema and the command say checkpoint; the verification vocabulary says anchor. |
| [Redaction](#redaction) and masking | Masking transforms a value on the way in and is decided by the pipeline. Redaction destroys content already sealed and is the only sanctioned write over a written entry. |
| `occurred_at` and `created_at` | When the fact happened, stamped at capture and never moved, versus when the entry settled. Under an asynchronous mode they are minutes apart. |
| [Tag](#tag-label) and `metadata` | Labels are outside the hash and are not tamper-evident. `metadata` is inside the canonical payload. Anything that must be provable goes in `metadata`. |
| [Projection](#projection) and evidence | `sentinel_audit_relations` and `sentinel_access_log` are indexes rebuildable from what the chain seals. Removing a row there is not tampering with the chain. |
| [Sequence](#sequence) and `version` | Sequence is the entry's position in its stream and is unique. `version` counts a subject's entries, is assigned without a lock, and a rehydrated range brings its original numbers back — so it is not unique. |
| `whereType()` and `whereEvent()` | Kind of entry versus name of what happened. An application is free to call its own custom event `updated`. |
| [Ledger](#ledger) and the database | The ledger is a contract. `database` is one of five drivers behind it, and the class names are internal. |

---

## ✅ Best practices

✅ **Do** — order a lifeline by the clock of the fact when the mode is not `sync`. `Sentinel::timeline()`
is `audits()->byOccurrence()`, which orders by `occurred_at`.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

Sentinel::timeline()->for($invoice)->get();
```

❌ **Don't** — read the default order as "the order things happened". `audits()->get()` orders by the
settlement clock, and under `queue` or `buffered` that is a different question.

```php
Sentinel::audits()->for($invoice)->get(); // settlement order, not event order
```

✅ **Do** — put a fact that has to be provable in `metadata`, which the hash covers.

```php
Sentinel::event('invoice.approved')
    ->subject($invoice)
    ->metadata(['approval_limit' => 50000])
    ->record();
```

❌ **Don't** — encode it as a label. Labels are outside the hash and anyone with write access can
change one without leaving a mark.

```php
Sentinel::event('invoice.approved')
    ->subject($invoice)
    ->tags(['approval-limit-50000'])   // classification, not evidence
    ->record();
```

✅ **Do** — ask for a kind of entry with `whereType()` when that is what you mean.

```php
Sentinel::audits()->for($invoice)->whereType('transition')->get();
```

❌ **Don't** — reach for the event name and assume it identifies the kind. `event` is a free string,
and a custom event may legitimately be called `updated`.

```php
Sentinel::audits()->for($invoice)->whereEvent('transition')->get(); // a different question
```

✅ **Do** — say "mask" when you mean a mask, and choose the protection by what you will need later.
Encryption is the only one a value comes back from.

```php
/** @var list<string> */
protected array $auditEncrypt = ['national_id'];   // recoverable while its key is on the ring
```

❌ **Don't** — call a mask or a digest anonymisation, and don't reach for either on a field you may
have to restore. The restore planner refuses both outright, reporting `Omission::RedactedField` or
`Omission::HashedField` and leaving the value where it was.

```php
/** @var list<string> */
protected array $auditHash = ['national_id'];      // comparable across entries, and gone for good
```

---

**See also:** [The audit record](02-the-audit-record.md) · [Architecture](05-architecture.md) · [The integrity model](04-the-integrity-model.md) · [Schema](../99-reference/03-schema.md) · [Enums](../99-reference/04-enums.md)
