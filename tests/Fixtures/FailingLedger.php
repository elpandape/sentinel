<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Fixtures;

use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Contracts\LedgerStream;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Ledger\ArrayStream;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Query\AuditQuery;
use ElPandaPe\Sentinel\Support\AuditCollection;
use RuntimeException;

/**
 * A destination that is down. It refuses what it is handed and says so, which is the only
 * behaviour a fanout policy is decided by.
 */
final class FailingLedger implements Ledger
{
    public const string REASON = 'this destination is unreachable';

    public function write(AuditData $audit): Audit
    {
        throw new RuntimeException(self::REASON);
    }

    public function writeMany(array $audits): AuditCollection
    {
        throw new RuntimeException(self::REASON);
    }

    public function append(Audit $audit): Audit
    {
        throw new RuntimeException(self::REASON);
    }

    public function find(string $id): ?Audit
    {
        return null;
    }

    public function query(AuditQuery $query): AuditCollection
    {
        return new AuditCollection([]);
    }

    public function stream(string $stream): LedgerStream
    {
        return new ArrayStream($stream, []);
    }
}
