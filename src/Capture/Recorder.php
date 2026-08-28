<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Capture;

use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Events\AuditWriteFailed;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Pipeline\Pipeline;
use ElPandaPe\Sentinel\Support\Config;
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
    ) {}

    /**
     * The subject is asked for its connection and nothing else: the transaction that decides
     * whether the fact happened is the one the subject was written in, not the one the ledger
     * writes to. An entry with no subject waits on the default connection.
     *
     * The correlation is sealed here and not in a pipeline stage, because the pipeline is not
     * guaranteed to run while the scope is still open — a Sentinel::transaction() inside a
     * DB::transaction() closes before the commit that releases the entries.
     */
    public function record(AuditData $audit, ?Model $subject = null): ?Audit
    {
        $this->transactions->stamp($audit);

        $transformed = $this->pipeline->process($audit);

        return $transformed instanceof AuditData ? $this->settle($transformed, $subject) : null;
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
    private function settle(AuditData $audit, ?Model $subject): ?Audit
    {
        $connection = $this->connection($subject);

        if (! $this->config->afterCommit() || $connection->transactionLevel() === 0) {
            return $this->written($this->ledger->write($audit));
        }

        $written = null;

        $connection->afterCommit(function () use ($audit, &$written): void {
            $written = $this->deferred($audit);
        });

        return $written;
    }

    /**
     * The deferred write is its own failure boundary. The framework runs commit callbacks in a
     * bare foreach, so an exception here would stop every later entry of the same transaction
     * from being attempted at all — an append-only engine losing the rest of an operation
     * because the first entry hit a constraint — and would surface out of a DB::transaction()
     * that has already committed. So it is announced, the way a fanout destination that refuses
     * an entry is announced, rather than swallowed or thrown.
     */
    private function deferred(AuditData $audit): ?Audit
    {
        try {
            return $this->written($this->ledger->write($audit));
        } catch (Throwable $failure) {
            $this->events->dispatch(AuditWriteFailed::of($audit, $failure));

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
