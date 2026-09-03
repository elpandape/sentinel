<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Dispatch;

use Countable;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Support\AuditCollection;
use IteratorAggregate;
use Traversable;

/**
 * What a batch settled, out of what it was handed. The two are not the same number and the
 * difference is not an error: a capture that already had an entry, and one the batch named twice,
 * are dropped before the write rather than sealed again.
 *
 * A caller holding only the entries cannot tell that batch from one that arrived smaller, and the
 * buffered mode is where the distinction has to survive — there, what was taken is gone from the
 * buffer whether or not anything was written for it.
 *
 * `skipped` is derived and not stored: three numbers that must add up are three chances to
 * disagree.
 *
 * @implements IteratorAggregate<int, Audit>
 */
final readonly class Settled implements Countable, IteratorAggregate
{
    public function __construct(
        public AuditCollection $entries,
        public int $taken,
    ) {}

    public function count(): int
    {
        return $this->entries->count();
    }

    public function skipped(): int
    {
        return $this->taken - $this->count();
    }

    public function getIterator(): Traversable
    {
        return $this->entries->getIterator();
    }
}
