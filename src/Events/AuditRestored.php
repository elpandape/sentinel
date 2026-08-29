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
 * that recorded the whole thing. That last one is why the announcement waits for the commit and
 * not merely for the restoration to return — inside a transaction of the application's own, the
 * call returns while the entry is still queued behind a commit that has not happened, and an
 * event carrying that result would name a restoration and hand over nothing that recorded it.
 */
final readonly class AuditRestored
{
    public function __construct(
        public Audit $entry,
        public Model $subject,
        public RestoreResult $result,
    ) {}
}
