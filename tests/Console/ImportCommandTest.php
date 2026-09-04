<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Console\ImportCommand;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Tests\Fixtures\AltekTrail;
use ElPandaPe\Sentinel\Tests\Fixtures\OwenItTrail;
use Illuminate\Console\Command;

use function ElPandaPe\Sentinel\Tests\seedAltekTrail;
use function ElPandaPe\Sentinel\Tests\seedForeignTrail;

it('brings the trail across and says the chain starts here', function (): void {
    seedForeignTrail(OwenItTrail::TABLE, OwenItTrail::rows());

    $this->artisan('sentinel:import', ['--from' => 'owenit'])
        ->expectsOutputToContain('The chain starts here')
        ->assertExitCode(Command::FAILURE);

    expect(Audit::query()->count())->toBe(3);
});

it('exits sound when every row of the source came across', function (): void {
    seedForeignTrail(OwenItTrail::TABLE, array_slice(OwenItTrail::rows(), 0, 3));

    $this->artisan('sentinel:import', ['--from' => 'owenit'])->assertExitCode(Command::SUCCESS);

    expect(Audit::query()->count())->toBe(3);
});

it('reads the other package too, under its own table name', function (): void {
    seedAltekTrail(AltekTrail::TABLE, AltekTrail::rows());

    $this->artisan('sentinel:import', ['--from' => 'altek'])->assertExitCode(Command::FAILURE);

    expect(Audit::query()->count())->toBe(2);
});

it('writes nothing when it was only asked what would happen', function (): void {
    seedForeignTrail(OwenItTrail::TABLE, array_slice(OwenItTrail::rows(), 0, 3));

    $this->artisan('sentinel:import', ['--from' => 'owenit', '--dry-run' => true])
        ->expectsOutputToContain('Nothing was written')
        ->assertExitCode(Command::SUCCESS);

    expect(Audit::query()->count())->toBe(0);
});

it('says what could not be read and why, without stopping for it', function (): void {
    seedForeignTrail(OwenItTrail::TABLE, OwenItTrail::rows());

    $this->artisan('sentinel:import', ['--from' => 'owenit'])
        ->expectsOutputToContain('1 could not be read because')
        ->assertExitCode(Command::FAILURE);
});

it('says which row to carry on from', function (): void {
    seedForeignTrail(OwenItTrail::TABLE, OwenItTrail::rows());

    $this->artisan('sentinel:import', ['--from' => 'owenit'])
        ->expectsOutputToContain('--after=4')
        ->assertExitCode(Command::FAILURE);
});

it('carries on from the row it was pointed at', function (): void {
    seedForeignTrail(OwenItTrail::TABLE, OwenItTrail::rows());

    $this->artisan('sentinel:import', ['--from' => 'owenit', '--after' => '2'])
        ->assertExitCode(Command::FAILURE);

    expect(Audit::query()->count())->toBe(1);
});

it('refuses a package it does not read, and names the ones it does', function (): void {
    $this->artisan('sentinel:import', ['--from' => 'revisionable'])
        ->expectsOutputToContain('owenit, altek')
        ->assertExitCode(Command::INVALID);
});

it('refuses to run with no package named at all', function (): void {
    $this->artisan('sentinel:import')->assertExitCode(Command::INVALID);
});

it('refuses a table that is not shaped like the history it was told to expect', function (): void {
    seedAltekTrail(AltekTrail::TABLE, AltekTrail::rows());

    $this->artisan('sentinel:import', ['--from' => 'owenit', '--table' => AltekTrail::TABLE])
        ->expectsOutputToContain('Nothing was imported')
        ->assertExitCode(Command::INVALID);

    expect(Audit::query()->count())->toBe(0);
});

it('reads the actor columns under the prefix it was given', function (): void {
    seedForeignTrail(OwenItTrail::TABLE, array_map(static function (array $row): array {
        $row['operator_type'] = $row['user_type'];
        $row['operator_id'] = $row['user_id'];
        unset($row['user_type'], $row['user_id']);

        return $row;
    }, array_slice(OwenItTrail::rows(), 0, 3)), 'operator');

    $this->artisan('sentinel:import', ['--from' => 'owenit', '--actor' => 'operator'])
        ->assertExitCode(Command::SUCCESS);

    expect(Audit::query()->orderBy('sequence')->firstOrFail()->actor_id)->toBe('7');
});

it('reads its description out of the translations', function (): void {
    app()->setLocale('es');

    expect(app(ImportCommand::class)->getDescription())
        ->toBe('Trae un rastro desde otro paquete de auditoría');
});
