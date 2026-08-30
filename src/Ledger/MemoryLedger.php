<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Ledger;

use ElPandaPe\Sentinel\Contracts\DeclaresFilters;
use ElPandaPe\Sentinel\Contracts\Deduplicates;
use ElPandaPe\Sentinel\Contracts\EnumeratesStreams;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Contracts\LedgerStream;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Enums\Filter;
use ElPandaPe\Sentinel\Integrity\Stream;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Query\AuditQuery;
use ElPandaPe\Sentinel\Support\AuditCollection;

/**
 * The reference implementation: the whole contract over plain arrays, chaining with the same
 * algorithm DatabaseLedger uses. It exists so the contract has a second implementation to be
 * read against — an interface that assumes its only backend is one nobody has questioned —
 * and so a suite that only needs entries back does not pay for a database.
 *
 * It keeps everything it is given and nothing survives the instance, which is why it is not
 * reachable as a default driver: a ledger with no durability that looks like it works is
 * worse than one that fails.
 */
final class MemoryLedger implements DeclaresFilters, Deduplicates, EnumeratesStreams, Ledger
{
    /**
     * @var array<string, list<Audit>>
     */
    private array $streams = [];

    /**
     * @var array<string, int>
     */
    private array $versions = [];

    public function __construct(
        private readonly Stream $stream,
        private readonly EntryBuilder $builder,
        private readonly ArrayQuery $queries,
    ) {}

    public function write(AuditData $audit): Audit
    {
        $stream = $this->stream->resolve($audit);
        $entries = $this->streams[$stream] ?? [];
        $last = end($entries);

        $written = $this->builder->build(
            $audit,
            $stream,
            count($entries) + 1,
            $last === false ? null : $last->hash,
            $this->version($audit),
        );

        $this->streams[$stream][] = $written;

        return $written;
    }

    public function writeMany(array $audits): AuditCollection
    {
        return new AuditCollection(array_map($this->write(...), $audits));
    }

    public function append(Audit $audit): Audit
    {
        $this->streams[$audit->stream][] = $audit;

        return $audit;
    }

    /**
     * @return list<string>
     */
    public function streams(): array
    {
        $streams = array_keys($this->streams);

        sort($streams);

        return $streams;
    }

    /**
     * @param  non-empty-list<string>  $captureIds
     * @return list<string>
     */
    public function settled(array $captureIds): array
    {
        $found = [];

        foreach ($this->streams as $entries) {
            foreach ($entries as $audit) {
                if ($audit->capture_id !== null && in_array($audit->capture_id, $captureIds, true)) {
                    $found[] = $audit->capture_id;
                }
            }
        }

        return $found;
    }

    public function find(string $id): ?Audit
    {
        foreach ($this->streams as $entries) {
            foreach ($entries as $audit) {
                if ($audit->id === $id) {
                    return $audit;
                }
            }
        }

        return null;
    }

    /**
     * @return list<Filter>
     */
    public function supportedFilters(): array
    {
        return Filter::cases();
    }

    public function query(AuditQuery $query): AuditCollection
    {
        return new AuditCollection($this->queries->resolve(array_merge([], ...array_values($this->streams)), $query));
    }

    public function stream(string $stream): LedgerStream
    {
        return new ArrayStream($stream, $this->streams[$stream] ?? []);
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
