<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Retention;

use ElPandaPe\Sentinel\Enums\RetentionHold;
use ElPandaPe\Sentinel\Integrity\Checkpoint;

/**
 * What one stream is willing to give up: the anchored windows every entry of which retention has
 * released, and — when there are none — which of the four things is holding it.
 *
 * The windows are anchors and not a shape of their own. The unit of purging is the anchored window
 * because it is the only one the chain admits, and carrying the anchor itself is what keeps that
 * from being a rule somebody has to remember.
 */
final readonly class Frontier
{
    /**
     * @param  list<Checkpoint>  $windows
     */
    private function __construct(
        public string $stream,
        public array $windows,
        public ?RetentionHold $hold,
        public int $heldAt,
        public string $heldBy,
    ) {}

    /**
     * @param  non-empty-list<Checkpoint>  $windows
     */
    public static function releasing(string $stream, array $windows): self
    {
        return new self($stream, $windows, null, 0, '');
    }

    public static function holding(string $stream, RetentionHold $hold, int $heldAt = 0, string $heldBy = ''): self
    {
        return new self($stream, [], $hold, $heldAt, $heldBy);
    }

    public function isEmpty(): bool
    {
        return $this->windows === [];
    }

    public function entries(): int
    {
        return array_sum(array_map(static fn (Checkpoint $window): int => $window->length(), $this->windows));
    }

    public function message(): string
    {
        return $this->hold instanceof RetentionHold
            ? $this->hold->message($this->stream, $this->heldAt, $this->heldBy)
            : '';
    }
}
