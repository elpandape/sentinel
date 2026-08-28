<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Transitions;

use BackedEnum;
use UnitEnum;

/**
 * The one place a state becomes the scalar the entry carries. It reads an enum exactly as
 * Snapshot\SnapshotBuilder does — the backing value, or the case name when there is none — so a
 * transition and the snapshot of the same column say the same thing, and the entry hashes the
 * same text whichever form the call used.
 */
final class State
{
    public static function of(bool|float|int|string|UnitEnum|null $state): bool|float|int|string|null
    {
        return match (true) {
            $state instanceof BackedEnum => $state->value,
            $state instanceof UnitEnum => $state->name,
            default => $state,
        };
    }

    /**
     * Whether a column holds something a state can be. A structure is not one — nothing a
     * person would call draft or approved is an array — and a column that holds one is left to
     * be the ordinary edit it is, rather than forced into a lifeline it cannot describe.
     *
     * @phpstan-assert-if-true bool|float|int|string|UnitEnum|null $value
     */
    public static function represents(mixed $value): bool
    {
        return $value === null || is_scalar($value) || $value instanceof UnitEnum;
    }
}
