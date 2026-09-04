<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Enums\Source;
use ElPandaPe\Sentinel\Import\Importer;
use ElPandaPe\Sentinel\Ledger\NullLedger;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Tests\Fixtures\OwenItTrail;

use function ElPandaPe\Sentinel\Tests\importing;
use function ElPandaPe\Sentinel\Tests\owenIt;
use function ElPandaPe\Sentinel\Tests\seedForeignTrail;

beforeEach(function (): void {
    seedForeignTrail(OwenItTrail::TABLE, OwenItTrail::rows());
});

it('reads every row of the source and settles the ones it could read', function (): void {
    $report = importing();

    expect($report->read)->toBe(4)
        ->and($report->written)->toBe(3)
        ->and($report->unreadable())->toBe(1)
        ->and($report->balances())->toBeTrue()
        ->and(Audit::query()->count())->toBe(3);
});

it('marks everything it wrote as having come from somewhere else', function (): void {
    importing();

    expect(Audit::query()->pluck('source')->unique()->all())->toBe([Source::Import]);
});

it('keeps the instant the source recorded, not the instant of the import', function (): void {
    importing();

    $entry = Audit::query()->orderBy('sequence')->firstOrFail();

    expect($entry->occurred_at->format('Y-m-d H:i:s'))->toBe('2024-01-02 03:04:05')
        ->and($entry->created_at->year)->toBeGreaterThan(2024);
});

it('groups what it could not read by the reason it could not', function (): void {
    expect(importing()->unmappable)->toHaveCount(1)
        ->and(array_key_first(importing()->unmappable))->toContain('does not say when it happened');
});

it('writes nothing at all when it was only asked what would happen', function (): void {
    $report = importing(rehearse: true);

    expect($report->read)->toBe(4)
        ->and($report->written)->toBe(3)
        ->and($report->rehearsed)->toBeTrue()
        ->and($report->balances())->toBeTrue()
        ->and(Audit::query()->count())->toBe(0);
});

it('finds its own work already done on a second run, and writes none of it again', function (): void {
    importing();
    $second = importing();

    expect($second->read)->toBe(4)
        ->and($second->written)->toBe(0)
        ->and($second->repeated)->toBe(3)
        ->and($second->balances())->toBeTrue()
        ->and(Audit::query()->count())->toBe(3);
});

it('lands the same trail whether it was interrupted or not', function (): void {
    $interrupted = importing(size: 2);
    $resumed = importing(size: 2, after: $interrupted->lastRow);

    $whole = Audit::query()->orderBy('sequence')->pluck('capture_id')->all();

    Audit::query()->delete();
    importing(size: 100);

    expect(Audit::query()->orderBy('sequence')->pluck('capture_id')->all())->toBe($whole)
        ->and($resumed->read)->toBe(0);
});

it('says which row it stopped at, so a run after it can be pointed there', function (): void {
    expect(importing()->lastRow)->toBe('4');
});

it('goes on from the row it was pointed at and reads nothing before it', function (): void {
    $report = importing(after: '2');

    expect($report->read)->toBe(2)
        ->and($report->written)->toBe(1)
        ->and(Audit::query()->count())->toBe(1);
});

it('writes synchronously however the application is configured, and puts the setting back', function (): void {
    config()->set('sentinel.mode', 'queue');

    importing();

    expect(Audit::query()->count())->toBe(3)
        ->and(config('sentinel.mode'))->toBe('queue');
});

it('reads a batch at a time whatever the size, and lands the same trail', function (): void {
    importing(size: 1);

    expect(Audit::query()->count())->toBe(3);
});

it('imports through a ledger that cannot say what it already holds, and says none were repeated', function (): void {
    app()->instance(Ledger::class, app(NullLedger::class));

    $report = app(Importer::class)->import(owenIt(), OwenItTrail::TABLE, null, 2, null, false);

    expect($report->read)->toBe(4)
        ->and($report->repeated)->toBe(0)
        ->and($report->balances())->toBeTrue();
});
