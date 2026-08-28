<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Exceptions;

use InvalidArgumentException;

final class ComparisonException extends InvalidArgumentException
{
    public static function acrossSubjects(): self
    {
        return new self('Two entries about different subjects have no shared history to compare; an empty diff would read as agreement, which is not what happened.');
    }

    public static function withoutSubject(): self
    {
        return new self('Comparing two versions needs to know whose versions they are; narrow the query with for() first.');
    }

    public static function missingVersion(int $version): self
    {
        return new self("No entry of this subject carries version {$version}, so there is nothing at that point to compare.");
    }
}
