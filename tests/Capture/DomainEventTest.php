<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Capture\PendingEvent;
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Events\Auditing;
use ElPandaPe\Sentinel\Exceptions\ConfigurationException;
use ElPandaPe\Sentinel\Exceptions\QueryException;
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Models\AuditTransaction;
use ElPandaPe\Sentinel\Tests\Fixtures\ActingUser;
use ElPandaPe\Sentinel\Tests\Fixtures\AuditedSubject;
use ElPandaPe\Sentinel\Tests\Fixtures\PassThroughStage;
use ElPandaPe\Sentinel\Tests\Fixtures\ProtectedSubject;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

use function ElPandaPe\Sentinel\Tests\httpRequest;
use function ElPandaPe\Sentinel\Tests\presenter;
use function ElPandaPe\Sentinel\Tests\stagedPipeline;

it('settles a fact that no model change describes', function (): void {
    Sentinel::event('invoice.approved')->record();

    $audit = Audit::query()->firstOrFail();

    expect($audit->audit_type)->toBe('custom')
        ->and($audit->event)->toBe('invoice.approved')
        ->and($audit->subject_type)->toBeNull()
        ->and($audit->subject_id)->toBeNull();
});

it('writes nothing until the terminal is called', function (): void {
    Sentinel::event('invoice.approved')->severity(Severity::Notice)->tags(['billing']);

    expect(Audit::query()->count())->toBe(0);
});

it('hands back the builder from every modifier, so the call chains', function (): void {
    $pending = Sentinel::event('invoice.approved');

    expect($pending->severity(Severity::Notice))->toBeInstanceOf(PendingEvent::class)
        ->and($pending->tags(['billing']))->toBeInstanceOf(PendingEvent::class)
        ->and($pending->metadata(['reason' => 'x']))->toBeInstanceOf(PendingEvent::class);
});

it('hangs the fact off a subject when there is one', function (): void {
    $invoice = AuditedSubject::query()->create(['name' => 'invoice']);
    Audit::query()->delete();

    Sentinel::event('invoice.approved')->subject($invoice)->record();

    $audit = Audit::query()->firstOrFail();

    expect($audit->subject_type)->toBe($invoice->getMorphClass())
        ->and($audit->subject_id)->toBe((string) $invoice->getKey());
});

it('takes the severity it was given rather than the default', function (): void {
    Sentinel::event('invoice.approved')->severity(Severity::Critical)->record();

    expect(Audit::query()->firstOrFail()->severity)->toBe(Severity::Critical);
});

it('falls back to the severity the configuration gives that event', function (): void {
    config()->set('sentinel.severity.events', ['invoice.approved' => 'warning']);

    Sentinel::event('invoice.approved')->record();

    expect(Audit::query()->firstOrFail()->severity)->toBe(Severity::Warning);
});

it('files the labels where whereTag can still find them', function (): void {
    Sentinel::event('invoice.approved')->tags(['billing', 'refund'])->record();

    $audit = Audit::query()->firstOrFail();

    expect($audit->tags->pluck('tag')->all())->toBe(['billing', 'refund'])
        ->and(Sentinel::audits()->whereTag('billing')->get())->toHaveCount(1);
});

it('keeps the metadata the caller attached', function (): void {
    Sentinel::event('invoice.approved')->metadata(['reason' => 'approved by finance'])->record();

    expect(Audit::query()->firstOrFail()->metadata)->toBe(['reason' => 'approved by finance']);
});

it('credits the actor it was told about, not the one who happens to be logged in', function (): void {
    $onBehalf = ActingUser::query()->create(['name' => 'Ada']);
    auth()->guard()->setUser(ActingUser::query()->create(['name' => 'Someone Else']));

    Sentinel::event('invoice.approved')->actor($onBehalf)->record();

    $audit = Audit::query()->firstOrFail();

    expect($audit->actor_id)->toBe((string) $onBehalf->getKey())
        ->and($audit->actor_type)->toBe($onBehalf->getMorphClass());
});

it('offers an Auditing listener the actor it named', function (): void {
    $named = ActingUser::query()->create(['name' => 'Ada']);
    auth()->guard()->setUser(ActingUser::query()->create(['name' => 'Someone Else']));
    $seen = null;
    Event::listen(Auditing::class, static function (Auditing $auditing) use (&$seen): void {
        $seen = $auditing->audit->actor_id;
    });

    Sentinel::event('invoice.approved')->actor($named)->record();

    expect($seen)->toBe((string) $named->getKey())
        ->and(Audit::query()->firstOrFail()->actor_id)->toBe($seen);
});

it('credits an entry recorded from inside a listener on its own terms, not on the outer capture', function (): void {
    $named = ActingUser::query()->create(['name' => 'Ada']);
    $loggedIn = ActingUser::query()->create(['name' => 'Someone Else']);
    auth()->guard()->setUser($loggedIn);
    Event::listen(Auditing::class, static function (Auditing $auditing): void {
        if ($auditing->audit->event === 'invoice.approved') {
            AuditedSubject::query()->create(['name' => 'from the listener']);
        }
    });

    Sentinel::event('invoice.approved')->actor($named)->record();

    expect(Audit::query()->where('event', 'created')->firstOrFail()->actor_id)->toBe((string) $loggedIn->getKey())
        ->and(Audit::query()->where('event', 'invoice.approved')->firstOrFail()->actor_id)->toBe((string) $named->getKey());
});

it('still credits the actor it named when a published stage list left the context stage out', function (): void {
    stagedPipeline([PassThroughStage::class]);
    $named = ActingUser::query()->create(['name' => 'Ada']);
    auth()->guard()->setUser(ActingUser::query()->create(['name' => 'Someone Else']));

    Sentinel::event('invoice.approved')->actor($named)->record();

    expect(Audit::query()->firstOrFail()->actor_id)->toBe((string) $named->getKey());
});

it('lets the context engine name the actor when nobody was named', function (): void {
    $user = ActingUser::query()->create(['name' => 'Ada']);
    auth()->guard()->setUser($user);

    Sentinel::event('invoice.approved')->record();

    expect(Audit::query()->firstOrFail()->actor_id)->toBe((string) $user->getKey());
});

it('takes an actor by type and key, for one that is no longer a model', function (): void {
    Sentinel::event('invoice.approved')->actor('system', 'cron')->record();

    $audit = Audit::query()->firstOrFail();

    expect($audit->actor_type)->toBe('system')
        ->and($audit->actor_id)->toBe('cron');
});

it('goes through the same redaction a model change goes through', function (): void {
    $subject = ProtectedSubject::query()->create(['name' => 'x', 'email' => 'ada@example.com']);
    Audit::query()->delete();

    Sentinel::event('invoice.approved')->subject($subject)->metadata(['email' => 'ada@example.com'])->record();

    expect(json_encode(Audit::query()->firstOrFail()->metadata))->not->toContain('ada@example.com');
});

it('earns its place in the chain the same way an update does', function (): void {
    Sentinel::event('invoice.approved')->record();

    $audit = Audit::query()->firstOrFail();

    expect($audit->sequence)->toBe(1)
        ->and($audit->hash)->not->toBeEmpty()
        ->and($audit->previous_hash)->toBeNull()
        ->and($audit->verifyIntegrity())->toBeTrue();
});

it('joins the business operation that was running', function (): void {
    Sentinel::transaction('invoice-payment', function (): void {
        Sentinel::event('invoice.approved')->record();
    });

    $header = AuditTransaction::query()->firstOrFail();

    expect(Audit::query()->firstOrFail()->transaction_id)->toBe($header->id)
        ->and($header->audits_count)->toBe(1);
});

it('waits for the commit like any other entry', function (): void {
    DB::transaction(function (): void {
        Sentinel::event('invoice.approved')->record();

        expect(Audit::query()->count())->toBe(0);
    });

    expect(Audit::query()->count())->toBe(1);
});

it('writes nothing at all when the package is not recording', function (): void {
    config()->set('sentinel.enabled', false);

    Sentinel::event('invoice.approved')->record();

    expect(Audit::query()->count())->toBe(0);
});

it('reads back with the name the application gave the event, untranslated', function (): void {
    Sentinel::event('invoice.approved')->record();

    expect(presenter()->entry(Audit::query()->firstOrFail()))->toContain('invoice.approved');
});

it('refuses an event name longer than the column holds, at the call that wrote it', function (): void {
    expect(fn (): mixed => Sentinel::event(str_repeat('a', 65)))
        ->toThrow(ConfigurationException::class);
});

it('takes a name of exactly the width the column holds', function (): void {
    Sentinel::event(str_repeat('a', 64))->record();

    expect(Audit::query()->firstOrFail()->verifyIntegrity())->toBeTrue();
});

/**
 * A known limitation of 1.0, pinned here so it cannot drift without saying so. The guard is the
 * width of the column and nothing else, so a name that says nothing is recorded as readily as one
 * that does. Refusing it is a call that works today refusing to work tomorrow, which is a break.
 */
it('takes a name with nothing in it, because only the width is guarded', function (): void {
    Sentinel::event('')->record();

    expect(Audit::query()->firstOrFail()->event)->toBeString()->toBeEmpty();
});

it('refuses a subject with no key rather than naming a type nobody can look up', function (): void {
    expect(fn (): mixed => Sentinel::event('invoice.approved')->subject(new AuditedSubject))
        ->toThrow(QueryException::class);
});

it('drops the resolved impersonator when the caller names the actor', function (): void {
    $impersonator = ActingUser::query()->create(['name' => 'Support']);
    $user = ActingUser::query()->create(['name' => 'Ada']);
    $named = ActingUser::query()->create(['name' => 'Third Party']);

    auth()->guard()->setUser($user);
    $request = httpRequest('/invoices');
    $request->setLaravelSession(app('session.store'));
    $request->session()->put('impersonated_by', $impersonator->getKey());

    Sentinel::event('invoice.approved')->actor($named)->record();

    $audit = Audit::query()->firstOrFail();

    expect($audit->actor_id)->toBe((string) $named->getKey())
        ->and($audit->impersonator_type)->toBeNull()
        ->and($audit->impersonator_id)->toBeNull();
});

it('keeps the resolved impersonator when the caller names nobody', function (): void {
    $impersonator = ActingUser::query()->create(['name' => 'Support']);
    $user = ActingUser::query()->create(['name' => 'Ada']);

    auth()->guard()->setUser($user);
    $request = httpRequest('/invoices');
    $request->setLaravelSession(app('session.store'));
    $request->session()->put('impersonated_by', $impersonator->getKey());

    Sentinel::event('invoice.approved')->record();

    expect(Audit::query()->firstOrFail()->impersonator_id)->toBe((string) $impersonator->getKey());
});
