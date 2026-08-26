<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Exceptions;

use BadMethodCallException;

final class LedgerException extends BadMethodCallException
{
    public static function queryNotImplemented(): self
    {
        return new self('The ledger declares query() but does not implement it yet; it arrives with the Query API over AuditQuery.');
    }
}
