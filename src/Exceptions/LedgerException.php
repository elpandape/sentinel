<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Exceptions;

use BadMethodCallException;
use ElPandaPe\Sentinel\Enums\Filter;

final class LedgerException extends BadMethodCallException
{
    public static function queryNotImplemented(): self
    {
        return new self('The ledger declares query() but does not implement it yet; it arrives with the Query API over AuditQuery.');
    }

    public static function cannotFilterBy(Filter $filter, string $ledger): self
    {
        return new self("{$ledger} cannot filter by {$filter->value}, so {$filter->method()}() is not part of the query it answers.");
    }
}
