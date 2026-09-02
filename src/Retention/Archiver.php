<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Retention;

use ElPandaPe\Sentinel\Archive\ArchiveBatch;
use ElPandaPe\Sentinel\Archive\BatchWriter;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Models\AuditTransaction;
use Illuminate\Support\Carbon;

/**
 * Reads a window out of the hot table and writes it to cold storage.
 *
 * It reads the entries WITH their labels. A stream walk eager-loads nothing, and labels are not in
 * the canonical payload, so archiving straight off one would write a batch whose entries claim to
 * carry none — silently, and with nothing downstream able to notice.
 *
 * It also takes the header of every operation the window touches. `Retention\Cascade` removes a
 * header once its last entry is gone, and no column of an entry holds an operation's name: without
 * this, archiving would save the entries of an operation and destroy what it was called.
 */
final readonly class Archiver
{
    public function __construct(
        private Audit $audits,
        private AuditTransaction $headers,
        private BatchWriter $writer,
    ) {}

    public function archive(string $stream, int $from, int $to): ArchiveBatch
    {
        $entries = array_values($this->audits->newQuery()
            ->with('tags')
            ->where('stream', $stream)
            ->whereBetween('sequence', [$from, $to])
            ->orderBy('sequence')
            ->get()
            ->all());

        return $this->writer->write(
            $stream,
            $from,
            $to,
            $entries,
            $this->operations($entries),
            Carbon::now()->format(Audit::SERIALIZED_AT),
        );
    }

    /**
     * @param  list<Audit>  $entries
     * @return list<AuditTransaction>
     */
    private function operations(array $entries): array
    {
        $identifiers = array_values(array_unique(array_filter(array_map(
            static fn (Audit $entry): ?string => $entry->transaction_id,
            $entries,
        ))));

        if ($identifiers === []) {
            return [];
        }

        return array_values($this->headers->newQuery()->whereIn('id', $identifiers)->get()->all());
    }
}
