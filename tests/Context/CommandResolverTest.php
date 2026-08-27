<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Context\Resolvers\CommandResolver;

use function ElPandaPe\Sentinel\Tests\runtime;

it('resolves nothing outside a command', function (): void {
    expect(app(CommandResolver::class)->resolve())->toBeEmpty();
});

it('names the command and its arguments', function (): void {
    runtime()->enteredCommand('invoices:close', ['month' => '2026-08', '--force' => true]);

    expect(app(CommandResolver::class)->resolve())->toBe([
        'command' => 'invoices:close',
        'arguments' => [
            'month' => '2026-08',
            '--force' => true,
        ],
    ]);
});

it('drops the command key from the arguments map', function (): void {
    runtime()->enteredCommand('invoices:close', ['command' => 'invoices:close', 'month' => '2026-08']);

    expect(app(CommandResolver::class)->resolve()['arguments'])->toBe(['month' => '2026-08']);
});

it('redacts arguments matched by the default needles', function (): void {
    runtime()->enteredCommand('user:password', [
        '--password' => 'super-secret',
        '--api-token' => 'abc123',
        '--secret-key' => 'xyz',
    ]);

    $mask = str_repeat('*', 8);

    expect(app(CommandResolver::class)->resolve()['arguments'])->toBe([
        '--password' => $mask,
        '--api-token' => $mask,
        '--secret-key' => $mask,
    ]);
});

it('redacts using a configured list instead of the default', function (): void {
    config()->set('sentinel.resolvers.command.redact', ['month']);

    runtime()->enteredCommand('invoices:close', ['month' => '2026-08', '--password' => 'still-here']);

    expect(app(CommandResolver::class)->resolve()['arguments'])->toBe([
        'month' => str_repeat('*', 8),
        '--password' => 'still-here',
    ]);
});

it('masks a sensitive key even when it holds an array', function (): void {
    runtime()->enteredCommand('invoices:close', ['--tokens' => ['a', 'b']]);

    expect(app(CommandResolver::class)->resolve()['arguments'])->toBe([
        '--tokens' => str_repeat('*', 8),
    ]);
});

it('drops a non-scalar, non-array argument value', function (): void {
    runtime()->enteredCommand('invoices:close', ['handler' => new stdClass]);

    expect(app(CommandResolver::class)->resolve()['arguments'])->toBe([]);
});
