<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Capture;

use Closure;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Dispatch\Dispatcher;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Pipeline\Pipeline;
use ElPandaPe\Sentinel\Support\AuditCollection;
use ElPandaPe\Sentinel\Support\Reference;
use ElPandaPe\Sentinel\Transactions\TransactionScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

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
        $transformed = $this->prepared($audit);

        if (! $transformed instanceof AuditData) {
            return null;
        }

        $this->attribute($transformed, $actor);

        return $this->dispatcher->dispatch($transformed, $subject, $settled);
    }

    /**
     * Entries one operation produced together. Each goes through the pipeline on its own — a policy
     * may refuse one row of a batch and keep the rest, and an entry the pipeline discarded is not
     * something the operation wrote — and what survives lands in one hand-over.
     *
     * No actor argument and no callback. A mass operation is not something an actor is named for
     * after the fact, and nothing is waiting for one row of a thousand to come back.
     *
     * @param  list<AuditData>  $audits
     * @return AuditCollection<int, Audit>
     */
    public function recordMany(array $audits, ?Model $subject = null): AuditCollection
    {
        $transformed = [];

        foreach ($audits as $audit) {
            $passed = $this->prepared($audit);

            if ($passed instanceof AuditData) {
                $transformed[] = $passed;
            }
        }

        return $this->dispatcher->dispatchMany($transformed, $subject);
    }

    /**
     * Named and correlated, then transformed. Both stamps happen before the pipeline because both
     * belong to the entry rather than to its journey through one, and the correlation in particular
     * has to be sealed while the scope that owns it is still open.
     */
    private function prepared(AuditData $audit): ?AuditData
    {
        $this->identify($audit);

        $this->transactions->stamp($audit);

        return $this->pipeline->process($audit);
    }

    /**
     * What the capture calls this entry, so the entry it becomes can be recognised as the same one.
     * Stamped here rather than at each point of capture for the reason the whole class exists: an
     * identifier that has to be on every entry belongs where every entry goes past.
     *
     * It is the correlation and the idempotency key at once. Nothing outside the ledger reads it
     * yet, and it is deliberately outside the canonical payload — the entry is about what happened,
     * not about how it travelled — so writing it changes no hash.
     *
     * A caller that brought its own keeps it. Retrying a unit of work means retrying it under the
     * name it already had, and generating a second one here would make the retry a second fact.
     */
    private function identify(AuditData $audit): void
    {
        $audit->capture_id ??= (string) Str::ulid();
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
