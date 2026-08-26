<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Contracts;

interface Canonicalizer
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function canonicalize(array $payload): string;
}
