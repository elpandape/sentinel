<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Fixtures;

final readonly class UnrepresentableValue
{
    public function __construct(public string $label = 'opaque') {}
}
