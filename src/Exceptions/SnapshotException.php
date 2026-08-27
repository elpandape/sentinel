<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Exceptions;

use RuntimeException;

final class SnapshotException extends RuntimeException
{
    public static function unsupportedType(string $attribute, mixed $value): self
    {
        return new self(sprintf(
            'Attribute [%s] holds a %s, which cannot be represented in a snapshot. Exclude it with $auditExclude or cast it to something serializable.',
            $attribute,
            get_debug_type($value),
        ));
    }
}
