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
     * The rows the table actually holds, put through the same reduction as the lines.
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
                'pivot_before' => $row->pivot_before,
                'pivot_after' => $row->pivot_after,
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
            $key = implode("\x1f", [
                $this->text($row['relation'] ?? null),
                $this->text($row['operation'] ?? null),
                $this->text($row['related_type'] ?? null),
                $this->text($row['related_id'] ?? null),
                $this->pivot($row['pivot_before'] ?? null),
                $this->pivot($row['pivot_after'] ?? null),
            ]);

            $tally[$key] = ($tally[$key] ?? 0) + 1;
        }

        ksort($tally);

        return $tally;
    }

    private function text(mixed $value): string
    {
        return is_string($value) ? $value : "\x00";
    }

    /**
     * A pivot map compared by what it says and not by how an engine wrote it down. `changes` is
     * `jsonb`, which sorts the keys of an object on the way in, and the two pivot columns are
     * `json`, which keeps the text exactly as it arrived — so the same map comes back from the two
     * of them in two different orders, and comparing either as text reports a divergence belonging
     * to PostgreSQL rather than to the data.
     */
    private function pivot(mixed $value): string
    {
        $decoded = is_string($value) ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : $value;

        if (! is_array($decoded)) {
            return "\x00";
        }

        $this->deepSort($decoded);

        return json_encode($decoded, JSON_THROW_ON_ERROR);
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private function deepSort(array &$value): void
    {
        ksort($value);

        foreach ($value as &$nested) {
            if (is_array($nested)) {
                $this->deepSort($nested);
            }
        }
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
