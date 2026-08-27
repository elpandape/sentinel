<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Diff;

use InvalidArgumentException;

/**
 * The only failure the component knows. It lives here and not in the package's Exceptions
 * namespace on purpose: Diff carries no dependency on the rest of Sentinel, and an
 * exception imported from outside would be one.
 */
final class DiffException extends InvalidArgumentException
{
    public static function unsupportedType(string $path, mixed $value): self
    {
        return new self(sprintf(
            'Cannot diff [%s]: a value of type [%s] has no representation.',
            $path === '' ? '/' : $path,
            get_debug_type($value),
        ));
    }

    public static function tooDeep(string $path): self
    {
        return new self(sprintf(
            'Cannot diff [%s]: the structure is deeper than the %d levels the comparison allows.',
            $path === '' ? '/' : $path,
            Normalizer::MAX_DEPTH,
        ));
    }

    public static function malformedEntry(int $index): self
    {
        return new self(sprintf('Diff entry at index %d is not a {path, op, old, new} entry.', $index));
    }

    public static function malformedPatch(int $index): self
    {
        return new self(sprintf('JSON Patch operation at index %d is malformed.', $index));
    }

    public static function unsupportedOperation(string $op): self
    {
        return new self(sprintf('JSON Patch operation [%s] has no diff equivalent.', $op));
    }
}
