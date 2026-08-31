<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Enums\FanoutPolicy;
use ElPandaPe\Sentinel\Events\LedgerDestinationFailed;
use ElPandaPe\Sentinel\Exceptions\ConfigurationException;
use ElPandaPe\Sentinel\Ledger\ArchiveLedger;
use ElPandaPe\Sentinel\Ledger\DatabaseLedger;
use ElPandaPe\Sentinel\Ledger\FanoutLedger;
use ElPandaPe\Sentinel\Models\AuditArchive;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

use function ElPandaPe\Sentinel\Tests\archiveLedger;
use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\auditsTable;
use function ElPandaPe\Sentinel\Tests\sentinelConfig;

beforeEach(function (): void {
    Storage::fake('cold');
    sentinelConfig([
        'ledger.default' => 'fanout',
        'ledger.ledgers.archive.disk' => 'cold',
        'ledger.ledgers.fanout.destinations' => ['database', 'archive'],
    ]);
});

it('writes one entry to the hot table and to cold storage at once', function (): void {
    $written = app(Ledger::class)->write(auditData());
    archiveLedger()->seal();

    expect(DB::table(auditsTable())->count())->toBe(1)
        ->and(Storage::disk('cold')->allFiles())->toHaveCount(1)
        ->and($written->sequence)->toBe(1);
});

it('lets the primary be the only one that numbers the chain', function (): void {
    $ledger = app(Ledger::class);

    $first = $ledger->write(auditData());
    $second = $ledger->write(auditData());

    expect([$first->sequence, $second->sequence])->toBe([1, 2])
        ->and($second->previous_hash)->toBe($first->hash);
});

it('keeps a cold destination out of the manifest, so the purge keeps its guard', function (): void {
    app(Ledger::class)->write(auditData());
    archiveLedger()->seal();

    expect(AuditArchive::query()->count())->toBe(0);
});

it('fails the write under strict when the disk refuses the batch', function (): void {
    sentinelConfig([
        'ledger.ledgers.archive.batch' => 1,
        'ledger.ledgers.archive.path' => str_repeat('deep/', 120),
    ]);

    expect(fn (): mixed => app(Ledger::class)->write(auditData()))
        ->toThrow(ConfigurationException::class);
});

it('lets the write settle under primary and says which destination refused it', function (): void {
    Event::fake([LedgerDestinationFailed::class]);

    sentinelConfig([
        'ledger.ledgers.archive.batch' => 1,
        'ledger.ledgers.archive.path' => str_repeat('deep/', 120),
        'ledger.ledgers.fanout.on_failure' => FanoutPolicy::Primary->value,
    ]);

    $written = app(Ledger::class)->write(auditData());

    expect($written->sequence)->toBe(1);
    Event::assertDispatched(LedgerDestinationFailed::class);
});

it('writes out the batch a request was still filling when it ends', function (): void {
    app(Ledger::class)->write(auditData());

    expect(Storage::disk('cold')->allFiles())->toBeEmpty();

    app()->terminate();

    expect(Storage::disk('cold')->allFiles())->toHaveCount(1);
});

it('builds no cold ledger on the way out of a request that never archived anything', function (): void {
    sentinelConfig(['ledger.default' => 'database']);

    app()->terminate();

    expect(app()->resolved(ArchiveLedger::class))->toBeFalse();
});

it('writes out the batch a worker was still filling when it stops', function (): void {
    app(Ledger::class)->write(auditData());

    app(Dispatcher::class)->dispatch(new WorkerStopping);

    expect(Storage::disk('cold')->allFiles())->toHaveCount(1);
});

it('composes the hot and the cold ledger the configuration names', function (): void {
    $ledger = app(Ledger::class);

    expect($ledger)->toBeInstanceOf(FanoutLedger::class);

    $ledger->write(auditData());

    expect(app(DatabaseLedger::class)->streams())->toBe(['global']);
});
