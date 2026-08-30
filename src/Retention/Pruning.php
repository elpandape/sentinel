<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Retention;

use ElPandaPe\Sentinel\Integrity\VerificationResult;

/**
 * What one run did to one stream. It mirrors Integrity\StreamVerification on purpose: a reader who
 * has learned to read one report has learned to read the other.
 */
final readonly class Pruning
{
    public function __construct(
        public Frontier $frontier,
        public Removed $removed,
        public int $windows = 0,
        public float $seconds = 0.0,
        public ?VerificationResult $break = null,
    ) {}

    public function succeeded(): bool
    {
        return ! $this->break instanceof VerificationResult;
    }

    /**
     * Entries per second, and null when there is nothing to divide. A zero would read as a purge
     * that crawled and an infinity as one that did not happen, and neither is what nothing means.
     */
    public function rate(): ?float
    {
        return $this->removed->audits === 0 || $this->seconds <= 0.0
            ? null
            : $this->removed->audits / $this->seconds;
    }
}
