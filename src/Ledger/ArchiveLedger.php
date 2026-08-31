<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Ledger;

use ElPandaPe\Sentinel\Archive\ArchiveBatch;
use ElPandaPe\Sentinel\Archive\BatchReader;
use ElPandaPe\Sentinel\Archive\BatchWriter;
use ElPandaPe\Sentinel\Contracts\DeclaresFilters;
use ElPandaPe\Sentinel\Contracts\EnumeratesStreams;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Contracts\LedgerStream;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Enums\Filter;
use ElPandaPe\Sentinel\Integrity\Stream;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Query\AuditQuery;
use ElPandaPe\Sentinel\Support\AuditCollection;
use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Support\Carbon;

/**
 * Cold storage over any disk `Storage` can reach: NDJSON, one file per batch, nothing but the
 * Filesystem contract underneath. Configure S3, R2 or MinIO and it works there without this package
 * knowing any of them exist.
 *
 * It is a DESTINATION and not a hot ledger, and three things say so rather than being discovered.
 * The tail of a stream lives on the instance, because sentinel_archives holds no hash and could
 * never hand one back — so a process that writes a stream it has not written itself would start a
 * second chain, and that is why the driver is not reachable as `ledger.default`. `version` is
 * counted per instance for the same reason. And what it can read back is what it has written, which
 * is what a destination is asked for; the reads that matter operationally go through the manifest,
 * which belongs to the purge.
 *
 * A batch is sealed when it fills, when a read is asked of the driver, or when somebody calls
 * seal(). What it does NOT do is write to sentinel_archives: a row there means a range left the hot
 * table, and a cold copy of a range that is still hot would disarm the tamper guard that reads it.
 */
final class ArchiveLedger implements DeclaresFilters, EnumeratesStreams, Ledger
{
    /**
     * @var array<string, StreamTail>
     */
    private array $tails = [];

    /**
     * @var array<string, int>
     */
    private array $versions = [];

    /**
     * @var array<string, non-empty-list<Audit>>
     */
    private array $open = [];

    /**
     * @var list<ArchiveBatch>
     */
    private array $written = [];

    public function __construct(
        private readonly Stream $stream,
        private readonly EntryBuilder $builder,
        private readonly ArrayQuery $queries,
        private readonly BatchWriter $writer,
        private readonly BatchReader $reader,
        private readonly Config $config,
    ) {}

    public function write(AuditData $audit): Audit
    {
        $stream = $this->stream->resolve($audit);
        $tail = $this->tails[$stream] ?? StreamTail::empty();

        return $this->hold($this->builder->build(
            $audit,
            $stream,
            $tail->sequence + 1,
            $tail->hash,
            $this->version($audit),
        ));
    }

    public function writeMany(array $audits): AuditCollection
    {
        return new AuditCollection(array_map($this->write(...), $audits));
    }

    public function append(Audit $audit): Audit
    {
        return $this->hold($audit);
    }

    public function find(string $id): ?Audit
    {
        foreach ($this->entries() as $entry) {
            if ($entry->id === $id) {
                return $entry;
            }
        }

        return null;
    }

    public function query(AuditQuery $query): AuditCollection
    {
        return new AuditCollection($this->queries->resolve($this->entries(), $query));
    }

    public function stream(string $stream): LedgerStream
    {
        return new ArrayStream($stream, array_values(array_filter(
            $this->entries(),
            static fn (Audit $entry): bool => $entry->stream === $stream,
        )));
    }

    /**
     * @return list<string>
     */
    public function streams(): array
    {
        $streams = array_unique(array_map(
            static fn (ArchiveBatch $batch): string => $batch->stream,
            [...$this->written, ...$this->pending()],
        ));

        sort($streams);

        return $streams;
    }

    /**
     * Every published filter, answered by walking the batches. `Deduplicates` is deliberately not
     * declared: a capture identifier would be a scan with no index behind it, and the contract says
     * a driver that cannot answer reliably should not claim to.
     *
     * @return list<Filter>
     */
    public function supportedFilters(): array
    {
        return Filter::cases();
    }

    /**
     * Writes out whatever is still being held, and says how many entries went. It is what the purge
     * calls when it is done and what the process calls on its way out.
     */
    public function seal(): int
    {
        $sealed = 0;

        foreach ($this->open as $stream => $entries) {
            $sealed += $this->flush($stream, $entries);
        }

        $this->open = [];

        return $sealed;
    }

    private function hold(Audit $audit): Audit
    {
        $this->open[$audit->stream] = [...$this->open[$audit->stream] ?? [], $audit];
        $this->tails[$audit->stream] = new StreamTail($audit->sequence, $audit->hash);

        if (count($this->open[$audit->stream]) >= $this->config->archiveBatch()) {
            $this->flush($audit->stream, $this->open[$audit->stream]);

            unset($this->open[$audit->stream]);
        }

        return $audit;
    }

    /**
     * @param  non-empty-list<Audit>  $entries
     */
    private function flush(string $stream, array $entries): int
    {
        $this->written[] = $this->writer->write(
            $stream,
            $entries[0]->sequence,
            $entries[count($entries) - 1]->sequence,
            $entries,
            [],
            Carbon::now()->format(Audit::SERIALIZED_AT),
        );

        return count($entries);
    }

    /**
     * Everything this instance has written, read back off the disk it wrote it to. Reading rather
     * than remembering is the point: what comes out of a query is what a file holds, not what a
     * variable happened to keep.
     *
     * @return list<Audit>
     */
    private function entries(): array
    {
        $this->seal();

        $entries = [];

        foreach ($this->written as $batch) {
            $entries = [...$entries, ...$this->reader->read($batch)];
        }

        return $entries;
    }

    /**
     * @return list<ArchiveBatch>
     */
    private function pending(): array
    {
        return array_values(array_map(
            fn (array $entries): ArchiveBatch => new ArchiveBatch(
                $entries[0]->stream,
                $entries[0]->sequence,
                $entries[count($entries) - 1]->sequence,
                count($entries),
                '',
                '',
                '',
                null,
            ),
            $this->open,
        ));
    }

    private function version(AuditData $audit): ?int
    {
        if ($audit->subject_type === null || $audit->subject_id === null) {
            return null;
        }

        $key = $audit->subject_type.'|'.$audit->subject_id;

        return $this->versions[$key] = ($this->versions[$key] ?? 0) + 1;
    }
}
