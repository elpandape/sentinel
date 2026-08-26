<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Enums\Source;

it('needs only what the capture always knows', function (): void {
    $data = new AuditData(
        audit_type: 'model',
        event: 'created',
        severity: Severity::Info,
        source: Source::Http,
        occurred_at: new DateTimeImmutable('2026-08-26 10:00:00'),
    );

    expect($data->stream)->toBeNull()
        ->and($data->context)->toBeEmpty()
        ->and($data->before)->toBeNull()
        ->and($data->capture_id)->toBeNull()
        ->and($data->affected_rows)->toBeNull();
});

it('is mutable, because the pipeline is what transforms it', function (): void {
    $data = new AuditData(
        audit_type: 'model',
        event: 'updated',
        severity: Severity::Info,
        source: Source::Cli,
        occurred_at: new DateTimeImmutable('2026-08-26 10:00:00'),
    );

    $data->before = ['email' => 'a@example.com'];
    $data->after = ['email' => 'b@example.com'];
    $data->severity = Severity::Warning;
    $data->capture_id = '01j0000000000000000000000';

    expect($data->before)->toBe(['email' => 'a@example.com'])
        ->and($data->after)->toBe(['email' => 'b@example.com'])
        ->and($data->severity)->toBe(Severity::Warning)
        ->and($data->capture_id)->toBe('01j0000000000000000000000');
});

it('mirrors the capture time columns and leaves the ledger its own', function (): void {
    $properties = array_map(
        static fn (ReflectionProperty $property): string => $property->getName(),
        new ReflectionClass(AuditData::class)->getProperties(),
    );

    expect($properties)->toHaveCount(27)
        ->and($properties)->not->toContain('id', 'sequence', 'version', 'hash', 'previous_hash')
        ->and($properties)->not->toContain('payload_version', 'algorithm', 'signature', 'created_at')
        ->and($properties)->not->toContain('redacted_at', 'redaction_reason', 'redacted_hash');
});
