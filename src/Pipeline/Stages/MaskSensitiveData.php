<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Pipeline\Stages;

use Closure;
use ElPandaPe\Sentinel\Contracts\Transformer;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Security\Digester;
use ElPandaPe\Sentinel\Security\Fields;
use ElPandaPe\Sentinel\Security\Maskers;
use ElPandaPe\Sentinel\Support\Config;
use ElPandaPe\Sentinel\Support\PolicyRegistry;

/**
 * The two irreversible transformations live together because they are the same operation
 * with a different result: one leaves something a human can recognise, the other leaves
 * something only a comparison can use. Neither can be undone, by us or by anyone.
 */
final readonly class MaskSensitiveData implements Transformer
{
    public function __construct(
        private PolicyRegistry $policies,
        private Config $config,
        private Maskers $maskers,
        private Digester $digester,
    ) {}

    /**
     * @param  Closure(AuditData): ?AuditData  $next
     */
    public function handle(AuditData $audit, Closure $next): ?AuditData
    {
        $policy = $this->policies->for($audit->subject_type);

        foreach ($this->config->redactedFields($policy->redacted) as $field) {
            Fields::protect($audit, [$field], fn (mixed $value): mixed => $this->maskers->for($field)->mask($field, $value));
        }

        Fields::protect($audit, $this->config->hashedFields($policy->hashed), $this->digester->digest(...));

        return $next($audit);
    }
}
