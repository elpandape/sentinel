# 🚀 Turning auditing off

> The three ways to stop Sentinel writing, which of them leaks, and what an audit trail with a hole
> in it looks like to the person who has to read it later.

**On this page:** [Three switches](#three-switches) · [withoutAuditing()](#withoutauditing-the-one-that-nests) · [pause() and resume()](#pause-and-resume-the-one-that-leaks) · [The configuration kill switch](#the-configuration-kill-switch) · [One operation, one model, everything](#one-operation-one-model-everything) · [What stays on](#what-stays-on) · [The silent-absence failure mode](#the-silent-absence-failure-mode) · [Pitfalls](#-pitfalls) · [Best practices](#-best-practices)

---

## Three switches

Every capture path in the package asks the same question first:

```php
// src/Sentinel.php
public function isRecording(): bool
{
    return $this->config->enabled() && ! $this->paused;
}
```

Two inputs, three ways to move them.

| Switch | Scope | Restores itself | Use it for |
|---|---|---|---|
| `Sentinel::withoutAuditing(Closure $callback)` | The callback | **Yes** — in a `finally`, previous state | One operation: an import, a backfill, a fixture seed |
| `Sentinel::pause()` / `Sentinel::resume()` | The container scope — the rest of the request or job | **No** | Only when the two calls genuinely cannot share a closure |
| `sentinel.enabled` (`SENTINEL_ENABLED`) | The whole application | n/a | An environment where nothing should be audited at all |

`Sentinel` is bound `scoped`, so the paused flag lives on one instance per request, job or Octane
scope. It never crosses a process boundary: it is not in a queue payload, and a job dispatched from
inside a suspended block is settled by a worker that resolves its own `Sentinel` with the flag down.

---

## `withoutAuditing()`: the one that nests

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

$imported = Sentinel::withoutAuditing(fn (): int => $importer->run());
```

It returns whatever the callback returns. The implementation is four lines and all four matter:

```php
public function withoutAuditing(Closure $callback): mixed
{
    $paused = $this->paused;      // remember, do not assume
    $this->paused = true;

    try {
        return $callback();
    } finally {
        $this->paused = $paused;  // restore, even on a throw
    }
}
```

Two properties follow, both covered by tests:

- **It survives an exception.** A throw inside the callback propagates and the previous state is
  still restored — `it('restores recording when the callback throws')`.
- **It nests correctly.** It restores the *previous* state, not "on". Calling it inside an
  already-paused scope leaves the scope paused when it returns —
  `it('keeps recording paused after a nested suspension')`.

This is the form to reach for in every case where the two ends of the suspension can be written in
the same place, which is nearly every case.

---

## `pause()` and `resume()`: the one that leaks

```php
public function pause(): void  { $this->paused = true; }
public function resume(): void { $this->paused = false; }
```

`resume()` sets the flag to false unconditionally. It does not restore a previous value, so it does
not nest: a `resume()` inside a block that was already suspended turns auditing back **on** for the
remainder of the outer block.

And nothing else takes the flag down. Throw between a `pause()` and its `resume()` and auditing stays
off for the rest of that container scope — the rest of the request, the rest of the job — with the
entries that were supposed to be written simply absent, and no error anywhere.

```php
// If $backfill->run() throws, nothing after this in the request is audited.
Sentinel::pause();
$backfill->run();
Sentinel::resume();
```

If you must use the unscoped pair, write the `finally` yourself:

```php
Sentinel::pause();

try {
    $backfill->run();
} finally {
    Sentinel::resume();
}
```

> ⚠️ **Warning.** That is `withoutAuditing()` with more lines and one less guarantee: it clears the
> flag rather than restoring it, so it still breaks nesting. Use the pair only when the two calls are
> genuinely in different methods and no closure can span them.

---

## The configuration kill switch

```php
// config/sentinel.php
'enabled' => env('SENTINEL_ENABLED', true),
```

```dotenv
SENTINEL_ENABLED=false
```

`Support\Config::enabled()` demands a real boolean. A string, a null, anything else raises
`ConfigurationException::expected('enabled', 'a boolean', …)` — `Config` caches nothing and
re-validates on every read, so a bad value planted at runtime surfaces inside a write path rather
than at boot. The same property is why `config()->set('sentinel.enabled', false)` takes effect on the
very next capture, which is how tests switch it.

What `enabled = false` does **not** do:

- It does not unregister the Eloquent listeners. `bootAuditable()` still runs and the observer is
  still resolved and called; it returns immediately without building a snapshot.
- It does not drop the tables, skip the migrations, or change the schema.
- It does not stop reads, verification, or the maintenance commands. See
  [What stays on](#what-stays-on).

> 📌 **Note.** [Compliance mode](../08-lifecycle/05-compliance-mode.md) does not close this door.
> `Compliance\Requirements::enforce()` checks `integrity.signature.enabled` and
> `integrity.checkpoints.enabled` at boot and nothing else — it has no opinion about `enabled` and no
> opinion about `pause()`.

---

## One operation, one model, everything

| Goal | Mechanism | What it actually does |
|---|---|---|
| One operation | `Sentinel::withoutAuditing($callback)` | Every capture path inside the callback returns without writing |
| One query | Simply do not call `->auditing()` | Mass statements are opt-in per query; they write nothing by default |
| One model | **There is no per-model switch** — use `Sentinel::filter()` | The entry is captured and transformed, then discarded in the last pipeline stage |
| One model's content | `$auditSnapshots = false`, `$auditExclude`, `$auditRedact` | The entry is still written and still chains; only what it carries changes |
| Everything, one environment | `SENTINEL_ENABLED=false` | Every capture path is a no-op |

There is no `$auditEnabled` property and no per-model configuration key. The nearest thing is a
global veto registered once, from a service provider:

```php
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Facades\Sentinel;
use Illuminate\Support\ServiceProvider;

final class AuditPolicyProvider extends ServiceProvider
{
    public function boot(): void
    {
        Sentinel::filter(static fn (AuditData $audit): bool => $audit->subject_type !== HeartbeatPing::class);
    }
}
```

The closure runs in `EnforcePolicies`, the last stage, so it sees the entry exactly as it would have
been written — masked, encrypted, labelled — and never the plaintext. Returning `false` discards before
the ledger assigns a sequence, so the chain gets no gap. Registered filters cannot be removed through
the public surface, which is why a provider is the right place and a controller is not. See
[Discarding entries](../05-pipeline-and-security/06-discarding-entries.md).

> 💡 **Tip.** A filter is a *narrower* instrument than a pause: it removes one class of entry and
> leaves the rest of the request fully audited. Prefer it whenever the thing you want gone is
> "entries about X" rather than "entries during Y".

---

## What stays on

Suspension governs capture. It does not govern the rest of the package.

| Still works while paused or disabled | Why |
|---|---|
| `Sentinel::audits()`, `timeline()`, `transitions()` and every query | Reading is not writing |
| `verifyIntegrity()`, `verifyAnchors()`, `verifyRoots()`, `verifyEverything()` | Verification reads the chain that exists |
| The artisan maintenance commands (prune, archive, checkpoint, verify) | They operate on entries already written |
| `Restore\Restorer` — the entry describing a restoration | It records through `Capture\Recorder`, which never asks `isRecording()` |
| `Redaction\Redactor` — the entry describing a redaction | Same path, same reason |

Those last two are deliberate and documented in the source: `withoutAuditing()` says not to audit
what the *application* is about to do, and the engine writing into your business model — or
destroying the contents of one of its own entries — is not that. A trail that could put a record back
or empty an entry without saying so would mislead by omission about the only two things the package
does that are not merely observing. See [Restoring state](../06-reading/08-restoring-state.md) and
[Redaction and tombstones](../08-lifecycle/04-redaction-and-tombstones.md).

One thing switches **off** with capture, and it is easy to miss: the state-machine check.
`ModelCapture::vet()` returns early when recording is off, so a model implementing
`Contracts\DeclaresTransitions` **does not refuse an illegal move inside `withoutAuditing()`**. That
is intentional — Sentinel refusing a move it would not have recorded would be governing the workflow
— but it means a suspended block is also an unguarded one. See
[State transitions](../03-capture/08-state-transitions.md).

`Sentinel::transaction()` is likewise a pass-through while paused: the callback runs, no header row
is written, and no correlation identifier is issued.

---

## The silent-absence failure mode

This is the part that matters.

A discarded or never-written entry consumes no sequence number. `sequence` stays dense and monotonic,
every `previous_hash` still points at the entry before it, and `verifyIntegrity()` reports the stream
intact. That is correct behaviour — the chain proves that *the entries present are the ones that were
written and that none of them was altered.* It was never able to prove that nothing else happened.

Which gives you the failure mode:

> ⚠️ **Warning.** An audit trail with a hole in it is byte-for-byte indistinguishable from the trail
> of a system where nothing happened. The chain cannot tell you which one you are looking at, and
> neither can any verification command. Absence is the one thing an append-only ledger cannot testify
> about.

A leaked `pause()` produces exactly that: a quiet stretch of trail, verifying clean, in which the
application was in fact writing to the database the whole time. Nothing logs it, nothing raises, and
the gap is invisible in every column.

Four things to do about it.

**1. Never leave the trail to infer the hole — state it.** `PendingEvent::record()` asks
`isRecording()`, so a marker written *inside* the suspension writes nothing. Bracket the block from
outside:

```php
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Facades\Sentinel;

Sentinel::event('audit.suspended')
    ->severity(Severity::Warning)
    ->metadata(['reason' => 'bulk price import', 'operator' => $user->getKey()])
    ->record();

$imported = Sentinel::withoutAuditing(fn (): int => $importer->run());

Sentinel::event('audit.resumed')
    ->severity(Severity::Warning)
    ->metadata(['reason' => 'bulk price import', 'rows' => $imported])
    ->record();
```

Both markers are ordinary entries: chained, hashed, signed if signing is on, and findable with
`Sentinel::audits()->whereEvent('audit.suspended')`. The hole now has a beginning, an end and a
stated reason, and a reader who finds a quiet stretch can tell a suspension from a quiet Tuesday.
See [Custom and authentication events](../03-capture/07-custom-and-authentication-events.md).

**2. Make the window the operation, not the request.** `withoutAuditing(fn () => $importer->run())`
suspends for one call. A `pause()` at the top of a controller suspends for everything that follows,
including the parts you did not think about.

**3. Assert the switch where it matters.** `Sentinel::isRecording()` is public and cheap. Put it in
the health check that your monitoring reads, and in the test that covers the operation you care
about:

```php
it('audits a price change', function (): void {
    expect(Sentinel::isRecording())->toBeTrue();

    $invoice->update(['total' => 990]);

    expect($invoice->latestAudit())->not->toBeNull();
});
```

**4. Treat `SENTINEL_ENABLED=false` as a deployment decision with a paper trail.** It belongs in the
environment configuration under review, not in an ad-hoc `.env` edit on a box — because from inside
the application it is indistinguishable from a system nobody is using. See
[Monitoring and troubleshooting](../09-operations/08-monitoring-and-troubleshooting.md).

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| Auditing stops halfway through a request and never comes back | An exception was thrown between `pause()` and `resume()`; nothing takes the flag down | Use `withoutAuditing()`, or put `resume()` in a `finally` |
| A nested block turns auditing back on for its caller | `resume()` clears the flag rather than restoring it | Use `withoutAuditing()`, which restores the previous state |
| `withoutAuditing()` seems to have no effect in a queue worker | The flag is on a `scoped` binding and is not part of the job payload; the worker resolves its own `Sentinel` | Suspend inside the job, not at dispatch |
| `Sentinel::event()` inside `withoutAuditing()` writes nothing | `PendingEvent::record()` asks `isRecording()` like every other capture path | Record the marker outside the closure |
| A restoration or a redaction wrote an entry although auditing was off | `Capture\Recorder` is not gated, and neither caller asks | Expected — those two entries are unconditional by design |
| An illegal state transition went through inside a suspended block | `ModelCapture::vet()` returns early when recording is off | Do the guarded write outside the suspension |
| `ConfigurationException: enabled … expected a boolean` | `SENTINEL_ENABLED` resolved to the string `"false"` or to null in a cached config | Cast it in `config/sentinel.php`, or clear the config cache |
| Verification reports a stream intact over a period you know is missing entries | The chain has no gap because the missing entries never took a sequence number | Verification cannot detect absence; rely on the bracketing markers |

---

## ✅ Best practices

✅ **Do** — suspend with `withoutAuditing()`, always, and let it return the value you need.

```php
$rows = Sentinel::withoutAuditing(fn (): int => $importer->run());
```

❌ **Don't** — use the bare pair. A throw in the middle leaves auditing off for the rest of the
request, silently, and the entries that should have been written are simply absent.

```php
Sentinel::pause();
$importer->run();     // throws
Sentinel::resume();   // never reached
```

---

✅ **Do** — bracket a suspension with two custom events, recorded outside the closure, so the hole is
itself in the trail.

```php
Sentinel::event('audit.suspended')->metadata(['reason' => 'schema backfill'])->record();
Sentinel::withoutAuditing(fn () => $backfill->run());
Sentinel::event('audit.resumed')->metadata(['reason' => 'schema backfill'])->record();
```

❌ **Don't** — record the marker inside the block. `record()` asks `isRecording()` first, so this
writes nothing at all and you are left believing you documented the gap.

```php
Sentinel::withoutAuditing(function () use ($backfill): void {
    Sentinel::event('audit.suspended')->record();   // no-op
    $backfill->run();
});
```

---

✅ **Do** — remove one class of entry with a filter registered once in a provider, when what you
want gone is "entries about X" rather than "everything during Y".

```php
Sentinel::filter(static fn (AuditData $audit): bool => $audit->subject_type !== SessionHeartbeat::class);
```

❌ **Don't** — wrap the noisy write in a suspension. You lose every other entry the same request
produced, including the ones you would have wanted.

```php
Sentinel::withoutAuditing(fn () => $heartbeat->touch());   // and everything else the request did
```

---

✅ **Do** — keep the suspension as narrow as the operation it is for.

```php
foreach ($rows as $row) {
    $order = Sentinel::withoutAuditing(fn (): Order => Order::query()->create($row));

    $order->update(['status' => 'imported']);   // this one is audited
}
```

❌ **Don't** — pause at the top of a controller or a job handler and hope the rest of the method is
uninteresting. Everything downstream — model writes, pivot changes, business transactions, custom
events — goes unrecorded too.

```php
public function handle(): void
{
    Sentinel::pause();   // governs the whole job, including code you did not write
    // ...
}
```

---

✅ **Do** — assert `Sentinel::isRecording()` in the health check and in the tests that cover audited
operations, so a leaked pause or a stray `SENTINEL_ENABLED=false` fails loudly somewhere.

```php
expect(Sentinel::isRecording())->toBeTrue();
```

❌ **Don't** — rely on `verifyIntegrity()` to notice. It proves the entries present are unaltered; a
period with no entries verifies exactly as clean as a period with all of them.

---

**See also:** [What a model declares](03-what-a-model-declares.md) · [Discarding entries](../05-pipeline-and-security/06-discarding-entries.md) · [Custom and authentication events](../03-capture/07-custom-and-authentication-events.md) · [State transitions](../03-capture/08-state-transitions.md) · [Restoring state](../06-reading/08-restoring-state.md) · [Redaction and tombstones](../08-lifecycle/04-redaction-and-tombstones.md) · [Verification](../07-integrity/06-verification.md) · [The Sentinel facade](../99-reference/01-facade-api.md) · [Configuration](../99-reference/02-configuration.md) · [Anti-patterns](../13-best-practices/02-anti-patterns.md)
