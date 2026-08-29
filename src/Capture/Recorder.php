<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Capture;

use Closure;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Dispatch\Dispatcher;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Pipeline\Pipeline;
use ElPandaPe\Sentinel\Support\Reference;
use ElPandaPe\Sentinel\Transactions\TransactionScope;
use Illuminate\Database\Eloquent\Model;

/**
 * The one door from a capture to the ledger. Capture decides what happened; this decides what
 * happens to it next, and it is the only place that knows both the pipeline and the dispatcher.
 *
 * There was a door per capture before, which was fine while the answer was always "transform it
 * and write it". It stops being fine the moment anything has to be true of every entry the
 * package writes — a correlation stamped on all of them, a decision about when they settle —
 * because two doors mean two places to get that right and one place to forget it.
 *
 * Where the entry lands is not decided here. That question has three answers depending on one
 * setting, and mixing them in with "what happened" is how a mode becomes a branch at every point
 * of capture instead of a strategy in one place.
 */
final readonly class Recorder
{
    public function __construct(
        private Pipeline $pipeline,
        private Dispatcher $dispatcher,
        private TransactionScope $transactions,
    ) {}

    /**
     * The correlation is sealed here and not in a pipeline stage, because the pipeline is not
     * guaranteed to run while the scope is still open — a Sentinel::transaction() inside a
     * DB::transaction() closes before the commit that releases the entries.
     *
     * The return value is what was written by the time the call came back, which inside a
     * transaction is nothing. A caller that opened that transaction itself and can wait for its
     * commit asks with $settled instead, and is handed the entry on whichever path wrote it.
     *
     * @param  Closure(Audit): void|null  $settled
     */
    public function record(AuditData $audit, ?Model $subject = null, ?Reference $actor = null, ?Closure $settled = null): ?Audit
    {
        $this->transactions->stamp($audit);

        $transformed = $this->pipeline->process($audit);

        if (! $transformed instanceof AuditData) {
            return null;
        }

        $this->attribute($transformed, $actor);

        return $this->dispatcher->dispatch($transformed, $subject, $settled);
    }

    /**
     * An actor the caller named outright is put back after the pipeline, because the context stage
     * reassigns that column on every pass — deliberately, so a second pass leaves none of the first
     * one's residue. A severity does not need this: it is decided at capture and no stage touches
     * it. The chain is unaffected either way, since the hash is sealed in the ledger, after this.
     *
     * The impersonator goes with it. Whoever the session resolved was standing in for the actor
     * that was resolved alongside them, not for the one the caller has just named, and an entry
     * pairing the two would claim a delegation that never happened.
     */
    private function attribute(AuditData $audit, ?Reference $actor): void
    {
        if (! $actor instanceof Reference) {
            return;
        }

        $audit->actor_type = $actor->type;
        $audit->actor_id = $actor->id;
        $audit->impersonator_type = null;
        $audit->impersonator_id = null;
    }
}
