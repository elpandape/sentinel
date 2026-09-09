<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Capture\Recorder;
use ElPandaPe\Sentinel\Context\Attribution;
use ElPandaPe\Sentinel\Context\Attributions;
use ElPandaPe\Sentinel\Support\AuditCollection;
use ElPandaPe\Sentinel\Support\Reference;

use function ElPandaPe\Sentinel\Tests\auditData;

it('records a batch on no attribution of its own, even from inside another capture\'s pass', function (): void {
    $recorder = app(Recorder::class);
    $outer = Attribution::by(new Reference('member', '77'));

    $written = app(Attributions::class)->within($outer, static fn (): AuditCollection => $recorder->recordMany([auditData()]));

    expect($written)->toHaveCount(1)
        ->and($written->first()?->actor_id)->toBeNull();
});
