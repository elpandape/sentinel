<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Ledger;

use Closure;
use ElPandaPe\Sentinel\Contracts\DeclaresFilters;
use ElPandaPe\Sentinel\Contracts\Deduplicates;
use ElPandaPe\Sentinel\Contracts\EnumeratesStreams;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Contracts\LedgerStream;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Enums\Filter;
use ElPandaPe\Sentinel\Enums\RelationOperation;
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Enums\Source;
use ElPandaPe\Sentinel\Integrity\Stream;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Models\AuditRelation;
use ElPandaPe\Sentinel\Models\AuditTag;
use ElPandaPe\Sentinel\Query\AuditQuery;
use ElPandaPe\Sentinel\Query\Period;
use ElPandaPe\Sentinel\Query\RelationCriteria;
use ElPandaPe\Sentinel\Query\TagCriteria;
use ElPandaPe\Sentinel\Support\AuditCollection;
use ElPandaPe\Sentinel\Support\Reference;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\UniqueConstraintViolationException;

final readonly class DatabaseLedger implements DeclaresFilters, Deduplicates, EnumeratesStreams, Ledger
{
    private const int MAX_ATTEMPTS = 3;

    /**
     * How many placeholders one statement may carry. PostgreSQL and the MySQL prepared protocol
     * both stop at this number, which a batch reaches sooner than it looks: an entry is thirty-odd
     * columns, so under two thousand of them is already the ceiling.
     *
     * Nothing enforced it until now because the only caller batching at all was the flush, and that
     * one is bounded by the buffer size. A mass operation is not, and a batch over the limit failed
     * as one statement — losing every entry in it, and losing them quietly when the write was
     * deferred to a commit, where nothing is left to refuse.
     */
    private const int MAX_PLACEHOLDERS = 65535;

    public function __construct(
        private Audit $model,
        private AuditTag $labels,
        private AuditRelation $relations,
        private Stream $stream,
        private EntryBuilder $builder,
        private ChangedFieldPredicate $fields,
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
            $this->project([$audit]);
        });

        $audit->exists = true;
        $audit->wasRecentlyCreated = true;

        return $audit;
    }

    /**
     * Labels come back loaded, always. An unloaded subject reads as null, which is legible; an
     * unloaded label list reads as an empty one, which is a claim that the entry carries none.
     */
    public function find(string $id): ?Audit
    {
        return $this->model->newQuery()->with('tags')->find($id);
    }

    /**
     * Which of these captures already have an entry. One seek into the unique index the table has
     * carried since it was written, and the only reason it is asked at all is so a retry can stop
     * being a retry: the index is what makes the write idempotent, this is what keeps a caller from
     * sealing a chain it is about to throw away.
     *
     * @param  non-empty-list<string>  $captureIds
     * @return list<string>
     */
    public function settled(array $captureIds): array
    {
        /** @var list<string> $found */
        $found = $this->model->newQuery()
            ->whereIn('capture_id', $captureIds)
            ->pluck('capture_id')
            ->all();

        return $found;
    }

    /**
     * One distinct scan of the leading column of the chain's own unique index. Ordered by name so
     * two runs of the same report can be diffed against each other.
     *
     * @return list<string>
     */
    public function streams(): array
    {
        /** @var list<string> $streams */
        $streams = $this->model->newQuery()
            ->distinct()
            ->orderBy('stream')
            ->pluck('stream')
            ->all();

        return $streams;
    }

    /**
     * @return list<Filter>
     */
    public function supportedFilters(): array
    {
        return Filter::cases();
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
        $clock = $query->byOccurrence ? 'occurred_at' : 'created_at';

        /** @var AuditCollection $entries */
        $entries = $this->model->newQuery()
            ->with('tags')
            ->when($query->subject, static fn (Builder $entries, Reference $subject): Builder => $entries
                ->where('subject_type', $subject->type)
                ->where('subject_id', $subject->id))
            ->when($query->actor, static fn (Builder $entries, Reference $actor): Builder => $entries
                ->where('actor_type', $actor->type)
                ->where('actor_id', $actor->id))
            ->when($query->type, static fn (Builder $entries, string $type): Builder => $entries->where('audit_type', $type))
            ->when($query->event, static fn (Builder $entries, string $event): Builder => $entries->where('event', $event))
            ->when($query->severity, static fn (Builder $entries, Severity $severity): Builder => $entries->where('severity', $severity->value))
            ->when($query->source, static fn (Builder $entries, Source $source): Builder => $entries->where('source', $source->value))
            ->when($query->tenantId, static fn (Builder $entries, string $tenant): Builder => $entries->where('tenant_id', $tenant))
            ->when($query->transactionId, static fn (Builder $entries, string $transaction): Builder => $entries->where('transaction_id', $transaction))
            ->when($query->traceId, static fn (Builder $entries, string $trace): Builder => $entries->where('trace_id', $trace))
            ->when($query->period, static fn (Builder $entries, Period $period): Builder => $entries
                ->whereBetween('created_at', [$period->from, $period->to]))
            ->when($query->tags, fn (Builder $entries, TagCriteria $tags): Builder => $this->narrowByLabel($entries, $tags))
            ->when($query->relations, fn (Builder $entries, RelationCriteria $lines): Builder => $this->narrowByRelation($entries, $lines))
            ->when($query->changedField, fn (Builder $entries, string $pointer): Builder => $this->narrowByField($entries, $pointer))
            ->when($query->versions, static fn (Builder $entries, array $versions): Builder => $entries->whereIn('version', $versions))
            ->orderBy($clock, $direction)
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
        return $this->attempt($audits, fn (array $audits): array => $this->model->getConnection()->transaction(function () use ($audits): array {
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

            $this->insertInStatements($rows, fn (array $slice): bool => $this->model->newQuery()->insert($slice));
            $this->label($written);
            $this->project($written);

            ksort($written);

            return array_values($written);
        }));
    }

    /**
     * The same rows, in as many statements as the placeholder ceiling requires. The chain is built
     * before any of this and is not what gets divided: sequences, hashes and links are already
     * settled, in order, and every statement runs inside the one transaction that wraps them — so
     * a batch split into four still lands whole or not at all.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  Closure(list<array<string, mixed>>): mixed  $insert
     */
    private function insertInStatements(array $rows, Closure $insert): void
    {
        if ($rows === []) {
            return;
        }

        foreach (array_chunk($rows, max(1, intdiv(self::MAX_PLACEHOLDERS, max(1, count($rows[0]))))) as $slice) {
            $insert($slice);
        }
    }

    /**
     * One correlated exists per required label and one for the optional set, each a seek into
     * the reversed index. A planner is free to walk the labels table instead when a label covers
     * a large share of it, which is the right plan for a label that broad — the shape is what is
     * being chosen here, not the plan.
     *
     * @param  Builder<Audit>  $entries
     * @return Builder<Audit>
     */
    private function narrowByLabel(Builder $entries, TagCriteria $tags): Builder
    {
        foreach ($tags->all as $tag) {
            $entries->whereExists(fn (QueryBuilder $carrying): QueryBuilder => $this->carrying($carrying)->where('tag', $tag));
        }

        if ($tags->any !== []) {
            $entries->whereExists(fn (QueryBuilder $carrying): QueryBuilder => $this->carrying($carrying)->whereIn('tag', $tags->any));
        }

        return $entries;
    }

    /**
     * One exists into the projection, with every part of the criterion inside it, so the row that
     * answers has to satisfy all of them at once. Split across three exists clauses, an entry that
     * attached one record and detached another would answer a question about one of them being
     * detached — a different fact.
     *
     * @param  Builder<Audit>  $entries
     * @return Builder<Audit>
     */
    private function narrowByRelation(Builder $entries, RelationCriteria $lines): Builder
    {
        return $entries->whereExists(function (QueryBuilder $touching) use ($lines): void {
            $touching
                ->selectRaw('1')
                ->from($this->relations->getTable())
                ->whereColumn($this->relations->getTable().'.audit_id', $this->model->getTable().'.id')
                ->when($lines->relation, static fn (QueryBuilder $line, string $relation): QueryBuilder => $line
                    ->where('relation', $relation))
                ->when($lines->related, static fn (QueryBuilder $line, Reference $related): QueryBuilder => $line
                    ->where('related_type', $related->type)
                    ->where('related_id', $related->id))
                ->when($lines->operations !== [], static fn (QueryBuilder $line): QueryBuilder => $line
                    ->whereIn('operation', array_map(
                        static fn (RelationOperation $operation): string => $operation->value,
                        $lines->operations,
                    )));
        });
    }

    /**
     * @param  Builder<Audit>  $entries
     * @return Builder<Audit>
     */
    private function narrowByField(Builder $entries, string $pointer): Builder
    {
        $connection = $this->model->getConnection();

        [$sql, $bindings] = $this->fields->for(
            $connection->getDriverName(),
            $connection->getQueryGrammar()->wrap($this->model->qualifyColumn('changes')),
            $pointer,
        );

        // The only value interpolated into that SQL is the column name the grammar just escaped.
        /** @phpstan-ignore argument.type */
        return $entries->whereRaw($sql, $bindings);
    }

    private function carrying(QueryBuilder $labels): QueryBuilder
    {
        return $labels
            ->selectRaw('1')
            ->from($this->labels->getTable())
            ->whereColumn($this->labels->getTable().'.audit_id', $this->model->getTable().'.id');
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

        $this->insertInStatements(
            $rows,
            fn (array $slice): int => $this->model->getConnection()->table($this->labels->getTable())->insertOrIgnore($slice),
        );
    }

    /**
     * Which entries have lines is asked of the lines, not of the entry's type: a restoration that
     * put a relation back carries the same lines under a type of its own, and asking whoever
     * filters by relation to know that would make the projection a list of producers.
     *
     * The indexable copy of what the entry already carries. Unlike the labels, these are read
     * back out of the entry rather than off a loaded relation: the lines are inside the canonical
     * payload, so an entry that has them cannot arrive without them, and deriving the rows here is
     * projection rather than guesswork. It is also what keeps the two from ever disagreeing — an
     * entry appended by a secondary destination projects exactly what the primary sealed.
     *
     * @param  array<int, Audit>  $written
     */
    private function project(array $written): void
    {
        $rows = [];

        foreach ($written as $audit) {
            /** @var array<array-key, mixed> $lines */
            $lines = $audit->getAttribute('changes') ?? [];

            foreach ($lines as $line) {
                if (is_array($line) && array_key_exists('relation', $line) && array_key_exists('operation', $line)) {
                    $rows[] = $this->row($audit->id, $line);
                }
            }
        }

        $this->insertInStatements(
            $rows,
            fn (array $slice): bool => $this->model->getConnection()->table($this->relations->getTable())->insert($slice),
        );
    }

    /**
     * @param  array<array-key, mixed>  $line
     * @return array<string, mixed>
     */
    private function row(string $audit, array $line): array
    {
        return [
            'audit_id' => $audit,
            'relation' => $this->text($line, 'relation'),
            'operation' => $this->text($line, 'operation'),
            'related_type' => $this->text($line, 'related_type'),
            'related_id' => $this->text($line, 'related_id'),
            'pivot_before' => $this->json($line, 'pivot_before'),
            'pivot_after' => $this->json($line, 'pivot_after'),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $line
     */
    private function text(array $line, string $key): ?string
    {
        $value = $line[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param  array<array-key, mixed>  $line
     */
    private function json(array $line, string $key): ?string
    {
        $value = $line[$key] ?? null;

        return is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : null;
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
     * The unique index is the final arbiter for the chain: no row lock covers a stream with no rows
     * yet, so a writer that lost the race reads the tail again and takes the next position.
     *
     * It is also the arbiter for the capture identifier, and there a retry is the wrong answer: the
     * position is not what was taken, the fact was, and replaying the batch would seal the same
     * chain three times over before giving up. So each attempt drops what has settled since the last
     * one, and a batch with nothing left is handed back its own violation rather than a silence the
     * caller would read as success.
     *
     * @param  non-empty-list<AuditData>  $audits
     * @param  Closure(non-empty-list<AuditData>): list<Audit>  $callback
     * @return list<Audit>
     */
    private function attempt(array $audits, Closure $callback): array
    {
        $attempt = 0;

        while (true) {
            try {
                return $callback($audits);
            } catch (UniqueConstraintViolationException $exception) {
                $remaining = $this->unsettled($audits);

                if ($remaining === [] || ++$attempt >= self::MAX_ATTEMPTS) {
                    throw $exception;
                }

                $audits = $remaining;
            }
        }
    }

    /**
     * @param  non-empty-list<AuditData>  $audits
     * @return list<AuditData>
     */
    private function unsettled(array $audits): array
    {
        $identifiers = array_values(array_filter(array_map(
            static fn (AuditData $audit): ?string => $audit->capture_id,
            $audits,
        )));

        if ($identifiers === []) {
            return $audits;
        }

        $settled = $this->settled($identifiers);

        return array_values(array_filter(
            $audits,
            static fn (AuditData $audit): bool => $audit->capture_id === null || ! in_array($audit->capture_id, $settled, true),
        ));
    }
}
