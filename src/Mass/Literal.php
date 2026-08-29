<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Mass;

use BackedEnum;
use DateTimeInterface;
use ElPandaPe\Sentinel\Diff\Normalizer;

/**
 * What a mass entry is allowed to write down of a value it was handed. A scalar, a date and an
 * enum are literals and go in; everything else stays out, and the entry says which column was
 * involved without saying what was in it.
 *
 * Narrower than what the snapshots accept, and deliberately so. A snapshot holds what a record
 * was, read from the record itself; this holds what a caller passed to a query, which is where
 * an SQL expression, a query object or an entity of the application's own turns up — and none of
 * those is something an entry can quote without either lying or leaking.
 */
final readonly class Literal
{
    /**
     * The value in a list of one, or an empty list when there is no writing it down. A list rather
     * than a null because null is itself a value a query can be given, and the two answers have to
     * stay apart.
     *
     * @return list<mixed>
     */
    public static function of(mixed $value): array
    {
        return match (true) {
            $value === null, is_scalar($value) => [$value],
            $value instanceof BackedEnum => [$value->value],
            $value instanceof DateTimeInterface => [$value->format(Normalizer::DATE_FORMAT)],
            default => [],
        };
    }
}
