<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Integrity\CanonicalPayload;
use ElPandaPe\Sentinel\Integrity\JsonCanonicalizer;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Tests\Fixtures\GoldenLedger;

use function ElPandaPe\Sentinel\Tests\hasher;

it('still canonicalizes the frozen entries the way payload version one did', function (array $attributes, string $canonical): void {
    $audit = new Audit()->forceFill($attributes);

    expect(new JsonCanonicalizer()->canonicalize(CanonicalPayload::from($audit)))->toBe($canonical);
})->with(GoldenLedger::entries());

it('still reproduces the frozen hashes', function (array $attributes, string $canonical, string $hash): void {
    $audit = new Audit()->forceFill($attributes);

    expect(hasher()->hash($audit))->toBe($hash);
})->with(GoldenLedger::entries());

it('reproduces the frozen hash without going through the package at all', function (array $attributes, string $canonical, string $hash): void {
    $prefix = implode("\x1f", [
        (string) $attributes['payload_version'],
        (string) $attributes['stream'],
        (string) $attributes['sequence'],
        $attributes['previous_hash'] ?? '',
    ]);

    expect(hash('sha256', $prefix."\x1f".$canonical))->toBe($hash);
})->with(GoldenLedger::entries());

it('breaks the frozen hash if a single canonical column moves', function (array $attributes, string $canonical, string $hash): void {
    $audit = new Audit()->forceFill([...$attributes, 'event' => 'moved']);

    expect(hasher()->hash($audit))->not->toBe($hash);
})->with(GoldenLedger::entries());
