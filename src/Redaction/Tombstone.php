<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Redaction;

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Models\Audit;

/**
 * What a redaction leaves behind: the entry still in its place, its hash and its link untouched, and
 * a second hash over what is left. It is returned rather than a bool because the caller of an
 * erasure request has to be able to say what was destroyed and when, and a bool cannot.
 */
final readonly class Tombstone
{
    public function __construct(
        public string $auditId,
        public string $stream,
        public int $sequence,
        public CarbonImmutable $redactedAt,
        public string $reason,
        public string $redactedHash,
        public ?Audit $trail = null,
    ) {}

    public static function of(Audit $audit, ?Audit $trail = null): self
    {
        return new self(
            $audit->id,
            $audit->stream,
            $audit->sequence,
            $audit->redacted_at ?? CarbonImmutable::now(),
            $audit->redaction_reason ?? '',
            $audit->redacted_hash ?? '',
            $trail,
        );
    }
}
