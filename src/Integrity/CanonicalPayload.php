<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Integrity;

use BackedEnum;
use DateTimeInterface;
use ElPandaPe\Sentinel\Models\Audit;

/**
 * The frozen definition of canonical(core) for payload_version 1. Nothing else in the
 * package may enumerate these columns: a second list of them is a second payload format.
 */
final class CanonicalPayload
{
    public const string DATE_FORMAT = 'Y-m-d H:i:s.u';

    /**
     * @var list<string>
     */
    public const array COLUMNS = [
        'id',
        'audit_type',
        'event',
        'severity',
        'subject_type',
        'subject_id',
        'actor_type',
        'actor_id',
        'impersonator_type',
        'impersonator_id',
        'tenant_id',
        'transaction_id',
        'request_id',
        'trace_id',
        'span_id',
        'source',
        'version',
        'context',
        'before',
        'after',
        'changes',
        'metadata',
        'encryption',
        'criteria',
        'affected_rows',
        'source_audit_id',
        'occurred_at',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function from(Audit $audit): array
    {
        $payload = [];

        foreach (self::COLUMNS as $column) {
            $payload[$column] = self::normalize($audit->getAttribute($column));
        }

        return $payload;
    }

    private static function normalize(mixed $value): mixed
    {
        return match (true) {
            $value instanceof BackedEnum => $value->value,
            $value instanceof DateTimeInterface => $value->format(self::DATE_FORMAT),
            default => $value,
        };
    }
}
