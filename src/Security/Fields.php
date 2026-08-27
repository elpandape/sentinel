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
     * @param  Closure(mixed): mixed  $transform
     */
    public static function protect(AuditData $audit, array $fields, Closure $transform): void
    {
        if ($fields === []) {
            return;
        }

        $names = array_flip($fields);

        $audit->before = self::walk($audit->before, $names, $transform);
        $audit->after = self::walk($audit->after, $names, $transform);
        $audit->metadata = self::walk($audit->metadata, $names, $transform);
        $audit->context = self::walk($audit->context, $names, $transform) ?? [];
        $audit->changes = self::changes($audit->changes, $names, $transform);
    }

    /**
     * @template TKey of array-key
     *
     * @param  array<TKey, mixed>|null  $container
     * @param  array<string, int>  $names
     * @param  Closure(mixed): mixed  $transform
     * @return array<TKey, mixed>|null
     */
    private static function walk(?array $container, array $names, Closure $transform): ?array
    {
        if ($container === null) {
            return null;
        }

        foreach ($container as $key => $value) {
            $container[$key] = match (true) {
                is_string($key) && array_key_exists($key, $names) => $transform($value),
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
     * @param  Closure(mixed): mixed  $transform
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

            $changes[$index] = self::protects($change, $names)
                ? self::values($change, $transform)
                : self::walk($change, $names, $transform);
        }

        return $changes;
    }

    /**
     * @param  array<array-key, mixed>  $change
     * @param  array<string, int>  $names
     */
    private static function protects(array $change, array $names): bool
    {
        $path = $change['path'] ?? null;

        if (! is_string($path)) {
            return false;
        }

        return array_any(
            Pointer::segments($path),
            static fn (string $segment): bool => array_key_exists($segment, $names),
        );
    }

    /**
     * @param  array<array-key, mixed>  $change
     * @param  Closure(mixed): mixed  $transform
     * @return array<array-key, mixed>
     */
    private static function values(array $change, Closure $transform): array
    {
        foreach (['old', 'new'] as $side) {
            if (array_key_exists($side, $change)) {
                $change[$side] = $transform($change[$side]);
            }
        }

        return $change;
    }
}
