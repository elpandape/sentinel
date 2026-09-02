<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Partitions;

/**
 * What one run of the maintenance did to one table, and what it declined to do.
 *
 * A table that is not divided is not a failure and does not report one: it reports that there was
 * nothing to divide. A command that exits non-zero on the ordinary state of an installation that
 * never partitioned anything is a command nobody can schedule.
 */
final readonly class Maintenance
{
    /**
     * @param  list<string>  $created
     * @param  list<string>  $retired
     * @param  array<string, string>  $kept  partition name to the reason it stayed
     */
    public function __construct(
        public string $table,
        public bool $divided,
        public array $created = [],
        public array $retired = [],
        public array $kept = [],
    ) {}

    public function refused(): bool
    {
        return $this->kept !== [];
    }

    public function idle(): bool
    {
        return $this->created === [] && $this->retired === [];
    }
}
