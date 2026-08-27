<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Diff;

final class Comparator
{
    /**
     * @var list<string>
     */
    private const array IDENTITIES = ['id', 'uuid'];

    /**
     * @return list<Change>
     */
    public static function compare(mixed $before, mixed $after, string $path = ''): array
    {
        if (is_array($before) && is_array($after)) {
            return self::structures($before, $after, $path);
        }

        return $before === $after ? [] : [new Change($path, 'replace', $before, $after)];
    }

    /**
     * An empty array is both shapes at once — php cannot tell an empty map from an empty
     * list — so it never counts as a shape change. Two populated arrays that disagree do:
     * a map that became a list is one replacement, not a key-by-key rewrite.
     *
     * @param  array<array-key, mixed>  $before
     * @param  array<array-key, mixed>  $after
     * @return list<Change>
     */
    private static function structures(array $before, array $after, string $path): array
    {
        $beforeIsList = array_is_list($before);
        $afterIsList = array_is_list($after);

        if ($before !== [] && $after !== [] && $beforeIsList !== $afterIsList) {
            return [new Change($path, 'replace', $before, $after)];
        }

        return $beforeIsList && $afterIsList
            ? self::lists($before, $after, $path)
            : self::maps($before, $after, $path);
    }

    /**
     * @param  array<array-key, mixed>  $before
     * @param  array<array-key, mixed>  $after
     * @return list<Change>
     */
    private static function maps(array $before, array $after, string $path): array
    {
        $keys = array_keys($before + $after);
        sort($keys);

        $changes = [];

        foreach ($keys as $key) {
            $changes = [
                ...$changes,
                ...self::presence($before, $after, $key, $path.'/'.Pointer::escape((string) $key)),
            ];
        }

        return $changes;
    }

    /**
     * @param  array<array-key, mixed>  $before
     * @param  array<array-key, mixed>  $after
     * @return list<Change>
     */
    private static function presence(array $before, array $after, string|int $key, string $path): array
    {
        if (! array_key_exists($key, $before)) {
            return [new Change($path, 'add', null, $after[$key])];
        }

        if (! array_key_exists($key, $after)) {
            return [new Change($path, 'remove', $before[$key])];
        }

        return self::compare($before[$key], $after[$key], $path);
    }

    /**
     * @param  list<mixed>  $before
     * @param  list<mixed>  $after
     * @return list<Change>
     */
    private static function lists(array $before, array $after, string $path): array
    {
        $identity = self::identity($before, $after);

        return $identity === null
            ? self::byPosition($before, $after, $path)
            : self::byIdentity($before, $after, $path, $identity);
    }

    /**
     * @param  list<mixed>  $before
     * @param  list<mixed>  $after
     * @return list<Change>
     */
    private static function byPosition(array $before, array $after, string $path): array
    {
        $changes = [];

        for ($index = 0; $index < max(count($before), count($after)); $index++) {
            $changes = [...$changes, ...self::presence($before, $after, $index, $path.'/'.$index)];
        }

        return $changes;
    }

    /**
     * Elements are followed, not compared where they happen to sit: an insertion in the
     * middle is one addition. An addition is indexed on the document it appears in and a
     * removal on the one it left, so the two can land on the same index.
     *
     * @param  list<mixed>  $before
     * @param  list<mixed>  $after
     * @return list<Change>
     */
    private static function byIdentity(array $before, array $after, string $path, string $identity): array
    {
        $kept = self::keyed($before, $identity);
        $survivors = self::keyed($after, $identity);
        $changes = [];

        foreach ($after as $index => $element) {
            /** @var array<array-key, mixed> $element */
            $id = self::key($element[$identity]);

            $changes = array_key_exists($id, $kept)
                ? [...$changes, ...self::compare($kept[$id], $element, $path.'/'.$index)]
                : [...$changes, new Change($path.'/'.$index, 'add', null, $element)];
        }

        foreach ($before as $index => $element) {
            /** @var array<array-key, mixed> $element */
            if (! array_key_exists(self::key($element[$identity]), $survivors)) {
                $changes[] = new Change($path.'/'.$index, 'remove', $element);
            }
        }

        return $changes;
    }

    /**
     * @param  list<mixed>  $before
     * @param  list<mixed>  $after
     */
    private static function identity(array $before, array $after): ?string
    {
        if ($before === [] || $after === []) {
            return null;
        }

        foreach (self::IDENTITIES as $identity) {
            if (self::identifies($before, $identity) && self::identifies($after, $identity)) {
                return $identity;
            }
        }

        return null;
    }

    /**
     * @param  list<mixed>  $elements
     */
    private static function identifies(array $elements, string $identity): bool
    {
        $seen = [];

        foreach ($elements as $element) {
            if (! is_array($element) || ! is_scalar($element[$identity] ?? null)) {
                return false;
            }

            $seen[self::key($element[$identity])] = true;
        }

        return count($seen) === count($elements);
    }

    /**
     * @param  list<mixed>  $elements
     * @return array<string, array<array-key, mixed>>
     */
    private static function keyed(array $elements, string $identity): array
    {
        $keyed = [];

        foreach ($elements as $element) {
            /** @var array<array-key, mixed> $element */
            $keyed[self::key($element[$identity])] = $element;
        }

        return $keyed;
    }

    /**
     * var_export renders the type as well as the value, so an id of 1 and an id of '1'
     * key differently and the strict equality the rest of the comparison applies keeps
     * holding here.
     */
    private static function key(mixed $value): string
    {
        return var_export($value, true);
    }
}
