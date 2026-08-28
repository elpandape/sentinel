<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Query;

use ElPandaPe\Sentinel\Enums\RelationOperation;
use ElPandaPe\Sentinel\Support\Reference;

/**
 * What a query asks of an entry's relation lines. The three parts narrow the same set of lines and
 * are answered together — an entry matches when **one** of its lines satisfies all of them at once.
 *
 * That is the whole reason they travel as one criterion rather than three. Asked separately, a
 * query for the times a role was attached would also match an entry that attached something else
 * and detached that role, which is a different fact and the wrong answer.
 */
final readonly class RelationCriteria
{
    /**
     * @param  list<RelationOperation>  $operations
     */
    public function __construct(
        public ?string $relation = null,
        public ?Reference $related = null,
        public array $operations = [],
    ) {}

    public function named(string $relation): self
    {
        return new self($relation, $this->related, $this->operations);
    }

    public function to(Reference $related): self
    {
        return new self($this->relation, $related, $this->operations);
    }

    /**
     * @param  list<RelationOperation>  $operations
     */
    public function doing(array $operations): self
    {
        return new self($this->relation, $this->related, array_values(array_unique(
            [...$this->operations, ...$operations],
            SORT_REGULAR,
        )));
    }

    /**
     * @param  array<array-key, mixed>  $lines
     */
    public function matches(array $lines): bool
    {
        return array_any($lines, fn (mixed $line): bool => is_array($line) && $this->satisfied($line));
    }

    /**
     * @param  array<array-key, mixed>  $line
     */
    private function satisfied(array $line): bool
    {
        return $this->is($line, 'relation', $this->relation)
            && ($this->related?->matches($this->string($line, 'related_type'), $this->string($line, 'related_id')) ?? true)
            && ($this->operations === [] || $this->among($this->string($line, 'operation')));
    }

    /**
     * @param  array<array-key, mixed>  $line
     */
    private function is(array $line, string $key, ?string $wanted): bool
    {
        return $wanted === null || $this->string($line, $key) === $wanted;
    }

    private function among(?string $operation): bool
    {
        return array_any(
            $this->operations,
            static fn (RelationOperation $wanted): bool => $wanted->value === $operation,
        );
    }

    /**
     * @param  array<array-key, mixed>  $line
     */
    private function string(array $line, string $key): ?string
    {
        $value = $line[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
