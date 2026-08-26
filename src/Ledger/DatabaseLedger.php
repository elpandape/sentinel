<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Ledger;

use Closure;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Contracts\LedgerStream;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Exceptions\LedgerException;
use ElPandaPe\Sentinel\Integrity\Stream;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Query\AuditQuery;
use ElPandaPe\Sentinel\Support\AuditCollection;
use Illuminate\Database\UniqueConstraintViolationException;

final readonly class DatabaseLedger implements Ledger
{
    private const int MAX_ATTEMPTS = 3;

    public function __construct(
        private Audit $model,
        private Stream $stream,
        private EntryBuilder $builder,
    ) {}

    public function write(AuditData $audit): Audit
    {
        return $this->writeMany([$audit])->firstOrFail();
    }

    public function writeMany(array $audits): AuditCollection
    {
        return new AuditCollection($audits === [] ? [] : $this->chain($audits));
    }

    public function find(string $id): ?Audit
    {
        return $this->model->newQuery()->find($id);
    }

    public function query(AuditQuery $query): AuditCollection
    {
        throw LedgerException::queryNotImplemented();
    }

    public function stream(string $stream): LedgerStream
    {
        return new DatabaseStream($this->model, $stream);
    }

    /**
     * @param  non-empty-list<AuditData>  $audits
     * @return list<Audit>
     */
    private function chain(array $audits): array
    {
        return $this->attempt(fn (): array => $this->model->getConnection()->transaction(function () use ($audits): array {
            $gate = new StreamGate($this->model->getConnection(), $this->model->getTable());
            $written = [];
            $rows = [];
            $versions = [];

            foreach ($this->groupByStream($audits) as $stream => $group) {
                $tail = $gate->tail($stream);
                $sequence = $tail->sequence;
                $previous = $tail->hash;

                foreach ($group as $index => $data) {
                    $audit = $this->builder->build($data, $stream, ++$sequence, $previous, $this->version($data, $versions));
                    $audit->exists = true;
                    $audit->wasRecentlyCreated = true;
                    $previous = $audit->hash;
                    $rows[] = $audit->getAttributes();
                    $written[$index] = $audit;
                }
            }

            $this->model->newQuery()->insert($rows);

            ksort($written);

            return array_values($written);
        }));
    }

    /**
     * @param  non-empty-list<AuditData>  $audits
     * @return array<string, array<int, AuditData>>
     */
    private function groupByStream(array $audits): array
    {
        $groups = [];

        foreach ($audits as $index => $data) {
            $groups[$this->stream->resolve($data)][$index] = $data;
        }

        return $groups;
    }

    /**
     * @param  array<string, int>  $versions
     */
    private function version(AuditData $data, array &$versions): ?int
    {
        if ($data->subject_type === null || $data->subject_id === null) {
            return null;
        }

        $key = $data->subject_type.'|'.$data->subject_id;

        if (! array_key_exists($key, $versions)) {
            $highest = $this->model->newQuery()
                ->where('subject_type', $data->subject_type)
                ->where('subject_id', $data->subject_id)
                ->max('version');

            $versions[$key] = is_numeric($highest) ? (int) $highest : 0;
        }

        return ++$versions[$key];
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    private function attempt(Closure $callback): mixed
    {
        $attempt = 0;

        while (true) {
            try {
                return $callback();
            } catch (UniqueConstraintViolationException $exception) {
                // The unique index is the final arbiter: no row lock covers a stream with no rows yet.
                if (++$attempt >= self::MAX_ATTEMPTS) {
                    throw $exception;
                }
            }
        }
    }
}
