<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Context\Resolvers\ImpersonatorResolver;
use ElPandaPe\Sentinel\Tests\Fixtures\ActingUser;

use function ElPandaPe\Sentinel\Tests\httpRequest;

it('resolves nothing without a request', function (): void {
    expect(app(ImpersonatorResolver::class)->resolve())->toBeEmpty();
});

it('resolves nothing when the request has no session', function (): void {
    httpRequest('/invoices');

    expect(app(ImpersonatorResolver::class)->resolve())->toBeEmpty();
});

it('resolves nothing when the session carries no impersonator', function (): void {
    $request = httpRequest('/invoices');
    $request->setLaravelSession(app('session.store'));

    expect(app(ImpersonatorResolver::class)->resolve())->toBeEmpty();
});

it('resolves nothing when nobody is authenticated on the actor guard', function (): void {
    $request = httpRequest('/invoices');
    $request->setLaravelSession(app('session.store'));
    $request->session()->put('impersonated_by', 1);

    expect(app(ImpersonatorResolver::class)->resolve())->toBeEmpty();
});

it('names who is acting on behalf of whom', function (): void {
    $impersonator = ActingUser::query()->create(['name' => 'Support']);
    $user = ActingUser::query()->create(['name' => 'User']);
    auth()->guard()->setUser($user);

    $request = httpRequest('/invoices');
    $request->setLaravelSession(app('session.store'));
    $request->session()->put('impersonated_by', $impersonator->getKey());

    expect(app(ImpersonatorResolver::class)->resolve())->toBe([
        'impersonator_type' => $user->getMorphClass(),
        'impersonator_id' => (string) $impersonator->getKey(),
    ]);
});

it('reads the session key the configuration names', function (): void {
    config()->set('sentinel.resolvers.impersonator.session_key', 'acting_as');

    $user = ActingUser::query()->create(['name' => 'User']);
    auth()->guard()->setUser($user);

    $request = httpRequest('/invoices');
    $request->setLaravelSession(app('session.store'));
    $request->session()->put('acting_as', 7);

    expect(app(ImpersonatorResolver::class)->resolve()['impersonator_id'])->toBe('7');
});

it('never fills the impersonator with the actor itself', function (): void {
    $user = ActingUser::query()->create(['name' => 'User']);
    auth()->guard()->setUser($user);

    $request = httpRequest('/invoices');
    $request->setLaravelSession(app('session.store'));
    $request->session()->put('impersonated_by', $user->getKey());

    expect(app(ImpersonatorResolver::class)->resolve())->toBeEmpty();
});

it('resolves nothing when the session carries something no column could hold', function (): void {
    $user = ActingUser::query()->create(['name' => 'Ada']);
    auth()->guard()->setUser($user);

    $request = httpRequest('/invoices');
    $request->setLaravelSession(app('session.store'));
    $request->session()->put('impersonated_by', ['id' => 7]);

    expect(app(ImpersonatorResolver::class)->resolve())->toBeEmpty();
});
