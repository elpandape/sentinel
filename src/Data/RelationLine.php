<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Data;

use ElPandaPe\Sentinel\Diff\Change;
use ElPandaPe\Sentinel\Diff\Diff;
use ElPandaPe\Sentinel\Diff\Pointer;
use ElPandaPe\Sentinel\Enums\RelationOperation;

/**
 * One line of what a relation operation did. The same shape reaches two places: the entry's
 * canonical changes, where the chain covers it, and the projection table, where it is indexed.
 *
 * The canonicaliser sorts an object's keys and leaves a list in the order it was given, so the
 * order of these lines inside changes is the only thing that decides whether two runs of the same
 * sync() hash alike — and the normalising stage deliberately does not touch changes. That is why
 * the order is fixed here, at capture, and not left to whatever order the engine returned rows in.
 */
final readonly class RelationLine
{
    /**
     * @var list<string>
     */
    private const array KEYS = ['relation', 'operation', 'related_type', 'related_id', 'pivot_before', 'pivot_after'];

    /**
     * @param  array<string, mixed>|null  $pivot_before  null means the row did not exist; an empty map, that it existed and carried nothing
     * @param  array<string, mixed>|null  $pivot_after
     */
    public function __construct(
        public string $relation,
        public RelationOperation $operation,
        public ?string $related_type = null,
        public ?string $related_id = null,
        public ?array $pivot_before = null,
        public ?array $pivot_after = null,
    ) {}

    /**
     * One stored line with the package's key order put back on it. A JSON column hands its objects
     * back in whatever order the engine kept them — MySQL and PostgreSQL both reorder an object's
     * keys on the way in — so a line read back and published as it came would be a different shape
     * on each of the three engines the chain is verified on.
     *
     * Anything the line carries that is not one of the six travels behind them, by name, so a
     * reordering is never also a loss.
     *
     * @param  array<array-key, mixed>  $line
     * @return array<string, mixed>
     */
    public static function ordered(array $line): array
    {
        $ordered = [];

        foreach (self::KEYS as $key) {
            $ordered[$key] = self::sortedValue($line[$key] ?? null);
        }

        $rest = array_diff_key($line, array_flip(self::KEYS));
        ksort($rest);

        foreach ($rest as $key => $value) {
            $ordered[(string) $key] = $value;
        }

        return $ordered;
    }

    /**
     * The lines as the entry carries them: ordered, with the pivot maps ordered too, so the same
     * operation over the same records produces the same bytes on every engine and every run.
     *
     * @param  list<self>  $lines
     * @return list<array<string, mixed>>
     */
    public static function canonical(array $lines): array
    {
        usort($lines, static fn (self $left, self $right): int => $left->ordinal() <=> $right->ordinal());

        return array_map(static fn (self $line): array => $line->toArray(), $lines);
    }

    /**
     * The lines read as a diff. This is presentation and nothing else: a relation entry stores
     * lines, not the path/op/old/new of an attribute change, and nothing here is ever written.
     * Reading it this way is what lets one caller walk a mixed trail — a model entry and a
     * relation entry answer diff() with the same type instead of one of them blowing up.
     *
     * The pointer is the relation and the record under it, so diffFor('/members') narrows to the
     * lines about that relation exactly as it does for an attribute beneath a field.
     *
     * @param  array<array-key, mixed>  $lines
     */
    public static function asDiff(array $lines): Diff
    {
        $changes = [];

        foreach ($lines as $line) {
            if (is_array($line)) {
                $changes[] = self::change($line);
            }
        }

        return Diff::fromChanges($changes);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'relation' => $this->relation,
            'operation' => $this->operation->value,
            'related_type' => $this->related_type,
            'related_id' => $this->related_id,
            'pivot_before' => $this->sorted($this->pivot_before),
            'pivot_after' => $this->sorted($this->pivot_after),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $line
     */
    private static function change(array $line): Change
    {
        $before = is_array($line['pivot_before'] ?? null) ? $line['pivot_before'] : null;
        $after = is_array($line['pivot_after'] ?? null) ? $line['pivot_after'] : null;

        return new Change(
            self::pointer($line),
            match ($line['operation'] ?? null) {
                RelationOperation::Attach->value => 'add',
                RelationOperation::Detach->value => 'remove',
                default => 'replace',
            },
            $before,
            $after,
        );
    }

    /**
     * @param  array<array-key, mixed>  $line
     */
    private static function pointer(array $line): string
    {
        $relation = $line['relation'] ?? null;
        $related = $line['related_id'] ?? null;

        $path = '/'.Pointer::escape(is_string($relation) ? $relation : '');

        return is_string($related) ? $path.'/'.Pointer::escape($related) : $path;
    }

    /**
     * @return list<string>
     */
    private function ordinal(): array
    {
        return [
            $this->relation,
            $this->related_type ?? '',
            $this->related_id ?? '',
            $this->operation->value,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $pivot
     * @return array<string, mixed>|null
     */
    private function sorted(?array $pivot): ?array
    {
        if ($pivot === null) {
            return null;
        }

        ksort($pivot);

        return $pivot;
    }

    private static function sortedValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        ksort($value);

        return $value;
    }
}
