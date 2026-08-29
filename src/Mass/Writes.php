<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Mass;

use ElPandaPe\Sentinel\Diff\Change;
use ElPandaPe\Sentinel\Diff\Pointer;

/**
 * The columns a mass operation writes, split by whether the entry can say what they were set to.
 *
 * A literal becomes a change like any other, with no old side: nothing was read, so there is no
 * earlier value to put there and none gets invented. A column set from an SQL expression becomes
 * a name and nothing else — the formula is not recorded, for the same reason a raw fragment in the
 * criteria is not, and a change claiming `new: null` for it would be the entry stating something
 * that did not happen.
 *
 * Columns are sorted, so two operations that wrote the same thing hash the same however the caller
 * happened to order the array.
 */
final readonly class Writes
{
    /**
     * @param  list<array{path: string, op: string, new: mixed}>  $changes
     * @param  list<string>  $opaque
     */
    private function __construct(public array $changes, public array $opaque) {}

    /**
     * @param  array<string, mixed>  $values
     */
    public static function of(array $values): self
    {
        ksort($values);

        $changes = [];
        $opaque = [];

        foreach ($values as $column => $value) {
            $literal = Literal::of($value);

            if ($literal === []) {
                $opaque[] = $column;

                continue;
            }

            /** @var array{path: string, op: string, new: mixed} $change */
            $change = new Change('/'.Pointer::escape($column), 'replace', new: $literal[0], oldKnown: false)->toArray();

            $changes[] = $change;
        }

        return new self($changes, $opaque);
    }

    /**
     * Nothing written at all, which is what a mass delete does: it names no column.
     */
    public static function none(): self
    {
        return new self([], []);
    }
}
