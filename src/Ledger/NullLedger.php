<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Ledger;

use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Contracts\LedgerStream;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Exceptions\LedgerException;
use ElPandaPe\Sentinel\Integrity\Stream;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Query\AuditQuery;
use ElPandaPe\Sentinel\Support\AuditCollection;

/**
 * Turns writing off without taking the code path apart: the chain is still computed, so
 * the contract suite is the same one DatabaseLedger runs. What it holds lives on the
 * instance and dies with it.
 */
final class NullLedger implements Ledger
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

    public function query(AuditQuery $query): AuditCollection
    {
        throw LedgerException::queryNotImplemented();
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
