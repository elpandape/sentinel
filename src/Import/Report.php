<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Import;

/**
 * What an import read and what became of it.
 *
 * The four outcomes account for every row, and the test that says so is the point of counting them
 * separately: a row becomes an entry, or it was already an entry from an earlier run, or the
 * pipeline refused it, or the origin could not read it. An import whose numbers do not add up has
 * lost something, and an operator finding that out from the trail months later is exactly what an
 * audit engine exists to prevent.
 *
 * The refusals carry their reasons and the discards do not, and that is not an oversight. An
 * origin refuses a row for one of a handful of reasons it wrote itself, so grouping them tells an
 * operator whether they hit one odd row or a whole column they did not know was empty. A pipeline
 * discard already announces itself as an event carrying its stage and its reason, and counting the
 * same thing twice in two shapes is how the two come to disagree.
 */
final readonly class Report
{
    /**
     * @param  array<string, int>  $unmappable  why a row could not be read, and how often
     */
    public function __construct(
        public int $read,
        public int $written,
        public int $repeated,
        public int $discarded,
        public array $unmappable = [],
        public ?string $lastRow = null,
        public bool $rehearsed = false,
    ) {}

    public function unreadable(): int
    {
        return array_sum($this->unmappable);
    }

    /**
     * Whether every row read is accounted for. It is asked by the test and never by the command:
     * a report that does not balance is a defect in this class, not news for an operator.
     */
    public function balances(): bool
    {
        return $this->read === $this->written + $this->repeated + $this->discarded + $this->unreadable();
    }
}
