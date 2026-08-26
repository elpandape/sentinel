<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Contracts;

use ElPandaPe\Sentinel\Models\Audit;
use IteratorAggregate;
use Traversable;

/**
 * @extends IteratorAggregate<int, Audit>
 */
interface LedgerStream extends IteratorAggregate
{
    public function name(): string;

    public function range(int $from, ?int $to = null): static;

    /**
     * @return Traversable<int, Audit>
     */
    public function getIterator(): Traversable;
}
