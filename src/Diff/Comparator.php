<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Diff;

final class Comparator
{
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
        $changes = [];

        for ($index = 0; $index < max(count($before), count($after)); $index++) {
            $changes = [...$changes, ...self::presence($before, $after, $index, $path.'/'.$index)];
        }

        return $changes;
    }
}
