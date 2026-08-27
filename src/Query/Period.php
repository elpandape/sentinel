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
 * The clock is created_at, the ledger's own. occurred_at is what the entry says about the
 * world and it has no index of its own; created_at is what the ledger says about itself and
 * it is the column every composite index carries in its tail.
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
