<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Retention;

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Archive\Manifest;
use ElPandaPe\Sentinel\Enums\IntegrityBreak;
use ElPandaPe\Sentinel\Enums\PruneAction;
use ElPandaPe\Sentinel\Integrity\Checkpoint;
use ElPandaPe\Sentinel\Integrity\Checkpoints;
use ElPandaPe\Sentinel\Integrity\VerificationResult;

/**
 * Retires the windows a stream has released, one at a time and in order.
 *
 * Every window is refolded before anything is removed, and that is not belt and braces: a purge must
 * never be the thing that destroys the evidence of a tampering. If the range no longer folds to the
 * root its anchor recorded, the run stops on that stream and says so, leaving every row where it is
 * so somebody can look.
 *
 * The manifest row is written before the rows go, so an interruption leaves a range recorded as
 * retired whose entries are still there — which the next run finishes, and which no verification
 * mistakes for anything, because it only ever consults the manifest about an absence.
 */
final readonly class Pruner
{
    public function __construct(
        private Frontiers $frontiers,
        private Cascade $cascade,
        private Manifest $archives,
        private Checkpoints $checkpoints,
    ) {}

    public function plan(string $stream, CarbonImmutable $now): Frontier
    {
        return $this->frontiers->of($stream, $now);
    }

    /**
     * A dry run walks the same windows through the same code and counts instead of removing, so the
     * two can never describe different things.
     */
    public function prune(Frontier $frontier, PruneAction $action, bool $dryRun): Pruning
    {
        $started = hrtime(true);
        $removed = Removed::none();
        $windows = 0;

        foreach ($frontier->windows as $window) {
            $break = $this->tampered($frontier->stream, $window);

            if ($break instanceof VerificationResult) {
                return new Pruning($frontier, $removed, $windows, $this->since($started), $break);
            }

            $removed = $removed->plus($this->retire($frontier->stream, $window, $action, $dryRun));
            $windows++;
        }

        return new Pruning($frontier, $removed, $windows, $this->since($started));
    }

    private function retire(string $stream, Checkpoint $window, PruneAction $action, bool $dryRun): Removed
    {
        $counted = $this->cascade->count($stream, $window->from, $window->to);

        if ($dryRun) {
            return $counted;
        }

        if (! $this->archives->holds($stream, $window->from, $window->to)) {
            $this->archives->record($stream, $window->from, $window->to, $counted->audits);
        }

        // A match and not a comparison: when the action that writes the range out first arrives,
        // this is where the analyser says so rather than where a boolean quietly picks a side.
        return match ($action) {
            PruneAction::Delete => $this->cascade->purge($stream, $window->from, $window->to),
        };
    }

    /**
     * Whether the range still folds to the root its anchor holds. Two columns a row and no rehashing,
     * which is what makes it affordable in front of every window rather than something an operator
     * has to remember to run first.
     */
    private function tampered(string $stream, Checkpoint $window): ?VerificationResult
    {
        // A range the manifest already claims was settled by a run that did not finish removing it.
        // Refolding it now would fail for the entries that did go, and report a tampering that is
        // this command's own half-finished work.
        if ($this->archives->holds($stream, $window->from, $window->to)) {
            return null;
        }

        $root = $this->checkpoints->refold($window, $this->checkpoints->rootBefore($stream, $window->from));

        return $root === $window->rootHash
            ? null
            : VerificationResult::broken($stream, 0, IntegrityBreak::CheckpointMismatch, $window->from, $window->rootHash);
    }

    private function since(float|int $started): float
    {
        return (hrtime(true) - $started) / 1_000_000_000;
    }
}
