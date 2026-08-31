<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Enums;

/**
 * How the bytes of a batch are written. The manifest records the name rather than a yes or no,
 * because a boolean cannot say what to inflate a batch written two years ago with — the same reason
 * an anchor records `fold-sha256` and not a flag.
 *
 * Gzip is the only one core PHP can be asked for without a package: bz2 and zip are no more enabled
 * by default, and zstd and brotli are extensions. It needs ext-zlib, which is a `suggest` rather
 * than a `require` because archiving is a driver most installations never resolve.
 */
enum ArchiveCodec: string
{
    case Gzip = 'gzip';

    public function compress(string $bytes): string
    {
        return match ($this) {
            self::Gzip => gzencode($bytes) ?: $bytes,
        };
    }

    public function decompress(string $bytes): string
    {
        return match ($this) {
            self::Gzip => gzdecode($bytes) ?: $bytes,
        };
    }

    public function extension(): string
    {
        return match ($this) {
            self::Gzip => '.gz',
        };
    }
}
