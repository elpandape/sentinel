# ♻️ Redaction and tombstones

> Destroying the contents of one sealed entry while its position, its hash and its link to the next
> entry stay exactly where they were — and what that costs you.

**On this page:** [What a redaction is](#what-a-redaction-is) · [Redacting an entry](#redacting-an-entry) · [What a tombstone empties](#what-a-tombstone-empties-field-by-field) · [The three content states](#the-three-content-states-and-what-the-second-hash-is-worth) · [What the verifier does with one](#what-the-verifier-does-with-a-tombstone) · [What redaction does not reach](#what-redaction-does-not-reach) · [Over an archived range](#redaction-over-an-archived-range) · [Naming who ordered it](#naming-who-ordered-it) · [The erasure-request workflow](#the-erasure-request-workflow-end-to-end) · [⚠️ Pitfalls](#️-pitfalls) · [✅ Best practices](#-best-practices)

---

## What a redaction is

Sentinel proves what an entry said. Destroying what it said therefore destroys a proof, and there is
no version of this feature that does not. What the package can do is make the destruction visible,
attributable and bounded: `ElPandaPe\Sentinel\Redaction\Redactor` is the only sanctioned write over
an entry that is already sealed, and everything it does is designed so that the chain around the
entry keeps verifying and the act itself is recorded on that same chain.

After a redaction the entry is still in the table, at the same `sequence`, carrying the same `hash`,
and the entry after it still links to that hash through its `previous_hash`. What is gone is the
content the hash was taken over. Nothing is deleted, reordered or renumbered — history stays
append-only, which is why a redaction adds an entry rather than removing one.

> 📌 **Note.** This page is about `Redaction\Redactor`, which destroys the contents of an entry
> sealed long ago. It is not `security.redaction.*`, which masks values as they are *captured*,
> before an entry is ever written. The two share a word and nothing else — see
> [Protecting sensitive data](../05-pipeline-and-security/02-protecting-sensitive-data.md).

| | Survives a redaction | Does not survive |
|---|---|---|
| The entry | its existence, `id`, `stream`, `sequence` | — |
| The chain | `hash`, `previous_hash`, `signature`, the next entry's link | — |
| The record | who, on whom, when, in which transaction, from which trace | what it said |
| The act | a new chained entry naming who ordered it and why | — |

---

## Redacting an entry

There is no facade method. Resolve the service from the container — it takes no configuration and
joins whatever database transaction the caller has open.

```php
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Redaction\Redactor;
use ElPandaPe\Sentinel\Support\Reference;

$entry = Audit::query()->findOrFail($auditId);

$tombstone = app(Redactor::class)->redact(
    $entry,
    'erasure request ERR-4711',
    Reference::to($officer),        // or new Reference('user', '9')
);
```

`redact(Audit $audit, string $reason, ?Reference $actor = null): Tombstone` returns a value object
rather than a boolean, because the person answering an erasure request has to be able to say what
was destroyed and when:

```php
$tombstone->auditId;      // the entry that was emptied
$tombstone->stream;       // where it lives
$tombstone->sequence;     // where it was, and still is
$tombstone->redactedAt;   // CarbonImmutable
$tombstone->reason;       // the string you passed
$tombstone->redactedHash; // the second hash, over what is left
$tombstone->trail;        // ?Audit — the chained entry that records who ordered it
```

`Support\Reference` is the morph pair an actor is named by: `new Reference($type, $id)`, or
`Reference::to($model)` which reads the model's morph alias and key. A caller-named actor is written
back onto the trail entry *after* the pipeline has run, and it clears the impersonator columns —
the session's impersonator was standing in for the actor the resolvers found, not for the one you
just named.

### From a terminal

```bash
php artisan sentinel:redact 01JB7QW3T0M9ZK4H2XF8ND6PVR \
    --reason="erasure request ERR-4711" \
    --actor=user:9
```

| Option | Required | What it does |
|---|---|---|
| `{audit}` | yes | The entry's `id`, looked up by primary key — not through the Query API |
| `--reason=` | yes | Stored on the entry as `redaction_reason` and on the trail as `metadata.redaction.reason` |
| `--actor=type:id` | yes | Who ordered it. Split on the **last** colon, so `App\Models\User:9` parses |
| `--dry-run` | no | Prints what it would destroy and destroys nothing |

The actor is mandatory even outside compliance mode, and that is not ceremony: nothing resolves an
actor in a console process, so a command that let it default would write the one entry in the whole
package whose entire purpose is to say who did this, with nobody's name on it.

| Exit code | Meaning |
|---|---|
| `0` | Redacted, or already redacted, or `--dry-run` |
| `1` | Refused — `RedactionException` or `ComplianceException`, message printed |
| `2` | Could not run — missing `--reason`/`--actor`, unparseable actor, unknown id, or any other throwable |

> ⚠️ **Warning.** `--dry-run` returns before any of the guards run. It will happily say
> "Would destroy the contents of entry …" for an entry the real run refuses because its range has
> been archived, or because it no longer reproduces its own hash. The dry run confirms the id and
> the actor format, nothing more.

---

## What a tombstone empties, field by field

Six columns, not three. `Redactor::CONTENT` names them so the list cannot be guessed at:

| Column | After a redaction | Why it is on the list |
|---|---|---|
| `context` | `[]` | Carries ip, user agent, url, route and method |
| `before` | `null` | The state before |
| `after` | `null` | The state after |
| `changes` | `null` | The literal old and new values — **a relation entry keeps its content only here** |
| `metadata` | `null` | Goes whole, including facts that are not personal data (a transition's `reason` among them) |
| `criteria` | `null` | A mass operation's serialised where clauses and their bindings |

Note the asymmetry: `context` is `NOT NULL` in the schema and is emptied to an empty array, while the
other five go to `null`. The canonicaliser renders the two differently, so this distinction is
load-bearing — one byte of disagreement between the writer and the verifier would report every
tombstone in the installation as tampering. See
[Canonicalization](../07-integrity/03-canonicalization.md).

Two satellite tables go with them:

- **labels** — every `sentinel_audit_tags` row for the entry is deleted. A label can name a person.
- **relation lines** — every `sentinel_audit_relations` row is deleted. A line names what the entry
  pointed at. See [Relationship auditing](../03-capture/04-relationships.md) and
  [Labels](../06-reading/06-labels.md).

Three columns are written: `redacted_at`, `redaction_reason`, `redacted_hash`.

**Everything else is untouched**, and that is the part readers get wrong. The entry still says who
did it, to whom, when, and under which correlation:

`id` · `stream` · `sequence` · `audit_type` · `event` · `severity` · `subject_type` · `subject_id` ·
`actor_type` · `actor_id` · `impersonator_type` · `impersonator_id` · `tenant_id` ·
`transaction_id` · `request_id` · `trace_id` · `span_id` · `source` · `version` · `payload_version` ·
`encryption` · `algorithm` · `previous_hash` · `hash` · `signature` · `signature_key_id` ·
`capture_id` · `source_audit_id` · `affected_rows` · `occurred_at` · `created_at`

> 🔒 **Security.** A redacted entry about a person still names that person in `subject_id`, and every
> entry that person caused still names them in `actor_id`. A tombstone destroys **content**, never
> identity. `encryption` survives too — it holds `{fields, key_id}`, so the *names* of the fields
> that were protected remain readable even though the values are gone.

---

## The three content states and what the second hash is worth

`Audit::verifyContent()` returns `ElPandaPe\Sentinel\Enums\ContentState`:

| Case | Meaning | How it is decided |
|---|---|---|
| `Sealed` | Untouched | `redacted_at` is null and the row reproduces `hash` |
| `Redacted` | A declared redaction | `redacted_at` is set and the row reproduces `redacted_hash` |
| `Altered` | Somebody wrote into the row | Neither of the above holds |

`redacted_at` is the discriminant and the hash only corroborates, **in that order**. An entry whose
content columns were already empty redacts to the very bytes it already had, so its second hash
equals its first and both "reproduces the original" and "reproduces the redacted state" are true at
once; asked the other way round, a tombstone over an already-empty entry would report as `Sealed`.

`verifyIntegrity()` keeps its old meaning — *does this row reproduce the hash it carries* — and a
tombstone answers `false`. That is deliberate: answering `true` would have to rest on
`redacted_hash`, a column no signature covers, and would then report a row somebody emptied by hand
as healthy.

```php
use ElPandaPe\Sentinel\Enums\ContentState;
use ElPandaPe\Sentinel\Models\Audit;

$reloaded = Audit::query()->findOrFail($auditId);

$reloaded->verifyContent();              // ContentState::Redacted
$reloaded->verifyIntegrity();            // false — unchanged meaning, errs towards the alarm
$reloaded->hash === $entry->hash;        // true — the link to the next entry is untouched
$reloaded->toArray()['integrity']['redacted'];
// ['at' => '…', 'reason' => 'erasure request ERR-4711', 'hash' => '…']
```

### What `redacted_hash` proves, and what it does not

It proves that the remains are the ones the redaction left: write anything into a redacted row
afterwards and `verifyContent()` turns `Altered`.

It proves nothing against somebody who can write the row. `redacted_hash`, `redacted_at` and
`redaction_reason` are **outside** the canonical payload, outside the
[signature](../07-integrity/04-signing.md) and outside the anchor
[fold](../07-integrity/05-checkpoints-and-anchors.md). Whoever can empty `before` can equally write
`redacted_at` and a recomputed `redacted_hash`. And because the reason is outside it too, the reason
can be rewritten later without the state changing at all.

> 📌 **Note.** What separates a declared redaction from an attack is the **trail entry** — chained,
> hashed and signed like any other, with `audit_type = 'security'`, `event = 'redacted'`,
> `source_audit_id` pointing at the emptied entry, and `metadata.redaction` holding the audit id,
> the stream, the sequence and the reason. An attacker does not write one.

The trail entry's severity comes from `severity.default` (`info`) unless you override
`severity.events.redacted` in the config. It is an ordinary entry: it consumes a sequence, links
into the chain, and can be queried with `whereType('security')` / `whereEvent('redacted')`.

---

## What the verifier does with a tombstone

A declared redaction is **counted, never announced**. The walk does not stop at one, `reason` is not
filled, and `isIntact()` does not invert — a watchdog must not page for an act somebody performed on
purpose and left a trail for.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

Sentinel::verifyIntegrity('global')->isIntact();   // true, with a tombstone inside
Sentinel::verifyEverything()->redacted();          // how many tombstones the walk read
Sentinel::verifyEverything()->content();           // ['sealed' => 5, 'redacted' => 1]
```

```
php artisan sentinel:verify
# Verified 6 entries across 1 streams. The chain is intact, and 1 of them were redacted
# on purpose: their contents are gone and the record of their destruction is not.
```

`sentinel:verify` exits `0` for a stream whose only finding is redactions, and prints
`(1 redacted)` beside the count of entries read.

A real tampering still wins. It reports `IntegrityBreak::HashMismatch`, stops the walk at that
sequence, and does so whether or not a tombstone stands next to it — otherwise a redaction would be
a place to hide one.

> ⚠️ **Warning.** The two shallow depths do not see tombstones. `verifyAnchors()` and
> `verifyRoots()` fold the `hash` column, which a tombstone keeps, so a redacted entry inside a
> closed anchor window reads as `anchored` and is counted zero times; the same tombstone in the
> unanchored tail *is* counted, because the tail is walked entry by entry. Whether a given tombstone
> shows up therefore depends on `integrity.checkpoints.every`. All three depths agree on the thing
> that matters: none of them calls a tombstone a tampering. See
> [Verification](../07-integrity/06-verification.md).

Two other readers know about tombstones:

- **Projection checking** (`sentinel:verify --projections`) skips a redacted entry rather than
  comparing it. Its relation lines were destroyed with the rest of its content, so comparing would
  report every redacted relation entry as divergent, permanently, over an act the package performed.
- **Restoring** refuses outright. `Restore\Planner` and `Restore\RelationPlanner` return
  `Omission::EntryRedacted` before they check anything else — a redacted entry holds nothing to put
  back. See [Restoring state](../06-reading/08-restoring-state.md).

---

## What redaction does not reach

Enumerated, because every one of these has been mistaken for a bug:

| It does not reach | What actually happens |
|---|---|
| **An archived or pruned range** | Refused in place with `RedactionException::archived` (naming the disk and path) or `::retired`. The round trip below is the supported answer |
| **A previous object version** | A batch's path is a pure function of its range, so re-archiving overwrites the same key. On a bucket with versioning or object-lock the pre-redaction object survives — the package never issues a delete on the archive disk |
| **Replicas, backups and copies you made** | Nothing in the package can prove a redaction reached them. Do not report an erasure as complete on the strength of a tombstone |
| **Identity** | `subject_*`, `actor_*`, `impersonator_*` and `tenant_id` are untouched, on the redacted entry and on every other entry that person appears in |
| **Exports already handed out** | `sentinel:export` renders a redacted entry *as* redacted, carrying its redaction block — but a file exported before the redaction still holds the content |
| **The transaction header** | `sentinel_transactions` rows are not touched; the entry keeps its `transaction_id` |
| **Access-log rows** | `sentinel_access_log` rows written under compliance mode are a separate table and are not touched |
| **Your own tables** | Redaction empties an audit entry. The record the entry is about is yours to deal with |
| **A subject axis in the manifest** | `sentinel_archives` is indexed by `(stream, sequence_from)` and `(stream, sequence_to)` and never by subject, so an erasure over one person's history is answered range by range |

### What a redaction trail carries across tenants

The trail entry goes through the normal write pipeline, and the context stage assigns **every**
promoted column on every pass — `actor_*`, `impersonator_*`, `tenant_id`, `request_id`, `trace_id`,
`span_id`, `source` — so that a second pass leaves none of the first one's residue. What the
redaction states outright is applied over that: the actor it was given, and **the tenant of the
entry it redacted**, null included.

The consequence, in plain terms: **the trail carries the redacted entry's tenant, not the run's.**

```php
$entry->tenant_id;              // 'acme'
$tombstone->trail?->tenant_id;  // 'acme' — from a console run with no tenancy resolver too
```

And with `integrity.stream = 'tenant'`, the stream is derived from `tenant_id`, so the trail lands
on `tenant:acme`, beside the entry it is about. An entry that had no tenant gets a trail with none,
on `global`, whichever tenant happens to be active. The rest of the trail's context — actor,
request, source, trace — describes the run that redacted, which is what those columns are for.

Before `v1.0.0-rc.2` the trail carried the run's tenant, and a console redaction of an `acme` entry
left its trail on `global`. Entries those releases wrote keep what they recorded: history is
append-only and nothing moves them. See [Multi-tenancy](../04-context/04-multi-tenancy.md).

---

## Redaction over an archived range

An entry that is not in the hot table has no row to empty. The question is asked of the **rows**, not
of the manifest, so a range that was brought back is redactable even though a manifest row still
claims it.

| Refusal | When | Message names |
|---|---|---|
| `RedactionException::archived` | The range left the hot table into a batch | the disk and the path holding it |
| `RedactionException::retired` | The range left with nothing kept (`--action=delete`) | the stream and the sequence |
| `RedactionException::unverifiable` | The row no longer reproduces its own hash | the stream and the sequence |

The first two end with "Nothing was written."; the third ends with "A tombstone over an altered row
would hide the alteration behind a declaration."

The round trip is the supported answer, and it produces a batch with the entry emptied inside it:

```php
use ElPandaPe\Sentinel\Archive\Rehydrator;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Redaction\Redactor;
use ElPandaPe\Sentinel\Support\Reference;

// 1. Bring the range back. Idempotent: what is already there with the same hash is skipped.
$done = app(Rehydrator::class)->restore('tenant:acme', 5001, 6000);
$done->restored;    // entries written back
$done->skipped;     // entries already present
$done->operations;  // transaction headers put back
$done->batches;     // files read

// 2. Redact in the hot table.
$entries = Audit::query()
    ->where('stream', 'tenant:acme')
    ->whereBetween('sequence', [5001, 6000])
    ->where('subject_type', 'patient')
    ->where('subject_id', '77')
    ->cursor();

foreach ($entries as $entry) {
    app(Redactor::class)->redact($entry, 'erasure request ERR-4711', Reference::to($officer));
}
```

```bash
# 3. Let the next prune write the range out again.
php artisan sentinel:prune --action=archive --stream=tenant:acme
```

The re-archived batch carries the tombstones — the archive asks the same `Integrity\Content` rule
the verifier does, and a tombstone reproduces the hash it is entitled to reproduce. The manifest row
for the range is **updated in place**, not duplicated: what changes is where the bytes are and what
they digest to, never which range it is about. Restore the range again afterwards and it comes back
redacted.

> ⚠️ **Warning.** On a bucket with versioning or object-lock — which is exactly what you want for
> tamper resistance — the previous, unredacted version of that object is still there. Reconciling
> those two wishes is an object-lifecycle decision on your storage, and the package cannot make it
> for you. See [Cold archiving](02-cold-archiving.md) and [Rehydration](03-rehydration.md).

---

## Naming who ordered it

Under [compliance mode](05-compliance-mode.md), the actor stops being optional: the one operation
that destroys evidence has to name who ordered it, and a trail entry with nobody on it is the shape
of an unattributable deletion.

```php
// config/sentinel.php
'compliance' => true,
```

```php
app(Redactor::class)->redact($entry, 'erasure request ERR-4711');
// ComplianceException: Sentinel is in compliance mode, where a redaction has to name who
// ordered it. Pass an actor to Redactor::redact(), or use sentinel:redact with --actor.
```

Two orderings are worth knowing:

- **Idempotency comes first.** Redacting an entry that is already redacted returns its `Tombstone`
  and writes no second trail — and it returns *before* the compliance check, so a second redaction
  without an actor does not throw. Only the first redaction of an entry is guarded.
- **Verification comes before the write.** The entry is rehashed and refused if it does not
  reproduce its own hash, so a tombstone can never be placed where an alarm should be.

Pass an actor even when compliance mode is off. The trail entry is the only thing that separates a
declared redaction from an attack, and one with nobody on it does not do that job.

---

## The erasure-request workflow, end to end

```php
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Models\AuditArchive;
use ElPandaPe\Sentinel\Redaction\Redactor;
use ElPandaPe\Sentinel\Support\Reference;

// 1. Find the entries. A person shows up in two roles, and they are different queries.
$about = Sentinel::audits()->for($patient)->take(500)->get();
$by    = Sentinel::audits()->by($patient)->take(500)->get();

// 2. Find what is no longer hot. The manifest is keyed by stream and range, never by subject.
$ranges = AuditArchive::query()->where('stream', 'tenant:acme')->orderBy('sequence_from')->get();

// 3. Rehydrate the ranges you need, redact, re-archive. (See the section above.)

// 4. Redact what is hot, one entry at a time, each with the same reference.
$redactor = app(Redactor::class);
$officer  = Reference::to($dataProtectionOfficer);
$receipts = [];

foreach ($about->merge($by) as $entry) {
    $tombstone = $redactor->redact($entry, 'erasure request ERR-4711', $officer);

    // 5. Keep the receipt: this is what you answer the request with.
    $receipts[] = [
        'entry' => $tombstone->auditId,
        'stream' => $tombstone->stream,
        'sequence' => $tombstone->sequence,
        'at' => $tombstone->redactedAt->toIso8601String(),
        'trail' => $tombstone->trail?->id,
    ];
}
```

**The honest limit: this is not one command.** There is no "erase everything about this person"
call, and the reasons are structural rather than missing effort:

1. Redaction's unit is one entry. Retention's unit is an anchored window and cannot free a single
   entry — see [Retention and pruning](01-retention-and-pruning.md).
2. Cold ranges have to be located range by range and brought back before they can be touched, one
   range at a time.
3. Every redaction writes a **new** entry naming the same subject. Answering an erasure request
   about a person adds entries about that person — that is what makes the destruction provable, and
   it is the trade the design makes on purpose.
4. Identity survives everywhere. `subject_id` and `actor_id` remain, on the redacted entries and on
   the trail.

> 🐘 **Engine.** The tombstone's write goes through the query builder and its second hash is taken
> over the same rendering the verifier uses, so a redaction reproduces byte for byte on SQLite,
> MySQL 9 and PostgreSQL 16 — the three engines the chain is gated on (`make test-dbs`). The
> `redacted_at` column is `datetime(6)`; a driver that truncates microseconds would move the entry's
> serialised form, not its hashes, which do not cover it.

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| `verifyIntegrity()` returns `false` on an entry you redacted on purpose | The bool means "reproduces the hash it carries"; a tombstone reproduces `redacted_hash`, which no signature covers | Ask `verifyContent()` — three states, not two. Keep the bool for the question it has always answered |
| `sentinel:verify` reports `0 redacted` on a stream that has a tombstone | `verifyAnchors()` / `verifyRoots()` never open an entry; an anchored range folds the `hash` column, which a tombstone keeps | Use the entries depth, or `Sentinel::verifyEverything()->redacted()` |
| `$tombstone->trail` is `null` after a successful redaction | The entry has not settled in this process: `after_commit` inside an open transaction, or queue/buffer dispatch | Query `Audit::query()->where('source_audit_id', $entry->id)` after the commit or the worker run |
| The trail entry has no tenant, or lands in `global` while the entry is in `tenant:acme` | The trail was written by a release before `v1.0.0-rc.2`, which took the trail's tenant from the run | Nothing moves it: history is append-only. From that candidate on, the trail carries the redacted entry's tenant |
| `--dry-run` says "Would destroy" but the real run refuses | The dry run returns before the archived / retired / unverifiable guards | Check `redacted_at` and the manifest yourself, or just run it and read the refusal |
| A second `sentinel:redact` with no `--actor` under compliance mode does not throw | Idempotency is the first branch; the guards follow it | Nothing to fix — the entry was already redacted and no second trail was written |
| `RedactionException::unverifiable` on an entry you need to erase | The row no longer reproduces its own hash | Investigate the alteration. The package refuses to put a declared redaction where an alarm should be |
| `RedactionException::archived` naming a disk and a path | The range left the hot table into a batch | Rehydrate the range, redact, re-archive |
| The pre-redaction batch is still downloadable from your bucket | The batch path is a pure function of the range, so re-archiving overwrites the same key — and versioning keeps the old object | Handle it in the bucket's lifecycle policy; the package never deletes from the archive disk |
| A restore of a redacted entry puts nothing back | `Restore\Planner` refuses with `Omission::EntryRedacted` before it looks at anything else | Expected. A tombstone holds no state to restore |
| `ArchiveException::unreadable` on a later rehydration | Someone deleted the batch object by hand while its manifest row still claims it | Never erase by deleting a batch; redact through the round trip |

---

## ✅ Best practices

✅ **Do** — pass an actor on every redaction, compliance mode or not. The chained trail entry is the
only thing that distinguishes a declared redaction from an attack on the row.

```php
app(Redactor::class)->redact($entry, 'erasure request ERR-4711', Reference::to($officer));
```

❌ **Don't** — let the actor default outside compliance mode. Nothing refuses you, and you end up
with the one entry whose whole purpose is to say who did this carrying nobody's name.

```php
app(Redactor::class)->redact($entry, 'erasure request ERR-4711');
```

✅ **Do** — keep the reason a reference to a record kept elsewhere. It lands on the entry, in the
trail's `metadata`, and in every export of that entry.

```php
$redactor->redact($entry, 'erasure request ERR-4711', $officer);
```

❌ **Don't** — write the personal data into the reason. You would be reinstating, in a column that
no hash and no signature covers and that can be rewritten later without changing `ContentState`,
exactly what the redaction was for.

```php
$redactor->redact($entry, 'erased home address of Ana Pérez, ana@example.test', $officer);
```

✅ **Do** — go through `Redactor` for every erasure, including bulk ones, so each entry gets its
second hash and its trail.

```php
foreach ($entries as $entry) {
    $redactor->redact($entry, 'erasure request ERR-4711', $officer);
}
```

❌ **Don't** — empty the columns yourself. The model refuses updates by contract, so this has to use
the query builder — and the result is `ContentState::Altered` on every row you touched, which is a
permanent alarm your verification will report forever.

```php
DB::table('sentinel_audits')->whereIn('id', $ids)->update(['before' => null, 'after' => null]);
```

✅ **Do** — erase inside a cold range with the round trip: rehydrate, redact, let the next prune
write it out again. The batch that comes back holds the tombstone.

```php
app(Rehydrator::class)->restore('tenant:acme', 5001, 6000);
// redact, then: php artisan sentinel:prune --action=archive --stream=tenant:acme
```

❌ **Don't** — delete the batch file from the archive disk to "erase" it. The manifest row still
claims a batch that is gone, and the next rehydration fails with `ArchiveException::unreadable`.

```bash
aws s3 rm s3://audit-cold/sentinel/global-1a2b3c4d/00000000000000005001-00000000000000006000.ndjson.gz
```

✅ **Do** — monitor redactions as a counted, expected quantity, and alert on the states that mean
something is wrong.

```php
$report = Sentinel::verifyEverything();

$report->redacted();                              // expected, and worth a metric
$report->isIntact();                              // this is what pages someone
```

❌ **Don't** — page on a per-entry `verifyIntegrity() === false`. Every tombstone in the trail
answers `false` by design, so this alert fires on your own erasure requests.

```php
if (! $entry->verifyIntegrity()) {
    $this->pageOnCall();  // fires on every entry you redacted on purpose
}
```

✅ **Do** — tell a reader that a value is gone by asking `verifyContent()` before you render it.

```php
if ($entry->verifyContent() === ContentState::Redacted) {
    return __('This entry was redacted on :at', ['at' => $entry->redacted_at]);
}
```

❌ **Don't** — infer it from an empty `before`. An entry that never held earlier state and one whose
state was destroyed both show `null`; only `redacted_at` and the redaction block tell them apart.

```php
if ($entry->before === null) {
    return 'Redacted';  // wrong for every creation event ever written
}
```

---

**See also:** [Retention and pruning](01-retention-and-pruning.md) · [Cold archiving](02-cold-archiving.md) · [Rehydration](03-rehydration.md) · [Compliance mode](05-compliance-mode.md) · [Export and rekey](06-export-and-rekey.md) · [Verification](../07-integrity/06-verification.md) · [The hash chain](../07-integrity/01-the-hash-chain.md) · [Protecting sensitive data](../05-pipeline-and-security/02-protecting-sensitive-data.md) · [Restoring state](../06-reading/08-restoring-state.md) · [Enums](../99-reference/04-enums.md) · [Exceptions](../99-reference/06-exceptions.md) · [Artisan commands](../09-operations/06-artisan-commands.md)
