<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Retention;

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Enums\RetentionHold;
use ElPandaPe\Sentinel\Integrity\Checkpoint;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Models\AuditCheckpoint;
use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Database\Query\Builder;
use stdClass;

/**
 * Which anchored windows of a stream may go, in one query over the anchors.
 *
 * Three things decide it, and each one is a rule the chain imposes rather than a preference. A
 * window still has to have rows, or there is nothing to do. Nothing inside it may still be retained,
 * because a window is folded whole and a partly emptied one can never reproduce its root again. And
 * the window holding the highest sequence of the stream is never offered: the writer derives the
 * next sequence from that row, so a stream emptied to nothing would start a second chain under the
 * first one's name.
 *
 * The result is not a prefix. A window one long-lived entry holds does not hold the windows behind
 * it, which is what lets a range in the middle be retired at all.
 */
final readonly class Frontiers
{
    public function __construct(
        private Audit $audits,
        private AuditCheckpoint $anchors,
        private Schedule $schedule,
        private RetainedPredicate $retained,
        private Config $config,
    ) {}

    public function of(string $stream, CarbonImmutable $now): Frontier
    {
        if ($this->schedule->isEmpty()) {
            return Frontier::holding($stream, RetentionHold::Undeclared);
        }

        $tail = $this->audits->newQuery()->where('stream', $stream)->max('sequence');
        $windows = [];

        foreach ($this->releasable($stream, is_numeric($tail) ? (int) $tail : 0, $now) as $row) {
            $windows[] = Checkpoint::of($row);
        }

        return $windows === []
            ? $this->holding($stream, $now)
            : Frontier::releasing($stream, $windows);
    }

    /**
     * @return iterable<AuditCheckpoint>
     */
    private function releasable(string $stream, int $tail, CarbonImmutable $now): iterable
    {
        return $this->anchors->newQuery()
            ->where('stream', $stream)
            ->where('sequence_to', '<', $tail)
            ->whereExists(fn (Builder $inside): Builder => $this->within($inside))
            ->whereNotExists(function (Builder $inside) use ($now): void {
                $this->retained->applyTo($this->within($inside), $now);
            })
            ->orderBy('sequence_from')
            ->limit($this->config->pruneWindows())
            ->cursor();
    }

    /**
     * The entries of the window the outer query is looking at. Correlated on both columns, so each
     * scan is bounded by the anchoring window and runs on the chain's own unique index.
     */
    private function within(Builder $inside): Builder
    {
        $entries = $this->audits->getTable();
        $anchors = $this->anchors->getTable();

        return $inside
            ->selectRaw('1')
            ->from($entries)
            ->whereColumn("{$entries}.stream", "{$anchors}.stream")
            ->whereColumn("{$entries}.sequence", '>=', "{$anchors}.sequence_from")
            ->whereColumn("{$entries}.sequence", '<=', "{$anchors}.sequence_to");
    }

    /**
     * Why nothing came back, asked only once nothing did. Three cheap questions in the order that
     * makes the answer specific: no anchors at all, anchors that are all in the live tail, or a
     * window with something still retained in it — and then which entry that is, because "some
     * policy, somewhere" is not an answer an operator can act on.
     */
    private function holding(string $stream, CarbonImmutable $now): Frontier
    {
        $first = $this->anchors->newQuery()->where('stream', $stream)->orderBy('sequence_from')->first();

        if (! $first instanceof AuditCheckpoint) {
            return Frontier::holding($stream, RetentionHold::Unanchored);
        }

        $tail = $this->audits->newQuery()->where('stream', $stream)->max('sequence');

        if ($first->sequence_to >= (is_numeric($tail) ? (int) $tail : 0)) {
            return Frontier::holding($stream, RetentionHold::Tail);
        }

        $held = $this->held($stream, $first, $now);

        return $held instanceof stdClass
            ? Frontier::holding($stream, RetentionHold::Retained, $this->sequenceOf($held), $this->label($held))
            : Frontier::holding($stream, RetentionHold::Tail);
    }

    private function held(string $stream, AuditCheckpoint $window, CarbonImmutable $now): ?stdClass
    {
        $entries = $this->audits->getConnection()->table($this->audits->getTable())
            ->where('stream', $stream)
            ->whereBetween('sequence', [$window->sequence_from, $window->sequence_to]);

        $this->retained->applyTo($entries, $now);

        return $entries->orderBy('sequence')->first(['sequence', 'subject_type', 'audit_type']);
    }

    private function sequenceOf(stdClass $held): int
    {
        return is_numeric($held->sequence) ? (int) $held->sequence : 0;
    }

    private function label(stdClass $held): string
    {
        return is_string($held->subject_type) && $held->subject_type !== ''
            ? 'model:'.$held->subject_type
            : (is_string($held->audit_type) ? $held->audit_type : '');
    }
}
