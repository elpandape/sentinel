<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Exceptions;

use RuntimeException;

final class RedactionException extends RuntimeException
{
    public static function retired(string $stream, int $sequence): self
    {
        return new self(
            "Entry {$sequence} of stream {$stream} is recorded as having left the hot table, so there is "
            .'no content here to destroy. Nothing was written.',
        );
    }

    public static function archived(string $stream, int $sequence, string $disk, string $path): self
    {
        return new self(
            "Entry {$sequence} of stream {$stream} lives in the archive batch at [{$path}] on the [{$disk}] "
            .'disk, which this version cannot redact. Nothing was written.',
        );
    }

    /**
     * Putting a tombstone over a row that no longer reproduces its own hash would destroy the evidence
     * that it was tampered with, and leave a declared redaction where an alarm should be.
     */
    public static function unverifiable(string $stream, int $sequence): self
    {
        return new self(
            "Entry {$sequence} of stream {$stream} does not reproduce its own hash, so it was not redacted. "
            .'A tombstone over an altered row would hide the alteration behind a declaration.',
        );
    }
}
