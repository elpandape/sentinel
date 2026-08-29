<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Mass\Strategies;

use Closure;
use ElPandaPe\Sentinel\Contracts\MassStrategy;
use ElPandaPe\Sentinel\Mass\Operation;
use ElPandaPe\Sentinel\Support\Config;

/**
 * Individual while the set is small enough to be worth describing row by row, and summary the
 * moment it is not. The summary is written either way, so an operation that degraded still says
 * what it was and how many rows it reached — the count is never the thing that gets lost.
 *
 * Which side of the line a set falls on is settled by reading one row past the threshold rather
 * than by counting: a count is a second statement over the same predicate, and this way the price
 * of asking is bounded by the threshold itself.
 */
final readonly class Hybrid implements MassStrategy
{
    public function __construct(
        private RowByRow $rows,
        private Config $config,
    ) {}

    /**
     * @param  Operation<covariant \Illuminate\Database\Eloquent\Model>  $operation
     * @param  Closure(): int  $run
     */
    public function capture(Operation $operation, Closure $run): int
    {
        return $this->rows->within($operation, $run, $this->config->massThreshold());
    }
}
