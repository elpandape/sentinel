<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Enums\MassMode;
use ElPandaPe\Sentinel\Exceptions\ConfigurationException;

use function ElPandaPe\Sentinel\Tests\sentinelConfig;

it('ships a default that does not grow with the size of the set', function (): void {
    $config = sentinelConfig();

    expect($config->massMode())->toBe(MassMode::Summary)
        ->and($config->massThreshold())->toBe(100)
        ->and($config->massSample())->toBe(20);
});

it('falls back on its own defaults where a published section has no key for them', function (): void {
    $config = sentinelConfig(['mass_operations' => []]);

    expect($config->massMode())->toBe(MassMode::Summary)
        ->and($config->massThreshold())->toBe(100)
        ->and($config->massSample())->toBe(20);
});

it('reads the mode and the two limits the configuration sets', function (): void {
    $config = sentinelConfig(['mass_operations' => ['mode' => 'hybrid', 'threshold' => 25, 'sample' => 5]]);

    expect($config->massMode())->toBe(MassMode::Hybrid)
        ->and($config->massThreshold())->toBe(25)
        ->and($config->massSample())->toBe(5);
});

it('holds both limits above nothing, because a hybrid that never describes a row is a summary', function (): void {
    $config = sentinelConfig(['mass_operations' => ['threshold' => 0, 'sample' => 0]]);

    expect($config->massThreshold())->toBe(1)
        ->and($config->massSample())->toBe(1);
});

it('refuses a mode it does not know', function (mixed $mode): void {
    expect(fn (): MassMode => sentinelConfig(['mass_operations.mode' => $mode])->massMode())
        ->toThrow(ConfigurationException::class, 'mass_operations.mode');
})->with([['nonesuch'], [1], [['summary']]]);

it('refuses a threshold that is not a number', function (): void {
    expect(static fn (): int => sentinelConfig(['mass_operations.threshold' => 'many'])->massThreshold())
        ->toThrow(ConfigurationException::class, 'must be an integer or null');
});

it('refuses a sample size that is not a number', function (): void {
    expect(static fn (): int => sentinelConfig(['mass_operations.sample' => 'many'])->massSample())
        ->toThrow(ConfigurationException::class, 'must be an integer or null');
});
