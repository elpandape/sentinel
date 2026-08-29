<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Dispatch\Handover;
use ElPandaPe\Sentinel\Models\Audit;

it('carries the entry when the mode settled it here', function (): void {
    $entry = new Audit()->forceFill(['id' => 'entry']);

    $handover = Handover::settled($entry);

    expect($handover->accepted)->toBeTrue()
        ->and($handover->entry)->toBe($entry);
});

it('tells an entry that settles elsewhere apart from one that never will', function (): void {
    expect(Handover::accepted()->accepted)->toBeTrue()
        ->and(Handover::accepted()->entry)->toBeNull()
        ->and(Handover::refused()->accepted)->toBeFalse()
        ->and(Handover::refused()->entry)->toBeNull();
});
