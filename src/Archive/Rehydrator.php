<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Archive;

use ElPandaPe\Sentinel\Exceptions\ArchiveException;
use ElPandaPe\Sentinel\Integrity\Content;
use ElPandaPe\Sentinel\Ledger\DatabaseLedger;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Models\AuditTransaction;

/**
 * Puts an archived range back where it came from.
 *
 * It writes through `DatabaseLedger` by name and never through the configured `Ledger`, and that is
 * the first rule rather than a detail. Under the hot-plus-cold composition the README recommends,
 * writing through the resolved ledger would hand every entry to the cold destination, which would
 * write a fresh batch at the same deterministic key — the path is a pure function of the range —
 * overwriting the very file being read, and without its operation lines.
 *
 * Headers go back before entries. `append()` commits its own transaction per entry, so a pass that
 * started with the entries and died would leave a range the next pass reads as already restored,
 * and therefore permanently without the operation it belonged to.
 *
 * It is idempotent and not atomic: the contract exposes no transaction. What is already there with
 * the same hash is skipped, a sequence held by a different hash is refused, and an interruption
 * leaves a prefix a second pass finishes. The check happens before the write and not inside it, so
 * two passes at once can still collide — rehydration is single-writer, and says so.
 */
final readonly class Rehydrator
{
    public function __construct(
        private Manifest $archives,
        private BatchReader $reader,
        private DatabaseLedger $ledger,
        private Audit $audits,
        private AuditTransaction $headers,
        private Content $content,
    ) {}

    public function restore(string $stream, int $from, int $to): Rehydration
    {
        $done = new Rehydration;

        foreach ($this->archives->batchesIn($stream, $from, $to) as $batch) {
            $done = $done->plus($this->batch($batch));
        }

        return $done;
    }

    private function batch(ArchiveBatch $batch): Rehydration
    {
        $operations = $this->restoreHeaders($batch);
        $entries = $this->reader->read($batch);
        $present = $this->present($batch, $entries);
        $restored = 0;
        $skipped = 0;

        foreach ($entries as $entry) {
            if ($this->alreadyThere($entry, $present)) {
                $skipped++;

                continue;
            }

            if (! $this->content->holds($entry)) {
                throw ArchiveException::unverifiable($batch->path, $entry->sequence);
            }

            $this->ledger->append($entry);
            $restored++;
        }

        return new Rehydration($restored, $skipped, $operations, 1);
    }

    /**
     * The headers of the operations this batch's entries belonged to, put back first and written
     * with an insert that ignores what is there: several batches of one operation carry the same
     * header, and its identifier is its table's primary key.
     */
    private function restoreHeaders(ArchiveBatch $batch): int
    {
        $rows = array_map(
            static fn (AuditTransaction $header): array => $header->getAttributes(),
            $this->reader->operations($batch),
        );

        return $rows === []
            ? 0
            : $this->headers->getConnection()->table($this->headers->getTable())->insertOrIgnore($rows);
    }

    /**
     * What the hot table already holds against this batch: the hash at each sequence of the range,
     * and which of the captures the batch carries are taken. The capture is a fourth axis the
     * declared key cannot see — a capture that was archived, purged and then replayed occupies that
     * unique index from a sequence slot that reads as free — and it is asked about by name. Reading
     * every capture in the table would answer the same question and grow with the table rather than
     * with the batch, which is the one thing a restore of a whole anchor window must not do.
     *
     * @param  list<Audit>  $entries
     * @return array{hashes: array<int, string>, captures: list<string>}
     */
    private function present(ArchiveBatch $batch, array $entries): array
    {
        $rows = $this->audits->newQuery()
            ->where('stream', $batch->stream)
            ->whereBetween('sequence', [$batch->from, $batch->to])
            ->get(['sequence', 'hash']);

        $carried = array_values(array_filter(array_map(
            static fn (Audit $entry): ?string => $entry->capture_id,
            $entries,
        )));

        /** @var list<string> $captures */
        $captures = $carried === []
            ? []
            : $this->audits->newQuery()->whereIn('capture_id', $carried)->pluck('capture_id')->all();

        /** @var array<int, string> $hashes */
        $hashes = $rows->pluck('hash', 'sequence')->all();

        return ['hashes' => $hashes, 'captures' => $captures];
    }

    /**
     * @param  array{hashes: array<int, string>, captures: list<string>}  $present
     */
    private function alreadyThere(Audit $entry, array $present): bool
    {
        $held = $present['hashes'][$entry->sequence] ?? null;

        if ($held !== null) {
            return hash_equals($held, $entry->hash)
                ? true
                : throw ArchiveException::occupied($entry->stream, $entry->sequence);
        }

        return $entry->capture_id !== null && in_array($entry->capture_id, $present['captures'], true);
    }
}
