<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Fixtures;

use ElPandaPe\Sentinel\Contracts\DeclaresFilters;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Contracts\LedgerStream;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Enums\Filter;
use ElPandaPe\Sentinel\Ledger\ArrayStream;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Query\AuditQuery;
use ElPandaPe\Sentinel\Support\AuditCollection;
use RuntimeException;

/**
 * A driver over a backend that can look an entry up by subject and by nothing else. It is
 * what a third-party ledger with a narrower store looks like from the query surface.
 */
final class LimitedLedger implements DeclaresFilters, Ledger
{
    public function supportedFilters(): array
    {
        return [Filter::Subject];
    }

    public function write(AuditData $audit): Audit
    {
        throw new RuntimeException('not part of what this fixture answers');
    }

    public function writeMany(array $audits): AuditCollection
    {
        throw new RuntimeException('not part of what this fixture answers');
    }

    public function append(Audit $audit): Audit
    {
        throw new RuntimeException('not part of what this fixture answers');
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
