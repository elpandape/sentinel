<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Enums\Omission;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Restore\Columns;
use ElPandaPe\Sentinel\Tests\Fixtures\AuditedSubject;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

use function ElPandaPe\Sentinel\Tests\restorableEntry;

it('asks the schema once and holds the answer for the rest of the scope', function (): void {
    $columns = app(Columns::class);

    $before = $columns->of(new AuditedSubject);

    Schema::table('fixture_audited_subjects', static function (Blueprint $table): void {
        $table->string('nickname')->nullable();
    });

    expect($columns->of(new AuditedSubject))->toBe($before)
        ->and(new Columns()->of(new AuditedSubject))->toContain('nickname');
});

it('answers separately for two tables', function (): void {
    $columns = app(Columns::class);

    expect($columns->of(new AuditedSubject))->toContain('name')
        ->and($columns->of(new Audit))->toContain('sequence')
        ->and($columns->of(new AuditedSubject))->not->toContain('sequence');
});

it('still refuses a field the schema no longer has', function (): void {
    $subject = AuditedSubject::query()->create(['name' => 'Ada']);
    $entry = restorableEntry($subject, ['name' => 'Grace', 'gone' => 'value']);

    expect($entry->restore()->skipped)->toBe(['gone' => Omission::UnknownField]);
});

it('puts a field back on a second restoration, with the column list already held', function (): void {
    $subject = AuditedSubject::query()->create(['name' => 'Ada']);
    $first = restorableEntry($subject, ['name' => 'Grace']);
    $second = restorableEntry($subject, ['name' => 'Ada']);

    expect($first->restore()->applied)->toBe(['name'])
        ->and($second->restore()->applied)->toBe(['name'])
        ->and($subject->fresh()?->name)->toBe('Ada');
});
