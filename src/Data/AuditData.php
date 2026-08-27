<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Data;

use DateTimeImmutable;
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Enums\Source;

/**
 * The entry as the capture knows it. Fields are named after their columns because
 * the canonical payload is too, and a translation layer between capture and hash
 * is where silent integrity bugs come from.
 */
final class AuditData
{
    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<array-key, mixed>|null  $changes
     * @param  array<string, mixed>|null  $metadata
     * @param  array<string, mixed>|null  $encryption
     * @param  array<array-key, mixed>|null  $criteria
     */
    public function __construct(
        public string $audit_type,
        public string $event,
        public Severity $severity,
        public DateTimeImmutable $occurred_at,
        public Source $source = Source::System,
        public ?string $stream = null,
        public ?string $subject_type = null,
        public ?string $subject_id = null,
        public ?string $actor_type = null,
        public ?string $actor_id = null,
        public ?string $impersonator_type = null,
        public ?string $impersonator_id = null,
        public ?string $tenant_id = null,
        public ?string $transaction_id = null,
        public ?string $request_id = null,
        public ?string $trace_id = null,
        public ?string $span_id = null,
        public array $context = [],
        public ?array $before = null,
        public ?array $after = null,
        public ?array $changes = null,
        public ?array $metadata = null,
        public ?array $encryption = null,
        public ?array $criteria = null,
        public ?int $affected_rows = null,
        public ?string $source_audit_id = null,
        public ?string $capture_id = null,
    ) {}
}
