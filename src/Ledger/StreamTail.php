<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Ledger;

/**
 * @internal
 */
final readonly class StreamTail
{
    public function __construct(public int $sequence, public ?string $hash) {}

    public static function empty(): self
    {
        return new self(0, null);
    }
}
