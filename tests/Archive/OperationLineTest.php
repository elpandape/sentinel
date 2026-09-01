<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Archive\ArchiveBatch;
use ElPandaPe\Sentinel\Archive\Batch;
use ElPandaPe\Sentinel\Archive\Line;
use ElPandaPe\Sentinel\Enums\BatchLine;
use ElPandaPe\Sentinel\Exceptions\ArchiveException;
use ElPandaPe\Sentinel\Models\AuditTransaction;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

use function ElPandaPe\Sentinel\Tests\archivedBatch;
use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\batchAtFormat;
use function ElPandaPe\Sentinel\Tests\batchReader;
use function ElPandaPe\Sentinel\Tests\batchWriter;
use function ElPandaPe\Sentinel\Tests\ledger;
use function ElPandaPe\Sentinel\Tests\sentinelConfig;
use function ElPandaPe\Sentinel\Tests\transactionsTable;

beforeEach(function (): void {
    Storage::fake('cold');
    sentinelConfig(['ledger.ledgers.archive.disk' => 'cold']);
});

it('names every column of an operation on the line, and nothing that is not one', function (): void {
    $line = Line::operation(new AuditTransaction);

    expect(array_values(array_diff(array_keys($line), ['kind'])))
        ->toEqualCanonicalizing(Schema::getColumnListing(transactionsTable()));
});

it('rebuilds an operation header as the batch found it', function (): void {
    $header = new AuditTransaction()->forceFill([
        'id' => str_pad('01JOP', 26, '0'),
        'name' => 'billing.run',
        'started_at' => '2026-08-31 10:00:00.000000',
        'finished_at' => '2026-08-31 10:00:05.000000',
        'audits_count' => 3,
        'metadata' => ['batch' => 7],
    ]);

    $rebuilt = Line::toTransaction(Line::operation($header), new AuditTransaction);

    expect($rebuilt->name)->toBe('billing.run')
        ->and($rebuilt->audits_count)->toBe(3)
        ->and($rebuilt->metadata)->toBe(['batch' => 7])
        ->and($rebuilt->started_at->format('Y-m-d H:i:s.u'))->toBe('2026-08-31 10:00:00.000000');
});

it('keeps what is not a column out of the header it rebuilds', function (): void {
    $rebuilt = Line::toTransaction(
        [...Line::operation(new AuditTransaction), 'kind' => BatchLine::Operation->value],
        new AuditTransaction,
    );

    expect(array_keys($rebuilt->getAttributes()))->not->toContain('kind');
});

it('refuses an operation line that names fewer columns than a header has', function (): void {
    $line = Line::operation(new AuditTransaction);
    unset($line['metadata'], $line['tenant_id']);

    expect(fn (): AuditTransaction => Line::toTransaction($line, new AuditTransaction))
        ->toThrow(ArchiveException::class, 'does not name the columns [tenant_id, metadata]');
});

it('reads the operation lines out of a batch', function (): void {
    $header = new AuditTransaction()->forceFill([
        'id' => str_pad('01JOP', 26, '0'),
        'name' => 'billing.run',
        'started_at' => '2026-08-31 10:00:00.000000',
        'audits_count' => 1,
    ]);

    $body = Line::operation($header);

    expect(iterator_to_array(Batch::operationsIn(json_encode($body))))->toHaveCount(1);
});

it('gives back the operation headers a batch carries', function (): void {
    $header = new AuditTransaction()->forceFill([
        'id' => str_pad('01JOP', 26, '0'),
        'name' => 'billing.run',
        'started_at' => '2026-08-31 10:00:00.000000',
        'audits_count' => 1,
    ]);

    $batch = batchWriter()->write('global', 1, 1, [ledger()->write(auditData())], [$header], '2026-08-31 10:00:00.000000');

    $read = batchReader()->operations($batch);

    expect($read)->toHaveCount(1)
        ->and($read[0]->name)->toBe('billing.run')
        ->and($read[0]->id)->toBe(str_pad('01JOP', 26, '0'));
});

it('gives back nothing for a batch whose entries belonged to no operation', function (): void {
    expect(batchReader()->operations(archivedBatch()))->toBeEmpty();
});

it('refuses a batch whose container format this build does not read', function (): void {
    expect(fn (): array => batchReader()->read(batchAtFormat(99)))
        ->toThrow(ArchiveException::class, 'declares container format 99 and this build reads 1');
});

it('refuses a batch with no header line at all', function (): void {
    Storage::disk('cold')->put('sentinel/headless.ndjson', "{\"kind\":\"entry\"}\n");

    $batch = new ArchiveBatch(
        'global', 1, 1, 0, 'cold', 'sentinel/headless.ndjson',
        'sha256:'.hash('sha256', "{\"kind\":\"entry\"}\n"), null,
    );

    expect(fn (): array => batchReader()->read($batch))
        ->toThrow(ArchiveException::class, 'declares container format nothing');
});
