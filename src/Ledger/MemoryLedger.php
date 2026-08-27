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
 * The reference implementation: the whole contract over plain arrays, chaining with the same
 * algorithm DatabaseLedger uses. It exists so the contract has a second implementation to be
 * read against — an interface that assumes its only backend is one nobody has questioned —
 * and so a suite that only needs entries back does not pay for a database.
 *
 * It keeps everything it is given and nothing survives the instance, which is why it is not
 * reachable as a default driver: a ledger with no durability that looks like it works is
 * worse than one that fails.
 */
final class MemoryLedger implements Ledger
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

    public function append(Audit $audit): Audit
    {
        $this->streams[$audit->stream][] = $audit;

        return $audit;
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
