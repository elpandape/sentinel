<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Capture;

use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Pipeline\Pipeline;

/**
 * The one door from a capture to the ledger. Capture decides what happened; this decides what
 * happens to it next, and it is the only place that knows both the pipeline and the ledger.
 *
 * There was a door per capture before, which was fine while the answer was always "transform it
 * and write it". It stops being fine the moment anything has to be true of every entry the
 * package writes — a correlation stamped on all of them, a decision about when they settle —
 * because two doors mean two places to get that right and one place to forget it.
 */
final readonly class Recorder
{
    public function __construct(
        private Pipeline $pipeline,
        private Ledger $ledger,
    ) {}

    public function record(AuditData $audit): ?Audit
    {
        $transformed = $this->pipeline->process($audit);

        return $transformed instanceof AuditData ? $this->ledger->write($transformed) : null;
    }
}
