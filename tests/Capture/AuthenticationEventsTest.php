<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Capture\AuthenticationSubscriber;
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Tests\Fixtures\ActingUser;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;

use function ElPandaPe\Sentinel\Tests\presenter;

it('writes nothing at all until the application registers the subscriber', function (): void {
    $user = ActingUser::query()->create(['name' => 'Ada']);

    Event::dispatch(new Login('web', $user, false));
    Event::dispatch(new Logout('web', $user));
    Event::dispatch(new Failed('web', $user, ['password' => 'hunter2']));

    expect(Audit::query()->count())->toBe(0);
});

it('records a login once the subscriber is registered', function (): void {
    Event::subscribe(AuthenticationSubscriber::class);
    $user = ActingUser::query()->create(['name' => 'Ada']);

    Event::dispatch(new Login('web', $user, false));

    $audit = Audit::query()->firstOrFail();

    expect($audit->audit_type)->toBe('auth')
        ->and($audit->event)->toBe('login')
        ->and($audit->actor_id)->toBe((string) $user->getKey())
        ->and($audit->subject_id)->toBe((string) $user->getKey())
        ->and($audit->metadata)->toBe(['guard' => 'web']);
});

it('records a logout with the user the event carried, not the one still on the guard', function (): void {
    Event::subscribe(AuthenticationSubscriber::class);
    $user = ActingUser::query()->create(['name' => 'Ada']);

    Event::dispatch(new Logout('web', $user));

    $audit = Audit::query()->firstOrFail();

    expect($audit->event)->toBe('logout')
        ->and($audit->actor_id)->toBe((string) $user->getKey());
});

it('weighs a refused attempt heavier than a successful one', function (): void {
    Event::subscribe(AuthenticationSubscriber::class);
    $user = ActingUser::query()->create(['name' => 'Ada']);

    Event::dispatch(new Login('web', $user, false));
    Event::dispatch(new Failed('web', $user, ['password' => 'hunter2']));

    $entries = Audit::query()->orderBy('id')->get();

    expect($entries->first()->severity)->toBe(Severity::Info)
        ->and($entries->last()->severity)->toBe(Severity::Warning);
});

it('weighs a lockout heaviest of all', function (): void {
    Event::subscribe(AuthenticationSubscriber::class);

    Event::dispatch(new Lockout(Request::create('/login', 'POST')));

    $audit = Audit::query()->firstOrFail();

    expect($audit->event)->toBe('lockout')
        ->and($audit->severity)->toBe(Severity::Critical)
        ->and($audit->actor_id)->toBeNull()
        ->and($audit->metadata)->toBeNull();
});

it('records an attempt that named nobody, with no actor and no trace of the credentials', function (): void {
    Event::subscribe(AuthenticationSubscriber::class);

    Event::dispatch(new Failed('web', null, ['email' => 'nobody@example.com', 'password' => 'hunter2']));

    $audit = Audit::query()->firstOrFail();

    expect($audit->event)->toBe('failed')
        ->and($audit->actor_id)->toBeNull()
        ->and($audit->subject_id)->toBeNull()
        ->and(json_encode($audit->getAttributes()))->not->toContain('hunter2')
        ->and(json_encode($audit->getAttributes()))->not->toContain('nobody@example.com');
});

it('records a password reset, which carries a user and no guard', function (): void {
    Event::subscribe(AuthenticationSubscriber::class);
    $user = ActingUser::query()->create(['name' => 'Ada']);

    Event::dispatch(new PasswordReset($user));

    $audit = Audit::query()->firstOrFail();

    expect($audit->event)->toBe('password_reset')
        ->and($audit->severity)->toBe(Severity::Notice)
        ->and($audit->actor_id)->toBe((string) $user->getKey())
        ->and($audit->metadata)->toBeNull();
});

it('earns its place in the chain like every other entry', function (): void {
    Event::subscribe(AuthenticationSubscriber::class);
    $user = ActingUser::query()->create(['name' => 'Ada']);

    Event::dispatch(new Login('web', $user, false));

    $audit = Audit::query()->firstOrFail();

    expect($audit->sequence)->toBe(1)
        ->and($audit->verifyIntegrity())->toBeTrue();
});

it('records the context an attempt came from, which is what makes an actorless entry worth having', function (): void {
    Event::subscribe(AuthenticationSubscriber::class);

    Event::dispatch(new Failed('web', null, ['password' => 'hunter2']));

    expect(Audit::query()->firstOrFail()->context)->not->toBeEmpty();
});

it('stops recording authentication when the package is not recording', function (): void {
    Event::subscribe(AuthenticationSubscriber::class);
    config()->set('sentinel.enabled', false);
    $user = ActingUser::query()->create(['name' => 'Ada']);

    Event::dispatch(new Login('web', $user, false));

    expect(Audit::query()->count())->toBe(0);
});

it('answers a query for what happened to a person, from either column', function (): void {
    Event::subscribe(AuthenticationSubscriber::class);
    $user = ActingUser::query()->create(['name' => 'Ada']);

    Event::dispatch(new Login('web', $user, false));

    expect(Sentinel::audits()->by($user)->get())->toHaveCount(1)
        ->and(Sentinel::audits()->for($user)->get())->toHaveCount(1);
});

it('reads back as a sentence in the language the package ships', function (): void {
    Event::subscribe(AuthenticationSubscriber::class);
    $user = ActingUser::query()->create(['name' => 'Ada']);

    Event::dispatch(new Login('web', $user, false));

    expect(presenter()->entry(Audit::query()->firstOrFail()))->toContain('signed in');
});
