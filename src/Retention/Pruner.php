<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Retention;

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Archive\Manifest;
use ElPandaPe\Sentinel\Enums\IntegrityBreak;
use ElPandaPe\Sentinel\Enums\PruneAction;
use ElPandaPe\Sentinel\Exceptions\ComplianceException;
use ElPandaPe\Sentinel\Integrity\Checkpoint;
use ElPandaPe\Sentinel\Integrity\Checkpoints;
use ElPandaPe\Sentinel\Integrity\VerificationResult;
use ElPandaPe\Sentinel\Support\Config;

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
        private Archiver $archiver,
        private Config $config,
    ) {}

    public function plan(string $stream, CarbonImmutable $now): Frontier
    {
        return $this->frontiers->of($stream, $now);
    }

    /**
     * A dry run walks the same windows through the same code and counts instead of removing, so the
     * two can never describe different things.
     */
    public function prune(Frontier $frontier, PruneAction $action, bool $dryRun, ?int $batch = null): Pruning
    {
        $started = hrtime(true);
        $removed = Removed::none();
        $windows = 0;

        foreach ($frontier->windows as $window) {
            $break = $this->tampered($frontier->stream, $window);

            if ($break instanceof VerificationResult) {
                return new Pruning($frontier, $removed, $windows, $this->since($started), $break);
            }

            $removed = $removed->plus($this->retire($frontier->stream, $window, $action, $dryRun, $batch));
            $windows++;
        }

        return new Pruning($frontier, $removed, $windows, $this->since($started));
    }

    private function retire(string $stream, Checkpoint $window, PruneAction $action, bool $dryRun, ?int $batch): Removed
    {
        $counted = $this->cascade->count($stream, $window->from, $window->to);

        if ($dryRun) {
            return $counted;
        }

        $this->refuseUnarchivedDelete($stream, $window, $action);

        if (! $this->unfinished($stream, $window, $counted->audits)) {
            $this->record($stream, $window, $action, $counted->audits);
        }

        return $this->cascade->purge($stream, $window->from, $window->to, $batch);
    }

    /**
     * Under compliance mode a range leaves only after a copy of it exists somewhere. The evidence is
     * the manifest row of a batch and not a flag — `holds()` answers for a range retired with nothing
     * kept as well, so the question goes to `batchesIn()`, which skips a row with no cold columns and
     * therefore only answers yes when there is a file.
     */
    private function refuseUnarchivedDelete(string $stream, Checkpoint $window, PruneAction $action): void
    {
        if ($action !== PruneAction::Delete || ! $this->config->complianceEnabled()) {
            return;
        }

        if ($this->archives->batchesIn($stream, $window->from, $window->to) === []) {
            throw ComplianceException::unarchived($stream, $window->from, $window->to);
        }
    }

    /**
     * Whether a claim over this window is a run that did not finish removing it, rather than a range
     * that is simply back.
     *
     * The claim alone is not the answer, and that is the third time this package has had to learn
     * it. A rehydrated range carries its original created_at, so the frontier offers it again the
     * moment it has rows — and a purge that took the standing claim as licence would remove it
     * without recording anything, believing it was finishing its own half-done work.
     *
     * A window that still holds every row it should is therefore treated as present, not as
     * half-removed. That deliberately loses one distinction the database cannot make anyway: a run
     * interrupted between recording the range and removing the first row looks exactly like a range
     * that came back. The cost of getting it wrong is asymmetric — recording twice costs one row,
     * which the schema declares legal, and recording nothing loses the record of a deletion — so it
     * is decided in the direction that cannot lose.
     */
    private function unfinished(string $stream, Checkpoint $window, int $present): bool
    {
        return $present < $window->length()
            && $this->archives->holds($stream, $window->from, $window->to);
    }

    /**
     * The range is written down before a row goes, and under `archive` it is written OUT first: the
     * batch has to be on the disk, read back and rehashed before the manifest hears about it, so an
     * interruption leaves a file nobody points at rather than a range nobody can restore.
     */
    private function record(string $stream, Checkpoint $window, PruneAction $action, int $records): void
    {
        match ($action) {
            PruneAction::Archive => $this->archives->archived($this->archiver->archive($stream, $window->from, $window->to)),
            PruneAction::Delete => $this->archives->retired($stream, $window->from, $window->to, $records),
        };
    }

    /**
     * Whether the range still folds to the root its anchor holds. Two columns a row and no rehashing,
     * which is what makes it affordable in front of every window rather than something an operator
     * has to remember to run first.
     */
    private function tampered(string $stream, Checkpoint $window): ?VerificationResult
    {
        $root = $this->checkpoints->refold($window, $this->checkpoints->rootBefore($stream, $window->from));

        if ($root === $window->rootHash) {
            return null;
        }

        /*
         * A root that could not be recomputed AT ALL is a range a previous run had already started
         * removing, and the manifest row is what says the half-finished work is this command's own.
         * A range that folds to a different root has its entries right here and they have moved, and
         * a purge must never be what erases that — so the manifest is not allowed to excuse it. The
         * row is unsigned and unhashed, and taking it as licence to skip the check would make one
         * insert the price of having evidence deleted rather than reported.
         */
        return $root === null && $this->archives->holds($stream, $window->from, $window->to)
            ? null
            : VerificationResult::broken($stream, 0, IntegrityBreak::CheckpointMismatch, $window->from, $window->rootHash);
    }

    private function since(float|int $started): float
    {
        return (hrtime(true) - $started) / 1_000_000_000;
    }
}
