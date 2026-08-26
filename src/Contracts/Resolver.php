<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Contracts;

interface Resolver
{
    /**
     * @return array<string, mixed>
     */
    public function resolve(): array;
}
