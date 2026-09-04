<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Console\About;

it('says how the package is configured, in the section it registered', function (): void {
    $this->artisan('about')
        ->expectsOutputToContain(About::SECTION)
        ->assertSuccessful();
});

it('names the mode, the ledger and the payload format a write would use', function (): void {
    config()->set('sentinel.mode', 'queue');

    $about = app()->call(app(About::class));

    expect($about)->toHaveKeys(['Version', 'Mode', 'Ledger', 'Payload version', 'Compliance mode', 'Telemetry'])
        ->and($about['Mode'])->toBe('queue')
        ->and($about['Ledger'])->toBe('database')
        ->and($about['Payload version'])->toBe('1');
});

it('reports a checkout with no recorded version as a checkout', function (): void {
    expect(app()->call(app(About::class))['Version'])->toBeString()->not->toBeEmpty();
});

it('renders the two switches for a person and for a machine', function (): void {
    config()->set('sentinel.compliance', true);

    $compliance = app()->call(app(About::class))['Compliance mode'];

    expect($compliance)->toBeCallable()
        ->and($compliance(false))->toContain('ENABLED')
        ->and($compliance(true))->toBeTrue();
});

it('carries no key, no key identifier and no signer anywhere in what it prints', function (): void {
    config()->set('sentinel.integrity.signature.enabled', true);
    config()->set('sentinel.integrity.signature.keys', ['default' => 'a-real-looking-secret']);

    $printed = json_encode(array_map(
        static fn (mixed $value): mixed => is_callable($value) ? $value(true) : $value,
        app()->call(app(About::class)),
    ));

    expect($printed)->not->toContain('a-real-looking-secret')
        ->and(strtolower((string) $printed))->not->toContain('key')
        ->and(strtolower((string) $printed))->not->toContain('signer');
});
