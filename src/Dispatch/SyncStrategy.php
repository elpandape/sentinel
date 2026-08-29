<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Dispatch;

use ElPandaPe\Sentinel\Capture\WriteFailure;
use ElPandaPe\Sentinel\Contracts\DispatchStrategy;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Models\Audit;
use Throwable;

/**
 * The entry settles in the process that captured it, inside the call that caused it. Maximum
 * durability, and the cost lands on whoever caused the fact — which is the right default for an
 * audit engine, and the only mode where the caller can still be told the write did not work.
 */
final readonly class SyncStrategy implements DispatchStrategy
{
    public function __construct(
        private Settlement $settlement,
        private WriteFailure $failures,
    ) {}

    /**
     * The write that happens in the request, where the configured policy is free to decide: the
     * caller is still on the stack and an exception reaches whoever caused the entry.
     */
    public function inRequest(AuditData $audit): ?Audit
    {
        try {
            return $this->settlement->settle($audit);
        } catch (Throwable $failure) {
            $this->failures->inRequest($audit, $failure);

            return null;
        }
    }

    /**
     * The deferred write is its own failure boundary, and the policy does not reach it. The
     * framework runs commit callbacks in a bare foreach, so an exception here would stop every
     * later entry of the same transaction from being attempted at all — an append-only engine
     * losing the rest of an operation because the first entry hit a constraint — and would
     * surface out of a DB::transaction() that has already committed.
     */
    public function afterCommit(AuditData $audit): ?Audit
    {
        try {
            return $this->settlement->settle($audit);
        } catch (Throwable $failure) {
            $this->failures->afterCommit($audit, $failure);

            return null;
        }
    }
}
