<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Query;

use ElPandaPe\Sentinel\Diff\Diff;
use ElPandaPe\Sentinel\Exceptions\ComparisonException;
use ElPandaPe\Sentinel\Models\Audit;

/**
 * Two entries and what changed between them. It carries the entries and not only the diff
 * because a diff has exactly one way to say "nothing", and there are several ways to arrive
 * there: nothing changed, the model keeps no snapshots, the event had no pair, the fields that
 * moved are redacted. With both entries in hand the answer can be interrogated instead of
 * guessed at.
 */
final readonly class Comparison
{
    private function __construct(
        public Audit $from,
        public Audit $to,
        public Diff $diff,
    ) {}

    public static function between(Audit $from, Audit $to): self
    {
        if ($from->subject_type !== $to->subject_type || $from->subject_id !== $to->subject_id) {
            throw ComparisonException::acrossSubjects();
        }

        return new self($from, $to, Diff::between($from->after ?? [], $to->after ?? []));
    }
}
