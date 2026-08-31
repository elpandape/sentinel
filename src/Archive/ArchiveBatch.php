<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Archive;

/**
 * A batch that is on a disk and has been proved to be there: the range it holds, where it went, and
 * the digest of the exact bytes that were written. It is what the writer hands back and what the
 * manifest is told, and it exists only for a file that survived being read back.
 */
final readonly class ArchiveBatch
{
    public function __construct(
        public string $stream,
        public int $from,
        public int $to,
        public int $records,
        public string $disk,
        public string $path,
        public string $checksum,
        public ?string $codec,
    ) {}
}
