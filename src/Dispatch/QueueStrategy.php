<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Dispatch;

use ElPandaPe\Sentinel\Capture\WriteFailure;
use ElPandaPe\Sentinel\Contracts\DispatchStrategy;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Jobs\SettleAudit;
use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Contracts\Bus\Dispatcher as Bus;
use Throwable;

/**
 * The entry leaves the request as a job and settles in a worker. What the request pays for is the
 * pipeline and one enqueue; what the worker pays for is the chain — which is the trade, and the
 * reason the mode exists at all.
 *
 * Nothing about the entry is decided later. The pipeline has already run, so no sensitive value is
 * waiting anywhere untransformed, and the context describes the request rather than the worker:
 * resolving it there would file every entry under a machine that did nothing.
 *
 * The job never carries a sequence, a hash or a link. Those are read from the chain and assigned
 * inside the write, in this mode exactly as in the other, because the order entries arrive in a
 * worker is not the order the facts happened in.
 */
final readonly class QueueStrategy implements DispatchStrategy
{
    public function __construct(
        private Bus $bus,
        private Config $config,
        private WriteFailure $failures,
    ) {}

    /**
     * A queue that will not take the job is a write that did not complete, and the configured
     * policy decides what that costs the request — the same answer the synchronous mode gives,
     * for the same reason: the caller is still on the stack.
     */
    public function inRequest(AuditData $audit): Handover
    {
        try {
            return $this->hand($audit);
        } catch (Throwable $failure) {
            $this->failures->inRequest($audit, $failure);

            return Handover::refused();
        }
    }

    public function afterCommit(AuditData $audit): Handover
    {
        try {
            return $this->hand($audit);
        } catch (Throwable $failure) {
            $this->failures->afterCommit($audit, $failure);

            return Handover::refused();
        }
    }

    /**
     * One job per entry, because that is what this mode is: the ledger work leaves the request and
     * the worker picks it up. There is nothing to amortise here — the tail read happens in the
     * worker, once per job, and batching the enqueue would only move the cost of the fan-out to
     * whoever dequeues it.
     *
     * @param  non-empty-list<AuditData>  $audits
     * @return list<Handover>
     */
    public function inRequestBatch(array $audits): array
    {
        return array_map($this->inRequest(...), $audits);
    }

    /**
     * @param  non-empty-list<AuditData>  $audits
     * @return list<Handover>
     */
    public function afterCommitBatch(array $audits): array
    {
        return array_map($this->afterCommit(...), $audits);
    }

    /**
     * The job is marked to wait for the commit whenever the package is, so the two never disagree.
     * With the deferral on it is belt and braces — the dispatch already happens from a commit
     * callback — and it is what covers the entry captured against one connection while a second
     * one still has a transaction open. With the deferral off it is left alone, because an operator
     * who turned that off asked for the entry to be written whatever the transaction decides.
     */
    private function hand(AuditData $audit): Handover
    {
        $job = new SettleAudit($audit->toPayload())
            ->onConnection($this->config->queueConnection())
            ->onQueue($this->config->queueName());

        if ($this->config->afterCommit()) {
            $job->afterCommit();
        }

        $this->bus->dispatch($job);

        return Handover::accepted();
    }
}
