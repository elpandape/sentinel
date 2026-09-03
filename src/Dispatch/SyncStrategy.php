<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Dispatch;

use ElPandaPe\Sentinel\Capture\WriteFailure;
use ElPandaPe\Sentinel\Contracts\DispatchStrategy;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Models\Audit;
use Throwable;

/**
 * The entry settles in the process that captured it, inside the call that caused it. Maximum
 * durability, and the cost lands on whoever caused the fact — which is the right default for an
 * audit engine, and the only mode where the caller can still be told the write did not work.
 */
final readonly class SyncStrategy implements DispatchStrategy
{
    public function __construct(
        private Settlement $settlement,
        private WriteFailure $failures,
    ) {}

    /**
     * The write that happens in the request, where the configured policy is free to decide: the
     * caller is still on the stack and an exception reaches whoever caused the entry.
     */
    public function inRequest(AuditData $audit): Handover
    {
        try {
            return Handover::settled($this->settlement->settle($audit));
        } catch (Throwable $failure) {
            $this->failures->inRequest($audit, $failure);

            return Handover::refused();
        }
    }

    /**
     * The deferred write is its own failure boundary, and the policy does not reach it. The
     * framework runs commit callbacks in a bare foreach, so an exception here would stop every
     * later entry of the same transaction from being attempted at all — an append-only engine
     * losing the rest of an operation because the first entry hit a constraint — and would
     * surface out of a DB::transaction() that has already committed.
     */
    public function afterCommit(AuditData $audit): Handover
    {
        try {
            return Handover::settled($this->settlement->settle($audit));
        } catch (Throwable $failure) {
            $this->failures->afterCommit($audit, $failure);

            return Handover::refused();
        }
    }

    /**
     * The batch settles in one assignment of the sequence, which is the whole reason it travels as
     * a batch: reading the tail of a stream is the part two writes cannot share.
     *
     * @param  non-empty-list<AuditData>  $audits
     * @return list<Handover>
     */
    public function inRequestBatch(array $audits): array
    {
        return $this->batch($audits, $this->failures->inRequest(...));
    }

    /**
     * @param  non-empty-list<AuditData>  $audits
     * @return list<Handover>
     */
    public function afterCommitBatch(array $audits): array
    {
        return $this->batch($audits, $this->failures->afterCommit(...));
    }

    /**
     * A batch that did not settle is one failure and is reported once. Reporting it per entry would
     * turn a single unreachable ledger into three thousand lines saying the same thing, and under a
     * policy that throws only the first of them would ever be read anyway.
     *
     * @param  non-empty-list<AuditData>  $audits
     * @param  callable(AuditData, Throwable): void  $failed
     * @return list<Handover>
     */
    private function batch(array $audits, callable $failed): array
    {
        try {
            return $this->handovers($this->settlement->settleBatch($audits), $audits);
        } catch (Throwable $failure) {
            $failed($audits[0], $failure);

            return array_map(Handover::refused(...), $audits);
        }
    }

    /**
     * Matched back by the identifier the capture stamped, not by position: a batch comes back
     * holding what settled, and a capture that already had an entry is not in it.
     *
     * An entry that never had an identifier is matched in order among the others like it, which is
     * the only thing left to go on. Nothing this package produces takes that path — the recorder
     * stamps every capture — but the dispatcher is reachable without it, and a batch that landed
     * and reported itself refused would be a write nobody announced.
     *
     * Each entry is claimed once. A caller that named the same capture twice gets one hand-over for
     * the row that settled and a refusal for the repeat, which is the same answer a retry of
     * something already settled gets — because it is the same fact. Handing back the row twice
     * would announce two writes where one happened.
     *
     * @param  non-empty-list<AuditData>  $audits
     * @return list<Handover>
     */
    private function handovers(Settled $written, array $audits): array
    {
        $settled = [];
        $unnamed = [];

        foreach ($written as $entry) {
            if ($entry->capture_id === null) {
                $unnamed[] = $entry;
            } else {
                $settled[$entry->capture_id] = $entry;
            }
        }

        return array_map(
            static function (AuditData $audit) use (&$settled, &$unnamed): Handover {
                $entry = $audit->capture_id === null
                    ? array_shift($unnamed)
                    : ($settled[$audit->capture_id] ?? null);

                unset($settled[$audit->capture_id]);

                return $entry instanceof Audit ? Handover::settled($entry) : Handover::refused();
            },
            $audits,
        );
    }
}
