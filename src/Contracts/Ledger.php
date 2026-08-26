<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Contracts;

use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Query\AuditQuery;
use ElPandaPe\Sentinel\Support\AuditCollection;

interface Ledger
{
    public function write(AuditData $audit): Audit;

    /**
     * @param  list<AuditData>  $audits
     */
    public function writeMany(array $audits): AuditCollection;

    public function find(string $id): ?Audit;

    public function query(AuditQuery $query): AuditCollection;

    public function stream(string $stream): LedgerStream;
}
