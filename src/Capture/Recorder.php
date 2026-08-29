<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Capture;

use Closure;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Events\AuditCreated;
use ElPandaPe\Sentinel\Events\AuditCreating;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Pipeline\Pipeline;
use ElPandaPe\Sentinel\Support\Config;
use ElPandaPe\Sentinel\Support\Reference;
use ElPandaPe\Sentinel\Transactions\TransactionScope;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;
use Throwable;

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
        private TransactionScope $transactions,
        private Config $config,
        private DatabaseManager $database,
        private Dispatcher $events,
        private WriteFailure $failures,
    ) {}

    /**
     * The subject is asked for its connection and nothing else: the transaction that decides
     * whether the fact happened is the one the subject was written in, not the one the ledger
     * writes to. An entry with no subject waits on the default connection.
     *
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

        return $this->settle($transformed, $subject, $settled);
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

    /**
     * What waits for the commit is the write, never the pipeline. Every stage runs at capture
     * because the context only exists then: the actor can change before the commit, a manual
     * context is restored the moment its callback returns, and the tenant decides the stream —
     * which is to say which chain signs the entry. Deferring the pipeline would also break the
     * rule it has carried since it was written, that nothing sensitive waits untransformed.
     *
     * Outside a transaction the entry is written on the spot, so an installation that never
     * opens one behaves exactly as it did, failures included. Inside one, the framework decides
     * when the callback runs: it discards it on a rollback, keeps it through the commit of a
     * savepoint, and runs it immediately where a test harness has replaced the manager — which
     * is why the written entry is read back out rather than assumed absent.
     */
    private function settle(AuditData $audit, ?Model $subject, ?Closure $settled): ?Audit
    {
        $connection = $this->connection($subject);

        if (! $this->config->afterCommit() || $connection->transactionLevel() === 0) {
            return $this->announce($this->attempted($audit), $settled);
        }

        $written = null;

        $connection->afterCommit(function () use ($audit, $settled, &$written): void {
            $written = $this->announce($this->deferred($audit), $settled);
        });

        return $written;
    }

    /**
     * The ledger boundary, announced from here rather than from a driver: the ledger is a contract
     * with several implementations and a fanout that wraps them, and an event per implementation
     * would be an event per destination for what the package treats as one write. One triple per
     * call, on whichever branch the call took.
     */
    private function write(AuditData $audit): Audit
    {
        $this->events->dispatch(new AuditCreating($audit));

        $written = $this->ledger->write($audit);

        $this->events->dispatch(new AuditCreated($written));

        return $written;
    }

    /**
     * @param  Closure(Audit): void|null  $settled
     */
    private function announce(?Audit $audit, ?Closure $settled): ?Audit
    {
        if ($audit instanceof Audit && $settled instanceof Closure) {
            $settled($audit);
        }

        return $audit;
    }

    /**
     * The write that happens in the request, where the configured policy is free to decide: the
     * caller is still on the stack and an exception reaches whoever caused the entry.
     */
    private function attempted(AuditData $audit): ?Audit
    {
        try {
            return $this->written($this->write($audit));
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
    private function deferred(AuditData $audit): ?Audit
    {
        try {
            return $this->written($this->write($audit));
        } catch (Throwable $failure) {
            $this->failures->afterCommit($audit, $failure);

            return null;
        }
    }

    /**
     * An operation counts what its entries settled into, not what its captures got as far as. A
     * capture that the pipeline passed and a rollback then threw away is not something the
     * operation wrote, and a header claiming it would be a header nobody could reconcile against
     * the entries carrying its id.
     */
    private function written(Audit $audit): Audit
    {
        $this->transactions->settled();

        return $audit;
    }

    private function connection(?Model $subject): Connection
    {
        $connection = $subject?->getConnection();

        return $connection instanceof Connection ? $connection : $this->database->connection();
    }
}
