<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Ledger;

use ElPandaPe\Sentinel\Contracts\DeclaresFilters;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Contracts\LedgerStream;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Enums\Filter;
use ElPandaPe\Sentinel\Integrity\Stream;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Query\AuditQuery;
use ElPandaPe\Sentinel\Support\AuditCollection;

/**
 * Turns writing off without taking the code path apart. The entry is still built, sealed and
 * chained, so what an application measures with this driver is what the package costs minus
 * the store — but nothing is kept: find() answers nothing, stream() walks nothing, and a query
 * comes back empty however narrow it was.
 *
 * What survives a write is the tail of each stream and a version counter per subject, because
 * both are sealed into the next entry and a chain cannot be continued without them. That is a
 * counter per stream and per subject, never one per entry: turning auditing off is not a
 * reason to grow with the traffic it is refusing to record.
 */
final class NullLedger implements DeclaresFilters, Ledger
{
    /**
     * @var array<string, StreamTail>
     */
    private array $tails = [];

    /**
     * @var array<string, int>
     */
    private array $versions = [];

    public function __construct(
        private readonly Stream $stream,
        private readonly EntryBuilder $builder,
    ) {}

    public function write(AuditData $audit): Audit
    {
        $stream = $this->stream->resolve($audit);
        $tail = $this->tails[$stream] ?? StreamTail::empty();

        $written = $this->builder->build(
            $audit,
            $stream,
            $tail->sequence + 1,
            $tail->hash,
            $this->version($audit),
        );

        return $this->remember($written);
    }

    public function writeMany(array $audits): AuditCollection
    {
        return new AuditCollection(array_map($this->write(...), $audits));
    }

    public function append(Audit $audit): Audit
    {
        return $this->remember($audit);
    }

    public function find(string $id): ?Audit
    {
        return null;
    }

    /**
     * Every filter, answered with nothing. Refusing one would say this ledger cannot translate
     * it; it translates all of them, into the same empty answer.
     *
     * @return list<Filter>
     */
    public function supportedFilters(): array
    {
        return Filter::cases();
    }

    public function query(AuditQuery $query): AuditCollection
    {
        return new AuditCollection([]);
    }

    public function stream(string $stream): LedgerStream
    {
        return new ArrayStream($stream, []);
    }

    private function remember(Audit $audit): Audit
    {
        $this->tails[$audit->stream] = new StreamTail($audit->sequence, $audit->hash);

        return $audit;
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
