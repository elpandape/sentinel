<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Retention;

use ElPandaPe\Sentinel\Integrity\VerificationResult;

/**
 * Every stream one run touched, in the order the ledger named them. One shape to read whether the
 * run covered one chain or all of them, which is the choice Integrity\IntegrityReport already made.
 */
final readonly class PruneReport
{
    /**
     * @param  list<Pruning>  $streams
     */
    public function __construct(public array $streams) {}

    public function entries(): int
    {
        return array_sum(array_map(static fn (Pruning $pruning): int => $pruning->removed->audits, $this->streams));
    }

    public function windows(): int
    {
        return array_sum(array_map(static fn (Pruning $pruning): int => $pruning->windows, $this->streams));
    }

    public function firstBreak(): ?VerificationResult
    {
        foreach ($this->streams as $pruning) {
            if ($pruning->break instanceof VerificationResult) {
                return $pruning->break;
            }
        }

        return null;
    }
}
