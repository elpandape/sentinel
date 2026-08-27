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
     * @param  array<array-key, mixed>  $patch
     */
    public static function fromJsonPatch(array $patch): self
    {
        $changes = [];
        $guard = null;
        $index = 0;

        foreach ($patch as $operation) {
            if (! is_array($operation) || ! is_string($operation['op'] ?? null) || ! is_string($operation['path'] ?? null)) {
                throw DiffException::malformedPatch($index);
            }

            $guard = self::apply($operation, $index, $guard, $changes);
            $index++;
        }

        return new self($changes);
    }

    /**
     * RFC 6902 has no place for the old value, so a `test` in front of what the patch
     * overwrites is what makes it verifiable against the document it came from.
     *
     * @return list<array{op: string, path: string, value?: mixed}>
     */
    public function toJsonPatch(bool $tests = true): array
    {
        $patch = [];

        foreach ($this->changes as $change) {
            if ($tests && $change->oldKnown && $change->op !== 'add') {
                $patch[] = ['op' => 'test', 'path' => $change->path, 'value' => $change->old];
            }

            $patch[] = $change->op === 'remove'
                ? ['op' => 'remove', 'path' => $change->path]
                : ['op' => $change->op, 'path' => $change->path, 'value' => $change->new];
        }

        return $patch;
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

    /**
     * An addition rebuilds with a known old value: there was none, and that is
     * information. A replacement or a removal without its guarding `test` has genuinely
     * lost it.
     *
     * @param  array<array-key, mixed>  $operation
     * @param  array{path: string, value: mixed}|null  $guard
     * @param  list<Change>  $changes
     * @return array{path: string, value: mixed}|null
     */
    private static function apply(array $operation, int $index, ?array $guard, array &$changes): ?array
    {
        /** @var string $op */
        $op = $operation['op'];
        /** @var string $path */
        $path = $operation['path'];

        if ($op === 'test') {
            return ['path' => $path, 'value' => $operation['value'] ?? null];
        }

        $known = $guard !== null && $guard['path'] === $path;
        $old = $known ? $guard['value'] : null;

        $changes[] = match ($op) {
            'add' => new Change($path, 'add', null, self::required($operation, $index)),
            'replace' => new Change($path, 'replace', $old, self::required($operation, $index), $known),
            'remove' => new Change($path, 'remove', $old, null, $known),
            default => throw DiffException::unsupportedOperation($op),
        };

        return null;
    }

    /**
     * @param  array<array-key, mixed>  $operation
     */
    private static function required(array $operation, int $index): mixed
    {
        if (! array_key_exists('value', $operation)) {
            throw DiffException::malformedPatch($index);
        }

        return $operation['value'];
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
