<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Pipeline;

final readonly class Discarded
{
    /**
     * @param  class-string  $stage
     */
    public function __construct(
        public string $stage,
        public string $reason,
    ) {}
}
