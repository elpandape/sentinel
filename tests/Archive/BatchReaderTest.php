<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Archive\ArchiveBatch;
use ElPandaPe\Sentinel\Exceptions\ArchiveException;
use ElPandaPe\Sentinel\Models\AuditTag;
use Illuminate\Support\Facades\Storage;

use function ElPandaPe\Sentinel\Tests\archivedBatch;
use function ElPandaPe\Sentinel\Tests\batchReader;
use function ElPandaPe\Sentinel\Tests\hasher;
use function ElPandaPe\Sentinel\Tests\sentinelConfig;

beforeEach(function (): void {
    Storage::fake('cold');
    sentinelConfig(['ledger.ledgers.archive.disk' => 'cold']);
});

it('gives every entry back with the sequence, the hash and the link it went in with', function (): void {
    $batch = archivedBatch();

    $read = batchReader()->read($batch);

    expect($read)->toHaveCount(2)
        ->and(array_map(fn ($entry): int => $entry->sequence, $read))->toBe([1, 2])
        ->and($read[1]->previous_hash)->toBe($read[0]->hash)
        ->and(hasher()->hash($read[0]))->toBe($read[0]->hash);
});

it('gives an entry back carrying the labels it was written with', function (): void {
    $batch = archivedBatch(1, ['tags' => ['billing', 'refund']]);

    expect(batchReader()->read($batch)[0]->tags->map(fn (AuditTag $tag): string => $tag->tag)->all())
        ->toBe(['billing', 'refund']);
});

it('refuses a batch whose bytes no longer digest to what was recorded', function (): void {
    $batch = archivedBatch();

    Storage::disk('cold')->put($batch->path, 'tampered');

    expect(fn (): array => batchReader()->read($batch))
        ->toThrow(ArchiveException::class, 'did not come back as the bytes');
});

it('refuses a batch that is not where its row says it is', function (): void {
    $batch = archivedBatch();

    Storage::disk('cold')->delete($batch->path);

    expect(fn (): array => batchReader()->read($batch))
        ->toThrow(ArchiveException::class, 'could not be read back');
});

it('refuses a batch that holds fewer entries than its row claims', function (): void {
    $batch = archivedBatch();

    $lying = new ArchiveBatch(
        $batch->stream, $batch->from, $batch->to, 5,
        $batch->disk, $batch->path, $batch->checksum, $batch->codec,
    );

    expect(fn (): array => batchReader()->read($lying))
        ->toThrow(ArchiveException::class, 'is recorded as holding 5 entries and holds 2');
});

it('refuses a batch whose entries do not start where its row says the range does', function (): void {
    $batch = archivedBatch();

    $lying = new ArchiveBatch(
        $batch->stream, 7, 8, $batch->records,
        $batch->disk, $batch->path, $batch->checksum, $batch->codec,
    );

    expect(fn (): array => batchReader()->read($lying))
        ->toThrow(ArchiveException::class, 'is missing sequence 7');
});

it('reads a batch from the disk its row names and not from the one now configured', function (): void {
    Storage::fake('elsewhere');
    $batch = archivedBatch();

    sentinelConfig(['ledger.ledgers.archive.disk' => 'elsewhere']);

    expect(batchReader()->read($batch))->toHaveCount(2);
});

it('reads a batch written while compression was on after it has been switched off', function (): void {
    $batch = archivedBatch();

    sentinelConfig(['ledger.ledgers.archive.codec' => null]);

    expect(batchReader()->read($batch))->toHaveCount(2);
});
