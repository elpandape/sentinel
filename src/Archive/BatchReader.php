<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Archive;

use ElPandaPe\Sentinel\Enums\ArchiveCodec;
use ElPandaPe\Sentinel\Exceptions\ArchiveException;
use ElPandaPe\Sentinel\Integrity\Hasher;
use ElPandaPe\Sentinel\Models\Audit;
use Illuminate\Contracts\Filesystem\Factory;

/**
 * Reads a batch back, and refuses it three ways before a line of it is parsed: the bytes have to
 * digest to what was recorded, the file has to hold the number of entries the manifest claims, and
 * their sequences have to be the range it claims. The first catches a file that changed; the other
 * two catch a manifest row that no longer describes the file it points at, which a digest over the
 * bytes cannot see.
 *
 * It reads from the disk the batch NAMES and never from the one configured now. A batch written
 * before an operator moved the archive is still on the old disk, and its row is what says so.
 */
final readonly class BatchReader
{
    public function __construct(
        private Factory $disks,
        private Hasher $hasher,
        private Audit $model,
    ) {}

    /**
     * @return list<Audit>
     */
    public function read(ArchiveBatch $batch): array
    {
        $body = $this->body($batch);
        $entries = [];
        $expected = $batch->from;

        foreach (Batch::entriesIn($body) as $decoded) {
            $entry = Line::toAudit($decoded, $this->model);

            if ($entry->sequence !== $expected) {
                throw ArchiveException::discontiguous($batch->path, $expected);
            }

            $entries[] = $entry;
            $expected++;
        }

        return count($entries) === $batch->records
            ? $entries
            : throw ArchiveException::miscounted($batch->path, $batch->records, count($entries));
    }

    private function body(ArchiveBatch $batch): string
    {
        $bytes = $this->disks->disk($batch->disk)->get($batch->path);

        if (! is_string($bytes)) {
            throw ArchiveException::unreadable($batch->disk, $batch->path);
        }

        [$algorithm] = explode(':', $batch->checksum, 2);

        if (! hash_equals($batch->checksum, $algorithm.':'.$this->hasher->digest($bytes, $algorithm))) {
            throw ArchiveException::corrupt($batch->path);
        }

        $codec = $batch->codec === null ? null : ArchiveCodec::from($batch->codec);

        return $codec?->decompress($bytes) ?? $bytes;
    }
}
