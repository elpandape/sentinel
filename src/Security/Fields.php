<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Security;

use Closure;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Diff\Pointer;

/**
 * Where a protected field can be hiding. Six containers, not two: a value duplicated into
 * `changes`, echoed by a resolver into `context` or searched for in the `criteria` of a mass
 * operation is the same secret in another column.
 *
 * Matching is by key name at any depth, so a field declared once is protected wherever it
 * surfaces — including inside the arguments of a console command.
 */
final readonly class Fields
{
    /**
     * @param  list<string>  $fields
     * @param  Closure(mixed, string): mixed  $transform
     * @return list<string> the fields that were found and transformed
     */
    public static function protect(AuditData $audit, array $fields, Closure $transform): array
    {
        if ($fields === []) {
            return [];
        }

        $touched = [];
        $names = array_flip($fields);
        $record = static function (string $field, mixed $value) use ($transform, &$touched): mixed {
            $touched[$field] = true;

            return $transform($value, $field);
        };

        $audit->before = self::walk($audit->before, $names, $record);
        $audit->after = self::walk($audit->after, $names, $record);
        $audit->metadata = self::walk($audit->metadata, $names, $record);
        $audit->context = self::walk($audit->context, $names, $record) ?? [];
        $audit->changes = self::changes($audit->changes, $names, $record);
        $audit->criteria = self::criteria($audit->criteria, $names, $record);

        $found = array_keys($touched);
        sort($found);

        return $found;
    }

    /**
     * @template TKey of array-key
     *
     * @param  array<TKey, mixed>|null  $container
     * @param  array<string, int>  $names
     * @param  Closure(string, mixed): mixed  $transform
     * @return array<TKey, mixed>|null
     */
    private static function walk(?array $container, array $names, Closure $transform): ?array
    {
        if ($container === null) {
            return null;
        }

        foreach ($container as $key => $value) {
            $container[$key] = match (true) {
                is_string($key) && array_key_exists($key, $names) => $transform($key, $value),
                is_array($value) => self::walk($value, $names, $transform),
                default => $value,
            };
        }

        return $container;
    }

    /**
     * A protected field that moved still shows its path: what travels transformed is the
     * pair of values, so the entry proves something changed without saying what.
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, mixed>|null  $changes
     * @param  array<string, int>  $names
     * @param  Closure(string, mixed): mixed  $transform
     * @return array<TKey, mixed>|null
     */
    private static function changes(?array $changes, array $names, Closure $transform): ?array
    {
        if ($changes === null) {
            return null;
        }

        foreach ($changes as $index => $change) {
            if (! is_array($change)) {
                continue;
            }

            $protected = self::protects($change, $names);

            $changes[$index] = $protected === null
                ? self::walk($change, $names, $transform)
                : self::values($change, $protected, $transform);
        }

        return $changes;
    }

    /**
     * @param  array<array-key, mixed>  $change
     * @param  array<string, int>  $names
     */
    private static function protects(array $change, array $names): ?string
    {
        $path = $change['path'] ?? null;

        if (! is_string($path)) {
            return null;
        }

        return array_find(
            Pointer::segments($path),
            static fn (string $segment): bool => array_key_exists($segment, $names),
        );
    }

    /**
     * @param  array<array-key, mixed>  $change
     * @param  Closure(string, mixed): mixed  $transform
     * @return array<array-key, mixed>
     */
    private static function values(array $change, string $field, Closure $transform): array
    {
        foreach (['old', 'new'] as $side) {
            if (array_key_exists($side, $change)) {
                $change[$side] = $transform($field, $change[$side]);
            }
        }

        return $change;
    }

    /**
     * What a mass operation was looking for is the same territory as what it found. A criteria
     * naming a protected column carries the value that column was compared to, and leaving it
     * alone would mean an engine that masks an email in a snapshot and prints it in the search
     * that went looking for it.
     *
     * Only the clauses are walked. The rest of the criteria — the columns of an upsert, the tables
     * of a join, the size of a set — is the query's own vocabulary and holds nothing of the
     * caller's to protect.
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, mixed>|null  $criteria
     * @param  array<string, int>  $names
     * @param  Closure(string, mixed): mixed  $transform
     * @return array<TKey, mixed>|null
     */
    private static function criteria(?array $criteria, array $names, Closure $transform): ?array
    {
        if ($criteria === null || ! is_array($criteria['wheres'] ?? null)) {
            return $criteria;
        }

        $criteria['wheres'] = self::clauses($criteria['wheres'], $names, $transform);

        return $criteria;
    }

    /**
     * @param  array<array-key, mixed>  $wheres
     * @param  array<string, int>  $names
     * @param  Closure(string, mixed): mixed  $transform
     * @return array<array-key, mixed>
     */
    private static function clauses(array $wheres, array $names, Closure $transform): array
    {
        foreach ($wheres as $index => $where) {
            if (is_array($where)) {
                $wheres[$index] = self::clause($where, $names, $transform);
            }
        }

        return $wheres;
    }

    /**
     * A group is descended into rather than skipped: nesting is where a clause hides from a walk
     * that only looks at the top level.
     *
     * @param  array<array-key, mixed>  $where
     * @param  array<string, int>  $names
     * @param  Closure(string, mixed): mixed  $transform
     * @return array<array-key, mixed>
     */
    private static function clause(array $where, array $names, Closure $transform): array
    {
        if (is_array($where['wheres'] ?? null)) {
            $where['wheres'] = self::clauses($where['wheres'], $names, $transform);

            return $where;
        }

        $column = $where['column'] ?? null;

        if (! is_string($column) || ! array_key_exists($column, $names)) {
            return $where;
        }

        if (array_key_exists('value', $where)) {
            $where['value'] = $transform($column, $where['value']);
        }

        if (is_array($where['values'] ?? null)) {
            $where['values'] = array_map(
                static fn (mixed $value): mixed => $transform($column, $value),
                $where['values'],
            );
        }

        return $where;
    }
}
