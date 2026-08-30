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
        public int $archived = 0,
    ) {}

    /**
     * `archived` counts entries the walk stepped over because they are no longer in the ledger and
     * something accounts for where they went. It is published beside `checked` and never added into
     * it, for the reason StreamVerification keeps `covered` separate: the two numbers mean
     * different things and one total would hide which.
     */
    public static function intact(string $stream, int $checked, int $archived = 0): self
    {
        return new self($stream, $checked, archived: $archived);
    }

    public static function broken(string $stream, int $checked, IntegrityBreak $reason, int $sequence, string $auditId, int $archived = 0): self
    {
        return new self($stream, $checked, $reason, $sequence, $auditId, $archived);
    }

    public function isIntact(): bool
    {
        return ! $this->reason instanceof IntegrityBreak;
    }

    /**
     * The sentence for what was found, empty for a walk that found nothing. Same source as the
     * event's, so a reason cannot say one thing when it is announced and another when it is read.
     */
    public function message(): string
    {
        return $this->reason instanceof IntegrityBreak
            ? $this->reason->message($this->stream, $this->sequence ?? 0, $this->auditId ?? '')
            : '';
    }
}
