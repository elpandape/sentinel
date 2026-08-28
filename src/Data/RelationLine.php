<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Data;

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
}
