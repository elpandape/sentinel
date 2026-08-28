<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Fixtures;

use ElPandaPe\Sentinel\Contracts\DeclaresFilters;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Contracts\LedgerStream;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Enums\Filter;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Query\AuditQuery;
use ElPandaPe\Sentinel\Support\AuditCollection;

/**
 * A third-party driver that keeps the whole chain but only translates the filters that had
 * been published when it was written. It is what the contract suite has to stay runnable
 * against: the expectations for a filter it never claimed are skipped, not failed.
 */
final readonly class NarrowLedger implements DeclaresFilters, Ledger
{
    public function __construct(private Ledger $store) {}

    /**
     * @return list<Filter>
     */
    public function supportedFilters(): array
    {
        return Filter::assumed();
    }

    public function write(AuditData $audit): Audit
    {
        return $this->store->write($audit);
    }

    public function writeMany(array $audits): AuditCollection
    {
        return $this->store->writeMany($audits);
    }

    public function append(Audit $audit): Audit
    {
        return $this->store->append($audit);
    }

    public function find(string $id): ?Audit
    {
        return $this->store->find($id);
    }

    public function query(AuditQuery $query): AuditCollection
    {
        return $this->store->query($query);
    }

    public function stream(string $stream): LedgerStream
    {
        return $this->store->stream($stream);
    }
}
