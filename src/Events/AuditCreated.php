<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Events;

use ElPandaPe\Sentinel\Models\Audit;

/**
 * An entry that exists. It has its place in the chain — a stream, a sequence, a hash over the link
 * before it — and from here nothing rewrites it: the model refuses an update and refuses a delete.
 *
 * Announced where the entry was written, which inside a transaction is the commit and not the
 * capture. A listener holding this one is holding a fact, not an intention.
 */
final readonly class AuditCreated
{
    public function __construct(public Audit $entry) {}
}
