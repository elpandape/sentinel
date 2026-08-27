<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Tests\Fixtures\CastingSubject;
use ElPandaPe\Sentinel\Tests\Fixtures\SubjectStatus;

use function ElPandaPe\Sentinel\Tests\auditsOf;

it('brings the snapshot back from the engine with the shape it went in with', function (): void {
    $subject = CastingSubject::query()->create([
        'name' => 'José',
        'status' => SubjectStatus::Draft,
        'options' => ['z' => 1, 'a' => ['x', 'y'], '2' => 'two'],
        'published_at' => '2026-08-26 10:00:00.123456',
        'active' => true,
    ]);

    $written = $subject->latestAudit();
    $reread = auditsOf($subject)->first();

    expect($reread?->after)->toBe($written?->after)
        ->and($reread?->after['options'] ?? null)->toBe(['2' => 'two', 'a' => ['x', 'y'], 'z' => 1])
        ->and($reread?->after['status'] ?? null)->toBe('draft')
        ->and($reread?->after['active'] ?? null)->toBeTrue()
        ->and($reread?->after['published_at'] ?? null)->toBe('2026-08-26T10:00:00.123456+00:00');
});

it('still verifies after the snapshot has made the round trip', function (): void {
    $subject = CastingSubject::query()->create(['name' => '海', 'options' => [1, 2, 3]]);

    expect(auditsOf($subject)->first()?->verifyIntegrity())->toBeTrue();
});
