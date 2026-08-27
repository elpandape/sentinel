<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Context\Resolvers\ActorResolver;
use ElPandaPe\Sentinel\Exceptions\ConfigurationException;
use ElPandaPe\Sentinel\Tests\Fixtures\ActingUser;

it('resolves nothing when nobody is authenticated', function (): void {
    expect(app(ActorResolver::class)->resolve())->toBeEmpty();
});

it('names who acted with the morph class of the authenticated model', function (): void {
    $user = ActingUser::query()->create(['name' => 'Ada']);
    auth()->guard()->setUser($user);

    expect(app(ActorResolver::class)->resolve())->toBe([
        'actor_type' => $user->getMorphClass(),
        'actor_id' => (string) $user->getKey(),
    ]);
});

it('reads the guard the configuration names', function (): void {
    config()->set('auth.guards.admin', ['driver' => 'session', 'provider' => 'users']);
    config()->set('sentinel.resolvers.actor.guard', 'admin');

    $user = ActingUser::query()->create(['name' => 'Ada']);
    auth()->guard('admin')->setUser($user);

    expect(app(ActorResolver::class)->resolve()['actor_id'])->toBe((string) $user->getKey());
});

it('refuses a guard the application never defined', function (): void {
    config()->set('sentinel.resolvers.actor.guard', 'ghost');

    expect(fn (): array => app(ActorResolver::class)->resolve())
        ->toThrow(ConfigurationException::class);
});
