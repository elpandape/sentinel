<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Fixtures;

use ElPandaPe\Sentinel\Contracts\Masker;

final readonly class FieldNamingMasker implements Masker
{
    public function mask(string $field, mixed $value): mixed
    {
        return "<{$field}>";
    }
}
