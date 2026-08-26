<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Ledger;

use Carbon\CarbonImmutable;
use Closure;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Contracts\LedgerStream;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Exceptions\LedgerException;
use ElPandaPe\Sentinel\Integrity\Hasher;
use ElPandaPe\Sentinel\Integrity\Stream;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Query\AuditQuery;
use ElPandaPe\Sentinel\Support\AuditCollection;
use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

final readonly class DatabaseLedger implements Ledger
{
    private const int PAYLOAD_VERSION = 1;

    private const int MAX_ATTEMPTS = 3;

    public function __construct(
        private Audit $model,
        private Stream $stream,
        private Hasher $hasher,
        private Config $config,
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
                    $audit = $this->build($data, $stream, ++$sequence, $previous, $versions);
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
    private function build(AuditData $data, string $stream, int $sequence, ?string $previous, array &$versions): Audit
    {
        $audit = $this->model->newInstance();

        $audit->forceFill([
            'id' => (string) Str::ulid(),
            'stream' => $stream,
            'sequence' => $sequence,
            'audit_type' => $data->audit_type,
            'event' => $data->event,
            'severity' => $data->severity,
            'subject_type' => $data->subject_type,
            'subject_id' => $data->subject_id,
            'actor_type' => $data->actor_type,
            'actor_id' => $data->actor_id,
            'impersonator_type' => $data->impersonator_type,
            'impersonator_id' => $data->impersonator_id,
            'tenant_id' => $data->tenant_id,
            'transaction_id' => $data->transaction_id,
            'request_id' => $data->request_id,
            'trace_id' => $data->trace_id,
            'span_id' => $data->span_id,
            'source' => $data->source,
            'version' => $this->version($data, $versions),
            'context' => $data->context,
            'before' => $data->before,
            'after' => $data->after,
            'changes' => $data->changes,
            'metadata' => $data->metadata,
            'encryption' => $data->encryption,
            'criteria' => $data->criteria,
            'affected_rows' => $data->affected_rows,
            'source_audit_id' => $data->source_audit_id,
            'capture_id' => $data->capture_id,
            'payload_version' => self::PAYLOAD_VERSION,
            'algorithm' => $this->config->integrityAlgorithm(),
            'previous_hash' => $previous,
            'occurred_at' => $data->occurred_at,
            'created_at' => CarbonImmutable::now(),
        ]);

        $audit->hash = $this->hasher->hash($audit);
        $audit->exists = true;
        $audit->wasRecentlyCreated = true;
        $audit->syncOriginal();

        return $audit;
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
