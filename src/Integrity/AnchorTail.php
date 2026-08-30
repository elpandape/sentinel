<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Integrity;

/**
 * Where the anchors of a stream end and what the last of them folded to. Both halves are what the
 * next emission needs and neither is worth reading a whole row for: the sequence says where the
 * next window starts, and the root is what that window folds over.
 */
final readonly class AnchorTail
{
    public function __construct(public int $sequence, public ?string $root) {}

    public static function empty(): self
    {
        return new self(0, null);
    }
}
