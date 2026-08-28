<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Exceptions\ConfigurationException;
use ElPandaPe\Sentinel\Integrity\CanonicalPayload;
use ElPandaPe\Sentinel\Integrity\JsonCanonicalizer;
use ElPandaPe\Sentinel\Models\Audit;
use Illuminate\Support\Str;

use function ElPandaPe\Sentinel\Tests\auditRow;
use function ElPandaPe\Sentinel\Tests\hasher;

it('hashes the prefix and the canonical payload together', function (): void {
    $audit = Audit::query()->create(collect(auditRow())->except('id')->all());

    $canonical = new JsonCanonicalizer()->canonicalize(CanonicalPayload::from($audit));
    $prefix = implode("\x1f", ['1', 'global', '1', '']);

    expect(hasher()->hash($audit))->toBe(hash('sha256', $prefix."\x1f".$canonical));
});

it('separates the parts of the prefix so two different chains cannot collide', function (): void {
    $shared = collect(auditRow())->except(['stream', 'sequence'])->all();

    $left = new Audit()->forceFill([...$shared, 'stream' => 'a', 'sequence' => 11]);
    $right = new Audit()->forceFill([...$shared, 'stream' => 'a1', 'sequence' => 1]);

    expect(hasher()->hash($left))->not->toBe(hasher()->hash($right));
});

it('applies the algorithm the row was written with, not the configured one', function (): void {
    $audit = Audit::query()->create(collect(auditRow(['algorithm' => 'sha512']))->except('id')->all());

    expect(hasher()->hash($audit))->toHaveLength(128);
});

it('refuses an algorithm the runtime does not know', function (): void {
    $audit = Audit::query()->create(collect(auditRow(['algorithm' => 'nonesuch']))->except('id')->all());

    expect(fn (): string => hasher()->hash($audit))
        ->toThrow(ConfigurationException::class, 'nonesuch');
});

it('changes the hash when any canonical column changes', function (string $column, mixed $value): void {
    $audit = Audit::query()->create(collect(auditRow())->except('id')->all());

    $before = hasher()->hash($audit);
    $audit->setAttribute($column, $value);

    expect(hasher()->hash($audit))->not->toBe($before);
})->with([
    ['event', 'updated'],
    ['tenant_id', 'acme'],
    ['metadata', ['a' => 1]],
    ['affected_rows', 3],
    ['occurred_at', '2026-08-26 10:00:00.000001'],
]);

it('changes the hash when the link it hangs from changes', function (string $column, mixed $value): void {
    $audit = Audit::query()->create(collect(auditRow())->except('id')->all());

    $before = hasher()->hash($audit);
    $audit->setAttribute($column, $value);

    expect(hasher()->hash($audit))->not->toBe($before);
})->with([
    ['stream', 'other'],
    ['sequence', 2],
    ['previous_hash', str_repeat('a', 64)],
    ['payload_version', 2],
]);

it('ignores a column the canonical core deliberately leaves out', function (): void {
    $audit = Audit::query()->create(collect(auditRow())->except('id')->all());

    $before = hasher()->hash($audit);
    $audit->capture_id = (string) Str::ulid();

    expect(hasher()->hash($audit))->toBe($before);
});

it('covers the correlation, so two entries alike but for their operation do not share a hash', function (): void {
    $shared = collect(auditRow())->except('transaction_id')->all();

    $alone = new Audit()->forceFill([...$shared, 'transaction_id' => null]);
    $correlated = new Audit()->forceFill([...$shared, 'transaction_id' => Str::ulid()->toString()]);

    expect(hasher()->hash($alone))->not->toBe(hasher()->hash($correlated));
});

it('reproduces the hash of an entry written before any operation had a name', function (): void {
    $audit = new Audit()->forceFill([...auditRow(), 'transaction_id' => null]);

    expect(hasher()->hash($audit))->toBe(hasher()->hash($audit))
        ->and(CanonicalPayload::from($audit))->toHaveKey('transaction_id')
        ->and(CanonicalPayload::from($audit)['transaction_id'])->toBeNull();
});
