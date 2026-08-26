<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Query\AuditQuery;

it('starts with every criterion unset', function (): void {
    $query = new AuditQuery;

    expect($query->stream)->toBeNull()
        ->and($query->severity)->toBeNull()
        ->and($query->from)->toBeNull()
        ->and($query->limit)->toBeNull();
});

it('holds the criteria the table indexes', function (): void {
    $query = new AuditQuery;
    $query->tenant_id = 'acme';
    $query->severity = Severity::Warning;
    $query->from = new DateTimeImmutable('2026-08-01 00:00:00');
    $query->limit = 50;

    expect($query->tenant_id)->toBe('acme')
        ->and($query->severity)->toBe(Severity::Warning)
        ->and($query->from->format('Y-m-d'))->toBe('2026-08-01')
        ->and($query->limit)->toBe(50);
});
