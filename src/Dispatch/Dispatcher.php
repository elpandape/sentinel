<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Dispatch;

use Closure;
use ElPandaPe\Sentinel\Contracts\DispatchStrategy;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Enums\Mode;
use ElPandaPe\Sentinel\Events\Audited;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Support\AuditCollection;
use ElPandaPe\Sentinel\Support\Config;
use ElPandaPe\Sentinel\Transactions\TransactionScope;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;

/**
 * How an entry the pipeline approved settles. Capture decides what happened and the pipeline
 * decides what it may say; this decides where and when it lands, and it is the only place that
 * knows the answer — which is what lets a mode be a setting rather than a branch repeated at
 * every point of capture.
 *
 * It never assigns a sequence and never computes a hash. Those belong to the ledger, in the same
 * operation as the write, in every mode: it is what makes the chain and an asynchronous mode
 * compatible at all, since the order entries arrive in is not the order the facts happened in.
 */
final readonly class Dispatcher
{
    public function __construct(
        private Container $container,
        private Config $config,
        private DatabaseManager $database,
        private TransactionScope $transactions,
        private Events $events,
    ) {}

    /**
     * The subject is asked for its connection and nothing else: the transaction that decides
     * whether the fact happened is the one the subject was written in, not the one the ledger
     * writes to. An entry with no subject waits on the default connection.
     *
     * What waits for the commit is this, never the pipeline. Every stage runs at capture because
     * the context only exists then: the actor can change before the commit, a manual context is
     * restored the moment its callback returns, and the tenant decides the stream — which is to
     * say which chain signs the entry.
     *
     * Outside a transaction the entry is handed over on the spot, so an installation that never
     * opens one behaves exactly as it did, failures included. Inside one, the framework decides
     * when the callback runs: it discards it on a rollback, keeps it through the commit of a
     * savepoint, and runs it immediately where a test harness has replaced the manager — which
     * is why the settled entry is read back out rather than assumed absent.
     *
     * @param  Closure(Audit): void|null  $settled
     */
    public function dispatch(AuditData $audit, ?Model $subject = null, ?Closure $settled = null): ?Audit
    {
        $connection = $this->connection($subject);

        if (! $this->config->afterCommit() || $connection->transactionLevel() === 0) {
            return $this->handed($this->strategy()->inRequest($audit), $audit, $settled);
        }

        $written = null;

        $connection->afterCommit(function () use ($audit, $settled, &$written): void {
            $written = $this->handed($this->strategy()->afterCommit($audit), $audit, $settled);
        });

        return $written;
    }

    /**
     * Several entries one operation produced, landing together. Same decision as above and same
     * three answers to it: what a batch adds is that the entries reach the ledger in one assignment
     * of the sequence, which is what keeps a mass operation from paying a tail read per row.
     *
     * What comes back is what settled in this process, which under an asynchronous mode is nothing
     * — the same answer dispatch() gives, for the same reason.
     *
     * @param  list<AuditData>  $audits
     * @return AuditCollection<int, Audit>
     */
    public function dispatchMany(array $audits, ?Model $subject = null): AuditCollection
    {
        if ($audits === []) {
            return new AuditCollection;
        }

        $connection = $this->connection($subject);

        if (! $this->config->afterCommit() || $connection->transactionLevel() === 0) {
            return $this->handedAll($this->strategy()->inRequestBatch($audits), $audits);
        }

        $written = new AuditCollection;

        $connection->afterCommit(function () use ($audits, &$written): void {
            $written = $this->handedAll($this->strategy()->afterCommitBatch($audits), $audits);
        });

        return $written;
    }

    /**
     * Resolved per call rather than held, because the mode is configuration and configuration is
     * allowed to change between one entry and the next — in a test that asserts on both, and in
     * an application that switches under load.
     */
    private function strategy(): DispatchStrategy
    {
        return match ($this->config->mode()) {
            Mode::Sync => $this->container->make(SyncStrategy::class),
            Mode::Queue => $this->container->make(QueueStrategy::class),
            Mode::Buffered => $this->container->make(BufferStrategy::class),
        };
    }

    /**
     * An operation counts what its entries got as far as here, and a refused hand-off is not one
     * of them: a capture the pipeline passed and a rollback then threw away is not something the
     * operation wrote, and a header claiming it would be a header nobody could reconcile against
     * the entries carrying its id.
     *
     * The journey is announced from here rather than from a strategy, because it is the same fact
     * in every mode — this process is done with this entry — and an event per strategy would make
     * three of what is one.
     *
     * @param  Closure(Audit): void|null  $settled
     */
    private function handed(Handover $handover, AuditData $audit, ?Closure $settled): ?Audit
    {
        if (! $handover->accepted) {
            return null;
        }

        $this->transactions->settled();

        $this->events->dispatch(new Audited($audit, $handover->entry));

        if ($handover->entry instanceof Audit && $settled instanceof Closure) {
            $settled($handover->entry);
        }

        return $handover->entry;
    }

    /**
     * Announced one at a time, because that is what the event has always meant: this process is
     * done with this entry. A batch that lost one to a race announces the rest, which is exactly
     * what happened.
     *
     * @param  list<Handover>  $handovers
     * @param  list<AuditData>  $audits
     * @return AuditCollection<int, Audit>
     */
    private function handedAll(array $handovers, array $audits): AuditCollection
    {
        $written = new AuditCollection;

        foreach ($handovers as $index => $handover) {
            $entry = $this->handed($handover, $audits[$index], null);

            if ($entry instanceof Audit) {
                $written->push($entry);
            }
        }

        return $written;
    }

    private function connection(?Model $subject): Connection
    {
        $connection = $subject?->getConnection();

        return $connection instanceof Connection ? $connection : $this->database->connection();
    }
}
