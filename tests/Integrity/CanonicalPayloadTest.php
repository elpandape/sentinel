<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Integrity\CanonicalPayload;
use ElPandaPe\Sentinel\Models\Audit;

use function ElPandaPe\Sentinel\Tests\auditRow;

it('carries the twenty-seven columns of the frozen core', function (): void {
    expect(CanonicalPayload::COLUMNS)->toHaveCount(27)
        ->and(CanonicalPayload::COLUMNS)->toEqualCanonicalizing([
            'id', 'audit_type', 'event', 'severity', 'subject_type', 'subject_id',
            'actor_type', 'actor_id', 'impersonator_type', 'impersonator_id', 'tenant_id',
            'transaction_id', 'request_id', 'trace_id', 'span_id', 'source', 'version',
            'context', 'before', 'after', 'changes', 'metadata', 'encryption', 'criteria',
            'affected_rows', 'source_audit_id', 'occurred_at',
        ]);
});

it('leaves out the columns that would make the hash circular or unstable', function (): void {
    expect(CanonicalPayload::COLUMNS)->not->toContain(
        'stream', 'sequence', 'previous_hash', 'payload_version',
        'hash', 'signature', 'signature_key_id', 'algorithm',
        'created_at', 'capture_id', 'redacted_at', 'redaction_reason', 'redacted_hash',
    );
});

it('normalizes enums, dates and json into values a canonicalizer accepts', function (): void {
    $payload = CanonicalPayload::from(
        Audit::query()->create(collect(auditRow())->except('id')->merge(['context' => []])->all()),
    );

    expect($payload['severity'])->toBe('info')
        ->and($payload['source'])->toBe('system')
        ->and($payload['occurred_at'])->toBe('2026-08-26 10:00:00.000000')
        ->and($payload['context'])->toBe([])
        ->and($payload['version'])->toBeNull();
});

it('names every column of the core, including the ones nothing writes yet', function (): void {
    $payload = CanonicalPayload::from(Audit::query()->create(collect(auditRow())->except('id')->all()));

    expect(array_keys($payload))->toEqualCanonicalizing(CanonicalPayload::COLUMNS)
        ->and($payload['criteria'])->toBeNull()
        ->and($payload['affected_rows'])->toBeNull()
        ->and($payload['source_audit_id'])->toBeNull();
});

it('reads the json columns as structures, never as the string the engine stored', function (): void {
    Audit::query()->create(
        collect(auditRow())->except('id')->merge(['context' => [], 'metadata' => ['b' => 1, 'a' => 2]])->all(),
    );

    $payload = CanonicalPayload::from(Audit::query()->firstOrFail());

    expect($payload['metadata'])->toBeArray()->toHaveKeys(['a', 'b']);
});
