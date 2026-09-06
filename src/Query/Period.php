<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Query;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * The window a query is bounded by, both ends included. It is one criterion and not two
 * nullable ones because half a period is not a period: a query with a start and no end is a
 * state no caller can reach and no driver should have to answer for.
 *
 * The clock is created_at, the ledger's own, and not occurred_at — which has had two indexes of its
 * own since v0.10.0, so the reason is not the one it looks like. It is that created_at is the column
 * the rest of the machinery is built on: the partition key of both published range plans, and what
 * retention counts from. A period following the clock of the fact would select across every
 * partition and bound a window that does not line up with the one a purge works in.
 */
final readonly class Period
{
    public function __construct(
        public DateTimeImmutable $from,
        public DateTimeImmutable $to,
    ) {}

    public function covers(DateTimeInterface $moment): bool
    {
        return $moment >= $this->from && $moment <= $this->to;
    }
}
