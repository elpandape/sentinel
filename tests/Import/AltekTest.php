<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Enums\Source;
use ElPandaPe\Sentinel\Import\Identity;
use ElPandaPe\Sentinel\Import\Row;
use ElPandaPe\Sentinel\Import\Shape;
use ElPandaPe\Sentinel\Tests\Fixtures\AltekTrail;

use function ElPandaPe\Sentinel\Tests\altek;
use function ElPandaPe\Sentinel\Tests\altekRow;
use function ElPandaPe\Sentinel\Tests\seedAltekTrail;

it('answers to the name a caller types and to the table that package publishes', function (): void {
    expect(altek()->name())->toBe('altek')
        ->and(altek()->table())->toBe('ledgers');
});

it('recognises the ledgers table that package has been writing since its third major', function (): void {
    seedAltekTrail(AltekTrail::TABLE, AltekTrail::rows());

    app(Shape::class)->verify(altek(), AltekTrail::TABLE, null);
})->throwsNoExceptions();

it('refuses an owen-it table handed to it, because they are not the same shape', function (): void {
    seedAltekTrail(AltekTrail::TABLE, AltekTrail::rows());

    expect(static fn (): mixed => app(Shape::class)->verify(\ElPandaPe\Sentinel\Tests\owenIt(), AltekTrail::TABLE, null))
        ->toThrow(ElPandaPe\Sentinel\Exceptions\ImportException::class, 'auditable_type');
});

it('carries the whole snapshot the source did write', function (): void {
    $data = altek()->map(altekRow(1))->data;

    expect($data?->source)->toBe(Source::Import)
        ->and($data?->event)->toBe('created')
        ->and($data?->subject_type)->toBe('App\\Models\\Invoice')
        ->and($data?->subject_id)->toBe('77')
        ->and($data?->after)->toBe(['number' => 'INV-1', 'total' => 100, 'status' => 'draft']);
});

it('leaves before empty, because nobody wrote one and pairing rows would be deducing it', function (): void {
    expect(altek()->map(altekRow(2))->data?->before)->toBeNull();
});

it('says which attributes took which values, and nothing about what they held before', function (): void {
    expect(altek()->map(altekRow(2))->data?->changes)
        ->toBe([['path' => '/status', 'op' => 'replace', 'new' => 'sent']]);
});

it('calls a creation an addition and everything else a replacement, as the source event does', function (): void {
    expect(altek()->map(altekRow(1))->data?->changes)->toBe([
        ['path' => '/number', 'op' => 'add', 'new' => 'INV-1'],
        ['path' => '/total', 'op' => 'add', 'new' => 100],
        ['path' => '/status', 'op' => 'add', 'new' => 'draft'],
    ]);
});

it('drops a modified name the snapshot does not carry, rather than reading past the end of it', function (): void {
    expect(altek()->map(new Row([
        'id' => '7',
        'event' => 'updated',
        'recordable_type' => 'App\\Models\\Invoice',
        'recordable_id' => '77',
        'properties' => '{"status":"sent"}',
        'modified' => '["status","a_column_dropped_since"]',
        'created_at' => '2024-01-01 00:00:00',
    ]))->data?->changes)->toBe([['path' => '/status', 'op' => 'replace', 'new' => 'sent']]);
});

it('keeps the source signature as data and never as this package own', function (): void {
    $data = altek()->map(altekRow(1))->data;

    expect($data?->metadata['import']['signature'] ?? null)->toBe(AltekTrail::rows()[0]['signature'])
        ->and($data?->encryption)->toBeNull();
});

it('keeps the execution bitmask as the number it is, because its absence is what tells a story', function (): void {
    expect(altek()->map(altekRow(1))->data?->metadata['import']['context'] ?? null)->toBe(4)
        ->and(altek()->map(altekRow(2))->data?->metadata['import']['context'] ?? null)->toBe(2);
});

it('keeps what the source wrote that this package has no column for', function (): void {
    expect(altek()->map(altekRow(1))->data?->metadata['import']['extra'] ?? null)->toBe(['tenant' => 'acme']);
});

it('gives a row the identity its key earns, and never the same one another origin would', function (): void {
    expect(altek()->map(altekRow(1))->data?->capture_id)->toBe(Identity::of('altek', '1'))
        ->and(Identity::of('altek', '1'))->not->toBe(Identity::of('owenit', '1'));
});

it('refuses a row with no timestamp instead of inventing one for it', function (): void {
    expect(altek()->map(altekRow(3))->refused)->toContain('does not say when it happened');
});

it('refuses a row that does not say what it is about', function (): void {
    expect(altek()->map(new Row(['id' => '9', 'created_at' => '2024-01-01 00:00:00']))->refused)
        ->toContain('does not say what it is about');
});

it('refuses a row with no key of its own', function (): void {
    expect(altek()->map(new Row(['event' => 'updated']))->refused)->toContain('no key of its own');
});

it('says nothing changed when the source listed nothing as modified', function (): void {
    expect(altek()->map(new Row([
        'id' => '5',
        'event' => 'deleted',
        'recordable_type' => 'App\\Models\\Invoice',
        'recordable_id' => '77',
        'properties' => '{"status":"sent"}',
        'modified' => '[]',
        'created_at' => '2024-01-01 00:00:00',
    ]))->data?->changes)->toBe([]);
});

it('says nothing at all about changes when the source wrote no snapshot to compare', function (): void {
    expect(altek()->map(new Row([
        'id' => '6',
        'event' => 'deleted',
        'recordable_type' => 'App\\Models\\Invoice',
        'recordable_id' => '77',
        'created_at' => '2024-01-01 00:00:00',
    ]))->data?->changes)->toBeNull();
});
