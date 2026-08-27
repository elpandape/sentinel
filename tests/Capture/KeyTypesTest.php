<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Tests\Fixtures\AuditedSubject;
use ElPandaPe\Sentinel\Tests\Fixtures\UlidAuditedSubject;
use ElPandaPe\Sentinel\Tests\Fixtures\UuidAuditedSubject;
use Illuminate\Database\Eloquent\Model;

use function ElPandaPe\Sentinel\Tests\auditsOf;

it('carries any key shape in subject_id without a migration', function (string $model): void {
    /** @var Model $subject */
    $subject = $model::query()->create(['name' => 'Ada']);

    $audits = auditsOf($subject);

    expect($audits)->toHaveCount(1)
        ->and($audits->first()?->subject_id)->toBe((string) $subject->getKey())
        ->and(strlen((string) $audits->first()?->subject_id))->toBeLessThanOrEqual(64);
})->with([AuditedSubject::class, UuidAuditedSubject::class, UlidAuditedSubject::class]);

it('counts versions per subject and not per stream', function (): void {
    $first = AuditedSubject::query()->create(['name' => 'Ada']);
    $second = AuditedSubject::query()->create(['name' => 'Grace']);
    $first->update(['name' => 'Hedy']);

    expect(auditsOf($first)->pluck('version')->all())->toBe([1, 2])
        ->and(auditsOf($second)->pluck('version')->all())->toBe([1]);
});
