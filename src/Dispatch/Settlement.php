<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Dispatch;

use ElPandaPe\Sentinel\Contracts\Deduplicates;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Events\AuditCreated;
use ElPandaPe\Sentinel\Events\AuditCreating;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Support\AuditCollection;
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
        return $this->unsettled([$audit]) === [] ? null : $this->settle($audit);
    }

    /**
     * A batch, settled in one assignment of the sequence instead of one per entry. It is what makes
     * a flush viable: reading the tail of a stream is the part that cannot be shared between two
     * writes, so doing it once for five hundred entries is the difference between a mode that
     * scales and one that only defers.
     *
     * The cycle is announced per entry and not per batch, because that is what it has always meant:
     * one entry is about to be written, one entry exists. A batch that loses an entry to a race
     * announces the first without the second, which is exactly what happened.
     *
     * @param  list<AuditData>  $audits
     */
    public function settleBatch(array $audits): AuditCollection
    {
        $fresh = $this->unsettled($audits);

        if ($fresh === []) {
            return new AuditCollection;
        }

        foreach ($fresh as $audit) {
            $this->events->dispatch(new AuditCreating($audit));
        }

        $written = $this->ledger->writeMany($fresh);

        foreach ($written as $audit) {
            $this->events->dispatch(new AuditCreated($audit));
        }

        return $written;
    }

    public function settle(AuditData $audit): Audit
    {
        $this->events->dispatch(new AuditCreating($audit));

        $written = $this->ledger->write($audit);

        $this->events->dispatch(new AuditCreated($written));

        return $written;
    }

    /**
     * The entries of this batch that have no entry yet. A ledger that cannot look a capture up
     * answers for none of them, and the unique index stays the arbiter it always was.
     *
     * @param  list<AuditData>  $audits
     * @return list<AuditData>
     */
    private function unsettled(array $audits): array
    {
        $identifiers = array_values(array_filter(array_map(
            static fn (AuditData $audit): ?string => $audit->capture_id,
            $audits,
        )));

        if ($identifiers === [] || ! $this->ledger instanceof Deduplicates) {
            return $audits;
        }

        $settled = $this->ledger->settled($identifiers);

        return array_values(array_filter(
            $audits,
            static fn (AuditData $audit): bool => $audit->capture_id === null || ! in_array($audit->capture_id, $settled, true),
        ));
    }
}
