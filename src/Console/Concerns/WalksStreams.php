<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Console\Concerns;

use ElPandaPe\Sentinel\Contracts\EnumeratesStreams;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Exceptions\QueryException;

/**
 * Every stream, for the commands that work on all of them when told none.
 *
 * Three commands asked this and two of them called it different names, which is how a reader ends
 * up believing there are two rules. There is one: a ledger that cannot list its chains is asked to
 * be given one, and it says so rather than working on whatever it happens to know about.
 */
trait WalksStreams
{
    /**
     * @return list<string>
     */
    private function streams(Ledger $ledger): array
    {
        return $ledger instanceof EnumeratesStreams
            ? $ledger->streams()
            : throw QueryException::cannotEnumerateStreams($ledger::class);
    }
}
