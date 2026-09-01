<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Storage;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\batchWriter;
use function ElPandaPe\Sentinel\Tests\ledger;
use function ElPandaPe\Sentinel\Tests\manifest;
use function ElPandaPe\Sentinel\Tests\sentinelConfig;

it('writes down the range it retired', function (): void {
    $archive = manifest()->retired('global', 1, 1000, 1000);

    expect($archive->stream)->toBe('global')
        ->and($archive->sequence_from)->toBe(1)
        ->and($archive->sequence_to)->toBe(1000)
        ->and($archive->records)->toBe(1000)
        ->and($archive->disk)->toBeNull()
        ->and($archive->checksum)->toBeNull();
});

it('explains nothing about a stream it has never been told about', function (): void {
    expect(manifest()->claim('global', 1)->reaches)->toBe(0)
        ->and(manifest()->claim('global', 1)->archiveId)->toBeNull();
});

it('explains the range it was told about', function (): void {
    $archive = manifest()->retired('global', 1, 1000, 1000);

    $claim = manifest()->claim('global', 1);

    expect($claim->reaches)->toBe(1000)
        ->and($claim->archiveId)->toBe($archive->id)
        ->and($claim->explains(1000))->toBeTrue()
        ->and($claim->explains(1001))->toBeFalse();
});

it('carries an explanation across two ranges that meet', function (): void {
    manifest()->retired('global', 1, 1000, 1000);
    manifest()->retired('global', 1001, 2000, 1000);

    expect(manifest()->claim('global', 1)->reaches)->toBe(2000);
});

it('stops at the first sequence no range accounts for', function (): void {
    manifest()->retired('global', 1, 1000, 1000);
    manifest()->retired('global', 1002, 2000, 999);

    expect(manifest()->claim('global', 1)->reaches)->toBe(1000);
});

it('reads two ranges that overlap as the one span they cover', function (): void {
    manifest()->retired('global', 1, 1000, 1000);
    manifest()->retired('global', 500, 1500, 1001);

    expect(manifest()->claim('global', 1)->reaches)->toBe(1500);
});

it('is not thrown off by a range that ends before the question starts', function (): void {
    manifest()->retired('global', 1, 100, 100);
    manifest()->retired('global', 501, 600, 100);

    expect(manifest()->claim('global', 501)->reaches)->toBe(600);
});

it('keeps one stream out of another stream answer', function (): void {
    manifest()->retired('tenant:acme', 1, 1000, 1000);

    expect(manifest()->claim('global', 1)->reaches)->toBe(0);
});

it('says whether a whole range is accounted for', function (): void {
    manifest()->retired('global', 1, 1000, 1000);

    expect(manifest()->holds('global', 1, 1000))->toBeTrue()
        ->and(manifest()->holds('global', 1, 1001))->toBeFalse();
});

it('does not let a later range explain an absence in front of it', function (): void {
    manifest()->retired('global', 500, 1000, 501);

    expect(manifest()->claim('global', 1)->reaches)->toBe(0);
});

it('gives back the batches that hold part of a range, oldest first', function (): void {
    Storage::fake('cold');
    sentinelConfig(['ledger.ledgers.archive.disk' => 'cold']);

    $second = batchWriter()->write('global', 3, 4, [ledger()->write(auditData())], [], '2026-08-31 10:00:00.000000');
    $first = batchWriter()->write('global', 1, 2, [ledger()->write(auditData())], [], '2026-08-31 10:00:00.000000');

    manifest()->archived($second);
    manifest()->archived($first);

    expect(array_map(fn ($batch): int => $batch->from, manifest()->batchesIn('global', 1, 4)))->toBe([1, 3]);
});

it('skips a range that was retired without being written anywhere', function (): void {
    manifest()->retired('global', 1, 4, 4);

    expect(manifest()->batchesIn('global', 1, 4))->toBeEmpty();
});

it('stops at a range that starts past the one it was asked about', function (): void {
    Storage::fake('cold');
    sentinelConfig(['ledger.ledgers.archive.disk' => 'cold']);

    manifest()->archived(batchWriter()->write('global', 9, 10, [ledger()->write(auditData())], [], '2026-08-31 10:00:00.000000'));

    expect(manifest()->batchesIn('global', 1, 4))->toBeEmpty();
});
