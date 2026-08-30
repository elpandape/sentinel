<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Retention;

use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Models\AuditRelation;
use ElPandaPe\Sentinel\Models\AuditTag;
use ElPandaPe\Sentinel\Models\AuditTransaction;
use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Sleep;

/**
 * The only place an entry ever leaves the table, and everything that hangs off it with it.
 *
 * It deletes through the query builder, which is the door the model's own immutability guard does
 * not cover — that guard runs on Eloquent model events, and the README has said so about update()
 * since v0.3.0. Nothing here suspends the guard and nothing here needs to: $audit->delete() throws
 * exactly as it did.
 *
 * A slice is named by a range of sequence and never by a LIMIT. The three engines compile a limited
 * delete three different ways, two of them as a second scan, and "the next N rows" is not a name a
 * restart can resume from — while a range is one scan on the chain's own unique index and resumes
 * by arithmetic.
 *
 * The children are found THROUGH the entries, so one slice is one transaction across the three
 * tables. Interrupted between the labels and the entries, the labels of an entry that is gone would
 * be rows nothing surviving could ever name again: they carry an identifier and no clock, and
 * nothing maps one back to a sequence once the entry is not there.
 */
final readonly class Cascade
{
    public function __construct(
        private Audit $audits,
        private AuditTag $labels,
        private AuditRelation $lines,
        private AuditTransaction $headers,
        private Config $config,
    ) {}

    public function purge(string $stream, int $from, int $to): Removed
    {
        $removed = Removed::none();
        $batch = $this->config->pruneBatch();
        $pause = $this->config->prunePause();

        for ($cursor = $from; $cursor <= $to; $cursor += $batch) {
            $removed = $removed->plus($this->slice($stream, $cursor, min($cursor + $batch - 1, $to)));

            if ($pause > 0) {
                Sleep::usleep($pause);
            }
        }

        return $removed;
    }

    /**
     * What a purge would take, counted the same way and taking nothing. It is what --dry-run runs,
     * so the report and the run cannot describe two different things.
     */
    public function count(string $stream, int $from, int $to): Removed
    {
        return new Removed(
            $this->entries($stream, $from, $to)->count(),
            $this->hanging($this->labels->getTable(), $stream, $from, $to)->count(),
            $this->hanging($this->lines->getTable(), $stream, $from, $to)->count(),
            count($this->operations($stream, $from, $to)),
        );
    }

    private function slice(string $stream, int $from, int $to): Removed
    {
        $connection = $this->connection();

        /** @var Removed $removed */
        $removed = $connection->transaction(function () use ($connection, $stream, $from, $to): Removed {
            $operations = $this->operations($stream, $from, $to);

            $tags = $this->hanging($this->labels->getTable(), $stream, $from, $to)->delete();
            $relations = $this->hanging($this->lines->getTable(), $stream, $from, $to)->delete();
            $entries = $this->entries($stream, $from, $to)->delete();

            return new Removed($entries, $tags, $relations, $this->headers($connection, $operations));
        });

        return $removed;
    }

    /**
     * A header goes when its last entry does, and that question is not scoped to the stream: under
     * a per-subject-type strategy one business operation writes into several chains, so a header
     * outlives a purge until nothing anywhere carries its identifier.
     *
     * audits_count is not decremented. It is what the operation captured when its scope closed, and
     * a purge does not change what happened.
     *
     * @param  list<string>  $operations
     */
    private function headers(Connection $connection, array $operations): int
    {
        if ($operations === []) {
            return 0;
        }

        $entries = $this->audits->getTable();
        $headers = $this->headers->getTable();

        return $connection->table($headers)
            ->whereIn('id', $operations)
            ->whereNotExists(fn (Builder $carrying): Builder => $carrying
                ->selectRaw('1')
                ->from($entries)
                ->whereColumn("{$entries}.transaction_id", "{$headers}.id"))
            ->delete();
    }

    /**
     * @return list<string>
     */
    private function operations(string $stream, int $from, int $to): array
    {
        /** @var list<string> $operations */
        $operations = $this->entries($stream, $from, $to)
            ->whereNotNull('transaction_id')
            ->distinct()
            ->pluck('transaction_id')
            ->all();

        return $operations;
    }

    /**
     * Rows of a satellite table that belong to entries of this range, named by a subquery and never
     * by a list of identifiers. Three placeholders whatever the batch is, which is the ceiling the
     * prior art hits with a whereIn over ten thousand of them.
     */
    private function hanging(string $table, string $stream, int $from, int $to): Builder
    {
        return $this->connection()->table($table)
            ->whereIn('audit_id', fn (Builder $of): Builder => $of
                ->select('id')
                ->from($this->audits->getTable())
                ->where('stream', $stream)
                ->whereBetween('sequence', [$from, $to]));
    }

    private function entries(string $stream, int $from, int $to): Builder
    {
        return $this->connection()->table($this->audits->getTable())
            ->where('stream', $stream)
            ->whereBetween('sequence', [$from, $to]);
    }

    private function connection(): Connection
    {
        return $this->audits->getConnection();
    }
}
