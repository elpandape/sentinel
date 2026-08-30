<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Exceptions\ImmutableAuditException;
use ElPandaPe\Sentinel\Models\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

use function ElPandaPe\Sentinel\Tests\auditRelationsTable;
use function ElPandaPe\Sentinel\Tests\auditsTable;
use function ElPandaPe\Sentinel\Tests\auditTagsTable;
use function ElPandaPe\Sentinel\Tests\cascade;
use function ElPandaPe\Sentinel\Tests\seedAudit;
use function ElPandaPe\Sentinel\Tests\sentinelConfig;
use function ElPandaPe\Sentinel\Tests\transactionsTable;

beforeEach(function (): void {
    foreach (range(1, 6) as $sequence) {
        seedAudit($sequence);
    }
});

it('takes the entries of the range and leaves the rest', function (): void {
    $removed = cascade()->purge('global', 2, 4);

    expect($removed->audits)->toBe(3)
        ->and(DB::table(auditsTable())->orderBy('sequence')->pluck('sequence')->all())
        ->toEqual([1, 5, 6]);
});

it('takes the labels hanging off the entries it took, and no others', function (): void {
    DB::table(auditTagsTable())->insert([
        ['audit_id' => str_pad('01JSEED2', 26, '0'), 'tag' => 'billing'],
        ['audit_id' => str_pad('01JSEED5', 26, '0'), 'tag' => 'billing'],
    ]);

    $removed = cascade()->purge('global', 2, 4);

    expect($removed->tags)->toBe(1)
        ->and(DB::table(auditTagsTable())->count())->toBe(1);
});

it('takes the relation lines hanging off the entries it took', function (): void {
    DB::table(auditRelationsTable())->insert([
        ['audit_id' => str_pad('01JSEED3', 26, '0'), 'relation' => 'tags', 'operation' => 'attached'],
        ['audit_id' => str_pad('01JSEED6', 26, '0'), 'relation' => 'tags', 'operation' => 'attached'],
    ]);

    $removed = cascade()->purge('global', 2, 4);

    expect($removed->relations)->toBe(1)
        ->and(DB::table(auditRelationsTable())->count())->toBe(1);
});

it('takes the header of an operation once its last entry is gone', function (): void {
    $operation = str_pad('01JOP', 26, '0');

    DB::table(transactionsTable())->insert([
        'id' => $operation,
        'name' => 'billing.run',
        'started_at' => '2026-08-30 12:00:00.000000',
        'audits_count' => 1,
    ]);

    DB::table(auditsTable())->where('sequence', 3)->update(['transaction_id' => $operation]);

    $removed = cascade()->purge('global', 2, 4);

    expect($removed->transactions)->toBe(1)
        ->and(DB::table(transactionsTable())->count())->toBe(0);
});

it('keeps the header of an operation that still has an entry somewhere else', function (): void {
    $operation = str_pad('01JOP', 26, '0');

    DB::table(transactionsTable())->insert([
        'id' => $operation,
        'name' => 'billing.run',
        'started_at' => '2026-08-30 12:00:00.000000',
        'audits_count' => 2,
    ]);

    DB::table(auditsTable())->whereIn('sequence', [3, 6])->update(['transaction_id' => $operation]);

    $removed = cascade()->purge('global', 2, 4);

    expect($removed->transactions)->toBe(0)
        ->and(DB::table(transactionsTable())->count())->toBe(1);
});

it('leaves the count of what an operation captured alone', function (): void {
    $operation = str_pad('01JOP', 26, '0');

    DB::table(transactionsTable())->insert([
        'id' => $operation,
        'name' => 'billing.run',
        'started_at' => '2026-08-30 12:00:00.000000',
        'audits_count' => 2,
    ]);

    DB::table(auditsTable())->whereIn('sequence', [3, 6])->update(['transaction_id' => $operation]);

    cascade()->purge('global', 2, 4);

    expect(DB::table(transactionsTable())->value('audits_count'))->toEqual(2);
});

it('counts what a run would take without taking any of it', function (): void {
    DB::table(auditTagsTable())->insert([['audit_id' => str_pad('01JSEED2', 26, '0'), 'tag' => 'billing']]);

    $counted = cascade()->count('global', 2, 4);

    expect($counted->audits)->toBe(3)
        ->and($counted->tags)->toBe(1)
        ->and(DB::table(auditsTable())->count())->toBe(6);
});

it('removes a range wider than one batch in as many statements as it takes', function (): void {
    sentinelConfig(['prune.batch' => 2]);

    $removed = cascade()->purge('global', 1, 6);

    expect($removed->audits)->toBe(6)
        ->and(DB::table(auditsTable())->count())->toBe(0);
});

it('waits between batches when it is asked to', function (): void {
    Sleep::fake();
    sentinelConfig(['prune.batch' => 2, 'prune.pause' => 1000]);

    cascade()->purge('global', 1, 6);

    Sleep::assertSleptTimes(3);
});

it('leaves the entry immutable through the model it always was', function (): void {
    $audit = Audit::query()->firstOrFail();

    expect(fn (): ?bool => $audit->delete())->toThrow(ImmutableAuditException::class);
});
