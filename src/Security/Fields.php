<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Security;

use Closure;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Diff\Pointer;

/**
 * Where a protected field can be hiding. Five containers, not two: a value duplicated into
 * `changes` or echoed by a resolver into `context` is the same secret in another column.
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
}
