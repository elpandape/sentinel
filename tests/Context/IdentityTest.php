<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Context\Identity;
use ElPandaPe\Sentinel\Tests\Fixtures\ActingUser;
use Illuminate\Auth\GenericUser;

it('names a model by the alias a query filters on', function (): void {
    $user = ActingUser::query()->create(['name' => 'Ada']);

    expect(Identity::type($user))->toBe($user->getMorphClass())
        ->and(Identity::id($user))->toBe((string) $user->getKey());
});

it('names anything that is not a model by its class', function (): void {
    $user = new GenericUser(['id' => 7]);

    expect(Identity::type($user))->toBe(GenericUser::class)
        ->and(Identity::id($user))->toBe('7');
});

it('has no id for a key no column could hold', function (): void {
    expect(Identity::id(new GenericUser(['id' => ['nested']])))->toBeNull();
});
