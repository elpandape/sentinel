<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Retention;

/**
 * What a purge took, counted per table and never as one total. An operator reading that four
 * thousand rows went needs to know which of them were entries and which were the labels hanging off
 * them, and a single number cannot be read back into either.
 */
final readonly class Removed
{
    public function __construct(
        public int $audits = 0,
        public int $tags = 0,
        public int $relations = 0,
        public int $transactions = 0,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    public function plus(self $other): self
    {
        return new self(
            $this->audits + $other->audits,
            $this->tags + $other->tags,
            $this->relations + $other->relations,
            $this->transactions + $other->transactions,
        );
    }
}
