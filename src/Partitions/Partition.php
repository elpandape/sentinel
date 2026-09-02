<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Partitions;

use Carbon\CarbonImmutable;

/**
 * One month of a divided table: what the engine calls it, and the half-open range it holds.
 *
 * The range is read out of the name rather than out of the engine's own bounds, and that is a
 * decision rather than a shortcut. The two dialects describe a range in ways that have nothing in
 * common — PostgreSQL prints the expression back, MySQL stores the result of TO_DAYS — so parsing
 * them would be two more dialects to keep. Reading the name means this only ever maintains the
 * partitions it would have created itself, and leaves a partition somebody named otherwise exactly
 * where it is, which is the right answer for one somebody made on purpose.
 *
 * A name that does not parse has no range, and neither does a catch-all: PostgreSQL's DEFAULT and
 * MySQL's MAXVALUE exist so a write whose clock matches no declared month lands somewhere instead
 * of failing. Neither is ever a candidate for retirement — dropping the floor is not maintenance.
 */
final readonly class Partition
{
    private const string MONTH = '/p(\d{4})_(\d{2})$/';

    private function __construct(
        public string $name,
        public ?CarbonImmutable $from,
        public ?CarbonImmutable $to,
    ) {}

    public static function of(string $prefix, CarbonImmutable $month): self
    {
        $first = $month->startOfMonth();

        return new self("{$prefix}p{$first->format('Y_m')}", $first, $first->addMonth());
    }

    public static function named(string $name): self
    {
        if (preg_match(self::MONTH, $name, $matches) !== 1) {
            return new self($name, null, null);
        }

        $first = CarbonImmutable::create((int) $matches[1], (int) $matches[2], 1, 0, 0, 0);

        return $first instanceof CarbonImmutable
            ? new self($name, $first, $first->addMonth())
            : new self($name, null, null);
    }

    public function catchAll(): bool
    {
        return ! $this->to instanceof CarbonImmutable;
    }

    /**
     * Whether everything this holds is older than the cutoff. It is the end of the range that
     * decides, not the start: a month that ends after the cutoff still holds entries the policy
     * keeps, and a partition is retired whole or not at all.
     */
    public function endedBefore(CarbonImmutable $cutoff): bool
    {
        return $this->to instanceof CarbonImmutable && $this->to <= $cutoff;
    }
}
