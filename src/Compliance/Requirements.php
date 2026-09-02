<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Compliance;

use ElPandaPe\Sentinel\Exceptions\ComplianceException;
use ElPandaPe\Sentinel\Support\Config;

/**
 * What compliance mode insists on before the application is allowed to boot.
 *
 * The chain is not on the list: it is unconditional since v0.3.0 and cannot be switched off. What can
 * be switched off is everything built on top of it — signatures, and the anchors that make a pruned
 * range provable — and an installation that declares itself compliant while running without them is
 * making a claim its configuration does not support.
 *
 * It fails at boot rather than at the first write, because the first write may be a year away and by
 * then the entries that were supposed to be signed are not.
 */
final readonly class Requirements
{
    public function __construct(private Config $config) {}

    public function enforce(): void
    {
        if (! $this->config->complianceEnabled()) {
            return;
        }

        $missing = [];

        if (! $this->config->signatureEnabled()) {
            $missing[] = 'integrity.signature.enabled';
        }

        if (! $this->config->checkpointsEnabled()) {
            $missing[] = 'integrity.checkpoints.enabled';
        }

        if ($missing !== []) {
            throw ComplianceException::incomplete($missing);
        }
    }
}
