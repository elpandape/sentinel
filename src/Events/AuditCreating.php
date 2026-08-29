<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Events;

use ElPandaPe\Sentinel\Data\AuditData;

/**
 * The entry at the ledger's door, the instant before it is given a sequence and a hash. It is the
 * last thing anyone hears about an entry that has no identity, and it is not cancellable: the
 * sequence is assigned in the same operation as the write, so refusing here would leave a gap that
 * verifyIntegrity() reports as tampering. Refusing is what Auditing is for, and it has run.
 *
 * It is announced, not consulted. The entry has already been through the pipeline and past the
 * application's own say, so whatever is changed here is sealed without either of them having seen
 * it — including the fields the pipeline is there to transform.
 */
final readonly class AuditCreating
{
    public function __construct(public AuditData $audit) {}
}
