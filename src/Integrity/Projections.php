<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Integrity;

use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Enums\IntegrityBreak;
use ElPandaPe\Sentinel\Events\IntegrityVerificationFailed;
use ElPandaPe\Sentinel\Ledger\RelationProjection;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Models\AuditRelation;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Whether `sentinel_audit_relations` still says what the entries say. The table is a reconstructible
 * projection of the relation lines inside `changes`, it is not in the canonical payload, and the hash
 * therefore does not cover it — so someone who edits it leaves the chain perfectly intact and every
 * query that reads relations answering a different question.
 *
 * That gap is real and this closes it, but it is reported as its own kind of defect and never as a
 * broken chain. Calling it one would be a lie about what the hash protects, and the only thing that
 * makes the hash worth anything is that it never claims more than it covers.
 *
 * It is a pass of its own rather than part of the chain walk, because verifying a chain reads the
 * entries and verifying the projection reads a second table as well. Nobody should pay for the
 * second question while asking the first.
 */
final readonly class Projections
{
    public function __construct(
        private Ledger $ledger,
        private AuditRelation $relations,
        private RelationProjection $projection,
        private Dispatcher $events,
        // How many entries are held before their rows are asked for: it bounds one `where in`.
        private int $batch = 500,
    ) {}

    /**
     * The first entry of the stream whose lines and whose rows disagree, or nothing when every one
     * of them agrees. A row that belongs to no line is as much a divergence as a line with no row:
     * both are the projection saying something the sealed entry does not.
     */
    public function verify(string $stream, ?int $from = null, ?int $to = null): ?VerificationResult
    {
        $checked = 0;
        $batch = [];

        foreach ($this->ledger->stream($stream)->range($from ?? 1, $to) as $audit) {
            $batch[$audit->id] = $audit;

            if (count($batch) < $this->batch) {
                continue;
            }

            $checked += count($batch);
            $divergent = $this->compare($batch);
            $batch = [];

            if ($divergent instanceof Audit) {
                return $this->announce($stream, $checked, $divergent);
            }
        }

        $checked += count($batch);
        $divergent = $batch === [] ? null : $this->compare($batch);

        return $divergent instanceof Audit ? $this->announce($stream, $checked, $divergent) : null;
    }

    /**
     * @param  array<string, Audit>  $batch
     */
    private function compare(array $batch): ?Audit
    {
        $stored = $this->stored(array_keys($batch));

        foreach ($batch as $id => $audit) {
            if ($this->tally($this->projection->rowsFor($audit)) !== ($stored[$id] ?? [])) {
                return $audit;
            }
        }

        return null;
    }

    /**
     * The rows the table actually holds, put through the same reduction as the lines. The pivot maps
     * are re-encoded rather than compared as the text the engine handed back: MySQL and PostgreSQL
     * both reorder the keys of a JSON object on the way in, and comparing that text would report a
     * divergence belonging to the driver rather than to the data.
     *
     * @param  list<string>  $ids
     * @return array<string, array<string, int>>
     */
    private function stored(array $ids): array
    {
        $rows = [];

        foreach ($this->relations->newQuery()->whereIn('audit_id', $ids)->get() as $row) {
            $rows[$row->audit_id][] = [
                'relation' => $row->relation,
                'operation' => $row->operation,
                'related_type' => $row->related_type,
                'related_id' => $row->related_id,
                'pivot_before' => $this->encoded($row->pivot_before),
                'pivot_after' => $this->encoded($row->pivot_after),
            ];
        }

        return array_map($this->tally(...), $rows);
    }

    /**
     * Both sides reduced to the same multiset, keyed by everything that identifies a line and
     * counting how many times it appears. The table carries no key of its own and no order worth
     * trusting — a null `related_type` sorts differently on each engine — so position cannot be part
     * of the comparison, and a count has to be: two identical lines are two rows.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, int>
     */
    private function tally(array $rows): array
    {
        $tally = [];

        foreach ($rows as $row) {
            $key = implode("\x1f", array_map(
                static fn (string $column): string => is_string($row[$column] ?? null) ? $row[$column] : "\x00",
                ['relation', 'operation', 'related_type', 'related_id', 'pivot_before', 'pivot_after'],
            ));

            $tally[$key] = ($tally[$key] ?? 0) + 1;
        }

        ksort($tally);

        return $tally;
    }

    /**
     * @param  array<string, mixed>|null  $pivot
     */
    private function encoded(?array $pivot): ?string
    {
        return $pivot === null ? null : json_encode($pivot, JSON_THROW_ON_ERROR);
    }

    private function announce(string $stream, int $checked, Audit $audit): VerificationResult
    {
        $this->events->dispatch(new IntegrityVerificationFailed(
            $stream,
            IntegrityBreak::ProjectionMismatch,
            $audit->sequence,
            $audit->id,
        ));

        return VerificationResult::broken(
            $stream,
            $checked,
            IntegrityBreak::ProjectionMismatch,
            $audit->sequence,
            $audit->id,
        );
    }
}
