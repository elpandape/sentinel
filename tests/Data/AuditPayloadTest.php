<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Enums\Source;
use ElPandaPe\Sentinel\Exceptions\DispatchException;

use function ElPandaPe\Sentinel\Tests\auditData;

it('carries every field of the capture across the boundary', function (): void {
    $audit = auditData([
        'subject_type' => 'invoice',
        'subject_id' => '500',
        'actor_type' => 'user',
        'actor_id' => '1',
        'impersonator_type' => 'user',
        'impersonator_id' => '7',
        'tenant_id' => 'acme',
        'transaction_id' => 'operation',
        'request_id' => 'request',
        'trace_id' => 'trace',
        'span_id' => 'span',
        'stream' => 'tenant:acme',
        'context' => ['ip' => '203.0.113.7'],
        'before' => ['name' => 'Ada'],
        'after' => ['name' => 'Grace'],
        'changes' => [['path' => '/name', 'op' => 'replace', 'old' => 'Ada', 'new' => 'Grace']],
        'metadata' => ['reason' => 'correction'],
        'encryption' => ['fields' => ['name'], 'key_id' => 'default'],
        'criteria' => ['where' => ['id' => 500]],
        'affected_rows' => 1,
        'source_audit_id' => 'origin',
        'capture_id' => 'capture',
        'tags' => ['billing', 'urgent'],
    ]);

    $restored = AuditData::fromPayload($audit->toPayload());

    expect($restored->toPayload())->toBe($audit->toPayload());
});

it('keeps the clock of the fact to the microsecond', function (): void {
    $audit = auditData(['occurred_at' => new DateTimeImmutable('2026-08-29 10:00:00.123456')]);

    $restored = AuditData::fromPayload($audit->toPayload());

    expect($restored->occurred_at->format('Y-m-d H:i:s.u'))->toBe('2026-08-29 10:00:00.123456');
});

it('keeps the severity and the source as the enums they were', function (): void {
    $audit = auditData(['severity' => Severity::Critical, 'source' => Source::Api]);

    $restored = AuditData::fromPayload($audit->toPayload());

    expect($restored->severity)->toBe(Severity::Critical)
        ->and($restored->source)->toBe(Source::Api);
});

it('drops a key it does not recognise, so a worker on the previous release can still read it', function (): void {
    $payload = [...auditData()->toPayload(), 'something_a_later_version_added' => 'value'];

    expect(AuditData::fromPayload($payload)->event)->toBe('created');
});

it('falls back on a value the enum does not have yet rather than losing the entry', function (): void {
    $payload = [...auditData()->toPayload(), 'severity' => 'catastrophic', 'source' => 'telepathy'];

    $restored = AuditData::fromPayload($payload);

    expect($restored->severity)->toBe(Severity::Info)
        ->and($restored->source)->toBe(Source::System);
});

it('refuses an entry that names its own place in the chain', function (string $column): void {
    $payload = [...auditData()->toPayload(), $column => 'proposed'];

    expect(static fn (): AuditData => AuditData::fromPayload($payload))
        ->toThrow(DispatchException::class, "already names its [{$column}]");
})->with(['sequence', 'hash', 'previous_hash']);

it('refuses an entry that cannot say what happened or when', function (string $key): void {
    $payload = auditData()->toPayload();
    unset($payload[$key]);

    expect(static fn (): AuditData => AuditData::fromPayload($payload))
        ->toThrow(DispatchException::class, "no [{$key}]");
})->with(['audit_type', 'event', 'occurred_at']);

it('reads a label list that arrived with something that is not a label in it', function (): void {
    $payload = [...auditData()->toPayload(), 'tags' => ['billing', 7, 'urgent']];

    expect(AuditData::fromPayload($payload)->tags)->toBe(['billing', 'urgent']);
});

it('reads a payload whose optional fields arrived as the wrong shape as if they were absent', function (): void {
    $payload = [...auditData()->toPayload(), 'tenant_id' => 7, 'before' => 'not a map', 'affected_rows' => 'many', 'tags' => 'billing'];

    $restored = AuditData::fromPayload($payload);

    expect($restored->tenant_id)->toBeNull()
        ->and($restored->before)->toBeNull()
        ->and($restored->affected_rows)->toBeNull()
        ->and($restored->tags)->toBeEmpty();
});
