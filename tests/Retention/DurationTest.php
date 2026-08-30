<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Exceptions\ConfigurationException;
use ElPandaPe\Sentinel\Retention\Duration;

it('reads a span written in plain units', function (): void {
    expect(Duration::of('auth', '90 days')->cutoff(new CarbonImmutable('2026-08-30 12:00:00')))
        ->toEqual(new CarbonImmutable('2026-06-01 12:00:00'));
});

it('reads a span written more than one unit at a time', function (): void {
    expect(Duration::of('auth', '1 year 6 months')->cutoff(new CarbonImmutable('2026-08-30 12:00:00')))
        ->toEqual(new CarbonImmutable('2025-02-28 12:00:00'));
});

it('reads a span written in ISO 8601', function (): void {
    expect(Duration::of('auth', 'P7D')->cutoff(new CarbonImmutable('2026-08-30 12:00:00')))
        ->toEqual(new CarbonImmutable('2026-08-23 12:00:00'));
});

it('subtracts a month by the calendar and not by a fixed number of days', function (): void {
    expect(Duration::of('auth', '1 month')->cutoff(new CarbonImmutable('2026-03-31 12:00:00')))
        ->toEqual(new CarbonImmutable('2026-02-28 12:00:00'));
});

it('keeps the string it was declared with', function (): void {
    expect(Duration::of('auth', '7 years')->declared)->toBe('7 years');
});

it('refuses a relative date, which would mean a different span on a different day', function (): void {
    expect(fn (): Duration => Duration::of('auth', 'tomorrow'))
        ->toThrow(ConfigurationException::class, 'sentinel.retention.auth');
});

it('refuses a unit it cannot read', function (): void {
    expect(fn (): Duration => Duration::of('auth', '7 bananas'))
        ->toThrow(ConfigurationException::class, '[7 bananas]');
});

it('refuses a span that does not reach into the past', function (): void {
    expect(fn (): Duration => Duration::of('auth', '0 days'))
        ->toThrow(ConfigurationException::class, 'does not reach into the past');
});

it('refuses an ISO span of no length', function (): void {
    expect(fn (): Duration => Duration::of('auth', 'PT0S'))
        ->toThrow(ConfigurationException::class, 'does not reach into the past');
});
