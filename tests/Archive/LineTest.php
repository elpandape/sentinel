<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Archive\Line;
use ElPandaPe\Sentinel\Enums\BatchLine;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Models\AuditTag;
use ElPandaPe\Sentinel\Models\AuditTransaction;
use ElPandaPe\Sentinel\Tests\Fixtures\GoldenLedger;
use Illuminate\Support\Facades\Schema;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\auditsTable;
use function ElPandaPe\Sentinel\Tests\hasher;
use function ElPandaPe\Sentinel\Tests\ledger;

it('names every column of an entry on the line, and nothing that is not one', function (): void {
    $written = ledger()->write(auditData());

    $keys = array_keys(Line::entry($written));

    expect(array_values(array_diff($keys, ['kind', 'tags'])))
        ->toEqualCanonicalizing(Schema::getColumnListing(auditsTable()));
});

it('says what kind of line it is on the line itself', function (): void {
    expect(Line::entry(ledger()->write(auditData()))['kind'])->toBe(BatchLine::Entry->value)
        ->and(Line::header('global', 1, 4, 4, '2026-08-31 00:00:00.000000')['kind'])->toBe(BatchLine::Header->value);
});

it('carries the version of the container on its first line', function (): void {
    $header = Line::header('global', 1, 4, 4, '2026-08-31 00:00:00.000000');

    expect($header['format'])->toBe(Line::FORMAT)
        ->and($header['sequence_from'])->toBe(1)
        ->and($header['sequence_to'])->toBe(4)
        ->and($header['records'])->toBe(4);
});

it('rebuilds an entry that still reproduces the hash it was sealed with', function (): void {
    $written = ledger()->write(auditData(['tags' => ['billing', 'refund']]));

    $rebuilt = Line::toAudit(Line::entry($written), new Audit);

    expect(hasher()->hash($rebuilt))->toBe($written->hash)
        ->and($rebuilt->sequence)->toBe($written->sequence)
        ->and($rebuilt->previous_hash)->toBe($written->previous_hash);
});

it('rebuilds an entry with the clocks it went in with', function (): void {
    $written = ledger()->write(auditData());

    $rebuilt = Line::toAudit(Line::entry($written), new Audit);

    expect($rebuilt->created_at->format('Y-m-d H:i:s.u'))->toBe($written->created_at->format('Y-m-d H:i:s.u'))
        ->and($rebuilt->occurred_at->format('Y-m-d H:i:s.u'))->toBe($written->occurred_at->format('Y-m-d H:i:s.u'));
});

it('carries the labels an entry was written with, and gives them back', function (): void {
    $written = ledger()->write(auditData(['tags' => ['billing', 'refund']]));

    $rebuilt = Line::toAudit(Line::entry($written), new Audit);

    expect(Line::entry($written)['tags'])->toBe(['billing', 'refund'])
        ->and($rebuilt->tags->map(fn (AuditTag $tag): string => $tag->tag)->all())->toBe(['billing', 'refund']);
});

it('says an entry carries no labels when it arrived saying nothing about them', function (): void {
    $written = ledger()->write(auditData());
    $written->unsetRelation('tags');

    expect(Line::entry($written)['tags'])->toBeEmpty();
});

it('keeps what is not a column out of the entry it rebuilds', function (): void {
    $written = ledger()->write(auditData(['tags' => ['billing']]));

    $rebuilt = Line::toAudit(Line::entry($written), new Audit);

    expect(array_keys($rebuilt->getAttributes()))
        ->not->toContain('kind')
        ->not->toContain('tags');
});

it('writes down an operation exactly as its header holds it', function (): void {
    $header = new AuditTransaction()->forceFill([
        'id' => str_pad('01JOP', 26, '0'),
        'name' => 'billing.run',
        'started_at' => '2026-08-31 10:00:00.000000',
        'audits_count' => 3,
    ]);

    $line = Line::operation($header);

    expect($line['kind'])->toBe(BatchLine::Operation->value)
        ->and($line['name'])->toBe('billing.run')
        ->and($line['started_at'])->toBe('2026-08-31 10:00:00.000000');
});

it('rehashes the frozen entries straight off the lines they would be archived as', function (array $attributes, string $canonical, string $hash): void {
    $rebuilt = Line::toAudit(Line::entry(new Audit()->forceFill($attributes)), new Audit);

    expect(hasher()->hash($rebuilt))->toBe($hash);
})->with(GoldenLedger::entries());
