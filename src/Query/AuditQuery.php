<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Query;

use DateTimeImmutable;
use DateTimeInterface;
use ElPandaPe\Sentinel\Contracts\DeclaresFilters;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Enums\AuditEvent;
use ElPandaPe\Sentinel\Enums\Filter;
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Enums\Source;
use ElPandaPe\Sentinel\Exceptions\LedgerException;
use ElPandaPe\Sentinel\Exceptions\QueryException;
use ElPandaPe\Sentinel\Support\AuditCollection;
use ElPandaPe\Sentinel\Support\Reference;

/**
 * A query stated against the ledger contract instead of against Eloquent. It describes; it
 * never executes, and the only thing that turns a description into entries is the driver's
 * own Ledger::query(). That is why the same filter a SQL driver compiles into a where clause
 * a ledger over arrays resolves with no database at all.
 *
 * Every method returns a new instance, so a query passed around is a query nobody else can
 * narrow behind your back. Every method also names one indexed criterion: there is no
 * where(string $column, ...) and no builder to reach past this surface, because the read
 * that compliance mode has to record is the one that goes through here.
 */
final class AuditQuery
{
    /**
     * What get() asks for when nothing else bounds it. A trail has no natural end, so a read
     * with no bound is a read of the whole table: the surface declines to issue it rather
     * than discovering the size of the answer once it has arrived. paginate() walks past it.
     */
    public const int DEFAULT_LIMIT = 500;

    public private(set) ?Reference $subject = null;

    public private(set) ?Reference $actor = null;

    public private(set) ?string $event = null;

    public private(set) ?Severity $severity = null;

    public private(set) ?Source $source = null;

    public private(set) ?string $tenantId = null;

    public private(set) ?string $transactionId = null;

    public private(set) ?string $traceId = null;

    public private(set) ?Period $period = null;

    public private(set) bool $newestFirst = false;

    public private(set) ?int $limit = null;

    public private(set) ?int $offset = null;

    /**
     * @var list<Filter>
     */
    private array $supported;

    public function __construct(private readonly Ledger $ledger)
    {
        $this->supported = $ledger instanceof DeclaresFilters ? $ledger->supportedFilters() : Filter::cases();
    }

    public function for(object|string $subject, int|string|null $id = null): self
    {
        $query = $this->accepting(Filter::Subject);
        $query->subject = Reference::to($subject, $id);

        return $query;
    }

    public function forModel(object|string $subject, int|string|null $id = null): self
    {
        return $this->for($subject, $id);
    }

    public function by(object|string $actor, int|string|null $id = null): self
    {
        $query = $this->accepting(Filter::Actor);
        $query->actor = Reference::to($actor, $id);

        return $query;
    }

    public function byActor(object|string $actor, int|string|null $id = null): self
    {
        return $this->by($actor, $id);
    }

    public function whereEvent(AuditEvent|string $event): self
    {
        $query = $this->accepting(Filter::Event);
        $query->event = $event instanceof AuditEvent ? $event->value : $event;

        return $query;
    }

    public function whereSeverity(Severity $severity): self
    {
        $query = $this->accepting(Filter::Severity);
        $query->severity = $severity;

        return $query;
    }

    public function whereSource(Source $source): self
    {
        $query = $this->accepting(Filter::Source);
        $query->source = $source;

        return $query;
    }

    public function forTenant(string $tenant): self
    {
        $query = $this->accepting(Filter::Tenant);
        $query->tenantId = $tenant;

        return $query;
    }

    public function inTransaction(string $transaction): self
    {
        $query = $this->accepting(Filter::Transaction);
        $query->transactionId = $transaction;

        return $query;
    }

    public function withTrace(string $trace): self
    {
        $query = $this->accepting(Filter::Trace);
        $query->traceId = $trace;

        return $query;
    }

    public function between(DateTimeInterface $from, DateTimeInterface $to): self
    {
        if ($to < $from) {
            throw QueryException::backwardsPeriod();
        }

        $query = $this->accepting(Filter::Period);
        $query->period = new Period(
            DateTimeImmutable::createFromInterface($from),
            DateTimeImmutable::createFromInterface($to),
        );

        return $query;
    }

    public function latest(): self
    {
        $query = clone $this;
        $query->newestFirst = true;

        return $query;
    }

    public function get(): AuditCollection
    {
        $query = clone $this;
        $query->limit = self::DEFAULT_LIMIT;

        return $this->ledger->query($query);
    }

    /**
     * One page, one call to the ledger. It asks for one entry more than the page holds and
     * hands back the page without it, which answers whether there is another page for the
     * price of a row instead of the price of a count over everything the filter matches.
     */
    public function paginate(int $perPage, int $page = 1): AuditPage
    {
        if ($perPage < 1 || $page < 1) {
            throw QueryException::unreachablePage($perPage, $page);
        }

        $query = clone $this;
        $query->limit = $perPage + 1;
        $query->offset = ($page - 1) * $perPage;

        $entries = $this->ledger->query($query);

        return new AuditPage(
            $entries->take($perPage)->values(),
            $page,
            $perPage,
            $entries->count() > $perPage,
        );
    }

    private function accepting(Filter $filter): self
    {
        return in_array($filter, $this->supported, true)
            ? clone $this
            : throw LedgerException::cannotFilterBy($filter, $this->ledger::class);
    }
}
