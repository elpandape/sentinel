<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Integrity\Checkpoint;
use ElPandaPe\Sentinel\Models\AuditCheckpoint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function ElPandaPe\Sentinel\Tests\checkpointRow;
use function ElPandaPe\Sentinel\Tests\checkpointsTable;

it('writes to the table the configuration names', function (): void {
    config()->set('sentinel.tables.prefix', 'audit_');

    expect(new AuditCheckpoint()->getTable())->toBe('audit_checkpoints');
});

it('takes an identifier that survives being moved between databases', function (): void {
    $anchor = AuditCheckpoint::query()->create(collect(checkpointRow())->except(['id', 'created_at'])->all());

    expect($anchor->id)->toHaveLength(26)
        ->and(Str::isUlid($anchor->id))->toBeTrue();
});

it('stamps the anchor with the microseconds the schema declares', function (): void {
    $anchor = AuditCheckpoint::query()->create(collect(checkpointRow())->except(['id', 'created_at'])->all());

    expect(AuditCheckpoint::query()->find($anchor->id)?->created_at->format('u'))
        ->toBe($anchor->created_at->format('u'));
});

it('reads a row back as the two ends of a range and the root between them', function (): void {
    DB::table(checkpointsTable())->insert(checkpointRow([
        'sequence_from' => 5,
        'sequence_to' => 8,
        'root_hash' => str_repeat('b', 64),
        'signature' => 'signed',
        'key_id' => 'default',
    ]));

    /** @var AuditCheckpoint $row */
    $row = AuditCheckpoint::query()->firstOrFail();
    $anchor = Checkpoint::of($row);

    expect($anchor->stream)->toBe('global')
        ->and($anchor->from)->toBe(5)
        ->and($anchor->to)->toBe(8)
        ->and($anchor->rootHash)->toBe(str_repeat('b', 64))
        ->and($anchor->algorithm)->toBe('fold-sha256')
        ->and($anchor->signature)->toBe('signed')
        ->and($anchor->keyId)->toBe('default')
        ->and($anchor->createdAt)->toBeInstanceOf(CarbonImmutable::class);
});

it('says which sequences it covers and which it does not', function (int $sequence, bool $covered): void {
    $anchor = new Checkpoint('global', 5, 8, str_repeat('b', 64), 'fold-sha256', null, null, CarbonImmutable::now());

    expect($anchor->covers($sequence))->toBe($covered);
})->with([[4, false], [5, true], [8, true], [9, false]]);

it('counts the entries in its range, both ends included', function (): void {
    $anchor = new Checkpoint('global', 5, 8, str_repeat('b', 64), 'fold-sha256', null, null, CarbonImmutable::now());

    expect($anchor->length())->toBe(4);
});

it('exports everything a verifier needs and nothing it has to hold the trail to read', function (): void {
    $anchor = new Checkpoint(
        'global',
        1,
        4,
        str_repeat('c', 64),
        'fold-sha256',
        'a-signature',
        'default',
        new CarbonImmutable('2026-08-30 09:00:00.123456+00:00'),
    );

    expect($anchor->toArray())->toBe([
        'stream' => 'global',
        'sequence_from' => 1,
        'sequence_to' => 4,
        'root_hash' => str_repeat('c', 64),
        'algorithm' => 'fold-sha256',
        'signature' => 'a-signature',
        'key_id' => 'default',
        'created_at' => '2026-08-30T09:00:00.123456+00:00',
    ]);
});

it('survives the round trip through json', function (): void {
    $anchor = new Checkpoint('global', 1, 4, str_repeat('c', 64), 'fold-sha256', null, null, CarbonImmutable::now());

    expect(json_decode((string) json_encode($anchor->toArray()), true))->toBe($anchor->toArray());
});
