<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Integrity;

use ElPandaPe\Sentinel\Enums\IntegrityBreak;

final readonly class VerificationResult
{
    private function __construct(
        public string $stream,
        public int $checked,
        public ?IntegrityBreak $reason = null,
        public ?int $sequence = null,
        public ?string $auditId = null,
    ) {}

    public static function intact(string $stream, int $checked): self
    {
        return new self($stream, $checked);
    }

    public static function broken(string $stream, int $checked, IntegrityBreak $reason, int $sequence, string $auditId): self
    {
        return new self($stream, $checked, $reason, $sequence, $auditId);
    }

    public function isIntact(): bool
    {
        return ! $this->reason instanceof IntegrityBreak;
    }
}
