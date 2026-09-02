<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Compliance\Requirements;
use ElPandaPe\Sentinel\Enums\PruneAction;
use ElPandaPe\Sentinel\Exceptions\ComplianceException;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Support\Reference;
use Illuminate\Support\Facades\Storage;

use function ElPandaPe\Sentinel\Tests\anchor;
use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\frontiers;
use function ElPandaPe\Sentinel\Tests\ledger;
use function ElPandaPe\Sentinel\Tests\pruner;
use function ElPandaPe\Sentinel\Tests\redactor;
use function ElPandaPe\Sentinel\Tests\sentinelConfig;

$later = new CarbonImmutable('2026-09-30 12:00:00');

it('lets a configuration that is not in compliance mode boot however it likes', function (): void {
    sentinelConfig(['compliance' => false, 'integrity.signature.enabled' => false]);

    app(Requirements::class)->enforce();
})->throwsNoExceptions();

it('refuses to boot in compliance mode without signatures', function (): void {
    sentinelConfig([
        'compliance' => true,
        'integrity.signature.enabled' => false,
        'integrity.checkpoints.enabled' => true,
    ]);

    expect(fn (): mixed => app(Requirements::class)->enforce())
        ->toThrow(ComplianceException::class, 'integrity.signature.enabled');
});

it('refuses to boot in compliance mode without anchors', function (): void {
    sentinelConfig([
        'compliance' => true,
        'integrity.signature.enabled' => true,
        'integrity.checkpoints.enabled' => false,
    ]);

    expect(fn (): mixed => app(Requirements::class)->enforce())
        ->toThrow(ComplianceException::class, 'integrity.checkpoints.enabled');
});

it('names every switch that is missing, not the first one', function (): void {
    sentinelConfig([
        'compliance' => true,
        'integrity.signature.enabled' => false,
        'integrity.checkpoints.enabled' => false,
    ]);

    expect(fn (): mixed => app(Requirements::class)->enforce())
        ->toThrow(ComplianceException::class, 'integrity.signature.enabled, integrity.checkpoints.enabled');
});

it('boots in compliance mode once both are on', function (): void {
    sentinelConfig([
        'compliance' => true,
        'integrity.signature.enabled' => true,
        'integrity.checkpoints.enabled' => true,
    ]);

    app(Requirements::class)->enforce();
})->throwsNoExceptions();

it('refuses a redaction that names nobody while in compliance mode', function (): void {
    $written = ledger()->write(auditData(['before' => ['a' => 1]]));

    sentinelConfig(['compliance' => true]);

    expect(fn (): mixed => redactor()->redact($written, 'erasure request'))
        ->toThrow(ComplianceException::class, 'has to name who ordered it')
        ->and(Audit::query()->findOrFail($written->id)->before)->toBe(['a' => 1]);
});

it('takes the same redaction once it names an actor', function (): void {
    $written = ledger()->write(auditData(['before' => ['a' => 1]]));

    sentinelConfig(['compliance' => true]);

    redactor()->redact($written, 'erasure request', new Reference('member', '77'));

    expect(Audit::query()->findOrFail($written->id)->before)->toBeNull();
});

it('refuses to delete a range nothing archived while in compliance mode', function () use ($later): void {
    foreach (range(1, 8) as $ignored) {
        ledger()->write(auditData(['before' => ['a' => 1]]));
    }

    anchor('global', 4);
    sentinelConfig(['compliance' => true]);

    expect(fn (): mixed => pruner()->prune(
        frontiers(['model' => '1 day'])->of('global', $later),
        PruneAction::Delete,
        false,
    ))->toThrow(ComplianceException::class, 'deleted only after it has been archived')
        ->and(Audit::query()->count())->toBe(8);
});

it('deletes a range that was archived first, even in compliance mode', function () use ($later): void {
    Storage::fake('cold');
    sentinelConfig(['ledger.ledgers.archive.disk' => 'cold']);

    foreach (range(1, 8) as $ignored) {
        ledger()->write(auditData(['before' => ['a' => 1]]));
    }

    anchor('global', 4);
    pruner()->prune(frontiers(['model' => '1 day'])->of('global', $later), PruneAction::Archive, false);

    app(ElPandaPe\Sentinel\Archive\Rehydrator::class)->restore('global', 1, 4);
    sentinelConfig(['compliance' => true]);

    pruner()->prune(frontiers(['model' => '1 day'])->of('global', $later), PruneAction::Delete, false);

    expect(Audit::query()->where('sequence', 2)->exists())->toBeFalse();
});

it('leaves a delete alone when compliance mode is off', function () use ($later): void {
    foreach (range(1, 8) as $ignored) {
        ledger()->write(auditData(['before' => ['a' => 1]]));
    }

    anchor('global', 4);

    pruner()->prune(frontiers(['model' => '1 day'])->of('global', $later), PruneAction::Delete, false);

    expect(Audit::query()->where('sequence', 2)->exists())->toBeFalse();
});
