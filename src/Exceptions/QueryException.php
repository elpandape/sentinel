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

    public static function backwardsPeriod(): self
    {
        return new self('A period that ends before it starts can only ever answer with nothing; pass the earlier moment first.');
    }

    public static function unreachablePage(int $perPage, int $page): self
    {
        return new self("A page of {$perPage} entries numbered {$page} is not a page: both have to be at least one.");
    }

    public static function unreferenceable(string $type): self
    {
        return new self("Cannot query for {$type}: pass an Eloquent model, or the type and key the entry recorded.");
    }
}
