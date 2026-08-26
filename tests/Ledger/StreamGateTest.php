<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Ledger\StreamGate;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Sentinel\Tests\auditsTable;
use function ElPandaPe\Sentinel\Tests\insertAudit;

it('reads the tail of a stream', function (): void {
    insertAudit(['stream' => 'alpha', 'sequence' => 1, 'hash' => str_repeat('a', 64)]);
    insertAudit(['stream' => 'alpha', 'sequence' => 2, 'hash' => str_repeat('b', 64)]);
    insertAudit(['stream' => 'beta', 'sequence' => 9, 'hash' => str_repeat('c', 64)]);

    $tail = new StreamGate(DB::connection(), auditsTable())->tail('alpha');

    expect($tail->sequence)->toBe(2)
        ->and($tail->hash)->toBe(str_repeat('b', 64));
});

it('reports an empty tail for a stream nobody has written to', function (): void {
    $tail = new StreamGate(DB::connection(), auditsTable())->tail('nonesuch');

    expect($tail->sequence)->toBe(0)
        ->and($tail->hash)->toBeNull();
});

it('locks the stream by name on postgresql, where no row lock covers an empty stream', function (): void {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn('pgsql');
    $connection->shouldReceive('statement')
        ->once()
        ->with('select pg_advisory_xact_lock(hashtext(?))', ['alpha']);
    $connection->shouldReceive('table')->andReturn(DB::table(auditsTable()));

    expect(new StreamGate($connection, auditsTable())->tail('alpha')->sequence)->toBe(0);
});

it('takes no advisory lock on the engines that do not have one', function (string $driver): void {
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn($driver);
    $connection->shouldReceive('statement')->never();
    $connection->shouldReceive('table')->andReturn(DB::table(auditsTable()));

    expect(new StreamGate($connection, auditsTable())->tail('alpha')->sequence)->toBe(0);
})->with(['mysql', 'sqlite']);
