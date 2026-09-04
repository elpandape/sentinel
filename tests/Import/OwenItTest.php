<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Enums\Source;
use ElPandaPe\Sentinel\Import\Identity;
use ElPandaPe\Sentinel\Import\Row;
use ElPandaPe\Sentinel\Import\Shape;
use ElPandaPe\Sentinel\Tests\Fixtures\OwenItTrail;

use function ElPandaPe\Sentinel\Tests\owenIt;
use function ElPandaPe\Sentinel\Tests\owenItRow;
use function ElPandaPe\Sentinel\Tests\seedForeignTrail;

it('recognises the table that package has been writing since its tenth major', function (): void {
    seedForeignTrail(OwenItTrail::TABLE, OwenItTrail::rows());

    app(Shape::class)->verify(owenIt(), OwenItTrail::TABLE, null);
})->throwsNoExceptions();

it('answers to the name a caller types and to the table that package publishes', function (): void {
    expect(owenIt()->name())->toBe('owenit')
        ->and(owenIt()->table())->toBe('audits');
});

it('recognises the actor columns under whatever prefix the application chose', function (): void {
    seedForeignTrail(OwenItTrail::TABLE, OwenItTrail::rows(), 'user');

    expect(owenIt('operator')->columns())->toContain('operator_type', 'operator_id')
        ->and(owenIt('operator')->columns())->not->toContain('user_type');
});

it('carries a create over with everything the record had, and its labels one by one', function (): void {
    $data = owenIt()->map(owenItRow(1))->data;

    expect($data?->event)->toBe('created')
        ->and($data?->source)->toBe(Source::Import)
        ->and($data?->subject_type)->toBe('App\\Models\\Invoice')
        ->and($data?->subject_id)->toBe('77')
        ->and($data?->actor_id)->toBe('7')
        ->and($data?->after)->toBe(['number' => 'INV-1', 'total' => 100, 'status' => 'draft'])
        ->and($data?->before)->toBe([])
        ->and($data?->tags)->toBe(['billing', 'quarter-one'])
        ->and($data?->context)->toBe([
            'url' => 'http://example.test/invoices',
            'ip' => '203.0.113.4',
            'user_agent' => 'Mozilla/5.0',
        ]);
});

it('recomputes what changed instead of copying it, because the source never wrote one', function (): void {
    expect(owenIt()->map(owenItRow(2))->data?->changes)
        ->toBe([['path' => '/status', 'op' => 'replace', 'old' => 'draft', 'new' => 'sent']]);
});

it('leaves nobody on whose behalf, because that package has no such idea', function (): void {
    $data = owenIt()->map(owenItRow(1))->data;

    expect($data?->impersonator_type)->toBeNull()
        ->and($data?->impersonator_id)->toBeNull();
});

it('leaves out the context keys the source has no answer for, rather than emptying them', function (): void {
    expect(owenIt()->map(owenItRow(3))->data?->context)->toBe(['url' => 'artisan invoices:sweep']);
});

it('gives a row the identity its key earns, so a second run finds it done', function (): void {
    expect(owenIt()->map(owenItRow(2))->data?->capture_id)->toBe(Identity::of('owenit', '2'));
});

it('says which row an entry came from, on the entry', function (): void {
    expect(owenIt()->map(owenItRow(2))->data?->metadata)
        ->toBe(['import' => ['origin' => 'owenit', 'row' => '2']]);
});

it('refuses a row with no timestamp instead of inventing one for it', function (): void {
    $mapping = owenIt()->map(owenItRow(4));

    expect($mapping->data)->toBeNull()
        ->and($mapping->refused)->toContain('does not say when it happened');
});

it('refuses a row that does not say what it is about', function (): void {
    $mapping = owenIt()->map(new Row(['id' => '9', 'created_at' => '2024-01-01 00:00:00']));

    expect($mapping->refused)->toContain('does not say what it is about');
});

it('refuses a row with no key of its own', function (): void {
    expect(owenIt()->map(new Row(['event' => 'updated']))->refused)->toContain('no key of its own');
});
