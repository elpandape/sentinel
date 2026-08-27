<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Exceptions;

use InvalidArgumentException;

final class QueryException extends InvalidArgumentException
{
    public static function unsavedModel(string $model): self
    {
        return new self("Cannot query for a {$model} that has no key yet: no entry can reference it.");
    }

    public static function missingKey(string $type): self
    {
        return new self("Querying by the recorded type \"{$type}\" needs the key it was recorded with as the second argument.");
    }

    public static function unreferenceable(string $type): self
    {
        return new self("Cannot query for {$type}: pass an Eloquent model, or the type and key the entry recorded.");
    }
}
