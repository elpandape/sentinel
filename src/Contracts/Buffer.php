<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Contracts;

use DateTimeImmutable;
use ElPandaPe\Sentinel\Data\AuditData;

/**
 * Where entries wait between the capture that produced them and the flush that settles them.
 *
 * It is not a ledger and it is not durable. What is in here has no sequence, no hash and no place
 * in any chain: it is a fact that happened and that nothing has recorded yet. Everything the
 * buffered mode promises and everything it costs comes from that one sentence.
 *
 * Taking is destructive and has to be atomic, because two processes flushing at once is the normal
 * case rather than the edge one — a request terminating while the console command runs. A driver
 * that cannot take atomically will hand the same entries to both, and only the unique index on
 * `capture_id` stands between that and the same fact settled twice.
 */
interface Buffer
{
    public function push(AuditData $audit): void;

    /**
     * Up to $limit entries, removed as they are handed over, oldest first.
     *
     * @return list<AuditData>
     */
    public function take(int $limit): array;

    public function size(): int;

    /**
     * When the oldest waiting entry happened, or null when nothing is waiting. It is what the time
     * threshold is measured against, and it is the clock of the fact rather than of the push: under
     * a deferred write the two are minutes apart, and the window that matters to whoever is counting
     * losses starts when the thing happened.
     */
    public function oldest(): ?DateTimeImmutable;
}
