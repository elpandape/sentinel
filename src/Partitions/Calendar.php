<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Partitions;

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Retention\Duration;

/**
 * Which months a divided table should have, and which of the ones it has are behind it.
 *
 * It answers from the clock alone and never touches a database, which is what lets the whole
 * decision be read in one place and tested without one.
 */
final readonly class Calendar
{
    /**
     * This month and the next few. The current one is included deliberately: an installation that
     * runs the command for the first time on a table created before this release has no partition
     * for today, and a maintenance command whose first run leaves the write path broken until next
     * month is worse than useless.
     *
     * @return list<Partition>
     */
    public function ahead(string $prefix, CarbonImmutable $now, int $months): array
    {
        $first = $now->startOfMonth();

        return array_map(
            static fn (int $month): Partition => Partition::of($prefix, $first->addMonths($month)),
            range(0, max(0, $months)),
        );
    }

    /**
     * The ones whose month ended before the cutoff the duration names. A catch-all is never here:
     * it has no end, and the floor of a divided table is not something maintenance removes.
     *
     * @param  list<Partition>  $existing
     * @return list<Partition>
     */
    public function behind(array $existing, CarbonImmutable $now, Duration $keep): array
    {
        $cutoff = $keep->cutoff($now);

        return array_values(array_filter(
            $existing,
            static fn (Partition $partition): bool => ! $partition->catchAll() && $partition->endedBefore($cutoff),
        ));
    }

    /**
     * The ones of `ahead` that are not there yet, compared by name so a partition an operator
     * created by hand under the same name is left alone rather than attempted again.
     *
     * @param  list<Partition>  $wanted
     * @param  list<Partition>  $existing
     * @return list<Partition>
     */
    public function missing(array $wanted, array $existing): array
    {
        $names = array_map(static fn (Partition $partition): string => $partition->name, $existing);

        return array_values(array_filter(
            $wanted,
            static fn (Partition $partition): bool => ! in_array($partition->name, $names, true),
        ));
    }
}
