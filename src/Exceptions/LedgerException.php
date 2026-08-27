<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Exceptions;

use BadMethodCallException;
use ElPandaPe\Sentinel\Enums\Filter;

final class LedgerException extends BadMethodCallException
{
    public static function cannotFilterBy(Filter $filter, string $ledger): self
    {
        return new self("{$ledger} cannot filter by {$filter->value}, so {$filter->method()}() is not part of the query it answers.");
    }
}
