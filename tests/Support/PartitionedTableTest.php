<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Support\PartitionedTable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;

it('pins the clause to the statement that creates the table and to no other', function (): void {
    $statements = new PartitionedTable()->statements(
        DB::connection(),
        'fixture_partitioned',
        static function (Blueprint $table): void {
            $table->char('id', 26);
            $table->dateTime('created_at', 6);
            $table->index('created_at');
        },
        'partition by range (created_at)',
    );

    expect($statements)->toHaveCount(2)
        ->and($statements[0])->toStartWith('create table')
        ->and($statements[0])->toEndWith('partition by range (created_at)')
        ->and($statements[1])->not->toContain('partition by');
});

it('leaves the statements alone when there is no clause to pin', function (): void {
    $statements = new PartitionedTable()->statements(
        DB::connection(),
        'fixture_partitioned',
        static fn (Blueprint $table) => $table->char('id', 26),
        '',
    );

    expect($statements[0])->toEndWith(') ');
});
