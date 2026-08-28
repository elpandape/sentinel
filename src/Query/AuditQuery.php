<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Query;

use DateTimeImmutable;
use DateTimeInterface;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Diff\Pointer;
use ElPandaPe\Sentinel\Enums\AuditEvent;
use ElPandaPe\Sentinel\Enums\Filter;
use ElPandaPe\Sentinel\Enums\RelationOperation;
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Enums\Source;
use ElPandaPe\Sentinel\Exceptions\ComparisonException;
use ElPandaPe\Sentinel\Exceptions\LedgerException;
use ElPandaPe\Sentinel\Exceptions\QueryException;
use ElPandaPe\Sentinel\Models\Audit;
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

    public private(set) ?TagCriteria $tags = null;

    public private(set) ?RelationCriteria $relations = null;

    public private(set) ?string $changedField = null;

    /**
     * @var list<int>
     */
    public private(set) array $versions = [];

    public private(set) bool $newestFirst = false;

    public private(set) bool $byOccurrence = false;

    public private(set) ?int $limit = null;

    public private(set) ?int $offset = null;

    /**
     * @var list<Filter>
     */
    private array $supported;

    public function __construct(private readonly Ledger $ledger)
    {
        $this->supported = Filter::answeredBy($ledger);
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

    /**
     * Every label named, on the same entry. Asking twice accumulates, so this and passing the
     * whole list at once are the same question.
     *
     * @param  list<string>|string  $tag
     */
    public function whereTag(array|string $tag): self
    {
        $query = $this->accepting(Filter::Tag);
        $query->tags = ($this->tags ?? new TagCriteria)->requiring($this->labels($tag));

        return $query;
    }

    /**
     * At least one of the labels named.
     *
     * @param  list<string>|string  $tag
     */
    public function whereAnyTag(array|string $tag): self
    {
        $query = $this->accepting(Filter::Tag);
        $query->tags = ($this->tags ?? new TagCriteria)->including($this->labels($tag));

        return $query;
    }

    /**
     * Entries that touched this relation. The three relation criteria narrow the same line, so an
     * entry answers only when one of its lines satisfies all of them at once — asked separately,
     * a question about a role being attached would also be answered by an entry that attached
     * something else and detached that role.
     */
    public function whereRelation(string $relation): self
    {
        $query = $this->accepting(Filter::Relation);
        $query->relations = ($this->relations ?? new RelationCriteria)->named($relation);

        return $query;
    }

    /**
     * Entries that touched this particular record through a relation.
     */
    public function whereRelated(object|string $related, int|string|null $id = null): self
    {
        $query = $this->accepting(Filter::Related);
        $query->relations = ($this->relations ?? new RelationCriteria)->to(Reference::to($related, $id));

        return $query;
    }

    /**
     * What happened to the related record: attached, detached, or its pivot updated. Naming more
     * than one asks for any of them, and asking twice accumulates.
     */
    public function whereOperation(RelationOperation|string ...$operations): self
    {
        $query = $this->accepting(Filter::Operation);
        $query->relations = ($this->relations ?? new RelationCriteria)->doing(
            array_map($this->operation(...), array_values($operations)),
        );

        return $query;
    }

    /**
     * Entries whose diff touched this field. Dot notation is read as a JSON Pointer, and a
     * pointer matches itself or anything beneath it — the same thing $audit->diffFor() means by
     * touching a field, so the package has one answer to that question and not two.
     */
    public function whereFieldChanged(string $path): self
    {
        $pointer = Pointer::of($path);

        if ($pointer === '') {
            throw QueryException::noField();
        }

        $query = $this->accepting(Filter::FieldChanged);
        $query->changedField = $pointer;

        return $query;
    }

    /**
     * The entries a subject carried at these version numbers. A refiner: the counter is not
     * indexed and this narrows a set that another filter has already found.
     */
    public function whereVersion(int ...$versions): self
    {
        $query = $this->accepting(Filter::Version);
        $query->versions = array_values(array_unique([...$this->versions, ...$versions]));

        return $query;
    }

    /**
     * Order by the clock of the fact rather than the clock of the ledger. The two agree while
     * writing is synchronous and come apart the moment it is not, and it is the first that says
     * what order things happened in.
     */
    public function byOccurrence(): self
    {
        $query = clone $this;
        $query->byOccurrence = true;

        return $query;
    }

    /**
     * Newest first, by whichever clock the query is ordered on.
     */
    public function latest(): self
    {
        $query = clone $this;
        $query->newestFirst = true;

        return $query;
    }

    /**
     * Everything the filter matches, up to the bound. If the bound is reached the read is
     * refused rather than answered: a prefix shaped exactly like a complete answer is the one
     * mistake a trail cannot afford, and this surface already declines to issue a read whose
     * size it would only learn once it arrived. take() asks for a prefix on purpose; paginate()
     * walks the whole thing.
     */
    public function get(): AuditCollection
    {
        if ($this->limit !== null) {
            return $this->ledger->query($this);
        }

        $query = clone $this;
        $query->limit = self::DEFAULT_LIMIT + 1;

        $entries = $this->ledger->query($query);

        return $entries->count() > self::DEFAULT_LIMIT
            ? throw QueryException::unbounded(self::DEFAULT_LIMIT)
            : $entries;
    }

    /**
     * A prefix, asked for knowingly.
     */
    public function take(int $limit): self
    {
        if ($limit < 1) {
            throw QueryException::unreachableLimit($limit);
        }

        $query = clone $this;
        $query->limit = $limit;

        return $query;
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

    /**
     * What changed for one subject between two of its versions. Two versions rather than two
     * adjacent ones is the whole point, and it costs one read: prior art does this with a loop
     * in the host language, or documents it as out of reach.
     *
     * A version is not unique — the ledger assigns it without a lock — so a repeated number
     * resolves to the newest entry carrying it.
     */
    public function compare(int $from, int $to): Comparison
    {
        if (! $this->subject instanceof Reference) {
            throw ComparisonException::withoutSubject();
        }

        $entries = $this->whereVersion($from, $to)->latest()->get();

        return Comparison::between($this->at($entries, $from), $this->at($entries, $to));
    }

    private function at(AuditCollection $entries, int $version): Audit
    {
        return $entries->firstWhere('version', $version)
            ?? throw ComparisonException::missingVersion($version);
    }

    private function operation(RelationOperation|string $operation): RelationOperation
    {
        if ($operation instanceof RelationOperation) {
            return $operation;
        }

        return RelationOperation::tryFrom($operation) ?? throw QueryException::unknownOperation($operation);
    }

    /**
     * @param  list<string>|string  $tag
     * @return non-empty-list<string>
     */
    private function labels(array|string $tag): array
    {
        $labels = array_values(array_unique(is_string($tag) ? [$tag] : $tag));

        return $labels === [] ? throw QueryException::noLabels() : $labels;
    }

    private function accepting(Filter $filter): self
    {
        return in_array($filter, $this->supported, true)
            ? clone $this
            : throw LedgerException::cannotFilterBy($filter, $this->ledger::class);
    }
}
