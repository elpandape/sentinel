<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Contracts\Deduplicates;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Exceptions\ArchiveException;
use ElPandaPe\Sentinel\Exceptions\ConfigurationException;
use ElPandaPe\Sentinel\Ledger\ArchiveLedger;
use ElPandaPe\Sentinel\Query\AuditQuery;
use Illuminate\Support\Facades\Storage;

use function ElPandaPe\Sentinel\Tests\archiveLedger;
use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\sentinelConfig;

beforeEach(function (): void {
    Storage::fake('cold');
    sentinelConfig(['ledger.ledgers.archive.disk' => 'cold']);
});

it('writes a batch out the moment it fills', function (): void {
    sentinelConfig(['ledger.ledgers.archive.batch' => 2]);
    $ledger = archiveLedger();

    $ledger->write(auditData());
    expect(Storage::disk('cold')->allFiles())->toBeEmpty();

    $ledger->write(auditData());
    expect(Storage::disk('cold')->allFiles())->toHaveCount(1);
});

it('starts a new batch once the one before it has been written out', function (): void {
    sentinelConfig(['ledger.ledgers.archive.batch' => 2]);
    $ledger = archiveLedger();

    foreach (range(1, 4) as $ignored) {
        $ledger->write(auditData());
    }

    expect(Storage::disk('cold')->allFiles())->toHaveCount(2);
});

it('says how many entries it was still holding when it was told to seal', function (): void {
    $ledger = archiveLedger();
    $ledger->write(auditData());
    $ledger->write(auditData());

    expect($ledger->seal())->toBe(2)
        ->and($ledger->seal())->toBe(0);
});

it('makes what it is still holding readable before it answers a read', function (): void {
    $ledger = archiveLedger();
    $ledger->write(auditData());

    expect($ledger->query(new AuditQuery($ledger))->all())->toHaveCount(1)
        ->and(Storage::disk('cold')->allFiles())->toHaveCount(1);
});

it('answers a read out of the file and not out of what it remembers', function (): void {
    $ledger = archiveLedger();
    $written = $ledger->write(auditData());
    $ledger->seal();

    Storage::disk('cold')->delete(Storage::disk('cold')->allFiles()[0]);

    expect(fn (): ?object => $ledger->find($written->id))
        ->toThrow(ArchiveException::class);
});

it('names the streams it holds, including the batch it has not written out yet', function (): void {
    $ledger = archiveLedger();
    $ledger->write(auditData(['stream' => 'beta']));
    $ledger->seal();
    $ledger->write(auditData(['stream' => 'alpha']));

    expect($ledger->streams())->toBe(['alpha', 'beta']);
});

it('does not claim it can say whether a capture has already settled', function (): void {
    expect(archiveLedger())->not->toBeInstanceOf(Deduplicates::class);
});

it('refuses to be the ledger everything writes to', function (): void {
    sentinelConfig(['ledger.default' => 'archive']);

    expect(fn (): Ledger => app(Ledger::class))
        ->toThrow(ConfigurationException::class, 'cannot use the archive driver as');
});

it('takes an entry another ledger sealed, as a destination does', function (): void {
    sentinelConfig(['ledger.ledgers.fanout.destinations' => ['memory', 'archive']]);
    sentinelConfig(['ledger.default' => 'fanout']);

    $written = app(Ledger::class)->write(auditData());

    expect($written->sequence)->toBe(1)
        ->and(app(ArchiveLedger::class)->seal())->toBe(1);
});
