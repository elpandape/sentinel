<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Exceptions;

use RuntimeException;

final class ArchiveException extends RuntimeException
{
    public static function refused(string $disk, string $path): self
    {
        return new self("The [{$disk}] disk refused to write the archive batch at [{$path}]. Nothing was removed.");
    }

    public static function unreadable(string $disk, string $path): self
    {
        return new self("The archive batch at [{$path}] on the [{$disk}] disk could not be read back after being written. Nothing was removed.");
    }

    public static function corrupt(string $path): self
    {
        return new self("The archive batch at [{$path}] did not come back as the bytes that were written to it. Nothing was removed.");
    }

    public static function miscounted(string $path, int $claimed, int $found): self
    {
        return new self("The archive batch at [{$path}] is recorded as holding {$claimed} entries and holds {$found}.");
    }

    public static function discontiguous(string $path, int $sequence): self
    {
        return new self("The archive batch at [{$path}] is missing sequence {$sequence} of the range it is recorded as holding.");
    }

    /**
     * The entry was written out and did not survive being read back and hashed again. It is checked
     * before anything is deleted precisely so that this is a batch nobody keeps rather than a hot
     * range nobody can restore.
     */
    public static function unverifiable(string $path, int $sequence): self
    {
        return new self(
            "The entry at sequence {$sequence} does not reproduce its own hash when read back out of [{$path}], "
            .'so the batch was not accepted and nothing was removed.',
        );
    }
}
