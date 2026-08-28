<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Ledger;

use Closure;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Contracts\LedgerStream;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Enums\Source;
use ElPandaPe\Sentinel\Integrity\Stream;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Models\AuditTag;
use ElPandaPe\Sentinel\Query\AuditQuery;
use ElPandaPe\Sentinel\Query\Period;
use ElPandaPe\Sentinel\Support\AuditCollection;
use ElPandaPe\Sentinel\Support\Reference;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;

final readonly class DatabaseLedger implements Ledger
{
    private const int MAX_ATTEMPTS = 3;

    public function __construct(
        private Audit $model,
        private AuditTag $labels,
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

    /**
     * A secondary destination takes an entry the primary already sealed, labels included: an
     * entry stored without the labels it arrived with is not the entry that arrived. The
     * transaction is what makes that an all-or-nothing statement rather than a hope.
     */
    public function append(Audit $audit): Audit
    {
        $this->model->getConnection()->transaction(function () use ($audit): void {
            $this->model->newQuery()->insert([$audit->getAttributes()]);
            $this->label([$audit]);
        });

        $audit->exists = true;
        $audit->wasRecentlyCreated = true;

        return $audit;
    }

    public function find(string $id): ?Audit
    {
        return $this->model->newQuery()->find($id);
    }

    /**
     * Every criterion arrives as a binding against a column this class names itself: nothing
     * the caller passes is ever a column, an operator or a direction. The order is the ledger's
     * own clock with the identifier behind it, which is the tail two of the composite indexes
     * carry and the only order that is total.
     */
    public function query(AuditQuery $query): AuditCollection
    {
        $direction = $query->newestFirst ? 'desc' : 'asc';

        /** @var AuditCollection $entries */
        $entries = $this->model->newQuery()
            ->when($query->subject, static fn (Builder $entries, Reference $subject): Builder => $entries
                ->where('subject_type', $subject->type)
                ->where('subject_id', $subject->id))
            ->when($query->actor, static fn (Builder $entries, Reference $actor): Builder => $entries
                ->where('actor_type', $actor->type)
                ->where('actor_id', $actor->id))
            ->when($query->event, static fn (Builder $entries, string $event): Builder => $entries->where('event', $event))
            ->when($query->severity, static fn (Builder $entries, Severity $severity): Builder => $entries->where('severity', $severity->value))
            ->when($query->source, static fn (Builder $entries, Source $source): Builder => $entries->where('source', $source->value))
            ->when($query->tenantId, static fn (Builder $entries, string $tenant): Builder => $entries->where('tenant_id', $tenant))
            ->when($query->transactionId, static fn (Builder $entries, string $transaction): Builder => $entries->where('transaction_id', $transaction))
            ->when($query->traceId, static fn (Builder $entries, string $trace): Builder => $entries->where('trace_id', $trace))
            ->when($query->period, static fn (Builder $entries, Period $period): Builder => $entries
                ->whereBetween('created_at', [$period->from, $period->to]))
            ->orderBy('created_at', $direction)
            ->orderBy('id', $direction)
            ->when($query->offset, static fn (Builder $entries, int $offset): Builder => $entries->offset($offset))
            ->when($query->limit, static fn (Builder $entries, int $limit): Builder => $entries->limit($limit))
            ->get();

        return $entries;
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
            $this->label($written);

            ksort($written);

            return array_values($written);
        }));
    }

    /**
     * Written through the connection the transaction is open on, not through the label model's
     * own: the audits connection is very often not the application default, and that setting
     * existing at all is the reason. insertOrIgnore keeps the unique pair from ever reaching the
     * retry above, which is there for the chain's unique index and would replay a whole sealed
     * batch for a repeated label.
     *
     * Only labels already loaded are written. An entry that arrives without the relation loaded
     * is an entry that says nothing about its labels, and the alternative — reading them here —
     * would be a query nobody asked for, issued inside a transaction that is holding a chain.
     *
     * @param  array<int, Audit>  $written
     */
    private function label(array $written): void
    {
        $rows = [];

        foreach ($written as $audit) {
            foreach ($audit->relationLoaded('tags') ? $audit->tags : [] as $tag) {
                $rows[] = ['audit_id' => $audit->id, 'tag' => $tag->tag];
            }
        }

        if ($rows !== []) {
            $this->model->getConnection()->table($this->labels->getTable())->insertOrIgnore($rows);
        }
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
