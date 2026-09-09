<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Context;

use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Support\Reference;

/**
 * What a capture states outright about the columns the resolvers would otherwise fill: the actor
 * it names, and the tenant it acts on behalf of. Both outrank the run's own context. An event that
 * names its actor is not about whoever happens to be logged in, and the trail of a redaction is
 * about the tenant of the entry it redacted, not the tenant of the console that ran it.
 *
 * Naming a tenant and naming none are two different statements, and null belongs to the first:
 * the trail of an entry that had no tenant keeps having none, whichever tenant is active.
 *
 * @internal
 */
final readonly class Attribution
{
    private function __construct(
        public ?Reference $actor,
        private bool $namesTenant,
        public ?string $tenantId,
    ) {}

    public static function none(): self
    {
        return new self(null, false, null);
    }

    public static function by(?Reference $actor): self
    {
        return new self($actor, false, null);
    }

    public function onBehalfOf(?string $tenantId): self
    {
        return new self($this->actor, true, $tenantId);
    }

    /**
     * The impersonator goes with the actor. Whoever the session resolved was standing in for the
     * actor resolved alongside them, not for the one named here, and an entry pairing the two
     * would claim a delegation that never happened.
     */
    public function apply(AuditData $audit): void
    {
        if ($this->actor instanceof Reference) {
            $audit->actor_type = $this->actor->type;
            $audit->actor_id = $this->actor->id;
            $audit->impersonator_type = null;
            $audit->impersonator_id = null;
        }

        if ($this->namesTenant) {
            $audit->tenant_id = $this->tenantId;
        }
    }
}
