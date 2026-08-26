<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Query;

use DateTimeImmutable;
use ElPandaPe\Sentinel\Enums\Severity;

/**
 * A container for the criteria the audits table indexes. It is deliberately
 * provisional: the fluent API that fills it lands with the query engine.
 */
final class AuditQuery
{
    public ?string $stream = null;

    public ?string $audit_type = null;

    public ?string $event = null;

    public ?Severity $severity = null;

    public ?string $subject_type = null;

    public ?string $subject_id = null;

    public ?string $actor_type = null;

    public ?string $actor_id = null;

    public ?string $tenant_id = null;

    public ?string $transaction_id = null;

    public ?string $request_id = null;

    public ?string $trace_id = null;

    public ?DateTimeImmutable $from = null;

    public ?DateTimeImmutable $to = null;

    public ?int $limit = null;

    public ?int $offset = null;
}
