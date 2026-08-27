<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Diff;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * @implements IteratorAggregate<int, array{path: string, op: string, old?: mixed, new: mixed}>
 */
final readonly class Diff implements Countable, IteratorAggregate
{
    /**
     * @var list<string>
     */
    public const array OPERATIONS = ['add', 'remove', 'replace'];

    /**
     * @param  list<Change>  $changes
     */
    private function __construct(private array $changes) {}

    public static function between(mixed $before, mixed $after): self
    {
        return new self(Comparator::compare(Normalizer::value($before), Normalizer::value($after)));
    }

    /**
     * @param  list<Change>  $changes
     */
    public static function fromChanges(array $changes): self
    {
        return new self($changes);
    }

    /**
     * @param  array<array-key, mixed>  $entries
     */
    public static function fromEntries(array $entries): self
    {
        $changes = [];
        $index = 0;

        foreach ($entries as $entry) {
            $changes[] = self::change($entry, $index);
            $index++;
        }

        return new self($changes);
    }

    /**
     * @return list<array{path: string, op: string, old?: mixed, new: mixed}>
     */
    public function toArray(): array
    {
        $entries = [];

        foreach ($this->changes as $change) {
            $entries[] = $change->toArray();
        }

        return $entries;
    }

    public function isEmpty(): bool
    {
        return $this->changes === [];
    }

    public function count(): int
    {
        return count($this->changes);
    }

    /**
     * @return Traversable<int, array{path: string, op: string, old?: mixed, new: mixed}>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->toArray());
    }

    private static function change(mixed $entry, int $index): Change
    {
        if (! is_array($entry) || ! is_string($entry['path'] ?? null) || ! array_key_exists('new', $entry)) {
            throw DiffException::malformedEntry($index);
        }

        $op = $entry['op'] ?? null;

        if (! is_string($op) || ! in_array($op, self::OPERATIONS, true)) {
            throw DiffException::malformedEntry($index);
        }

        /** @var 'add'|'remove'|'replace' $op */
        return new Change(
            $entry['path'],
            $op,
            $entry['old'] ?? null,
            $entry['new'],
            array_key_exists('old', $entry),
        );
    }
}
