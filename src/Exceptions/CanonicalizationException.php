<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Exceptions;

use RuntimeException;

final class CanonicalizationException extends RuntimeException
{
    public static function unsupportedNumber(float $value): self
    {
        return new self(sprintf(
            'Sentinel cannot canonicalize the number [%s]: JSON carries neither NAN nor INF.',
            is_nan($value) ? 'NAN' : ($value > 0 ? 'INF' : '-INF'),
        ));
    }

    public static function unsupportedType(mixed $value): self
    {
        return new self(sprintf(
            'Sentinel cannot canonicalize a value of type [%s]; a canonical payload holds scalars, arrays and null.',
            get_debug_type($value),
        ));
    }

    public static function invalidString(): self
    {
        return new self('Sentinel cannot canonicalize a string that is not valid UTF-8.');
    }
}
