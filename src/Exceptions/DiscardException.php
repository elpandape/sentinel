<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Exceptions;

use LogicException;

final class DiscardException extends LogicException
{
    public static function outsideThePipeline(string $reason): self
    {
        return new self(sprintf(
            'Sentinel was asked to discard an entry outside the pipeline, giving [%s] as the reason. '
            .'The ledger assigns sequence once the pipeline has already run, so a discard past that point '
            .'would leave a gap that verifyIntegrity() reports as tampering. Discard from a stage instead.',
            $reason,
        ));
    }
}
