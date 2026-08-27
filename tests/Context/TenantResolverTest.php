<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Context\Resolvers\TenantResolver;
use ElPandaPe\Sentinel\Exceptions\ConfigurationException;

it('resolves nothing when the application wired no tenant', function (): void {
    expect(app(TenantResolver::class)->resolve())->toBeEmpty();
});

it('resolves nothing when the hook returns null', function (): void {
    config()->set('sentinel.resolvers.tenant.using', fn (): ?string => null);

    expect(app(TenantResolver::class)->resolve())->toBeEmpty();
});

it('takes the tenant from the hook, whatever package produced it', function (): void {
    config()->set('sentinel.resolvers.tenant.using', fn (): string => 'acme');

    expect(app(TenantResolver::class)->resolve())->toBe(['tenant_id' => 'acme']);
});

it('accepts an integer tenant key', function (): void {
    config()->set('sentinel.resolvers.tenant.using', fn (): int => 42);

    expect(app(TenantResolver::class)->resolve())->toBe(['tenant_id' => '42']);
});

it('refuses a hook that returns something no column can hold', function (): void {
    config()->set('sentinel.resolvers.tenant.using', fn (): array => ['acme']);

    expect(fn (): array => app(TenantResolver::class)->resolve())
        ->toThrow(ConfigurationException::class);
});
