<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Console\PruneCommand;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

use function ElPandaPe\Sentinel\Tests\ageEntries;
use function ElPandaPe\Sentinel\Tests\anchor;
use function ElPandaPe\Sentinel\Tests\auditsTable;
use function ElPandaPe\Sentinel\Tests\seedChain;
use function ElPandaPe\Sentinel\Tests\sentinelConfig;

beforeEach(function (): void {
    seedChain(12);
    ageEntries('global', 5, 8, '2020-01-01 00:00:00.000000');
    anchor('global', 4);
    sentinelConfig(['retention' => ['model' => '1 year']]);
});

it('writes a range out when it is told nothing, because that is the action that loses nothing', function (): void {
    Storage::fake('cold');
    sentinelConfig(['ledger.ledgers.archive.disk' => 'cold']);

    $this->artisan('sentinel:prune')
        ->expectsOutputToContain('Wrote out and removed 4 entries')
        ->assertExitCode(Command::SUCCESS);

    expect(Storage::disk('cold')->allFiles())->toHaveCount(1)
        ->and(DB::table(auditsTable())->count())->toBe(8);
});

it('refuses an action it does not have', function (): void {
    $this->artisan('sentinel:prune', ['--action' => 'incinerate'])
        ->expectsOutputToContain('There is no incinerate action')
        ->assertExitCode(Command::INVALID);
});

it('says what it would remove and removes none of it', function (): void {
    $this->artisan('sentinel:prune', ['--action' => 'delete', '--dry-run' => true])
        ->expectsOutputToContain('Would remove 4 entries')
        ->assertExitCode(Command::SUCCESS);

    expect(DB::table(auditsTable())->count())->toBe(12);
});

it('removes what retention released and says how much', function (): void {
    $this->artisan('sentinel:prune', ['--action' => 'delete'])
        ->expectsOutputToContain('Removed 4 entries')
        ->assertExitCode(Command::SUCCESS);

    expect(DB::table(auditsTable())->count())->toBe(8);
});

it('exits zero with the reason when nothing is released', function (): void {
    sentinelConfig(['retention' => ['model' => '900 years']]);

    $this->artisan('sentinel:prune', ['--action' => 'delete'])
        ->expectsOutputToContain('Nothing was removed')
        ->assertExitCode(Command::SUCCESS);
});

it('separates a chain it could not read from one it would not touch', function (): void {
    config()->set('sentinel.ledger.default', 'null');

    $this->artisan('sentinel:prune', ['--action' => 'delete'])
        ->expectsOutputToContain('cannot say which streams it holds')
        ->assertExitCode(Command::INVALID);
});

it('stops and reports when a range no longer folds to the root it recorded', function (): void {
    DB::table(auditsTable())->where('sequence', 6)->update(['hash' => str_repeat('0', 64)]);

    $this->artisan('sentinel:prune', ['--action' => 'delete', '--stream' => 'global'])
        ->assertExitCode(Command::FAILURE);

    expect(DB::table(auditsTable())->count())->toBe(12);
});

it('names the stream it was asked about and no other', function (): void {
    seedChain(4, 'other');

    $this->artisan('sentinel:prune', ['--action' => 'delete', '--stream' => 'global', '--dry-run' => true])
        ->expectsOutputToContain('global')
        ->assertExitCode(Command::SUCCESS);
});

it('reads its description out of the translations', function (): void {
    app()->setLocale('es');

    expect(app(PruneCommand::class)->getDescription())
        ->toBe('Aplica las políticas de retención y reporta lo que se fue');
});

it('removes the same range in the number of statements it is told to', function (): void {
    $this->artisan('sentinel:prune', ['--action' => 'delete', '--batch' => '2'])
        ->expectsOutputToContain('Removed 4 entries')
        ->assertExitCode(Command::SUCCESS);

    expect(DB::table(auditsTable())->count())->toBe(8);
});

it('reports a range that stopped folding even when it was only asked what would happen', function (): void {
    DB::table(auditsTable())->where('sequence', 6)->update(['hash' => str_repeat('0', 64)]);

    $this->artisan('sentinel:prune', ['--action' => 'delete', '--stream' => 'global', '--dry-run' => true])
        ->assertExitCode(Command::FAILURE);

    expect(DB::table(auditsTable())->count())->toBe(12);
});
