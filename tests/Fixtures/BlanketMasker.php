<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Fixtures;

use ElPandaPe\Sentinel\Contracts\Masker;

final readonly class BlanketMasker implements Masker
{
    public const string MASK = '[redacted]';

    public function mask(string $field, mixed $value): mixed
    {
        return $value === null ? null : self::MASK;
    }
}
