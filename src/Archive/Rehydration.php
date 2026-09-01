<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Archive;

/**
 * What one restore put back and what it found already there, counted apart. An operator resuming an
 * interrupted run needs to see that the second pass restored the tail rather than the whole range,
 * and one total cannot say that.
 */
final readonly class Rehydration
{
    public function __construct(
        public int $restored = 0,
        public int $skipped = 0,
        public int $operations = 0,
        public int $batches = 0,
    ) {}

    public function plus(self $other): self
    {
        return new self(
            $this->restored + $other->restored,
            $this->skipped + $other->skipped,
            $this->operations + $other->operations,
            $this->batches + $other->batches,
        );
    }
}
