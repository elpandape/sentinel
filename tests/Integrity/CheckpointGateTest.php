<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Integrity\CheckpointGate;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Sentinel\Tests\checkpointRow;
use function ElPandaPe\Sentinel\Tests\checkpointsTable;
use function ElPandaPe\Sentinel\Tests\frozenUlid;

it('reads where the anchors of a stream end, and what the last of them folded to', function (): void {
    DB::table(checkpointsTable())->insert(checkpointRow(['stream' => 'alpha']));
    DB::table(checkpointsTable())->insert(checkpointRow([
        'id' => frozenUlid('NEXT'),
        'stream' => 'alpha',
        'sequence_from' => 5,
        'sequence_to' => 8,
        'root_hash' => str_repeat('b', 64),
    ]));
    DB::table(checkpointsTable())->insert(checkpointRow([
        'id' => frozenUlid('BETA'),
        'stream' => 'beta',
        'sequence_from' => 1,
        'sequence_to' => 40,
    ]));

    $tail = new CheckpointGate(DB::connection(), checkpointsTable())->tail('alpha');

    expect($tail->sequence)->toBe(8)
        ->and($tail->root)->toBe(str_repeat('b', 64));
});

it('reports an empty tail for a stream nobody has anchored', function (): void {
    $tail = new CheckpointGate(DB::connection(), checkpointsTable())->tail('nonesuch');

    expect($tail->sequence)->toBe(0)
        ->and($tail->root)->toBeNull();
});

it('locks the anchors by name on postgresql, and never under the name the writers lock', function (): void {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn('pgsql');
    $connection->shouldReceive('statement')
        ->once()
        ->with('select pg_advisory_xact_lock(hashtext(?))', ['checkpoint:alpha']);
    $connection->shouldReceive('table')->andReturn(DB::table(checkpointsTable()));

    expect(new CheckpointGate($connection, checkpointsTable())->tail('alpha')->sequence)->toBe(0);
});

it('takes no advisory lock on the engines that do not have one', function (string $driver): void {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn($driver);
    $connection->shouldReceive('statement')->never();
    $connection->shouldReceive('table')->andReturn(DB::table(checkpointsTable()));

    expect(new CheckpointGate($connection, checkpointsTable())->tail('alpha')->sequence)->toBe(0);
})->with(['mysql', 'sqlite']);
