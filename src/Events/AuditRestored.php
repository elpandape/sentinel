<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Events;

use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Restore\RestoreResult;
use Illuminate\Database\Eloquent\Model;

/**
 * A restoration that happened, announced after the commit that made it true. Announcing it any
 * earlier would tell listeners about a change a rollback is still free to undo — which is the
 * same reason the entries themselves wait for the commit.
 *
 * The result is closed by the time it gets here: what was applied, what was not, and the entry
 * that recorded the whole thing.
 */
final readonly class AuditRestored
{
    public function __construct(
        public Audit $entry,
        public Model $subject,
        public RestoreResult $result,
    ) {}
}
