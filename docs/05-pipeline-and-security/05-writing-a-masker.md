# 🛡️ Writing a masker

> The one-method contract that decides what a redacted field looks like in an entry, the masker the
> package ships, and three replacements you would actually put in production.

**On this page:** [The contract](#the-contract) · [The default masker](#the-default-masker) · [Resolution order](#resolution-order) · [Three maskers](#three-maskers-you-would-actually-ship) · [Testing a masker](#testing-a-masker) · [The rule a masker must not break](#the-rule-a-masker-must-not-break)

---

## The contract

A masker is any class implementing `ElPandaPe\Sentinel\Contracts\Masker`. There is one method and no
base class:

```php
namespace ElPandaPe\Sentinel\Contracts;

interface Masker
{
    public function mask(string $field, mixed $value): mixed;
}
```

- **`$field`** is the **key name that matched** — `email`, `card_number`, `ip`. Not a path, not a
  column reference, not a model. When the match came from a diff operation's JSON Pointer, it is the
  matching *segment* of that pointer. A masker cannot tell whether it is masking `after.email`,
  `context.arguments.email` or the `new` side of a change; it is handed a name and a value.
- **`$value`** is whatever sat under that key: a scalar, `null`, or a whole array when the declared
  name is also a container key.
- **The return value** replaces the value inline, in the same key. It may be any type the canonical
  payload accepts — `null`, `bool`, `int`, `float`, `string`, or arrays of those.

Maskers apply to **redaction only**. A field declared in `$auditHash` goes through `Security\Digester`
and a field declared in `$auditEncrypt` goes through the keyring; neither consults a masker. See
[Hashing and the salt](04-hashing-and-the-salt.md) and
[Encryption and the keyring](03-encryption-and-the-keyring.md).

> 📌 **Note.** `Contracts\Masker` is the published surface. `Security\PartialMasker`,
> `Security\Maskers`, `Security\Fields` and `Security\Digester` are all marked `@internal` and a test
> holds that list as data. Implement the interface; do not extend the shipped class or type-hint it.
> See [API stability](../99-reference/09-api-stability.md).

## The default masker

`Security\PartialMasker` is what every redacted field gets when nothing else is configured. It keeps
the **shape** of the value and the **first and last character of each alphanumeric run**, replacing
the middle with a **fixed four** mask characters.

Two decisions in that sentence are deliberate and both are worth knowing:

- **Fixed width, never padded to the original length.** Padding would hand back how long the secret
  was.
- **A run shorter than three characters is replaced whole.** Keeping both ends of a two-character run
  would be keeping all of it.

A run is `[\p{L}\p{N}]+` — Unicode letters and numbers. Everything else (`@`, `.`, `-`, spaces)
survives untouched, which is what makes the result still recognisable as an address, a name or a
reference.

| Input | Output (mask `*`) | Why |
|---|---|---|
| `'carlos@example.com'` | `'c****s@e****e.c****m'` | four runs, each keeping its ends |
| `'Ada Lovelace'` | `'A****a L****e'` | the space is not part of a run |
| `'A. B.'` | `'****. ****.'` | every run is one character, so each is replaced whole |
| `'ada@b.com'` with mask `#` | `'a####a@####.c####m'` | `b` is a one-character run |
| `1234567` (int) | `'1****7'` | scalars are stringified first — **the type changes** |
| `null` | `null` | the absence of a value is not a value |
| `['email' => 'ada@b.com', 'city' => 'London']` | `['email' => 'a****a@****.c****m', 'city' => 'L****n']` | arrays are mapped element-wise, keys untouched |
| `new stdClass` | `'****'` | anything unstringifiable gets the mask and nothing else |

The mask character comes from `security.redaction.mask` (default `'*'`); `null` or `''` there falls
back to `'*'`.

> ⚠️ **Warning.** `abcdefghijklmnoz` and `abz` produce the identical mask. That is the fixed width
> working as designed — and it also means the default masker is **not anonymisation**. On a small
> domain, `c****s@e****e.c****m` identifies one person to anyone who knows the domain. The package
> promises a mask, not anonymity. If the value must be unrecoverable *and* unlinkable, mask it to a
> constant, or exclude the field so it is never recorded.

## Resolution order

`Security\Maskers` picks one masker per field name, checking two config keys in order:

| Order | Config key | Effect |
|---|---|---|
| 1 | `security.redaction.maskers.<field>` | Per-field override. A map of field name → class-string. |
| 2 | `security.redaction.masker` | The default for every field that has no override. `null` means `PartialMasker`. |
| 3 | — | `Security\PartialMasker`, constructed with `security.redaction.mask`. |

A class named in either key that does not exist, or does not implement `Contracts\Masker`, throws
`ConfigurationException::invalidClass` naming the exact key at fault — `security.redaction.maskers.email`,
not just `security.redaction`. A non-string value throws `ConfigurationException::expected`. Both
happen the first time that field is masked, not at boot.

```php
// config/sentinel.php
'security' => [
    'redaction' => [
        'mask' => '*',
        'fields' => ['ip'],                                  // added to every model's $auditRedact
        'masker' => App\Sentinel\ShapeMasker::class,          // the default for every field
        'maskers' => [
            'card_number' => App\Sentinel\LastFourMasker::class,
            'email' => App\Sentinel\DomainMasker::class,
        ],
    ],
],
```

### How the instance is built

- **A custom masker is resolved from the container** with `make()`, so constructor injection works —
  a masker may take `Support\Config`, a repository, anything the container can build.
- **`PartialMasker` is not.** `Maskers::resolve()` constructs it directly with the configured mask
  character. Binding or decorating `PartialMasker` in the container has no effect on what the
  pipeline uses.
- **One instance per field name, memoised.** `Maskers::for()` caches by field, and `Maskers` itself is
  bound as a **scoped** singleton — so it is rebuilt once per request and once per queued job, and a
  masker instance never leaks state between them.

> 📌 **Note.** The per-field lookup is a config path, so a field name containing a `.` is read as
> nested config and the override will not be found — the field falls through to the default masker
> with no error. Column names never look like that; keys you invent for `metadata` or `context` can.

> 🧪 **Verify it.** After registering a masker, write one record and read the entry back:
> `php artisan tinker --execute="dd(App\Models\Order::query()->latest()->first()->audits->last()->after);"`

## Three maskers you would actually ship

All three follow the same skeleton, and the skeleton is not decoration: **null, array and non-scalar
have to be handled** or the masker will be handed one of them eventually — `null` from a nullable
column, an array from a declared name that turned out to be a container key, an object from something
a resolver pushed into `context`.

### A card number, keeping the last four

Injects `Support\Config` so it honours `security.redaction.mask` like the shipped masker does. Note
what it does *not* do: it never pads to the length of the original, so an eight-digit and a
nineteen-digit value are indistinguishable in the entry.

```php
namespace App\Sentinel;

use ElPandaPe\Sentinel\Contracts\Masker;
use ElPandaPe\Sentinel\Support\Config;

final readonly class LastFourMasker implements Masker
{
    private const int KEPT = 4;

    public function __construct(private Config $config) {}

    public function mask(string $field, mixed $value): mixed
    {
        $filler = str_repeat($this->config->redactionMask(), self::KEPT);

        return match (true) {
            $value === null => null,
            is_array($value) => array_map(fn (mixed $item): mixed => $this->mask($field, $item), $value),
            is_scalar($value) => $this->tail((string) $value, $filler),
            default => $filler,
        };
    }

    private function tail(string $value, string $filler): string
    {
        $digits = (string) preg_replace('/\D/', '', $value);

        return mb_strlen($digits) <= self::KEPT
            ? $filler
            : $filler.mb_substr($digits, -self::KEPT);
    }
}
```

`'4111 1111 1111 1234'` becomes `'****1234'`. `'12'` becomes `'****'` — a value too short to keep
four of is not worth keeping any of.

### An email, keeping the domain

Useful when the trail has to answer "was this a corporate account or a personal one" without holding
the address. The local part is gone entirely, at a fixed width.

```php
namespace App\Sentinel;

use ElPandaPe\Sentinel\Contracts\Masker;

final readonly class DomainMasker implements Masker
{
    private const string FILLER = '****';

    public function mask(string $field, mixed $value): mixed
    {
        return match (true) {
            $value === null => null,
            is_array($value) => array_map(fn (mixed $item): mixed => $this->mask($field, $item), $value),
            is_string($value) && str_contains($value, '@') => self::FILLER.(string) mb_strstr($value, '@'),
            default => self::FILLER,
        };
    }
}
```

`'carlos@example.com'` becomes `'****@example.com'`. Anything that is not a string containing `@` —
a number, a boolean, a malformed address — becomes `'****'` rather than being passed through, because
a masker that lets an unexpected shape through is a masker that leaks on the day the column changes.

> 🔒 **Security.** Keeping the domain keeps a real attribute of the person. On a domain with three
> employees, `****@acme-legal.example` is close to naming one. Decide that trade per field, not per
> installation.

### A national id, keeping nothing but its shape

Every letter becomes `A`, every digit becomes `0`, separators survive. The entry proves that a
well-formed identifier was written, and says nothing else about it.

```php
namespace App\Sentinel;

use ElPandaPe\Sentinel\Contracts\Masker;

final readonly class ShapeMasker implements Masker
{
    public function mask(string $field, mixed $value): mixed
    {
        return match (true) {
            $value === null => null,
            is_array($value) => array_map(fn (mixed $item): mixed => $this->mask($field, $item), $value),
            is_scalar($value) => (string) preg_replace(['/\p{L}/u', '/\p{N}/u'], ['A', '0'], (string) $value),
            default => '',
        };
    }
}
```

`'12345678Z'` becomes `'00000000A'`; `'X-1234567-L'` becomes `'A-0000000-A'`. This one **does** leak
the length and the format — that is the whole feature, and it is a bigger disclosure than the shipped
masker's fixed width. Use it where the format is the thing a reviewer needs to see.

## Testing a masker

Test the class directly first. It has no framework dependencies to speak of, and the cases that
matter are the three shapes the pipeline will eventually hand it.

```php
use App\Sentinel\DomainMasker;

it('keeps the domain and none of the local part', function (): void {
    expect(new DomainMasker()->mask('email', 'carlos@example.com'))->toBe('****@example.com');
});

it('gives the same value the same mask, twice', function (): void {
    $masker = new DomainMasker();

    expect($masker->mask('email', 'carlos@example.com'))
        ->toBe($masker->mask('email', 'carlos@example.com'));
});

it('leaves a null as the absence of a value', function (): void {
    expect(new DomainMasker()->mask('email', null))->toBeNull();
});

it('masks every value of a structure, not the structure', function (): void {
    expect(new DomainMasker()->mask('contacts', ['work' => 'ada@acme.test', 'note' => 'primary']))
        ->toBe(['work' => '****@acme.test', 'note' => '****']);
});

it('does not hand back anything the canonical payload refuses', function (): void {
    expect(new DomainMasker()->mask('email', new stdClass))->toBeString();
});
```

Then test it where it actually runs — through the pipeline, on a model that declares the field:

```php
use App\Models\Order;              // protected array $auditRedact = ['card_number'];
use App\Sentinel\LastFourMasker;

it('stores only the last four digits of a card number', function (): void {
    config()->set('sentinel.security.redaction.maskers', ['card_number' => LastFourMasker::class]);

    $order = Order::query()->create([
        'reference' => 'INV-2291',
        'card_number' => '4111111111111234',
    ]);

    expect($order->audits->last()?->after['card_number'] ?? null)->toBe('****1234');
});
```

> ⚠️ **Warning.** Set the config **before** the first write of the test. `Maskers` memoises one
> instance per field name for the life of the container scope, so changing
> `security.redaction.maskers` after something already masked that field in the same test leaves the
> old masker in place. If you must change it mid-test, call `app()->forgetScopedInstances()` first.

## The rule a masker must not break

**A masker must be deterministic: the same `$field` and the same `$value` must always produce the
same result.** Not "should" — the package cannot enforce it, and the cost of breaking it is paid
silently and permanently.

Here is precisely what happens if you break it, because the obvious guess is wrong.

**It does not break the hash chain.** The chain hash is computed once, at write time, over the value
the masker produced, and re-computed at verification from what is stored. A random mask is sealed as
fact and `Sentinel::verifyIntegrity()` will confirm that entry as intact for as long as it exists.
Nothing will ever tell you it is wrong. That is not a reassurance — it is the reason this matters:
the damage is unrecoverable and undetectable, and the entry is append-only, so there is no fixing it
afterwards.

What breaks instead:

| What | Why |
|---|---|
| The two snapshots of one entry | `Security\Fields` walks `before` and `after` separately, so an unchanged value is handed to the masker twice. `$audit->before['email']` and `$audit->after['email']` then disagree on a field that never moved. |
| `AuditQuery::compare($from, $to)` | `Query\Comparison` diffs the two entries' **stored** `after` snapshots. Every masked field reports as changed on every comparison. See [Field history](../06-reading/04-field-history.md). |
| Any equality across entries | "The same address appears in these four entries" stops being answerable, which for many teams was the whole reason to mask instead of exclude. |
| A field in both `$auditRedact` and `$auditHash` | `MaskSensitiveData` applies the redaction list first and the hashing list second, over the same entry, so the digest is taken **of the mask**. A non-deterministic mask makes a fresh digest on every write. |

Three more rules, all enforced by something other than politeness:

- **It must return something the canonical payload accepts.** `null`, `bool`, `int`, `float`,
  `string`, or arrays of those. Return an object and the write fails with
  `CanonicalizationException` — "a canonical payload holds scalars, arrays and null" — thrown at
  hashing time by the canonicaliser, not by your masker, which makes the stack trace unhelpful. See
  [Canonicalization](../07-integrity/03-canonicalization.md).
- **It must not throw.** The pipeline runs inside the capture, and nothing catches it there:
  `on_write_failure` governs the **ledger write**, which happens after. A masker that throws takes
  the `save()` that triggered it down with it, in every performance mode. See
  [Failure policy](../09-operations/05-failure-policy.md).
- **It must be cheap.** The pipeline runs in the request under every mode, including `queue` and
  `buffered` — a sensitive value must never exist untransformed, not even for the moment it waits to
  be flushed. A masker that makes a network call or a database query adds that latency to every save
  that touches a protected field. See [Performance modes](../09-operations/01-performance-modes.md).

## ⚠️ Pitfalls

| Symptom | Cause | Fix |
|---|---|---|
| A field is still masked with asterisks after registering a custom masker | The class is in `security.redaction.masker` but the field has an entry in `security.redaction.maskers`, which wins | Remove the per-field override, or point it at the class you meant |
| `ConfigurationException` naming `security.redaction.maskers.email` on the first write | That class does not exist or does not implement `Contracts\Masker` | Fix the class-string; the exception names the exact key |
| A masker bound or decorated in a service provider is ignored | The class is `PartialMasker`, which `Maskers::resolve()` constructs directly rather than through the container | Write your own class implementing `Contracts\Masker` and name it in config |
| A masker change mid-test has no effect | `Maskers` memoises one instance per field name and is a scoped singleton | Set the config before the first write, or call `app()->forgetScopedInstances()` |
| An entire nested structure came back as one masked string | The declared name is also a container key; `Fields::walk` transforms a matched key without descending into it | Declare the leaf key names instead of the container's |
| A masked integer column breaks a downstream consumer | `PartialMasker` stringifies scalars — `1234567` becomes `'1****7'` | Write a masker that preserves the type, or stop assuming a masked snapshot is type-faithful. A restore refuses redacted fields anyway |
| A console argument is masked with eight characters your masker never produced | `resolvers.command.redact` is a separate, older mechanism that runs in `ResolveContext`, before `MaskSensitiveData`, and consults no `Masker` | Maintain both lists, or drop the name from `resolvers.command.redact` and declare it in `security.redaction.fields` instead. See [The ten resolvers](../04-context/02-resolvers-reference.md) |
| `compare()` reports a masked field as changed between two versions that hold the same value | The masker is not deterministic, and `Query\Comparison` diffs the stored snapshots | Make it deterministic. Entries already written cannot be repaired — the chain sealed them, and they will go on verifying as intact |
| A `save()` fails with `CanonicalizationException` and the trace points at the hasher | The masker returned an object or a resource | Return a scalar, `null`, or an array of those |

## ✅ Best practices

✅ **Do** — implement `Contracts\Masker` and register the class by name. That is the whole supported
surface, and it survives package upgrades.

```php
use ElPandaPe\Sentinel\Contracts\Masker;

final readonly class DomainMasker implements Masker
{
    public function mask(string $field, mixed $value): mixed { /* … */ }
}
```

❌ **Don't** — extend or type-hint `Security\PartialMasker`. Extending it does not merely go
unsupported: the class is declared `final readonly`, so the snippet below is a fatal error at
compile time. Type-hinting it compiles, but it is `@internal`, it is built outside the container,
and a change to it is not a breaking change.

```php
use ElPandaPe\Sentinel\Security\PartialMasker;

final class MyMasker extends PartialMasker {}   // PHP Fatal error: class PartialMasker is final
```

---

✅ **Do** — handle `null`, arrays and non-scalars in every masker, in that order. The pipeline will
hand you all three eventually, and the default arm is your only defence against a column whose type
changed.

```php
return match (true) {
    $value === null => null,
    is_array($value) => array_map(fn (mixed $item): mixed => $this->mask($field, $item), $value),
    is_scalar($value) => $this->partial((string) $value),
    default => self::FILLER,
};
```

❌ **Don't** — assume a string. A nullable column, a `metadata` key or a declared container name
gives you `null` or an array, and this returns a value the canonical payload will refuse.

```php
public function mask(string $field, mixed $value): mixed
{
    return substr($value, 0, 1).'****';   // TypeError on null, "Array" on an array
}
```

---

✅ **Do** — keep the masker pure: same field, same value, same result, every time. Determinism is what
keeps `diff()` and `compare()` honest about a masked field.

```php
public function mask(string $field, mixed $value): mixed
{
    return is_string($value) ? '****'.(string) mb_strstr($value, '@') : null;
}
```

❌ **Don't** — introduce randomness, a clock, or per-request state. The chain seals whatever comes
out, and it will verify as intact forever — so nothing will ever tell you the entry is wrong.

```php
public function mask(string $field, mixed $value): mixed
{
    return '****'.bin2hex(random_bytes(2));   // a field that never moved now reads as changed
}
```

---

✅ **Do** — give one field its own masker when the shipped mask says too much or too little, and leave
the rest on the default.

```php
'maskers' => [
    'card_number' => App\Sentinel\LastFourMasker::class,
    'national_id' => App\Sentinel\ShapeMasker::class,
],
```

❌ **Don't** — replace `security.redaction.masker` globally to fix one field. Every other redacted
field silently changes shape too, including keys added by `security.redaction.fields` that you were
not thinking about.

```php
'masker' => App\Sentinel\LastFourMasker::class,   // now `ip` and `email` go through it as well
```

---

✅ **Do** — take dependencies through the constructor when a masker needs them. Custom maskers are
resolved from the container, and honouring `security.redaction.mask` costs one injected `Config`.

```php
use ElPandaPe\Sentinel\Support\Config;

public function __construct(private Config $config) {}
```

❌ **Don't** — reach out of the masker for data. It runs inside the capture, in the request, on every
save that touches the field, under every performance mode.

```php
use Illuminate\Support\Facades\Http;

public function mask(string $field, mixed $value): mixed
{
    return Http::get("https://tokenizer.internal/mask/{$value}")->body();   // in the request path
}
```

---

✅ **Do** — mask to a constant when the requirement is "unrecoverable and unlinkable", and say so in
the class name.

```php
final readonly class BlankMasker implements Masker
{
    public function mask(string $field, mixed $value): mixed
    {
        return $value === null ? null : '[redacted]';
    }
}
```

❌ **Don't** — call a partial mask anonymisation in a compliance document. It keeps the shape and
both ends of every run; on a small domain that still identifies someone.

---

**See also:** [Protecting sensitive data](02-protecting-sensitive-data.md) · [Hashing and the salt](04-hashing-and-the-salt.md) · [Encryption and the keyring](03-encryption-and-the-keyring.md) · [The write pipeline](01-the-write-pipeline.md) · [Swapping components](../11-extending/06-swapping-components.md) · [Restoring state](../06-reading/08-restoring-state.md) · [Configuration](../99-reference/02-configuration.md) · [Security checklist](../13-best-practices/03-security-checklist.md)
