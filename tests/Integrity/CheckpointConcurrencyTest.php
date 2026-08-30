<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Integrity\CheckpointGate;
use ElPandaPe\Sentinel\Tests\Fixtures\ReferenceChain;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Sentinel\Tests\anchor;
use function ElPandaPe\Sentinel\Tests\auditRow;
use function ElPandaPe\Sentinel\Tests\auditsTable;
use function ElPandaPe\Sentinel\Tests\checkpointsTable;
use function ElPandaPe\Sentinel\Tests\lockTimeout;
use function ElPandaPe\Sentinel\Tests\raceTheAnchor;
use function ElPandaPe\Sentinel\Tests\rivalConnection;
use function ElPandaPe\Sentinel\Tests\seedTheReferenceChain;

beforeEach(function (): void {
    $this->rival = rivalConnection();

    if ($this->rival === null) {
        $this->markTestSkipped('A second connection to an in-memory SQLite database is a different database.');
    }

    seedTheReferenceChain();
});

it('leaves neither an overlap nor a hole when a rival emitter races it', function (): void {
    raceTheAnchor($this->rival);

    anchor(ReferenceChain::STREAM, 4);

    $starts = DB::table(checkpointsTable())
        ->where('stream', ReferenceChain::STREAM)
        ->orderBy('sequence_from')
        ->pluck('sequence_from')
        ->all();

    expect($starts)->toBe(array_values(array_unique($starts)))
        ->and(DB::table(checkpointsTable())->where('sequence_from', '<=', 4)->where('sequence_to', '>=', 5)->count())->toBe(0);
});

it('makes a second emitter of the same stream wait for the first', function (): void {
    anchor(ReferenceChain::STREAM, 4);

    DB::beginTransaction();
    new CheckpointGate(DB::connection(), checkpointsTable())->tail(ReferenceChain::STREAM);

    $blocked = false;

    try {
        DB::connection($this->rival)->statement(lockTimeout());
        DB::connection($this->rival)->transaction(function (): void {
            new CheckpointGate(DB::connection($this->rival), checkpointsTable())->tail(ReferenceChain::STREAM);
        });
    } catch (Throwable) {
        $blocked = true;
    } finally {
        DB::rollBack();
    }

    expect($blocked)->toBeTrue();
});

it('lets an emitter of another stream through while one stream is locked', function (): void {
    anchor(ReferenceChain::STREAM, 4);
    anchor(ReferenceChain::FORK, 2);

    DB::beginTransaction();
    new CheckpointGate(DB::connection(), checkpointsTable())->tail(ReferenceChain::STREAM);

    try {
        DB::connection($this->rival)->statement(lockTimeout());
        $tail = DB::connection($this->rival)->transaction(
            fn (): int => new CheckpointGate(DB::connection($this->rival), checkpointsTable())->tail(ReferenceChain::FORK)->sequence,
        );

        expect($tail)->toBe(2);
    } finally {
        DB::rollBack();
    }
});

it('holds the anchors of a stream without holding the writers of it', function (): void {
    anchor(ReferenceChain::STREAM, 4);

    DB::beginTransaction();
    new CheckpointGate(DB::connection(), checkpointsTable())->tail(ReferenceChain::STREAM);

    try {
        DB::connection($this->rival)->statement(lockTimeout());
        DB::connection($this->rival)->table(auditsTable())->insert(auditRow([
            'id' => '01JCHAIN0000000000000000S9',
            'stream' => ReferenceChain::STREAM,
            'sequence' => 9,
        ]));

        expect(DB::connection($this->rival)->table(auditsTable())->where('sequence', 9)->count())->toBe(1);
    } finally {
        DB::rollBack();
    }
});
