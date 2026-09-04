<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Console\PartitionsCommand;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Sentinel\Tests\auditsTable;
use function ElPandaPe\Sentinel\Tests\dividingDatabase;
use function ElPandaPe\Sentinel\Tests\divisionForThisEngine;
use function ElPandaPe\Sentinel\Tests\partitionCount;
use function ElPandaPe\Sentinel\Tests\partitionTheTrail;
use function ElPandaPe\Sentinel\Tests\seedChain;
use function ElPandaPe\Sentinel\Tests\sentinelConfig;

it('says an undivided table had nothing to maintain, and exits zero doing it', function (): void {
    $this->artisan('sentinel:partitions')
        ->expectsOutputToContain('is not partitioned')
        ->assertExitCode(Command::SUCCESS);
});

it('refuses a table it does not maintain', function (): void {
    $this->artisan('sentinel:partitions', ['--table' => 'checkpoints'])
        ->expectsOutputToContain('not one this command maintains')
        ->assertExitCode(Command::INVALID);
});

it('refuses a retention period it cannot read, rather than guessing at one', function (): void {
    $this->artisan('sentinel:partitions', ['--retire' => 'next tuesday'])
        ->expectsOutputToContain('Nothing was maintained')
        ->assertExitCode(Command::INVALID);
});

it('reads its description out of the translations', function (): void {
    app()->setLocale('es');

    expect(app(PartitionsCommand::class)->getDescription())
        ->toBe('Mantiene un rastro particionado con meses por delante y sin las particiones vacías de atrás');
});

it('creates the months ahead and creates nothing on a second run', function (): void {
    partitionTheTrail(divisionForThisEngine() ?? '');

    $this->artisan('sentinel:partitions', ['--ahead' => '6'])->assertExitCode(Command::SUCCESS);

    $this->artisan('sentinel:partitions', ['--ahead' => '6'])
        ->expectsOutputToContain('Nothing to do')
        ->assertExitCode(Command::SUCCESS);
})->skip(
    fn (): bool => divisionForThisEngine() === null,
    'SQLite does not partition, so there is nothing for the command to maintain.',
);

it('changes nothing when it is asked what it would do', function (): void {
    partitionTheTrail(divisionForThisEngine() ?? '');

    $before = partitionCount();

    $this->artisan('sentinel:partitions', ['--ahead' => '9', '--dry-run' => true])->assertExitCode(Command::SUCCESS);

    expect(partitionCount())->toBe($before);
})->skip(
    fn (): bool => divisionForThisEngine() === null,
    'SQLite does not partition, so there is nothing for the command to maintain.',
);

it('retires a month that ended behind the cutoff once it holds nothing', function (): void {
    partitionTheTrail(divisionForThisEngine() ?? '');

    $before = partitionCount();
    CarbonImmutable::setTestNow('2027-06-15 09:00:00');

    $this->artisan('sentinel:partitions', ['--ahead' => '0', '--retire' => '3 months'])
        ->expectsOutputToContain('retired')
        ->assertExitCode(Command::SUCCESS);

    expect(partitionCount())->toBeLessThan($before);
})->skip(
    fn (): bool => divisionForThisEngine() === null,
    'SQLite does not partition, so there is nothing for the command to maintain.',
);

it('refuses to retire a partition that still holds entries under compliance', function (): void {
    partitionTheTrail(divisionForThisEngine() ?? '');
    seedChain(3);

    sentinelConfig(['compliance' => true]);
    CarbonImmutable::setTestNow('2027-06-15 09:00:00');

    $this->artisan('sentinel:partitions', ['--ahead' => '0', '--retire' => '3 months', '--force' => true])
        ->expectsOutputToContain('Refused to retire')
        ->assertExitCode(Command::FAILURE);

    expect(DB::table(auditsTable())->count())->toBe(3);
})->skip(
    fn (): bool => divisionForThisEngine() === null,
    'SQLite does not partition, so there is nothing for the command to maintain.',
);

/**
 * The report itself, over a connection that answers as a dividing engine. `make ci` runs on SQLite,
 * where no table is ever divided, so this is the only place the rendering of a real run is read.
 */
it('reports what it created and what it left behind', function (): void {
    dividingDatabase([
        (object) ['name' => 'sentinel_audits_p2026_09'],
        (object) ['name' => 'sentinel_audits_p2020_01'],
    ], entries: 4);

    CarbonImmutable::setTestNow('2026-09-14 09:00:00');

    $this->artisan('sentinel:partitions', ['--ahead' => '1', '--retire' => '1 year'])
        ->expectsOutputToContain('sentinel_audits_p2026_10')
        ->expectsOutputToContain('Still holds entries')
        ->assertExitCode(Command::FAILURE);
});

it('summarises a run that had nothing left to do', function (): void {
    dividingDatabase([
        (object) ['name' => 'sentinel_audits_p2026_09'],
        (object) ['name' => 'sentinel_audits_p2026_10'],
    ]);

    CarbonImmutable::setTestNow('2026-09-14 09:00:00');

    $this->artisan('sentinel:partitions', ['--ahead' => '1'])
        ->expectsOutputToContain('Nothing to do')
        ->assertExitCode(Command::SUCCESS);
});

it('counts what it would have done without touching anything', function (): void {
    dividingDatabase([(object) ['name' => 'sentinel_audits_p2026_09']]);

    CarbonImmutable::setTestNow('2026-09-14 09:00:00');

    $this->artisan('sentinel:partitions', ['--ahead' => '2', '--dry-run' => true])
        ->expectsOutputToContain('Would create 2')
        ->assertExitCode(Command::SUCCESS);
});

it('reports a partition it would refuse to retire even when it was only asked what would happen', function (): void {
    partitionTheTrail(divisionForThisEngine() ?? '');
    seedChain(3);

    sentinelConfig(['compliance' => true]);
    CarbonImmutable::setTestNow('2027-06-15 09:00:00');

    $this->artisan('sentinel:partitions', ['--ahead' => '0', '--retire' => '3 months', '--force' => true, '--dry-run' => true])
        ->expectsOutputToContain('Refused to retire')
        ->assertExitCode(Command::FAILURE);

    expect(DB::table(auditsTable())->count())->toBe(3);
})->skip(
    fn (): bool => divisionForThisEngine() === null,
    'SQLite does not partition, so there is nothing for the command to maintain.',
);
