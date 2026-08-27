<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Pipeline\Stages;

use Closure;
use ElPandaPe\Sentinel\Contracts\Transformer;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Pipeline\Discard;
use ElPandaPe\Sentinel\Support\Policies;

/**
 * Last, so a policy decides on the entry as it will be written: masked, encrypted and
 * complete. Deciding on the plaintext would mean the policy read something the ledger
 * never gets to see.
 */
final readonly class EnforcePolicies implements Transformer
{
    public const string REASON = 'policy';

    public function __construct(
        private Policies $policies,
        private Discard $discard,
    ) {}

    /**
     * @param  Closure(AuditData): ?AuditData  $next
     */
    public function handle(AuditData $audit, Closure $next): ?AuditData
    {
        if ($this->policies->allows($audit)) {
            return $next($audit);
        }

        $this->discard->because(self::REASON);

        return null;
    }
}
