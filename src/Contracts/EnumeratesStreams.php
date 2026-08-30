<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Contracts;

/**
 * A ledger that can say which chains it holds. Declared and not required, for the same reason as
 * Deduplicates: the Ledger contract is deliberately answerable by a store that is not a table, and
 * one that keeps nothing has no list to give. Verifying the whole trail needs the list, so a driver
 * that cannot produce it is told so rather than asked twice.
 *
 * The order is the ledger's own and only has to be stable: a report that lists the same streams in a
 * different order on every run is a report nobody can diff.
 */
interface EnumeratesStreams
{
    /**
     * @return list<string>
     */
    public function streams(): array;
}
