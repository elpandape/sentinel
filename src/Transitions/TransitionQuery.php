<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Transitions;

use DateTimeInterface;
use ElPandaPe\Sentinel\Query\AuditQuery;
use Illuminate\Support\Collection;

/**
 * A lifeline, asked for. It composes an AuditQuery rather than extending it and publishes only the
 * criteria that mean something about a sequence of states: narrowing a lifeline by the field that
 * changed or by the relation that was touched would be asking a different question.
 *
 * Always ordered by the clock of the fact. The two clocks agree while writing is synchronous and
 * come apart the moment it is not, and it is the first that says how long a record was in a state.
 *
 * There is no paginate(). The interval of the first row of a page is the distance to an entry the
 * page does not contain, so a paged lifeline would hand back a number that is either wrong or
 * missing. entries() drops back to the query underneath, which pages like any other.
 */
final readonly class TransitionQuery
{
    public function __construct(private AuditQuery $query) {}

    public function for(object|string $subject, int|string|null $id = null): self
    {
        return new self($this->query->for($subject, $id));
    }

    public function by(object|string $actor, int|string|null $id = null): self
    {
        return new self($this->query->by($actor, $id));
    }

    public function between(DateTimeInterface $from, DateTimeInterface $to): self
    {
        return new self($this->query->between($from, $to));
    }

    public function latest(): self
    {
        return new self($this->query->latest());
    }

    public function take(int $limit): self
    {
        return new self($this->query->take($limit));
    }

    /**
     * The entries themselves, for the questions this surface does not answer: paging, the labels
     * an entry carries, everything a lifeline is not.
     */
    public function entries(): AuditQuery
    {
        return $this->query;
    }

    /**
     * @return Collection<int, Transition>
     */
    public function get(): Collection
    {
        $newestFirst = $this->query->newestFirst;
        $entries = $this->query->byOccurrence()->get();

        // The interval is the distance from the step before it in time, which is the step before
        // it in the list only while the list runs forwards.
        $ordered = $newestFirst ? $entries->reverse()->values() : $entries;

        $steps = new Collection;
        $previous = null;

        foreach ($ordered as $entry) {
            $previous = Transition::of($entry, $previous);
            $steps->push($previous);
        }

        return $newestFirst ? $steps->reverse()->values() : $steps;
    }
}
