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

    public function record(string $stream, int $from, int $to, int $records): AuditArchive
    {
        $archive = $this->archives->newInstance();

        $archive->forceFill([
            'stream' => $stream,
            'sequence_from' => $from,
            'sequence_to' => $to,
            'records' => $records,
            'created_at' => CarbonImmutable::now(),
        ])->save();

        return $archive;
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
