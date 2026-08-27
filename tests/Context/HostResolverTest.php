<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Context\Resolvers\HostResolver;

it('resolves the hostname and the current environment', function (): void {
    $resolved = app(HostResolver::class)->resolve();

    expect($resolved)->toHaveKeys(['hostname', 'environment'])
        ->and($resolved['hostname'])->toBeString()->not->toBeEmpty()
        ->and($resolved['environment'])->toBe(app()->environment());
});
