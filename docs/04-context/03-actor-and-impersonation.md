# 🧭 Actor and impersonation

> Who did it, and on whose behalf. How the two pairs of columns are filled, what shape of key fits
> in them, and what an entry says when the thing that acted was not a person.

**On this page:** [The four columns](#the-four-columns) · [How the actor is resolved](#how-the-actor-is-resolved) · [Why the id is always a string](#why-the-id-is-always-a-string) · [Impersonation](#impersonation) · [Wiring a different impersonation package](#wiring-a-different-impersonation-package) · [Naming the actor yourself](#naming-the-actor-yourself) · [When the actor is a system](#when-the-actor-is-a-system) · [Reading it back](#reading-it-back) · [⚠️ Pitfalls](#️-pitfalls) · [✅ Best practices](#-best-practices)

---

## The four columns

Attribution is four columns, filled by two resolvers, and they answer two different questions.

| Column | Schema | Filled by | Indexed | Query filter |
|---|---|---|---|---|
| `actor_type` | `string` (default length) | `Context\Resolvers\ActorResolver` | `(actor_type, actor_id, id)` | `by()` |
| `actor_id` | `string(64)` | `ActorResolver` | same index | `by()` |
| `impersonator_type` | `string` (default length) | `Context\Resolvers\ImpersonatorResolver` | **no** | **none** |
| `impersonator_id` | `string(64)` | `ImpersonatorResolver` | **no** | **none** |

All four are nullable, all four are among the twenty-seven names in
`Integrity\CanonicalPayload::COLUMNS`, and therefore all four are **inside the hash**. Changing who
an entry says acted is not an update — it breaks the entry's own hash and every link after it. See
[The hash chain](../07-integrity/01-the-hash-chain.md).

The asymmetry in that table is deliberate and worth planning around: the actor pair is indexed and
has a published filter, the impersonator pair has neither. You read the impersonator off the entry
or through `Audit::impersonator()`, not with a query.

> 📌 **Note.** `actor` and `impersonator` are separate concerns from `subject`. The subject is the
> record something happened *to*; the actor is who made it happen. An authentication entry is the
> one place they coincide — `Capture\AuthenticationSubscriber` writes the person as both, because a
> login is something someone did and the thing it happened to is that same someone. See
> [Custom and authentication events](../03-capture/07-custom-and-authentication-events.md).

---

## How the actor is resolved

`ActorResolver` does one thing: it asks an auth guard for its user.

```php
// src/Context/Resolvers/ActorResolver.php, in prose
$user = $this->guard()->user();      // the guard named by resolvers.actor.guard, or the default
if ($user === null) return [];       // nobody authenticated → both columns stay null
$id = Identity::id($user);           // string|int key → string; anything else → null
if ($id === null) return [];         // an unusable key → BOTH columns stay null, not just the id
return ['actor_type' => Identity::type($user), 'actor_id' => $id];
```

`Context\Identity` — marked `@internal`, so do not call it yourself — is the two lines that decide
the shape:

| The authenticated thing | `actor_type` becomes |
|---|---|
| An Eloquent model | `$user->getMorphClass()` — the morph-map alias if one is registered, the fully qualified class name otherwise |
| Any other `Authenticatable` (a token guard's user object, a value object) | `$user::class` |

### Choosing the guard

```php
// config/sentinel.php
'resolvers' => [
    'actor' => ['class' => null, 'guard' => 'admin'],
],
```

`null` means the application's default guard. A name the application never defined is caught at
**capture time, not at boot** — `ActorResolver::guard()` wraps the framework's
`InvalidArgumentException` and rethrows it as a `Exceptions\ConfigurationException` naming the key:

```
Sentinel configuration key [sentinel.resolvers.actor.guard] has unknown value [ghost].
Accepted: Auth guard [ghost] is not defined.
```

That means a typo in the guard name does not break `php artisan config:cache`; it breaks the first
audited write after the deploy. Under `on_write_failure => throw` that surfaces as a 500 on a real
request. Exercise one audited write in a smoke test after changing this key.

> ⚠️ **Warning.** Only **one** guard is consulted. If your application authenticates staff on
> `admin` and customers on `web`, `resolvers.actor.guard` picks one of them and every entry written
> by the other is attributed to nobody. The fix is a resolver of your own that tries the guards in
> order — see [Writing your own resolver](07-writing-your-own-resolver.md).

---

## Why the id is always a string

`actor_id` is `string(64)`, and `Identity::id()` casts before it hands the value over:

```php
$id = $user->getAuthIdentifier();

return is_string($id) || is_int($id) ? (string) $id : null;
```

That single cast is what lets three common key shapes share one column:

| Key on the user model | Stored in `actor_id` | Fits in 64 chars |
|---|---|---|
| Auto-increment `int` (`42`) | `'42'` | yes |
| UUID v4 (36 chars) | the string, unchanged | yes |
| ULID (26 chars) | the string, unchanged | yes |
| A value object, a `Stringable`, an array | nothing — the resolver returns `[]` | — |

The last row is the one that surprises people. A key that is neither a string nor an int does not
produce a truncated or stringified column: `Identity::id()` returns null, `ActorResolver` returns an
empty array, and **both** `actor_type` and `actor_id` come out null. The entry says nobody acted,
and nothing is logged. If your user model's key is a wrapped identifier, cast it in a replacement
resolver.

`ContextEngine` enforces the same rule one level up. `ContextEngine::column()` writes a resolved
value only when `is_string()` is true and writes null otherwise, so a custom resolver returning
`['actor_id' => $user->id]` with an integer id produces a null column in silence. Cast in the
resolver: `(string) $user->getKey()`.

> 🐘 **Engine.** Nothing engine-specific here — `actor_id` is a plain `varchar(64)` on all three
> supported engines, and the composite index `(actor_type, actor_id, id)` is created identically.
> A key longer than 64 characters is a schema problem, not a Sentinel one; it will be refused or
> truncated by the engine on write.

---

## Impersonation

**Laravel has no impersonation.** There is no impersonation feature anywhere in
`illuminate/*` — no event, no guard method, no session key. Every impersonation feature in the
ecosystem is a package or a few lines in an application, and they agree on one thing only: the
original user's identifier goes into the session.

So Sentinel resolves the impersonator from a session key, and the key is configurable:

```php
// config/sentinel.php
'resolvers' => [
    'impersonator' => ['class' => null, 'session_key' => 'impersonated_by'],
],
```

`impersonated_by` is the default because it is the key the common Laravel impersonation package
writes. It is a **convention Sentinel adopted**, not a standard, and if your application stores the
original user somewhere else, this key is where you say so.

### What the resolver actually checks

`ImpersonatorResolver::resolve()` walks six gates, and returns an empty array at the first one that
fails:

| # | Gate | Returns `[]` when |
|---|---|---|
| 1 | `Runtime::request()` holds a request | There is no HTTP request — a console run, a queue worker, the scheduler |
| 2 | `$request->hasSession()` | The route is stateless — most API routes |
| 3 | The session key holds a value | Nobody is impersonating |
| 4 | That value is a `string` or an `int` | The key holds an array, an object or a model |
| 5 | Somebody is authenticated on the actor guard | The session says who is impersonating but the guard says nobody is logged in |
| 6 | The session id differs from the actor's own id | They are equal — that is the same session, not a delegation |

Two of those are invariants, each fixed by a test in `tests/Context/ImpersonatorResolverTest.php`:

- **No impersonation means both columns are null**, never a copy of the actor. An entry never claims
  a delegation that did not happen.
- **An impersonator id equal to the actor's own resolves to nothing** — *"never fills the
  impersonator with the actor itself"*.

### The shape of `impersonator_type`, and its one real limitation

Read this carefully, because it is the sharpest edge on the page:

```php
return [
    'impersonator_type' => Identity::type($actor),   // the ACTOR's class
    'impersonator_id'   => (string) $id,             // the impersonator's key, from the session
];
```

`impersonator_type` is the class of the **person being impersonated**, not the class of the
impersonator. The session carries an identifier and nothing else, and
`Illuminate\Contracts\Auth\Guard` exposes no `getProvider()`, so the resolver has no way to hydrate
that identifier into a model and ask what class it is. It assumes a person impersonates a person and
records the class it can see.

That assumption is correct for the ordinary case (an admin `User` impersonating another `User`) and
wrong for one specific setup: an `Admin` model impersonating a `User` model. There the entry would
say `impersonator_type = 'user'` with an admin's key, and `Audit::impersonator()` would resolve to
the wrong record — or to nothing.

> ⚠️ **Warning.** If two different model classes can impersonate each other in your application, the
> shipped resolver will mislabel it. Replace it (next section). This is not a bug you can configure
> around with `session_key`.

---

## Wiring a different impersonation package

Two cases, and only one of them needs code.

### Same convention, different key

```php
// config/sentinel.php
'resolvers' => [
    'impersonator' => ['session_key' => 'original_user'],
],
```

An empty string here raises `ConfigurationException::expected('resolvers.impersonator.session_key',
'a non-empty string or null', …)` at the first capture.

### A different convention entirely

Replace the resolver. It is built through the container, so it may take constructor dependencies.

```php
namespace App\Sentinel;

use ElPandaPe\Sentinel\Contracts\Resolver;
use Illuminate\Contracts\Auth\Factory;
use Illuminate\Database\Eloquent\Model;

final readonly class AdminImpersonatorResolver implements Resolver
{
    public function __construct(private Factory $auth) {}

    /**
     * @return array<string, mixed>
     */
    public function resolve(): array
    {
        $admin = $this->auth->guard('admin')->user();
        $actor = $this->auth->guard('web')->user();

        if (! $admin instanceof Model || ! $actor instanceof Model) {
            return [];
        }

        if ($admin->getMorphClass() === $actor->getMorphClass()
            && (string) $admin->getKey() === (string) $actor->getKey()) {
            return [];
        }

        return [
            'impersonator_type' => $admin->getMorphClass(),
            'impersonator_id' => (string) $admin->getKey(),
        ];
    }
}
```

```php
// config/sentinel.php
'resolvers' => [
    'impersonator' => ['class' => App\Sentinel\AdminImpersonatorResolver::class],
],
```

Keep both invariants when you write one: no impersonation means an empty array, and an impersonator
who *is* the actor means an empty array. Every promoted value must already be a string — the engine
drops anything else without a word.

> 📌 **Note.** `ImpersonatorResolver` runs on **every capture**, not once per scope. It is one of the
> five in `ContextEngine::RESOLVERS` that are not memoised, precisely because an impersonation can
> start and stop inside one request. A replacement of yours will be called just as often, so keep it
> to reading the session and the guard — no queries.

---

## Naming the actor yourself

Two capture surfaces let you state the actor outright, for the case where nobody is authenticated or
where the authenticated user is not the one who decided:

```php
use App\Models\User;
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Facades\Sentinel;

Sentinel::event('invoice.approved')
    ->actor($approver)                  // a model
    ->subject($invoice)
    ->severity(Severity::Notice)
    ->record();

Sentinel::transition($invoice, 'pending', 'approved')
    ->actor(User::class, 91)            // or a recorded type and a key
    ->reason('Credit check cleared')
    ->record();
```

Both go through `Support\Reference::to()`, which accepts either shape and refuses the two that
cannot be recorded honestly:

| Argument | Result |
|---|---|
| A saved Eloquent model | `type = getMorphClass()`, `id = (string) getKey()` |
| A model with no key yet | `QueryException` — *"Cannot query for a … that has no key yet: no entry can reference it."* |
| A string type **and** a key | Taken verbatim: `actor('system', 'cron')` |
| A string type with no key | `QueryException` — *"Querying by the recorded type … needs the key it was recorded with as the second argument."* |
| Any other object | `QueryException` — *"Cannot query for …: pass an Eloquent model, or the type and key the entry recorded."* |

### What happens to the entry, in order

1. The capture builds the `AuditData` and hands the named actor to `Capture\Recorder::record()`.
2. The **pipeline runs**, and `Pipeline\Stages\ResolveContext` overwrites `actor_type`/`actor_id`
   with whatever the resolvers found — because the engine assigns every promoted column on every
   pass, deliberately.
3. `Recorder::attribute()` then writes the named actor back **after** the pipeline, and clears
   `impersonator_type` and `impersonator_id` at the same time.

Step 3 has two consequences you will meet:

- **A policy sees the resolved actor, not the named one.** `EnforcePolicies` is the last pipeline
  stage, and the swap happens after it. A policy that decides on the basis of who acted is deciding
  on the basis of the authenticated user. See
  [Discarding entries](../05-pipeline-and-security/06-discarding-entries.md).
- **The impersonator is cleared, always.** Whoever the session resolved was standing in for the
  actor the engine resolved, not for the one you have just named. Pairing them would state a
  delegation that never happened. This is fixed by the test *"drops the resolved impersonator when
  the caller names the actor"*.

There is **no** `->actor()` on a model change. An `$invoice->update(...)` is attributed by the
resolvers or not at all; if you need attribution there in a background runtime, replace
`ActorResolver` — see [Queues, commands and schedulers](05-queues-commands-and-schedulers.md).

---

## When the actor is a system

An entry with `actor_type = null` and `actor_id = null` is legal and sometimes correct — an
authentication failure that named nobody, a webhook, a cascade. But an entire nightly run attributed
to nobody is not a fact, it is a gap. `Reference::to()` accepts a type and a key that belong to no
model precisely so a non-person can be named:

```php
use ElPandaPe\Sentinel\Facades\Sentinel;

Sentinel::event('invoices.closed')
    ->actor('system', 'invoices:close')       // no model needed, and none is looked up
    ->metadata(['period' => '2026-08'])
    ->record();
```

Both columns are written verbatim: `actor_type = 'system'`, `actor_id = 'invoices:close'`. Fixed by
the test *"takes an actor by type and key, for one that is no longer a model"*.

Choose the two strings on purpose, because they are what a query and a report will use:

| Convention | Reads as | Query |
|---|---|---|
| `actor('system', 'invoices:close')` | the command that did it | `by('system', 'invoices:close')` |
| `actor('system', 'scheduler')` | anything the scheduler did | `by('system', 'scheduler')` |
| `actor('webhook', 'stripe')` | an inbound integration | `by('webhook', 'stripe')` |

> ⚠️ **Warning.** `$audit->actor` is a `MorphTo`. Reading it for a synthetic type resolves the type
> through Laravel's morph map, and with no entry for `'system'` the relation tries to instantiate a
> class called `system` and fails with a PHP `Error`. Either never touch `->actor` on those entries,
> or register the alias:
> ```php
> use Illuminate\Database\Eloquent\Relations\Relation;
>
> // AppServiceProvider::boot()
> Relation::enforceMorphMap(['system' => App\Models\SystemActor::class]);
> ```
> The columns themselves, `by()`, `toArray()` and `Presentation\AuditPresenter` are all unaffected —
> they read the strings, not the relation.

Two commands make the point that a nameless actor is sometimes refused outright:

- `php artisan sentinel:redact --actor=…` requires it. A console process resolves nobody, and the
  one entry in the package whose whole purpose is to say who destroyed a record cannot be the entry
  with nobody's name on it. Missing it exits `INVALID`.
- Under [compliance mode](../08-lifecycle/05-compliance-mode.md), `Redaction\Redactor::redact()`
  throws `ComplianceException::unattributed()` when no actor is passed, whatever the caller.

---

## Reading it back

```php
use App\Models\User;
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Presentation\AuditPresenter;

// Indexed: rides (actor_type, actor_id, id)
Sentinel::audits()->by($user)->take(50)->get();
Sentinel::audits()->by(User::class, 91)->take(50)->get();
Sentinel::audits()->by('system', 'invoices:close')->take(50)->get();

$audit = Sentinel::audits()->for($invoice)->latest()->take(1)->get()->first();

$audit->actor_type;            // 'user' with a morph map, App\Models\User otherwise
$audit->actor_id;              // '91'
$audit->actor;                 // MorphTo — the model, when the class still resolves
$audit->impersonator_type;     // null unless somebody was genuinely standing in
$audit->impersonator;          // MorphTo

// No published filter for the impersonator columns. Query the table.
Audit::query()->whereNotNull('impersonator_id')->latest('created_at')->limit(50)->get();
```

`AuditPresenter::entry()` renders the impersonation as **its own sentence**, not as a clause bolted
onto the plain one, because languages disagree about where "on behalf of" goes:

```php
app(AuditPresenter::class)->entry($audit);

// No impersonator:   "User #91 changed Invoice #4821"
// With impersonator: "User #7 acting as User #91 changed Invoice #4821"
```

Both lines come from `resources/lang/*/sentinel.php` under `presenter.entry`
(`:actor :event :subject`) and `presenter.impersonated`
(`:impersonator acting as :actor :event :subject`), and an entry with no actor renders the
`presenter.someone` fallback ("Someone"). The name shown is `class_basename()` of the stored type,
so a morph map changes what the sentence reads: `App\Models\User` renders as `User`, the alias
`user` renders as `user`. `Models\Audit::toArray()` exposes the same pairs as `actor` and `impersonator`, each
either `['type' => …, 'id' => …]` or null. See
[Presenting and serializing](../06-reading/07-presenting-and-serializing.md).

> 🧪 **Verify it.** Confirm the two invariants on your own installation without impersonating
> anything:
> ```shell
> php artisan tinker
> >>> ElPandaPe\Sentinel\Models\Audit::query()->whereNotNull('impersonator_id')->count();
> >>> ElPandaPe\Sentinel\Models\Audit::query()->whereColumn('impersonator_id', 'actor_id')->count();
> ```
> Both should be `0` on a trail where nobody has impersonated anyone.

---

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| Every entry has a null actor although users are logged in | `resolvers.actor.guard` names a guard those users do not authenticate on, or the writes happen in a runtime with no session | Set the right guard; for background runtimes see [Queues, commands and schedulers](05-queues-commands-and-schedulers.md) |
| `ConfigurationException: … [sentinel.resolvers.actor.guard] has unknown value [x]` on the first audited write, not at boot | The guard name is validated when `ActorResolver` runs, not when config is loaded | Fix the name; add one audited write to your post-deploy smoke test |
| `actor_id` is null for a user whose key is a value object | `Identity::id()` accepts only `string` and `int`; anything else makes the resolver return an empty array, clearing **both** columns | Replace `ActorResolver` and cast: `(string) $user->getKey()` |
| A custom resolver sets `actor_id` and the column is still null | `ContextEngine::column()` keeps the value only when `is_string()` is true — an int, a `Stringable` or a UUID object is dropped silently | Cast to string inside the resolver |
| `by($user)` finds nothing although the trail clearly holds that user's entries | A morph map was introduced after those entries were written. Both storage and `by()` use `getMorphClass()`, so old rows hold the FQCN and the query now asks for the alias | Query the old rows with `by(User::class, $id)` as well, or backfill `actor_type` — which rewrites the hash of every row touched and requires re-chaining |
| `impersonator_type` names the wrong class | The resolver records the **actor's** class, since `Guard` has no `getProvider()` to hydrate the session id | Replace `ImpersonatorResolver` when two model classes can impersonate each other |
| The impersonator columns are null although the session key is set | The session id equals the actor's own (gate 6), or the value in the session is not a string or an int (gate 4) | Both are intended. Store the original user's key as a scalar |
| Impersonation is never recorded on API routes | Gate 2: `$request->hasSession()` is false for stateless routes | Impersonation over a token is not session-shaped; resolve it from the token in a replacement resolver |
| A `InvalidArgumentException` (not a `ConfigurationException`) about an undefined guard | `ImpersonatorResolver` calls `$auth->guard()` without the conversion `ActorResolver` has. Only reachable once `ActorResolver` has been replaced with one that does not read the guard | Fix the guard name; the message names it |
| `$audit->actor` throws `Error: Class "system" not found` | A synthetic `actor_type` is not in the morph map, and `MorphTo` tries to instantiate it | Register the alias with `Relation::enforceMorphMap()`, or read the columns instead of the relation |
| A policy allowed an entry it should have refused, given the actor named on it | The named actor is applied **after** the pipeline; `EnforcePolicies` saw the resolved one | Decide the policy on the subject, the event or the metadata, not on an actor a caller can override |
| An `UPDATE` fixing an actor makes `verifyIntegrity()` return false for that entry and every entry after it | All four attribution columns are inside `CanonicalPayload::COLUMNS` | Never patch attribution in place. Record a new entry stating the correction |

---

## ✅ Best practices

✅ **Do** — name the actor outright wherever the runtime cannot resolve one. An entry that says
`system / invoices:close` is a fact; an entry with two nulls is a gap somebody will have to
investigate later.

```php
Sentinel::event('invoices.closed')
    ->actor('system', 'invoices:close')
    ->metadata(['period' => '2026-08'])
    ->record();
```

❌ **Don't** — leave a nightly run unattributed and plan to work out who ran it from the timestamps.
Nothing else on the entry names the caller: `context.command` says which command, never who invoked
it.

```php
Sentinel::event('invoices.closed')->metadata(['period' => '2026-08'])->record();
// actor_type: null, actor_id: null
```

✅ **Do** — cast the key to a string in any resolver you write. The engine keeps strings and drops
everything else without an error.

```php
return ['actor_type' => $user->getMorphClass(), 'actor_id' => (string) $user->getKey()];
```

❌ **Don't** — hand the engine a raw key and expect a cast downstream. The column comes out null and
the entry silently loses its actor.

```php
return ['actor_type' => $user->getMorphClass(), 'actor_id' => $user->getKey()];   // int → null
```

✅ **Do** — decide the morph map before the first entry is written, and keep it. Storage and `by()`
both go through `getMorphClass()`, so they agree — with each other, and only with the rows written
under the same map.

```php
// AppServiceProvider::boot()
Relation::enforceMorphMap([
    'user' => App\Models\User::class,
    'system' => App\Models\SystemActor::class,
]);
```

❌ **Don't** — introduce a morph map over a trail that already has history and assume the old entries
follow. They hold the class name, the query asks for the alias, and correcting them means rewriting
hashed columns.

```php
Sentinel::audits()->by($user)->get();   // matches rows written after the map, and only those
```

✅ **Do** — replace `ImpersonatorResolver` when your impersonation crosses model classes, and keep
both shipped invariants in the replacement.

```php
return (string) $admin->getKey() === (string) $actor->getKey() ? [] : [
    'impersonator_type' => $admin->getMorphClass(),
    'impersonator_id' => (string) $admin->getKey(),
];
```

❌ **Don't** — fill the impersonator columns with a copy of the actor "so the column is never empty".
Every reader, the presenter included, treats a non-null `impersonator_type` as a delegation that
happened.

```php
return ['impersonator_type' => $actor->getMorphClass(), 'impersonator_id' => (string) $actor->getKey()];
```

✅ **Do** — treat the actor as evidence and correct it forward. A wrong attribution is itself a fact
worth recording.

```php
Sentinel::event('audit.attribution_corrected')
    ->actor('system', 'ops')
    ->metadata(['audit_id' => $audit->id, 'should_have_been' => 'user:91'])
    ->record();
```

❌ **Don't** — `UPDATE` the columns. All four are inside the canonical payload; the row stops
reproducing its own hash and `sentinel:verify` reports it as tampering — correctly.

```sql
UPDATE sentinel_audits SET actor_id = '91' WHERE id = '01J…';   -- breaks the chain from here on
```

✅ **Do** — keep the actor resolver cheap. It runs on every capture, on the write path, in the
request that is waiting for a response.

```php
$user = $this->auth->guard('admin')->user() ?? $this->auth->guard('web')->user();
```

❌ **Don't** — look the actor up over the network or in the database from a resolver. One lookup here
is one lookup per audited write.

```php
return ['actor_id' => (string) Http::get('https://sso.internal/whoami')->json('id')];
```

---

**See also:** [Execution context](01-execution-context.md) · [The ten resolvers](02-resolvers-reference.md) · [Queues, commands and schedulers](05-queues-commands-and-schedulers.md) · [Writing your own resolver](07-writing-your-own-resolver.md) · [Custom and authentication events](../03-capture/07-custom-and-authentication-events.md) · [The Query API](../06-reading/01-the-query-api.md) · [Presenting and serializing](../06-reading/07-presenting-and-serializing.md) · [Schema](../99-reference/03-schema.md)
