<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Archive\Batch;
use ElPandaPe\Sentinel\Exceptions\ArchiveException;
use ElPandaPe\Sentinel\Exceptions\ConfigurationException;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Models\AuditTransaction;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\batchWriter;
use function ElPandaPe\Sentinel\Tests\hasher;
use function ElPandaPe\Sentinel\Tests\ledger;
use function ElPandaPe\Sentinel\Tests\sentinelConfig;
use function ElPandaPe\Sentinel\Tests\writerOverDisk;

beforeEach(function (): void {
    Storage::fake('cold');
    sentinelConfig(['ledger.ledgers.archive.disk' => 'cold']);
});

it('writes one file holding a header line and a line per entry', function (): void {
    $entries = [ledger()->write(auditData()), ledger()->write(auditData())];

    $batch = batchWriter()->write('global', 1, 2, $entries, [], '2026-08-31 10:00:00.000000');

    expect(Storage::disk('cold')->exists($batch->path))->toBeTrue()
        ->and($batch->records)->toBe(2)
        ->and(iterator_to_array(Batch::entriesIn(gzdecode(Storage::disk('cold')->get($batch->path)))))->toHaveCount(2);
});

it('digests the exact bytes it wrote, and says which digest made the checksum', function (): void {
    $batch = batchWriter()->write('global', 1, 1, [ledger()->write(auditData())], [], '2026-08-31 10:00:00.000000');

    expect($batch->checksum)->toStartWith('sha256:')
        ->and($batch->checksum)->toBe('sha256:'.hash('sha256', Storage::disk('cold')->get($batch->path)));
});

it('names a batch after the range it holds, so a listing sorts the way the chain does', function (): void {
    $first = batchWriter()->write('global', 1, 2, [ledger()->write(auditData())], [], '2026-08-31 10:00:00.000000');
    $second = batchWriter()->write('global', 11, 12, [ledger()->write(auditData())], [], '2026-08-31 10:00:00.000000');

    expect([$first->path, $second->path])->toBe([$first->path, $second->path])
        ->and(min($first->path, $second->path))->toBe($first->path);
});

it('keeps two streams that slug alike in two different places', function (): void {
    $entry = ledger()->write(auditData());

    $one = batchWriter()->write('tenant:acme', 1, 1, [$entry], [], '2026-08-31 10:00:00.000000');
    $other = batchWriter()->write('tenant-acme', 1, 1, [$entry], [], '2026-08-31 10:00:00.000000');

    expect($one->path)->not->toBe($other->path);
});

it('writes a batch in the clear when no codec is named', function (): void {
    sentinelConfig(['ledger.ledgers.archive.codec' => null]);

    $batch = batchWriter()->write('global', 1, 1, [ledger()->write(auditData())], [], '2026-08-31 10:00:00.000000');

    expect($batch->codec)->toBeNull()
        ->and($batch->path)->not->toEndWith('.gz')
        ->and(Storage::disk('cold')->get($batch->path))->toContain('"kind":"entry"');
});

it('refuses to hand back a batch the disk would not take', function (): void {
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('put')->andReturnFalse();

    expect(fn (): mixed => writerOverDisk($disk)->write('global', 1, 1, [ledger()->write(auditData())], [], '2026-08-31 10:00:00.000000'))
        ->toThrow(ArchiveException::class, 'refused to write');
});

it('refuses to hand back a batch it cannot read again', function (): void {
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('put')->andReturnTrue();
    $disk->shouldReceive('get')->andReturnNull();

    expect(fn (): mixed => writerOverDisk($disk)->write('global', 1, 1, [ledger()->write(auditData())], [], '2026-08-31 10:00:00.000000'))
        ->toThrow(ArchiveException::class, 'could not be read back');
});

it('refuses to hand back a batch that came back as different bytes', function (): void {
    $disk = Mockery::mock(Filesystem::class);
    $disk->shouldReceive('put')->andReturnTrue();
    $disk->shouldReceive('get')->andReturn('not what went in');

    expect(fn (): mixed => writerOverDisk($disk)->write('global', 1, 1, [ledger()->write(auditData())], [], '2026-08-31 10:00:00.000000'))
        ->toThrow(ArchiveException::class, 'did not come back as the bytes');
});

it('refuses an entry that does not survive being read back and hashed again', function (): void {
    $entry = ledger()->write(auditData());

    $entry->forceFill(['metadata' => [1 => 'b', 0 => 'a']]);
    $entry->hash = hasher()->hash($entry);

    expect(fn (): mixed => batchWriter()->write('global', 1, 1, [$entry], [], '2026-08-31 10:00:00.000000'))
        ->toThrow(ArchiveException::class, 'does not reproduce its own hash when read back');
});

it('leaves nothing to be removed when the entry it wrote will not come back', function (): void {
    $entry = ledger()->write(auditData());
    $entry->forceFill(['metadata' => [1 => 'b', 0 => 'a']]);
    $entry->hash = hasher()->hash($entry);

    rescue(fn (): mixed => batchWriter()->write('global', 1, 1, [$entry], [], '2026-08-31 10:00:00.000000'), report: false);

    expect(Audit::query()->count())->toBe(1);
});

it('writes down the operations the entries of a batch belong to', function (): void {
    $header = new AuditTransaction()->forceFill([
        'id' => str_pad('01JOP', 26, '0'),
        'name' => 'billing.run',
        'started_at' => '2026-08-31 10:00:00.000000',
        'audits_count' => 1,
    ]);

    $batch = batchWriter()->write('global', 1, 1, [ledger()->write(auditData())], [$header], '2026-08-31 10:00:00.000000');

    expect(gzdecode(Storage::disk('cold')->get($batch->path)))->toContain('billing.run');
});

it('refuses to write where the configured prefix would make a path the manifest cannot hold', function (): void {
    sentinelConfig(['ledger.ledgers.archive.path' => str_repeat('deep/', 120)]);

    expect(fn (): mixed => batchWriter()->write('global', 1, 1, [ledger()->write(auditData())], [], '2026-08-31 10:00:00.000000'))
        ->toThrow(ConfigurationException::class, 'longer than the 512 characters');
});
