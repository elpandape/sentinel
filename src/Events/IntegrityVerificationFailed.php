<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Events;

use ElPandaPe\Sentinel\Enums\IntegrityBreak;

final readonly class IntegrityVerificationFailed
{
    public function __construct(
        public string $stream,
        public IntegrityBreak $reason,
        public int $sequence,
        public string $auditId,
    ) {}

    public function message(): string
    {
        return (string) trans('sentinel::sentinel.integrity.'.$this->reason->value, [
            'stream' => $this->stream,
            'sequence' => $this->sequence,
            'id' => $this->auditId,
        ]);
    }
}
