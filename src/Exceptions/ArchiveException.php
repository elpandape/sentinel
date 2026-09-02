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

    public static function unknownFormat(string $path, ?int $format, int $known): self
    {
        return new self(sprintf(
            'The archive batch at [%s] declares container format %s and this build reads %d. '
            .'A file it cannot read is a refusal, not something to parse and hope.',
            $path,
            $format === null ? 'nothing' : (string) $format,
            $known,
        ));
    }

    public static function miscounted(string $path, int $claimed, int $found): self
    {
        return new self("The archive batch at [{$path}] is recorded as holding {$claimed} entries and holds {$found}.");
    }

    /**
     * A line that names fewer columns than an operation has. It is refused rather than filled in:
     * headers go back in one insert, and a row with a narrower set of keys than its neighbours makes
     * that insert fail somewhere far from here, or name the wrong columns.
     *
     * @param  list<string>  $missing
     */
    public static function incompleteOperation(array $missing): self
    {
        return new self(
            'An operation line in the batch does not name the columns ['.implode(', ', $missing).'], '
            .'so no header was rebuilt from it and nothing was put back.',
        );
    }

    /**
     * A range this build cannot archive because one of its entries was redacted: the writer proves a
     * batch by rehashing every entry against the hash it carries sealed, and a tombstone reproduces
     * its second hash instead. Declared here rather than discovered at the point of proof, where the
     * file is already written and the whole run is lost.
     */
    public static function redacted(string $stream, int $sequence): self
    {
        return new self(
            "Sequence {$sequence} of stream {$stream} was redacted, and this build cannot archive a range "
            .'that holds a tombstone. Nothing was written and nothing was removed.',
        );
    }

    public static function discontiguous(string $path, int $sequence): self
    {
        return new self("The archive batch at [{$path}] is missing sequence {$sequence} of the range it is recorded as holding.");
    }

    public static function occupied(string $stream, int $sequence): self
    {
        return new self(
            "Sequence {$sequence} of stream {$stream} is already held by an entry with a different hash, "
            .'so the archived one was not put back. Two entries cannot occupy one position of a chain.',
        );
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
