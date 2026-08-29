<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Contracts;

use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Dispatch\Handover;

/**
 * How an entry the pipeline already approved reaches the ledger. One implementation per mode,
 * and the only thing that separates them: what the entry goes through before this is identical,
 * and what happens to it after is the ledger's.
 *
 * The two methods are one decision the caller has already made and cannot make again — whether
 * the transaction that produced the fact has committed. It is not a flag on one method because
 * the branches are not variations of a policy: a write in the request may refuse the request,
 * and one running from a commit callback may never do so.
 */
interface DispatchStrategy
{
    public function inRequest(AuditData $audit): Handover;

    public function afterCommit(AuditData $audit): Handover;
}
