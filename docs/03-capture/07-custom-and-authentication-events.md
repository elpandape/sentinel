# 📥 Custom and authentication events

> How to record a fact that no model change describes — an approval, a dispatch, a sign-in — and how
> to turn Laravel's authentication events into entries of the same trail.

**On this page:** [When a fact is not a change](#when-a-fact-is-not-a-change) · [The builder](#the-builder) · [The 64-character name cap](#the-64-character-name-cap) · [Naming events](#naming-events) · [Authentication events](#authentication-events) · [Reading them back](#reading-them-back) · [⚠️ Pitfalls](#️-pitfalls) · [✅ Best practices](#-best-practices)

---

## When a fact is not a change

Most of what Sentinel records arrives because Eloquent announced it: a model was created, updated,
deleted, a pivot moved. Some of what happens in an application is not a column moving. An invoice is
approved. A shipment is dispatched. A decision is taken and recorded. Nothing in the database
necessarily changes, and if something does, the change is the consequence rather than the fact.

`Sentinel::event()` states that fact outright. It settles through the same pipeline and the same
ledger as an update: same context resolution, same redaction, same encryption, same policies, same
stream, same `sequence`, `previous_hash` and `hash`. Nothing about the chain is different because a
human named the event instead of Eloquent.

There are three shapes and they are not interchangeable:

| You want to record | Use | `audit_type` | Notes |
|---|---|---|---|
| A column moved on a record | The `Auditable` trait — nothing to call | `model` | See [What gets audited](01-what-gets-audited.md) |
| A record moved from one state to the next | `Sentinel::transition()` or `$auditTransitions` | `transition` | Readable as a lifeline; see [State transitions](08-state-transitions.md) |
| Something happened that no column describes | `Sentinel::event()` | `custom` | The subject is optional |

> 📌 **Note.** A custom event is not a cheaper entry. It costs the same write, takes the same
> sequence number in the same stream, and is verified by the same walk. If you would not want it in
> the tamper-evident chain, do not put it there — use your application's log.

---

## The builder

`Sentinel::event(string $name)` returns a `ElPandaPe\Sentinel\Capture\PendingEvent`. Every method on
it is a chainable modifier; `record()` is the terminal and the only one that writes.

```php
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Facades\Sentinel;

Sentinel::event('invoice.approved')
    ->subject($invoice)
    ->actor($approver)
    ->severity(Severity::Notice)
    ->tags(['billing', 'approval'])
    ->metadata(['reason' => 'Within the delegated limit'])
    ->record();
```

| Method | Signature | Effect when omitted |
|---|---|---|
| `subject()` | `subject(Model $subject): self` | The entry has no subject at all — `subject_type` and `subject_id` stay null |
| `actor()` | `actor(object\|string $actor, int\|string\|null $id = null): self` | The context engine resolves the actor from the guard |
| `severity()` | `severity(Severity $severity): self` | `severity.events[<name>]`, then `severity.default` |
| `tags()` | `tags(array $tags): self` | The model's declared labels plus `tags.default`, if there are any |
| `metadata()` | `metadata(array $metadata): self` | `metadata` is null |
| `record()` | `record(): void` | **Nothing is written.** No exception, no warning |

### Nothing happens until `record()`

The builder holds state and writes on the terminal. A chain that is built and never terminated
produces no entry and no diagnostic:

```php
// Writes nothing. This is the single most common mistake with this API.
Sentinel::event('invoice.approved')->subject($invoice)->tags(['billing']);
```

`record()` returns `void`, not the `Audit`. That is deliberate and not an oversight: with
`transactions.after_commit` on — the shipped default — the write is deferred to the commit of the
transaction that produced it, so at the moment `record()` returns the entry does not exist yet. A
nullable return would have to mean both "the pipeline discarded it" and "it has not been written
yet", which are not the same answer. To react to the settled entry, listen for `Audited` or
`AuditCreated` — see [Events and listeners](../09-operations/04-events-and-listeners.md).

`record()` is not idempotent and the builder is not consumed. Calling it twice builds two
`AuditData` objects and writes two entries.

### Where each part ends up

```php
$entry = Sentinel::audits()->whereType('custom')->latest()->take(1)->get()->first();

$entry->audit_type;   // 'custom'
$entry->event;        // 'invoice.approved' — the name you passed, verbatim
$entry->subject_type; // the morph class of the subject, or null
$entry->before;       // null — a custom event compares nothing
$entry->after;        // null
$entry->metadata;     // what you attached, after masking
```

A custom event carries no `before`, no `after` and no `changes`: nothing was compared. That also
means `FilterUnchanged` — the pipeline stage that drops an update where nothing moved — never
discards one, because it only tests entries whose comparison actually ran.

### The actor, and what naming one costs

`->actor()` accepts a model, or a type string with a key for an actor that is not a model at all:

```php
Sentinel::event('invoice.approved')->actor($approver)->record();       // a model
Sentinel::event('ledger.closed')->actor('system', 'month-end')->record(); // a type and a key
```

Naming an actor also **clears the impersonator** the context engine resolved. Whoever the session
was standing in for was standing in for the resolved actor, not for the one you have just named, and
an entry pairing the two would claim a delegation that never happened. Say nothing and the resolved
actor keeps its resolved impersonator. See
[Actor and impersonation](../04-context/03-actor-and-impersonation.md).

> 📌 **Note.** A policy registered with `Sentinel::filter()` and an `Auditing` listener both see the
> actor you passed to `->actor()`: `ResolveContext` applies it inside the pipeline, before either of
> them runs. Before `v1.0.0-rc.2` they saw the resolved actor instead.

### Redaction still applies

The metadata of a custom event goes through the same two protection stages as a model's `before` and
`after`. Two lists are consulted, and which one applies depends on whether the entry has a subject:

| Entry | Protected by |
|---|---|
| With `->subject($model)` | `security.redaction.fields` **plus** the model's `$auditRedact` / `$auditHash` / `$auditEncrypt` |
| Without a subject | `security.redaction.fields` only — there is no model to ask |

`Security\Fields::protect()` matches by key name at any depth, in `before`, `after`, `metadata`,
`context`, `changes` and `criteria` alike. A key called `email` inside your metadata is masked if
`email` is protected — which is what you want for a value, and a trap for a map keyed by field name.
See [Protecting sensitive data](../05-pipeline-and-security/02-protecting-sensitive-data.md).

---

## The 64-character name cap

`PendingEvent::MAX_NAME_LENGTH` is 64 characters, and the name is checked in the constructor — at the
`Sentinel::event()` call, before any modifier and long before the write. A name with nothing in it
is refused at the same place: an entry whose event says nothing happened is not something a trail
records.

```php
use ElPandaPe\Sentinel\Exceptions\ConfigurationException;

Sentinel::event(str_repeat('a', 65));  // throws ConfigurationException::eventTooLong()
Sentinel::event(str_repeat('a', 64));  // fine — the full width of the column
Sentinel::event('');                   // throws ConfigurationException::eventEmpty()
Sentinel::event('   ');                // the same: nothing but spaces is nothing
Sentinel::event('invoice approved');   // fine — spaces inside a name that says something stay
```

The check is at the call rather than at the ledger because the event name is inside the **canonical
payload the hash covers**. An engine that truncated it would seal a hash over the full name and then
store a shorter one, leaving an entry that can never reproduce its own hash again — a permanent,
silent integrity failure for a value nobody thought was load-bearing. Raising at the call site turns
that into an exception the developer can read.

> 🐘 **Engine.** SQLite ignores VARCHAR widths, so an over-long name reaches the column unharmed
> there; MySQL outside strict mode truncates it; PostgreSQL rejects the statement. The guard in PHP
> is what makes the three engines behave the same way.

> 📌 **Note.** `event`, like `sequence`, `hash` and `previous_hash`, is part of the canonical payload.
> Anything that would change how it is serialised bumps `payload_version` and ships a
> backwards-compatibility test — see [Canonicalization](../07-integrity/03-canonicalization.md).

---

## Naming events

Sentinel does not reserve or validate event names. `Sentinel::event('updated')` is legal, and it
writes an entry whose `event` column is indistinguishable from a model update's. Only `audit_type`
tells them apart, so `whereType('custom')` is the reliable filter and `whereEvent('updated')` is not.

The convention that keeps this readable is a dotted, domain-first name: `invoice.approved`,
`shipment.dispatched`, `contract.countersigned`.

### Translating a name

The presenter renders an unknown event name verbatim, which is correct — the package cannot translate
names it has never seen. To give one a sentence, add it under `events` in your published language
files. A dotted name resolves as a **nested** key, because that is how Laravel resolves dotted
translation keys:

```php
// resources/lang/vendor/sentinel/en/sentinel.php
'events' => [
    'invoice' => [
        'approved' => 'approved',   // resolves for 'invoice.approved'
    ],
],

// This never matches. Nothing warns you.
'events' => [
    'invoice.approved' => 'approved',
],
```

### Severity by name

`severity.events` is keyed by whatever lands in the `event` column, so your own names work with no
enum case:

```php
// config/sentinel.php
'severity' => [
    'default' => 'info',
    'events' => [
        'invoice.approved' => 'notice',
        'contract.countersigned' => 'warning',
    ],
],
```

`Support\Config::defaultSeverity()` throws `ConfigurationException::expected()` for a non-string
value and `ConfigurationException::unknown()` for a string that is not a `Severity` case. Both are
raised when the entry is captured, not at boot.

### Correlating with an operation

A custom event captured inside `Sentinel::transaction()` takes that operation's `transaction_id` like
any other entry, and counts towards its `audits_count`:

```php
Sentinel::transaction('invoice-payment', function () use ($invoice): void {
    $invoice->update(['status' => 'paid']);
    Sentinel::event('invoice.approved')->subject($invoice)->record();
});
```

See [Business transactions](06-business-transactions.md).

---

## Authentication events

Sentinel ships `ElPandaPe\Sentinel\Capture\AuthenticationSubscriber`, which turns Laravel's
authentication events into entries with `audit_type = 'auth'`. It is **opt-in and stays opt-in**:
until the application registers it, nothing about authentication is written. An installation that
upgrades the package does not silently start recording who logs in.

### Registering it

```php
// app/Providers/AppServiceProvider.php
use ElPandaPe\Sentinel\Capture\AuthenticationSubscriber;
use Illuminate\Support\Facades\Event;

public function boot(): void
{
    Event::subscribe(AuthenticationSubscriber::class);
}
```

That is the whole integration. You write no listener, register no handler and configure no mapping —
the subscriber declares its own event-to-handler map and Laravel's dispatcher resolves the class from
the container. Pass the class name and nothing else: its constructor takes internal collaborators
(`Capture\Recorder`, `Support\Config`), so never construct it or call its handler methods yourself.

### The five events

| Framework event | `event` column | Severity | `metadata` | Actor / subject | Fired by |
|---|---|---|---|---|---|
| `Illuminate\Auth\Events\Login` | `login` | `info` | `['guard' => …]` | the user, in both | `SessionGuard` |
| `Illuminate\Auth\Events\Logout` | `logout` | `info` | `['guard' => …]` | the user, in both | `SessionGuard` |
| `Illuminate\Auth\Events\Failed` | `failed` | `warning` | `['guard' => …]` | the user if the provider found one, otherwise neither | `SessionGuard` |
| `Illuminate\Auth\Events\Lockout` | `lockout` | `critical` | `null` | neither | **not the framework** |
| `Illuminate\Auth\Events\PasswordReset` | `password_reset` | `notice` | `null` | the user, in both | **not the framework** |

The person is recorded as **both the actor and the subject**: an authentication event is something
someone did, and the thing it happened to is that same someone. So both `by($user)` and `for($user)`
find it.

### Two of the five are not fired by Laravel

`Lockout` and `PasswordReset` are event classes the framework defines and never constructs. In
`laravel/framework` 13.30.1 there is no `new Lockout(` and no `new PasswordReset(` anywhere in
`src/` — `SessionGuard` fires `Login`, `Logout` and `Failed`, and that is all. The other two are
dispatched by application-level code: a starter kit such as Fortify or Breeze, the login throttler in
your own controller, or the password broker callback you wrote yourself.

**Register the subscriber on a bare install and you get three of the five authentication events, not
five.** All five write one `audit_type` — `auth` — so this is a count of events, never of entry
kinds. If throttling and password resets matter to your trail, check that whatever handles them in
your application actually dispatches those events. Nothing in Sentinel can tell you they are missing:
an event that is never fired looks exactly like an event that never happened.

### Credentials are never captured

`Illuminate\Auth\Events\Failed` carries the credentials the attempt was made with, marked
`#[\SensitiveParameter]` by the framework. **Sentinel never reads them.** The handler
`AuthenticationSubscriber::failed()` takes `$event->user` and `$event->guard` from the event and
nothing else; `$event->credentials` is not touched, not passed to the recorder, and therefore never
present in any container the pipeline could mask.

> 🔒 **Security.** This is enforced by not reading the value, not by redacting it afterwards. A
> password that was never copied into an `AuditData` cannot leak through a listener on `Auditing`,
> through a queued payload under `mode = queue`, or through a buffer under `mode = buffered` — all of
> which see the entry after the pipeline but before the ledger. Redaction protects a value the
> engine already holds; this is the stronger guarantee that it never held it.

A failed attempt that named nobody the provider could find — the common case for a wrong email —
records no actor and no subject. It is still worth keeping: the execution context stamps the IP, the
user agent, the route and the request id, so the entry says an attempt happened, from where, and
when. See [Execution context](../04-context/01-execution-context.md).

### What the subscriber does not record

- **The `remember` flag** on `Login`. The event carries it; the entry does not.
- **Any other authentication event.** `Attempting`, `Authenticated`, `Validated`, `Registered`,
  `Verified`, `CurrentDeviceLogout`, `OtherDeviceLogout` and `PasswordResetLinkSent` all exist in
  `Illuminate\Auth\Events` and none of them is subscribed. If you want one of those, write a listener
  that calls `Sentinel::event()`.
- **Anything at all while recording is off.** Every handler returns early when
  `Sentinel::isRecording()` is false — `enabled = false`, or inside `Sentinel::withoutAuditing()`.
  See [Turning auditing off](../02-getting-started/04-turning-auditing-off.md).

### The severity configuration

The shipped defaults are in `config/sentinel.php`. `login` and `logout` are deliberately absent:
getting in is routine, being refused or shut out is not.

```php
// config/sentinel.php
'severity' => [
    'default' => 'info',            // login and logout land here
    'events' => [
        'deleted' => 'notice',
        'force_deleted' => 'warning',
        'rekeyed' => 'notice',
        'failed' => 'warning',
        'lockout' => 'critical',
        'password_reset' => 'notice',
    ],
],
```

Raise `failed` to `critical` if a refused attempt should page someone; add `login` if a successful
sign-in is not routine in your system.

---

## Reading them back

Both `audit_type` values on this page — `custom` and `auth` — are ordinary rows in the trail and
answer the whole [Query API](../06-reading/01-the-query-api.md).

```php
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Facades\Sentinel;

// Every custom event, whatever it was called — bounded, because the trail is not.
Sentinel::audits()->whereType('custom')->take(100)->get();

// One record's approvals, newest first.
Sentinel::audits()->for($invoice)->whereEvent('invoice.approved')->latest()->take(20)->get();

// Everything that happened to a person's account, from either column.
Sentinel::audits()->whereType('auth')->by($user)->latest()->take(50)->get();

// Refused attempts in the last hour.
Sentinel::audits()
    ->whereEvent('failed')
    ->whereSeverity(Severity::Warning)
    ->between(now()->subHour(), now())
    ->take(100)
    ->get();
```

`get()` refuses rather than truncates: a read with no `take()` throws `QueryException::unbounded()`
once **more than** 500 entries match, and a filter matching exactly 500 is answered whole. Use
`take()` for a deliberate prefix and `paginate()` to walk the whole thing — the probe and the
reasoning behind the refusal are in
[Order, paging and walking the trail](../06-reading/03-order-paging-and-walking.md#how-much-comes-back).

> 💡 **Tip.** Prefer `whereType('custom')` over `whereEvent(...)` when you mean "a fact the
> application stated". The `event` column is a free string shared with model events; `audit_type` is
> the indexed column that separates the kinds.

> ⚠️ **Warning.** `Filter::Type` is not one of the nine filters a ledger driver is assumed to answer.
> A custom driver that does not declare it through `Contracts\DeclaresFilters` refuses
> `whereType()` with `LedgerException::cannotFilterBy()`. The shipped database ledger declares it.
> See [The Ledger contract](../11-extending/01-the-ledger-contract.md).

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| No entry appears and no error is raised | The chain was built but `record()` was never called | End every `Sentinel::event(...)` chain with `->record()` |
| `record()` returns `null`, so you assume the entry was discarded | It returns `void`; there is nothing to inspect | Listen for `Audited` (capture side) or `AuditCreated` (ledger side) |
| Two entries for one fact | `record()` was called twice on the same builder; it is not idempotent and the builder is not consumed | Build a fresh chain per fact |
| `ConfigurationException` at `Sentinel::event()`, before any modifier | The name is longer than 64 characters, or has nothing in it | Shorten the name — it is inside the hashed payload and cannot be truncated — or give the event a name |
| `QueryException` from `->subject()` | The model has no key yet (never saved) | Save the model first, or leave the entry subjectless |
| `Sentinel::filter()` policy decides on the wrong person | You are on a release before `v1.0.0-rc.2`, where `->actor()` was reapplied after the pipeline and a policy saw the resolved actor | Upgrade: from that candidate on a policy sees the actor you named |
| `impersonator_type` is null on an entry you expected it on | `->actor()` clears the resolved impersonator by design | Omit `->actor()` and let the context engine resolve both |
| Your translation for `invoice.approved` never renders | A dotted name needs a nested key (`events.invoice.approved`), not a flat `'invoice.approved'` key | Nest it two levels under `events` |
| `whereEvent('updated')` returns custom entries too | Nothing stops an application naming its event `updated`; only `audit_type` separates the kinds | Add `whereType('model')` or `whereType('custom')` |
| Metadata that should be masked is written in the clear | The entry has no subject, so only `security.redaction.fields` applies — the model's `$auditRedact` was never consulted | Pass `->subject($model)`, or add the field to the global list |
| A metadata key called `email` disappears on a model that redacts `email` | `Security\Fields` matches by key name at any depth, so the **key** is masked too | Use fixed keys or lists of pairs, never a map keyed by a model field name |
| No `auth` entries at all | The subscriber was never registered; it is opt-in | `Event::subscribe(AuthenticationSubscriber::class)` in a service provider |
| `lockout` and `password_reset` entries never appear | `laravel/framework` never constructs those two events | Check that your login throttler / password broker dispatches them |
| An auth entry has no actor although a user was involved | `Context\Identity::id()` returns null when `getAuthIdentifier()` is not an int or a string | Make the authenticatable return a scalar key |
| `AuditEvent::Custom` matches nothing you wrote | `audit_type` is `custom`; the `event` column holds your name. Nothing in the package writes the literal string `custom` into `event` | Query by `whereType('custom')` |

---

## ✅ Best practices

✅ **Do** — terminate every chain with `record()`, on the same statement where you built it. A
builder assigned to a variable and terminated later is a fact waiting for an early `return` to
swallow it.

```php
Sentinel::event('invoice.approved')
    ->subject($invoice)
    ->metadata(['limit' => $limit])
    ->record();
```

❌ **Don't** — split the chain across a branch. The entry is written only on the path that reaches
the terminal, and nothing tells you about the path that did not.

```php
$pending = Sentinel::event('invoice.approved')->subject($invoice);

if ($approver->isSenior()) {
    $pending->severity(Severity::Notice)->record();
}
// The junior path recorded nothing at all.
```

✅ **Do** — give custom events a dotted, domain-first name and read them back by `audit_type`. The
name says which fact it is, and the type says it is a fact the application stated.

```php
Sentinel::event('invoice.approved')->subject($invoice)->record();

Sentinel::audits()->whereType('custom')->whereEvent('invoice.approved')->take(50)->get();
```

❌ **Don't** — reuse a model event's name and then query it by name. `whereEvent('updated')` will
return both your fact and every Eloquent update in the trail.

```php
Sentinel::event('updated')->subject($invoice)->record();

Sentinel::audits()->whereEvent('updated')->take(50)->get();  // two kinds of fact, mixed
```

✅ **Do** — pass a subject when the fact is about a record, so the model's own protection
declarations apply to the entry's metadata.

```php
// App\Models\Invoice declares: protected array $auditRedact = ['contact_email'];
Sentinel::event('invoice.approved')
    ->subject($invoice)
    ->metadata(['contact_email' => $invoice->contact_email])   // masked on the way in
    ->record();
```

❌ **Don't** — put a value from a protected model into a subjectless entry and assume it is masked.
With no subject, `Support\PolicyRegistry::for(null)` hands back the empty policy and only
`security.redaction.fields` is consulted.

```php
Sentinel::event('invoice.approved')
    ->metadata(['contact_email' => $invoice->contact_email])   // written as given
    ->record();
```

✅ **Do** — register the authentication subscriber deliberately, in one place, and verify in your own
application that the events you care about are actually dispatched.

```php
use ElPandaPe\Sentinel\Capture\AuthenticationSubscriber;
use Illuminate\Support\Facades\Event;

Event::subscribe(AuthenticationSubscriber::class);
```

❌ **Don't** — construct the subscriber or call its handlers yourself. Its constructor takes internal
collaborators, and calling a handler directly bypasses nothing useful while coupling you to a shape
that is not part of the public surface.

```php
(new AuthenticationSubscriber($sentinel, $recorder, $config))->login($event);
```

✅ **Do** — build an alerting rule on the severities the package already assigns, so a lockout is
loud without any code of yours.

```php
Sentinel::audits()
    ->whereType('auth')
    ->whereSeverity(Severity::Critical)
    ->between(now()->subDay(), now())
    ->take(200)
    ->get();
```

❌ **Don't** — write your own "failed login" recorder that captures the attempted credentials so you
can "redact them later". The subscriber's guarantee is that they are never read; a second path that
reads them gives that guarantee away for every entry in the trail.

```php
Sentinel::event('failed')->metadata(['credentials' => $request->all()])->record();
```

---

**See also:** [What gets audited](01-what-gets-audited.md) · [Business transactions](06-business-transactions.md) · [State transitions](08-state-transitions.md) · [Actor and impersonation](../04-context/03-actor-and-impersonation.md) · [The write pipeline](../05-pipeline-and-security/01-the-write-pipeline.md) · [Protecting sensitive data](../05-pipeline-and-security/02-protecting-sensitive-data.md) · [The Query API](../06-reading/01-the-query-api.md) · [Events and listeners](../09-operations/04-events-and-listeners.md) · [Configuration](../99-reference/02-configuration.md) · [Enums](../99-reference/04-enums.md) · [Exceptions](../99-reference/06-exceptions.md)
