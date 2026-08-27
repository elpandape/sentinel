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
