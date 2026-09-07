<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Integrity\VerificationResult;

it('says nothing when the walk found nothing to report', function (): void {
    $result = VerificationResult::intact('global', 3);

    expect($result->isIntact())->toBeTrue()
        ->and($result->message())->toBeEmpty();
});
