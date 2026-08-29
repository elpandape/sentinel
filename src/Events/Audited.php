<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Events;

use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Models\Audit;

/**
 * The end of the journey for the process that captured the fact. It says that Sentinel is done
 * with this entry here: nothing else will happen to it in this request, this job or this command.
 *
 * It is not AuditCreated, and the difference is the whole reason it exists. AuditCreated says an
 * entry has identity, and it is announced wherever the ledger assigned it — which under `queue` is
 * a worker the request will never hear from. This one is announced where the capture happened, and
 * carries the entry only when the two are the same place.
 *
 * A null entry therefore means "settled elsewhere", never "not settled": a write that did not
 * complete announces AuditWriteFailed instead, and the two never both go out for one capture.
 */
final readonly class Audited
{
    public function __construct(
        public AuditData $audit,
        public ?Audit $entry = null,
    ) {}
}
