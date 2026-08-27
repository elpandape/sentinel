<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Contracts;

interface Masker
{
    public function mask(string $field, mixed $value): mixed;
}
