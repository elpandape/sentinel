<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Dispatch;

use ElPandaPe\Sentinel\Contracts\Deduplicates;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Events\AuditCreated;
use ElPandaPe\Sentinel\Events\AuditCreating;
use ElPandaPe\Sentinel\Models\Audit;
use Illuminate\Contracts\Events\Dispatcher as Events;

/**
 * The ledger boundary, announced from here rather than from a driver: the ledger is a contract
 * with several implementations and a fanout that wraps them, and an event per implementation
 * would be an event per destination for what the package treats as one write.
 *
 * It carries no failure policy of its own on purpose. What a failed write costs depends on where
 * it is being attempted from — a request that can still be refused, a commit callback that cannot
 * be, a worker whose queue is going to retry it — and that is the caller's to know.
 */
final readonly class Settlement
{
    public function __construct(
        private Ledger $ledger,
        private Events $events,
    ) {}

    /**
     * The same capture, settled at most once. A queue retries and a flush repeats, and neither of
     * those is a second fact: what tells them apart is the identifier the capture stamped, and what
     * enforces it is the unique index the schema has carried since it was written.
     *
     * Asking first is not what makes it safe — two workers can both be told no and both write. It
     * is what keeps the common case, a retry of something that already landed, from sealing a chain
     * only to have the database throw it away.
     */
    public function settleOnce(AuditData $audit): ?Audit
    {
        return $this->alreadySettled($audit) ? null : $this->settle($audit);
    }

    public function settle(AuditData $audit): Audit
    {
        $this->events->dispatch(new AuditCreating($audit));

        $written = $this->ledger->write($audit);

        $this->events->dispatch(new AuditCreated($written));

        return $written;
    }

    private function alreadySettled(AuditData $audit): bool
    {
        return $audit->capture_id !== null
            && $this->ledger instanceof Deduplicates
            && $this->ledger->settled([$audit->capture_id]) !== [];
    }
}
