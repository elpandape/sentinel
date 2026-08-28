<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Diff;

/**
 * RFC 6901. The escape order matters: a tilde introduced by escaping a slash must not
 * be escaped again.
 */
final class Pointer
{
    public static function escape(string $segment): string
    {
        return str_replace(['~', '/'], ['~0', '~1'], $segment);
    }

    public static function unescape(string $segment): string
    {
        return str_replace(['~1', '~0'], ['/', '~'], $segment);
    }

    /**
     * @return list<string>
     */
    public static function segments(string $path): array
    {
        return array_values(array_map(self::unescape(...), array_filter(explode('/', $path), static fn (string $segment): bool => $segment !== '')));
    }

    /**
     * Whether a pointer reaches a path: the path itself, or anything beneath it. The slash is
     * what keeps /email from reaching /email_verified_at.
     */
    public static function covers(string $pointer, string $path): bool
    {
        return $path === $pointer || str_starts_with($path, $pointer.'/');
    }

    /**
     * A literal pointer passes through; anything else is read as dot notation, which is
     * what a caller writing `profile.address.city` means.
     */
    public static function of(string $path): string
    {
        if ($path === '' || str_starts_with($path, '/')) {
            return $path;
        }

        return '/'.implode('/', array_map(self::escape(...), explode('.', $path)));
    }
}
