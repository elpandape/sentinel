<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Dispatch;

use ElPandaPe\Sentinel\Buffer\Flusher;
use ElPandaPe\Sentinel\Capture\WriteFailure;
use ElPandaPe\Sentinel\Contracts\Buffer;
use ElPandaPe\Sentinel\Contracts\DispatchStrategy;
use ElPandaPe\Sentinel\Data\AuditData;
use Throwable;

/**
 * The entry waits in the buffer and settles in batches. The least durable of the three modes and
 * the only one that can lose a fact outright: what a process dies holding never reached a chain,
 * has no sequence and no hash, and leaves no gap for verification to find. That is the trade, and
 * it is the reason the two thresholds exist — they are what bounds it.
 *
 * The pipeline has already run, exactly as in the other two: nothing sensitive waits in the buffer
 * untransformed, and the entry carries the context of the request that captured it rather than of
 * whatever process happens to vacate it.
 */
final readonly class BufferStrategy implements DispatchStrategy
{
    public function __construct(
        private Buffer $buffer,
        private Flusher $flusher,
        private WriteFailure $failures,
    ) {}

    public function inRequest(AuditData $audit): Handover
    {
        return $this->hand($audit, $this->failures->inRequest(...));
    }

    public function afterCommit(AuditData $audit): Handover
    {
        return $this->hand($audit, $this->failures->afterCommit(...));
    }

    /**
     * The entry is buffered first and the thresholds are read after, so a flush that fails cannot
     * cost the entry that triggered it: it is already waiting, and the failure is about the ones
     * that were there before.
     *
     * The hand-over is accepted either way. The entry is in the buffer whatever the flush did, and
     * saying otherwise would report as lost something that is going to settle on the next trigger.
     *
     * @param  callable(AuditData, Throwable): void  $failed
     */
    private function hand(AuditData $audit, callable $failed): Handover
    {
        $this->buffer->push($audit);

        try {
            if ($this->flusher->due()) {
                $this->flusher->flush();
            }
        } catch (Throwable $failure) {
            $failed($audit, $failure);
        }

        return Handover::accepted();
    }
}
