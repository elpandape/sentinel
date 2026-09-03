<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Fixtures;

use ElPandaPe\Sentinel\Contracts\Deduplicates;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Contracts\LedgerStream;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Ledger\DatabaseLedger;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Query\AuditQuery;
use ElPandaPe\Sentinel\Support\AuditCollection;
use RuntimeException;

/**
 * A destination that takes a batch or two and then goes down, which is what a flush of several
 * batches meets in practice and what a fixture that refuses everything cannot reproduce: the
 * entries of the batches that landed are real, chained and deduplicable.
 */
final class LateFailingLedger implements Deduplicates, Ledger
{
    public const string REASON = 'this destination went down partway through';

    private int $batches = 0;

    public function __construct(
        private readonly DatabaseLedger $ledger,
        private readonly int $takes,
    ) {}

    public function write(AuditData $audit): Audit
    {
        return $this->ledger->write($audit);
    }

    public function writeMany(array $audits): AuditCollection
    {
        if (++$this->batches > $this->takes) {
            throw new RuntimeException(self::REASON);
        }

        return $this->ledger->writeMany($audits);
    }

    public function append(Audit $audit): Audit
    {
        return $this->ledger->append($audit);
    }

    public function find(string $id): ?Audit
    {
        return $this->ledger->find($id);
    }

    public function query(AuditQuery $query): AuditCollection
    {
        return $this->ledger->query($query);
    }

    public function stream(string $stream): LedgerStream
    {
        return $this->ledger->stream($stream);
    }

    public function settled(array $captureIds): array
    {
        return $this->ledger->settled($captureIds);
    }
}
