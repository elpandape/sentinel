<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Tests\Fixtures\AuditedSubject;

use function ElPandaPe\Sentinel\Tests\keptBy;
use function ElPandaPe\Sentinel\Tests\seedAudit;

it('keeps everything when nothing is declared', function (): void {
    seedAudit(1, ['created_at' => '2019-01-01 00:00:00.000000']);
    seedAudit(2, ['audit_type' => 'auth', 'subject_type' => null, 'created_at' => '2019-01-01 00:00:00.000000']);

    expect(keptBy([]))->toBe([1, 2]);
});

it('keeps an entry that no policy names, however old it is', function (): void {
    seedAudit(1, ['audit_type' => 'custom', 'subject_type' => null, 'created_at' => '2019-01-01 00:00:00.000000']);

    expect(keptBy(['auth' => '90 days']))->toBe([1]);
});

it('keeps an entry with no subject whose kind no policy names', function (): void {
    seedAudit(1, ['audit_type' => 'custom', 'subject_type' => null, 'created_at' => '2026-08-29 12:00:00.000000']);

    expect(keptBy([AuditedSubject::class => '7 years', 'auth' => '90 days']))->toBe([1]);
});

it('keeps an entry with no subject inside the period of its kind', function (): void {
    seedAudit(1, ['audit_type' => 'auth', 'subject_type' => null, 'created_at' => '2026-08-29 12:00:00.000000']);

    expect(keptBy(['auth' => '90 days']))->toBe([1]);
});

it('releases an entry with no subject past the period of its kind', function (): void {
    seedAudit(1, ['audit_type' => 'auth', 'subject_type' => null, 'created_at' => '2026-01-01 12:00:00.000000']);

    expect(keptBy(['auth' => '90 days']))->toBeEmpty();
});

it('keeps an entry inside the period of its subject', function (): void {
    seedAudit(1, ['subject_type' => AuditedSubject::class, 'subject_id' => '1', 'created_at' => '2020-01-01 12:00:00.000000']);

    expect(keptBy([AuditedSubject::class => '7 years']))->toBe([1]);
});

it('releases an entry past the period of its subject', function (): void {
    seedAudit(1, ['subject_type' => AuditedSubject::class, 'subject_id' => '1', 'created_at' => '2015-01-01 12:00:00.000000']);

    expect(keptBy([AuditedSubject::class => '7 years']))->toBeEmpty();
});

it('lets the policy of a subject decide instead of the policy of its kind', function (): void {
    seedAudit(1, [
        'audit_type' => 'model',
        'subject_type' => AuditedSubject::class,
        'subject_id' => '1',
        'created_at' => '2020-01-01 12:00:00.000000',
    ]);

    expect(keptBy([AuditedSubject::class => '7 years', 'model' => '90 days']))->toBe([1]);
});

it('releases an entry the policy of its subject no longer keeps, whatever its kind says', function (): void {
    seedAudit(1, [
        'audit_type' => 'model',
        'subject_type' => AuditedSubject::class,
        'subject_id' => '1',
        'created_at' => '2015-01-01 12:00:00.000000',
    ]);

    expect(keptBy([AuditedSubject::class => '7 years', 'model' => '900 years']))->toBeEmpty();
});

it('governs a subject no policy names by the policy of its kind', function (): void {
    seedAudit(1, [
        'audit_type' => 'model',
        'subject_type' => 'some\\Other\\Model',
        'subject_id' => '1',
        'created_at' => '2026-01-01 12:00:00.000000',
    ]);

    expect(keptBy([AuditedSubject::class => '7 years', 'model' => '90 days']))->toBeEmpty();
});

it('keeps and releases side by side in one pass', function (): void {
    seedAudit(1, ['audit_type' => 'auth', 'subject_type' => null, 'created_at' => '2026-08-29 12:00:00.000000']);
    seedAudit(2, ['audit_type' => 'auth', 'subject_type' => null, 'created_at' => '2026-01-01 12:00:00.000000']);
    seedAudit(3, ['audit_type' => 'custom', 'subject_type' => null, 'created_at' => '2019-01-01 12:00:00.000000']);

    expect(keptBy(['auth' => '90 days']))->toBe([1, 3]);
});
