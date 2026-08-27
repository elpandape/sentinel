<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Fixtures;

use Stringable;

final readonly class Slug implements Stringable
{
    public function __construct(private string $value) {}

    public function __toString(): string
    {
        return $this->value;
    }
}
