# 📚 Exit codes

> The three codes every `sentinel:*` command speaks, what each one means for each of the eleven, and
> shell that branches on them without getting it wrong.

**On this page:** [The vocabulary](#the-vocabulary) · [Which command returns what](#which-command-returns-what) ·
[Per command](#per-command) · [The dry-run asymmetry](#the-dry-run-asymmetry) ·
[Codes that did not come from Sentinel](#codes-that-did-not-come-from-sentinel) ·
[Worked examples](#worked-examples) · [Reading a code from PHP](#reading-a-code-from-php) ·
[⚠️ Pitfalls](#️-pitfalls) · [✅ Best practices](#-best-practices)

---

## The vocabulary

Three codes, one meaning each, the same across all eleven commands. They are Laravel's own constants
(`Illuminate\Console\Command`, inherited from Symfony), so they are `0`, `1` and `2` and nothing else.

| Code | Constant | Means | What a watchdog should do |
|---|---|---|---|
| `0` | `Command::SUCCESS` | The ordinary outcome — **including having found nothing to do** | Nothing |
| `1` | `Command::FAILURE` | A bad finding from a run that actually happened | Wake a human |
| `2` | `Command::INVALID` | A run that could not happen at all | Retry, then page the operator |

Three and not two, because *a broken chain* and *an unreachable database* are different facts. A
watchdog that cannot tell them apart will eventually treat one as the other — and the direction it
gets wrong is always the expensive one: a database outage that looks like tampering wastes an
incident, and tampering that looks like an outage gets retried until it scrolls off the screen.

Two consequences follow, and both bite:

- **`0` includes "nothing to do".** `sentinel:checkpoint` with nothing left to anchor, `sentinel:prune`
  with nothing released, `sentinel:flush` over an empty buffer, `sentinel:partitions` on a table that
  is not partitioned — all exit `0`. That is what makes them schedulable. It also means **a `0` is not
  evidence that work happened**; if you need that, read the output, not the code.
- **`1` is not uniform in what it asks of you.** For `sentinel:verify` it means investigate now. For
  `sentinel:redact` it means *this entry will never be redactable by this version* — retrying is
  pointless, and it is `2` that is the retryable one for that command. That is the opposite of the
  intuition, and it is the single easiest thing to get wrong here.

> 📌 **Note.** What is frozen from `v1.0.0-rc.1` is the CLI: the eleven command names, their options
> and their exit codes. The command classes themselves are `@internal` and must not be extended or
> type-hinted ([API stability](09-api-stability.md)).

---

## Which command returns what

| Command | `0` | `1` | `2` | Safe on a schedule |
|---|:---:|:---:|:---:|---|
| `sentinel:install` | ✅ | — | ✅ | no — one-shot |
| `sentinel:show` | ✅ | — | ✅ | no — interactive |
| `sentinel:verify` | ✅ | ✅ | ✅ | **yes** |
| `sentinel:checkpoint` | ✅ | — | ✅ | **yes** |
| `sentinel:prune` | ✅ | ✅ | ✅ | **yes** |
| `sentinel:flush` | ✅ | ✅ | ✅ | **yes**, in `buffered` mode |
| `sentinel:redact` | ✅ | ✅ | ✅ | **no** — destructive, one entry per run |
| `sentinel:export` | ✅ | — | ✅ | no — unbounded, not resumable |
| `sentinel:rekey` | ✅ | — | ✅ | no — needs `--after` chained by hand |
| `sentinel:import` | ✅ | ✅ | ✅ | no — one-shot migration |
| `sentinel:partitions` | ✅ | ✅ | ✅ | **yes**, monthly |
| `php artisan about` | Laravel's | Laravel's | Laravel's | — |

**Five commands never return `1` of their own accord**: `install`, `show`, `checkpoint`, `export`,
`rekey`. A watchdog written for one command's vocabulary therefore does not transfer to another's
unexamined — and see [Codes that did not come from Sentinel](#codes-that-did-not-come-from-sentinel)
before you rely on "never returns 1" as a guarantee about the *process*.

`php artisan about` is Laravel's own command; Sentinel only contributes a section to it and has no
say in its exit code.

---

## Per command

### `sentinel:install`

| Code | For this command |
|---|---|
| `0` | The config was published, **or** was already there and left untouched — and **also** when tables are missing. A missing table is the ordinary state between publishing and migrating, so it is reported and the code stays `0`. |
| `2` | The schema could not be read at all: `sentinel.database.connection` is unreachable or names a connection that does not exist. Prints `The configuration is in place, but the schema could not be read: <reason>`. |

The config publish happens **before** the schema check and is not rolled back by the `2`. Read the
output — `All 7 tables are present.` versus `N of 7 tables are not there yet: <names>` — because the
exit code will not tell you which you got.

### `sentinel:show`

| Code | For this command |
|---|---|
| `0` | Read one entry out, read a subject's life out, **or** found nothing recorded about the subject (`Nothing has been recorded about :subject.`) |
| `2` | An id no entry answers to · a `--subject` that is not readable as `type:id` · both an id and `--subject` · neither · a life query that threw |

Passing both an id and `--subject` is refused rather than resolved: they are two questions and the
answer to one is not the answer to the other. Note that an explicitly empty positional argument still
counts as *given*, so `sentinel:show "" --subject=invoice:77` lands in the ambiguity refusal.

### `sentinel:verify`

| Code | For this command |
|---|---|
| `0` | The chain is intact. Includes a trail **nobody has signed** (the table says how many are unsigned), a trail whose only finding is deliberate redactions, and a shallow walk that took entries on the word of their anchors. |
| `1` | A real break: a hash that does not reproduce, a broken link, a sequence gap nothing accounts for, a signature its own key does not verify, a bad anchor, or — under `--projections` — a divergent relation index. |
| `2` | `--from`/`--to` without `--stream` · `--from`/`--to` on a depth that takes no range · an unknown `--depth` · a ledger that cannot enumerate its streams · anything thrown. |

An unsigned trail exiting `0` is deliberate: it is sound, and reporting otherwise would make the
command useless on every installation that has not switched signing on. The `2` message says the
important half out loud — *Nothing was checked, which is not the same as nothing being wrong.*

> ⚠️ **Warning.** Under `--projections`, a `1` can mean a stale relation index over a chain that is
> perfectly intact. `Enums\IntegrityBreak::ProjectionMismatch` says so in its own sentence. If your
> pager cannot distinguish them, run `--projections` on a separate schedule from the chain walk
> ([Verification](../07-integrity/06-verification.md)).

### `sentinel:checkpoint`

| Code | For this command |
|---|---|
| `0` | Anchored one or more ranges, **or** had nothing left to anchor (`Nothing left to anchor: no stream has a complete window the anchors do not already cover.`) |
| `2` | A ledger that cannot name its chains · a collision · anything thrown. Prints `Nothing was anchored: <reason>` |

No exit `1`: anchoring either happens or cannot be attempted. There is nothing for it to *find*.

### `sentinel:prune`

| Code | For this command |
|---|---|
| `0` | Removed a range, **or** removed nothing. The Note column names which of the four holds — undeclared, unanchored, tail, retained — stopped each stream. |
| `1` | A range no longer folds to the root its anchor recorded. The run stops there and **the rows stay**. |
| `2` | An unknown `--action` · a ledger that cannot enumerate streams · anything thrown, **including the compliance refusal** of `--action=delete` over a range with no archive batch. |

That last one is worth memorising: `ComplianceException::unarchived` is a `2`, not a `1`. It is a run
that could not happen, and the fix is `--action=archive`, not an investigation
([Retention and pruning](../08-lifecycle/01-retention-and-pruning.md)).

### `sentinel:flush`

| Code | For this command |
|---|---|
| `0` | Any completed flush, including one that settled `0` entries |
| `1` | The flush did not settle. The batch was taken, refused and put back — **nothing was lost** — and the answer is to run again. |
| `2` | `sentinel.mode` is not `buffered`, so there is no buffer to settle |

This is the one command where `1` is deliberately *retryable*, and it is deliberately not `2`. The
run happened; the outcome was bad; running again is the fix, and `1` is what tells a cron that. The
printed line says so: `Nothing was lost: what did not settle is back in the buffer.`
([The buffered mode](../09-operations/02-the-buffered-mode.md))

### `sentinel:redact`

| Code | For this command |
|---|---|
| `0` | Destroyed the contents of the entry · a `--dry-run` · an entry that was **already** redacted (it returns the existing tombstone) |
| `1` | A deliberate, permanent refusal from the service: `RedactionException` — the entry is retired, archived, or no longer reproduces its own hash. (`ComplianceException` is caught on the same arm, but its one runtime case is a redaction with no actor, and `--actor` is required here: a missing one is a `2`.) |
| `2` | Missing `--reason` or `--actor` · an `--actor` that is not `type:id` · an id no entry answers to · anything else thrown |

> ⚠️ **Warning.** `1` here is **not** retryable and `2` is. A dead database connection exits `2`;
> reporting it as a refusal would tell an operator their entry is archived when nothing of the sort
> is true. Never wire a retry loop to this command's `1`
> ([Redaction and tombstones](../08-lifecycle/04-redaction-and-tombstones.md)).

### `sentinel:export`

| Code | For this command |
|---|---|
| `0` | Rendered the body to standard output, **or** wrote body and manifest to a disk |
| `2` | A `--format` it does not write (`json`, `ndjson`, `csv`) · a disk it could not reach · a query that failed. Prints `Nothing was exported: <reason>` |

No exit `1`. And a `0` does **not** mean a file exists: writing to a disk needs **both** `--disk` and
`--path`, and with either missing the command prints the whole export to standard output and still
exits `0` ([Export and rekey](../08-lifecycle/06-export-and-rekey.md)).

### `sentinel:rekey`

| Code | For this command |
|---|---|
| `0` | Rotated entries · a `--dry-run` · a pass that rotated nothing |
| `2` | An unresolvable `--key` · a ledger failure · anything thrown inside the rotation loop. Prints `Nothing was re-encrypted: <reason>` |

No exit `1` of its own. A `0` does not mean the trail is rotated: without `--after` every pass reads
the same oldest `--limit` entries. Chain `--after=<the id it printed>` until the run reports nothing
read.

### `sentinel:import`

| Code | For this command |
|---|---|
| `0` | **Every** source row came across — no unreadable rows and no pipeline discards |
| `1` | The run happened and something did not come with it: rows that could not be read, or rows the pipeline refused. A `--dry-run` can return this too. Rows that were *already imported* do **not** trigger it. |
| `2` | A package it does not read · no `--from` at all · a table that is not shaped like the history it was told to expect · anything thrown |

The `1` is a finding about the source, not a rollback: the rows that did land are written and chained.
Decide, then run for real ([The import runbook](../12-migrating/03-the-import-runbook.md)).

### `sentinel:partitions`

| Code | For this command |
|---|---|
| `0` | Maintained the table · had nothing to do · the table is not partitioned at all |
| `1` | It **kept** any partition it was asked to retire — `Still holds entries.` without `--force`, or the compliance refusal with it |
| `2` | A `--table` it does not maintain (only `audits` and `access_log`) · a `--retire` it cannot read as a span · anything thrown |

> ⚠️ **Warning.** This is the exit `1` that fires on a *correct* state. Any partition behind the
> `--retire` cutoff that still holds rows is kept, and any kept partition makes the run exit `1`. A
> monthly schedule with `--retire` that runs before `sentinel:prune` has archived that range wakes a
> watchdog every month over nothing. Order matters: archive first, then retire
> ([Partitioning](../10-database-engines/06-partitioning.md)).

---

## The dry-run asymmetry

A dry run suppresses the **acting**, never the **checking** — but only where the check happens before
the action. That is not true of all five commands that have one:

| Command | `--dry-run` can exit `1`? | Why |
|---|---|---|
| `sentinel:prune` | **yes** | It counts through the same code path |
| `sentinel:partitions` | **yes** | The refusal is computed before the action |
| `sentinel:import` | **yes** | It maps every row and applies the pipeline that would refuse one |
| `sentinel:redact` | **no** | It returns before any check runs |
| `sentinel:rekey` | **no** | It counts what it read and returns |

So `sentinel:redact --dry-run` and `sentinel:rekey --dry-run` tell you *what* would be attempted and
nothing about *whether it would be allowed*. Both return `0` structurally.

One more gap on the same theme: `sentinel:prune --dry-run` returns the counted range **before** the
compliance check for `--action=delete`, so under compliance mode the rehearsal reports
`Would remove N entries` and exits `0` while the real run throws and exits `2`.

---

## Codes that did not come from Sentinel

The eleven commands hand back `0`, `1` or `2`. The **process** can still exit with something none of
them chose:

| Code | Where it came from |
|---|---|
| `1` | An **uncaught** exception. Laravel's console kernel reports it, renders it, and returns `1`. So does an unknown command name. |
| `1`–`255` | Symfony maps an uncaught exception's own non-zero code onto the exit status and caps it at `255` |
| `255` | A PHP fatal error — the command never returned at all |

That first row is the one that matters, because **the five commands with "no exit 1" can still exit
`1` this way.** Four places where a command's own `try` does not cover the work:

| Command | The line outside the catch | What escapes |
|---|---|---|
| `sentinel:install` | publishing `config/sentinel.php` | An unwritable config path |
| `sentinel:show <id>` and `sentinel:redact` | finding the entry by primary key | A dropped table, an unreachable connection |
| `sentinel:rekey` | the Query API read that feeds the rotation | `QueryException`, `LedgerException` |
| `sentinel:flush` | reading `sentinel.mode` | `ConfigurationException` for a mode that is not one of the three |

An uncaught exception is distinguishable by its output — a stack trace, rather than the command's own
one-line `Nothing was …: <reason>`. If your CI captures output, that is how you tell a real `1` from
a leaked one ([Exceptions](06-exceptions.md)).

---

## Worked examples

### A verification gate in CI

```bash
#!/usr/bin/env bash
# Fail the pipeline on a broken chain; fail it differently on a broken run.
set -uo pipefail          # NOT -e: the whole point is to read the code ourselves

php artisan sentinel:verify --depth=roots
code=$?

case "$code" in
  0)
    echo "audit chain sound"
    ;;
  1)
    echo "::error title=Audit chain::The chain does not add up. Do not deploy."
    exit 1
    ;;
  2)
    echo "::error title=Audit chain::Verification could not run — nothing was checked,"
    echo "which is not the same as nothing being wrong."
    exit 2
    ;;
  *)
    echo "::error title=Audit chain::Unexpected exit ${code} — this is not one of Sentinel's."
    exit "$code"
    ;;
esac
```

`set -e` is deliberately absent. With it, the shell aborts on the non-zero exit before `case` is ever
reached, and the gate silently becomes "any failure is the same failure".

`--depth=roots` re-folds each anchored range from the hashes the rows carry now, without rehashing
every entry. That is the depth to put on a per-build gate; keep the full `--depth=entries` walk on a
weekly schedule ([The verification playbook](../07-integrity/07-the-verification-playbook.md)).

### A cron that alerts on `1` and retries on `2`

```bash
#!/usr/bin/env bash
# /etc/cron.daily/sentinel-prune
set -uo pipefail

notify() { printf '%s\n' "$1" | mail -s 'sentinel:prune' ops@example.com; }

for attempt in 1 2 3; do
  php /srv/app/artisan sentinel:prune --action=archive
  code=$?

  if [ "$code" -eq 0 ]; then
    exit 0
  fi

  if [ "$code" -eq 1 ]; then
    # A range no longer folds to the root its anchor recorded. The rows stayed.
    # Retrying will find the same thing. A person has to look.
    notify 'A pruned range does not fold to its anchor. Rows were NOT removed.'
    exit 1
  fi

  # code 2: the run could not happen — an unreachable disk, a locked table,
  # a compliance refusal. Back off and try again.
  sleep $(( attempt * 60 ))
done

notify 'sentinel:prune could not run after three attempts.'
exit 2
```

Retrying a `1` here would be wrong twice over: the finding is stable, and the rows were never
removed, so there is nothing racing. Retrying a `2` is right, because a compliance refusal, an
unreachable archive disk and a lock timeout all land there and two of the three clear on their own.

### The buffered flush, where `1` means "run again"

```bash
#!/usr/bin/env bash
# Every minute, in buffered mode. Exit 1 is retryable HERE and nowhere else.
set -uo pipefail

php /srv/app/artisan sentinel:flush
code=$?

case "$code" in
  0) exit 0 ;;
  1) exit 0 ;;   # the batch went back into the buffer whole; the next minute takes it
  2) echo 'sentinel:flush ran outside buffered mode — check sentinel.mode' >&2; exit 2 ;;
esac
```

Swallowing the `1` is defensible only because of what the command guarantees on that branch — the
batch was put back, nothing was lost, and the next scheduled run picks it up. If you would rather see
it, count it as a metric instead of paging on it; the fact you actually want is
`Events\BufferFlushFailed`, which names the batch ([Events](05-events.md)).

### A deploy check

```bash
#!/usr/bin/env bash
set -uo pipefail

# 1. Publish the config and report the schema. Exit 0 here does NOT mean the
#    tables exist — install reports missing tables and still exits 0.
output=$(php artisan sentinel:install)
code=$?
printf '%s\n' "$output"

if [ "$code" -ne 0 ]; then
  echo 'Sentinel could not read the schema on sentinel.database.connection.' >&2
  exit 2
fi

case "$output" in
  *'are not there yet'*)
    php artisan migrate --force || exit 2
    ;;
esac

# 2. A cheap integrity check before traffic returns. Anchors only: it reads the
#    anchors and takes their word, which is seconds rather than a full walk.
php artisan sentinel:verify --depth=anchors
case $? in
  0) echo 'audit trail sound; releasing' ;;
  1) echo 'audit chain broken — rolling back' >&2; exit 1 ;;
  2) echo 'could not verify — holding the release' >&2; exit 2 ;;
esac
```

The output match is not decoration: `sentinel:install` reports missing tables *and exits `0`*, so the
code alone cannot drive the migrate step.

---

## Reading a code from PHP

`Artisan::call()` returns the same integer, so the vocabulary works from application code too.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

$code = Artisan::call('sentinel:verify', ['--depth' => 'anchors']);

match ($code) {
    Command::SUCCESS => null,
    Command::FAILURE => OnCall::page('Audit chain broken', Artisan::output()),
    Command::INVALID => Ops::retryLater('sentinel:verify could not run', Artisan::output()),
    default => Ops::alert("Unexpected exit {$code} from sentinel:verify"),
};
```

The `default` arm is not defensive padding: an uncaught exception inside the command comes back as
`1`, and anything Symfony maps from an exception's own code comes back as itself.

Some of what a command does has a published service behind it that hands back an object rather than
an integer — the facade's four `verify*` walks, `Redaction\Redactor`, `Security\Rekeyer`,
`Archive\Rehydrator`. Prefer those inside application code; the exit codes exist for the terminal and
the cron. Pruning and exporting have no such surface: `Retention\Pruner` and `Compliance\Export`
carry `@internal`, and the command is the published thing.

```php
// The same verification, as a result you can inspect rather than a code you decode.
$report = Sentinel::verifyEverything();
```

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| A CI gate treats every failure identically | `set -e` aborted the script before the `case` on `$?` | Drop `set -e` (keep `set -uo pipefail`) and read the code explicitly |
| `sentinel:redact` retried five times and never succeeded | Exit `1` there is a **permanent** refusal — archived, retired, or an entry that no longer reproduces its own hash | Retry on `2`, never on `1`, for this command |
| `sentinel:partitions` wakes the pager every month over a healthy database | Any partition kept for still holding entries makes the run exit `1` | Run `sentinel:prune --action=archive` over that range first, then retire |
| The prune cron alerts with `Nothing was removed: … compliance mode …` and code `2` | A `--action=delete` under compliance met a range with no archive batch — and the dry run did not warn, because it returns before that check | Use `--action=archive` |
| `sentinel:export` exits `0` and there is no file | Writing to a disk needs **both** `--disk` and `--path`; with either missing it prints to standard output | Pass both, and keep `<path>.manifest.json` beside the body |
| `sentinel:rekey` exits `0` forever and the old key is still in use | Without `--after` every pass re-reads the same oldest `--limit` entries | Chain `--after=<the id it printed>` until it reports nothing read |
| `sentinel:checkpoint`, `prune` and `verify` all go red at once with exit `2` | `ledger.default` was set to `null`, which is the one shipped driver that cannot enumerate streams | Do not use a null ledger to "turn auditing off" on a host that runs the schedule — use `sentinel.enabled` |
| `sentinel:verify --from=yesterday` exits `0` having verified far more than asked | A non-numeric numeric option is read as **absent**, not as zero — so there was no lower bound, silently | Pass a sequence number; check the reported entry count |
| A command exits `1` with a stack trace instead of a one-line reason | An uncaught exception; Laravel's console kernel returns `1` for any of them | Read the output, not just the code — a real `1` prints a sentence, a leaked one prints a trace |
| A `0` from `sentinel:checkpoint` is read as "anchoring is working" | `0` covers "nothing left to anchor", which is also what an empty or misconfigured stream produces | Read the summary line, or count anchors |

---

## ✅ Best practices

✅ **Do** — branch on the three codes as one vocabulary, and treat `2` as the retryable one
everywhere except `sentinel:flush`.

```bash
php artisan sentinel:verify --depth=roots
case $? in
  0) : ;;                      # fine
  1) page 'audit chain broken' ; exit 1 ;;
  2) retry_later 'verify could not run' ; exit 2 ;;
esac
```

❌ **Don't** — collapse `1` and `2` into "non-zero". A database outage and a tampered chain then look
identical, and the first one you get wrong is the one that costs an incident.

```bash
php artisan sentinel:verify || page 'audit problem'   # which problem?
```

---

✅ **Do** — read the command's output when a `0` has to mean *work happened*. `0` includes "nothing
to do", by design, on every schedulable command.

```bash
output=$(php artisan sentinel:checkpoint)
printf '%s\n' "$output"

case "$output" in
  *'Nothing left to anchor'*) metric 'sentinel.anchors.idle' 1 ;;
  *)                          metric 'sentinel.anchors.issued' 1 ;;
esac
```

❌ **Don't** — infer that a scheduled command is doing its job from a green cron. An unanchored stream
releases nothing on a prune and reports it at exit `0` for months.

```bash
php artisan sentinel:checkpoint && echo 'anchoring healthy'   # says nothing of the sort
```

---

✅ **Do** — put `sentinel:checkpoint` before `sentinel:prune` in the schedule. Retention's unit is the
anchored window, so an unanchored stream releases nothing and the prune reports it as a `0`.

```php
// routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('sentinel:checkpoint')->hourly();
Schedule::command('sentinel:prune')->dailyAt('03:20');
```

❌ **Don't** — schedule the prune alone and read its `0` as retention working. It removed nothing, and
the Note column is the only place that says why.

```php
Schedule::command('sentinel:prune')->daily();   // nothing is anchored; nothing is ever released
```

---

✅ **Do** — rehearse with the dry run on the three commands whose rehearsal can actually find
something, and read the exit code of the rehearsal.

```bash
php artisan sentinel:import --from=owenit --connection=legacy --dry-run
case $? in
  0) php artisan sentinel:import --from=owenit --connection=legacy ;;
  1) echo 'some source rows will not come across — decide first' >&2 ;;
  2) echo 'the source table is not shaped like that history' >&2 ; exit 2 ;;
esac
```

❌ **Don't** — trust `sentinel:redact --dry-run` or `sentinel:rekey --dry-run` to tell you whether the
real run would be refused. Both return `0` before any check runs.

```bash
php artisan sentinel:redact 01J… --reason=… --actor=member:7 --dry-run \
  && php artisan sentinel:redact 01J… --reason=… --actor=member:7
# The dry run exited 0. The real one exits 1: the entry is archived.
```

---

✅ **Do** — call the service from application code when you want a result you can inspect. The exit
codes are for the terminal.

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

$result = Sentinel::verifyIntegrity('global');

if (! $result->isIntact()) {
    OnCall::page($result->message());   // names the break, the stream and the sequence
}
```

❌ **Don't** — shell out to an Artisan command from a request just to get an integer back. You lose
the reason, the coordinate and the stack, and you pay for a whole console bootstrap.

```php
$code = Artisan::call('sentinel:verify', ['--stream' => 'global']);

if ($code !== 0) {
    // Which stream? Which sequence? Which kind of break? All of it is in the
    // output you just threw away.
}
```

---

**See also:** [Artisan commands](../09-operations/06-artisan-commands.md) ·
[Scheduling](../09-operations/07-scheduling.md) · [Exceptions](06-exceptions.md) ·
[Monitoring and troubleshooting](../09-operations/08-monitoring-and-troubleshooting.md) ·
[The verification playbook](../07-integrity/07-the-verification-playbook.md) ·
[Retention and pruning](../08-lifecycle/01-retention-and-pruning.md) ·
[Compliance mode](../08-lifecycle/05-compliance-mode.md)
