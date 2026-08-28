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
    public static function of(int|string|UnitEnum $state): int|string
    {
        return match (true) {
            $state instanceof BackedEnum => $state->value,
            $state instanceof UnitEnum => $state->name,
            default => $state,
        };
    }
}
