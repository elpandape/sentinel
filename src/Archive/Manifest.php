<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Archive;

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Models\AuditArchive;

/**
 * Everything anybody asks of sentinel_archives, in one place. Two callers ask — the purge, to know
 * what it already retired, and the verification, to know what explains an absence — and a second
 * reading of the same table is a second meaning for it.
 *
 * The range arithmetic is done in PHP over rows ordered by where they start, never in SQL. Ranges
 * may overlap and may be recorded twice, so the answer is a union and not a lookup, and computing
 * it here is what keeps SQLite, MySQL and PostgreSQL from disagreeing about it.
 */
final readonly class Manifest
{
    public function __construct(private AuditArchive $archives) {}

    /**
     * A range that left the hot table without being written anywhere. The four cold columns stay
     * null, which is what says the entries are gone rather than moved.
     */
    public function retired(string $stream, int $from, int $to, int $records): AuditArchive
    {
        return $this->write([
            'stream' => $stream,
            'sequence_from' => $from,
            'sequence_to' => $to,
            'records' => $records,
        ]);
    }

    /**
     * A range that left the hot table into a batch that has been written, read back and proved. The
     * row names where it went and what the bytes digest to, so a reader can refuse a file that is no
     * longer the one this row describes.
     */
    public function archived(ArchiveBatch $batch): AuditArchive
    {
        return $this->write([
            'stream' => $batch->stream,
            'sequence_from' => $batch->from,
            'sequence_to' => $batch->to,
            'records' => $batch->records,
            'disk' => $batch->disk,
            'path' => $batch->path,
            'checksum' => $batch->checksum,
            'compressed' => $batch->codec,
        ]);
    }

    /**
     * Every batch that holds part of a range, oldest range first. It is the way in to a file this
     * process did not write: the driver only knows what its own instance wrote, and the row is the
     * only place `disk`, `path`, `checksum` and the codec exist.
     *
     * A range that was retired without being written anywhere is skipped, not refused — a row with
     * no cold columns is a truthful record of a deletion, and having nothing to give back is the
     * answer to asking it for a batch.
     *
     * @return list<ArchiveBatch>
     */
    public function batchesIn(string $stream, int $from, int $to): array
    {
        $batches = [];

        foreach ($this->rangesFrom($stream, $from) as $archive) {
            if ($archive->sequence_from > $to) {
                break;
            }

            $batch = $this->batchOf($archive);

            if ($batch instanceof ArchiveBatch) {
                $batches[] = $batch;
            }
        }

        return $batches;
    }

    /**
     * The batch a row points at, or null for a range that went nowhere.
     */
    public function batchOf(AuditArchive $archive): ?ArchiveBatch
    {
        return $archive->disk === null || $archive->path === null || $archive->checksum === null
            ? null
            : new ArchiveBatch(
                $archive->stream,
                $archive->sequence_from,
                $archive->sequence_to,
                $archive->records,
                $archive->disk,
                $archive->path,
                $archive->checksum,
                $archive->compressed,
            );
    }

    /**
     * How far the manifest explains, walking forward from one sequence and stopping at the first
     * gap it cannot bridge. A range that starts beyond where the walk has reached is a different
     * absence with something unexplained in front of it, so the walk stops rather than jumping.
     */
    public function claim(string $stream, int $from): Claim
    {
        $claim = Claim::none($from);

        foreach ($this->rangesFrom($stream, $from) as $archive) {
            if ($archive->sequence_from > $claim->reaches + 1) {
                return $claim;
            }

            if ($archive->sequence_to > $claim->reaches) {
                $claim = Claim::of($archive->sequence_to, $archive->id);
            }
        }

        return $claim;
    }

    public function holds(string $stream, int $from, int $to): bool
    {
        return $this->claim($stream, $from)->explains($to);
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function write(array $row): AuditArchive
    {
        $archive = $this->archives->newInstance();

        $archive->forceFill([...$row, 'created_at' => CarbonImmutable::now()])->save();

        return $archive;
    }

    /**
     * @return iterable<AuditArchive>
     */
    private function rangesFrom(string $stream, int $from): iterable
    {
        return $this->archives->newQuery()
            ->where('stream', $stream)
            ->where('sequence_to', '>=', $from)
            ->orderBy('sequence_from')
            ->cursor();
    }
}
