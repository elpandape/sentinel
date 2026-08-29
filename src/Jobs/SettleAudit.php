<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Jobs;

use ElPandaPe\Sentinel\Context\Runtime;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Dispatch\Settlement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * The write, moved off the request that caused it. What travels is the entry the pipeline already
 * finished with — filtered, redacted, encrypted, its context resolved — and never a model: a worker
 * re-reading the record would photograph it as it is now rather than as it was then, and would
 * resolve a context describing itself.
 *
 * It carries an array and not the object, so a worker running the previous release can read a
 * payload the current one wrote. That matters on exactly one day, and it is the day a rolling
 * deploy has both versions running at once.
 *
 * Nothing is caught here. In a worker the queue is the failure policy: it retries under the same
 * capture identifier — which the ledger refuses to settle twice — and what still does not land ends
 * up where an operator can see it. The write-failure policy governs the request that caused the
 * fact, and by now there is no request left to protect.
 */
final class SettleAudit implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(public array $payload) {}

    public function handle(Settlement $settlement, Runtime $runtime): void
    {
        $audit = AuditData::fromPayload($this->payload);

        $runtime->whileWritingAudit(static fn (): mixed => $settlement->settleOnce($audit));
    }
}
