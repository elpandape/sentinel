<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Ledger;

use ElPandaPe\Sentinel\Contracts\DeclaresFilters;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Contracts\LedgerStream;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Enums\FanoutPolicy;
use ElPandaPe\Sentinel\Enums\Filter;
use ElPandaPe\Sentinel\Events\LedgerDestinationFailed;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Query\AuditQuery;
use ElPandaPe\Sentinel\Support\AuditCollection;
use Illuminate\Contracts\Events\Dispatcher;
use Throwable;

/**
 * One entry, several destinations: hot plus cold, or hot plus a search satellite.
 *
 * Only the primary assigns the sequence and seals the hash. The secondaries are handed the
 * entry it sealed, through append(), because two ledgers each numbering their own chain
 * produce two different truths about one fact. For the same reason every read goes to the
 * primary: it is the destination whose chain the sequence belongs to.
 */
final readonly class FanoutLedger implements DeclaresFilters, Ledger
{
    /**
     * @param  list<Ledger>  $secondaries
     */
    public function __construct(
        private Ledger $primary,
        private array $secondaries,
        private FanoutPolicy $policy,
        private Dispatcher $events,
    ) {}

    public function write(AuditData $audit): Audit
    {
        return $this->fanOut($this->primary->write($audit));
    }

    public function writeMany(array $audits): AuditCollection
    {
        $written = $this->primary->writeMany($audits);

        foreach ($written as $audit) {
            $this->fanOut($audit);
        }

        return $written;
    }

    public function append(Audit $audit): Audit
    {
        return $this->fanOut($this->primary->append($audit));
    }

    public function find(string $id): ?Audit
    {
        return $this->primary->find($id);
    }

    public function query(AuditQuery $query): AuditCollection
    {
        return $this->primary->query($query);
    }

    public function supportedFilters(): array
    {
        return $this->primary instanceof DeclaresFilters ? $this->primary->supportedFilters() : Filter::cases();
    }

    public function stream(string $stream): LedgerStream
    {
        return $this->primary->stream($stream);
    }

    private function fanOut(Audit $audit): Audit
    {
        foreach ($this->secondaries as $secondary) {
            try {
                $secondary->append($audit);
            } catch (Throwable $exception) {
                /*
                 * Announced before the policy decides, because strict is the policy that most
                 * needs the announcement: it rethrows out of a primary that has already sealed
                 * and stored the entry, and this is the only event that names the entry that
                 * did land. Announced after the throw, the operator would be told a write did
                 * not complete and never told which one did.
                 */
                $this->events->dispatch(new LedgerDestinationFailed(
                    $secondary::class,
                    $audit->stream,
                    $audit->sequence,
                    $audit->id,
                    $exception,
                ));

                if ($this->policy === FanoutPolicy::Strict) {
                    throw $exception;
                }
            }
        }

        return $audit;
    }
}
