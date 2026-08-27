<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Query;

use Countable;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Support\AuditCollection;
use IteratorAggregate;
use Traversable;

/**
 * One page of the trail, and whether there is another one behind it.
 *
 * There is no total, and that is the decision rather than an omission: counting the rows a
 * filter matches on a table that only ever grows is the one question in this API whose cost
 * is unbounded and that no index answers. The page is resolved with a single call to the
 * ledger, asking for one entry more than it hands back — which is how it knows.
 *
 * @implements IteratorAggregate<int, Audit>
 */
final readonly class AuditPage implements Countable, IteratorAggregate
{
    public function __construct(
        public AuditCollection $entries,
        public int $page,
        public int $perPage,
        public bool $hasMore,
    ) {}

    public function count(): int
    {
        return $this->entries->count();
    }

    public function getIterator(): Traversable
    {
        return $this->entries->getIterator();
    }
}
