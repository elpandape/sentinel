<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Enums\IntegrityBreak;
use ElPandaPe\Sentinel\Enums\RetentionHold;
use ElPandaPe\Sentinel\Integrity\VerificationResult;
use ElPandaPe\Sentinel\Retention\Frontier;
use ElPandaPe\Sentinel\Retention\PruneReport;
use ElPandaPe\Sentinel\Retention\Pruning;
use ElPandaPe\Sentinel\Retention\Removed;

it('divides the entries it removed by the seconds it took', function (): void {
    $pruning = new Pruning(Frontier::holding('global', RetentionHold::Tail), new Removed(10), 1, 2.0);

    expect($pruning->rate())->toBe(5.0);
});

it('reports no rate for a run whose clock measured nothing', function (): void {
    $pruning = new Pruning(Frontier::holding('global', RetentionHold::Tail), new Removed(10), 1, 0.0);

    expect($pruning->rate())->toBeNull();
});

it('names the first stream that broke and not the first stream it walked', function (): void {
    $report = new PruneReport([
        new Pruning(Frontier::holding('global', RetentionHold::Tail), new Removed(4), 1, 1.0),
        new Pruning(
            Frontier::holding('other', RetentionHold::Tail),
            new Removed,
            1,
            1.0,
            VerificationResult::broken('other', 0, IntegrityBreak::CheckpointMismatch, 5, str_repeat('0', 64)),
        ),
    ]);

    expect($report->firstBreak()?->stream)->toBe('other');
});
