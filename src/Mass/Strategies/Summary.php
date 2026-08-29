<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Mass\Strategies;

use Closure;
use ElPandaPe\Sentinel\Contracts\MassStrategy;
use ElPandaPe\Sentinel\Mass\MassCapture;
use ElPandaPe\Sentinel\Mass\Operation;

/**
 * One entry for the whole operation: what it was aimed at, which columns it wrote, and how many
 * rows it reached. Nothing is read, so nothing is read — no count, no keys, no earlier state — and
 * the cost of auditing a statement that touches three thousand five hundred rows is the same as
 * auditing one that touches one.
 *
 * The default, and it stays the default. It is the only one of the three whose cost does not grow
 * with the size of the set, and a mode that turns a one-line update into thousands of inserts is a
 * decision an application makes for itself.
 */
final readonly class Summary implements MassStrategy
{
    public function __construct(private MassCapture $capture) {}

    /**
     * The statement runs first. The count is what the entry is about, and there is no way to know
     * it without having run the thing — which is also why this mode cannot be the one that reads
     * a before.
     *
     * @param  Operation<covariant \Illuminate\Database\Eloquent\Model>  $operation
     * @param  Closure(): int  $run
     */
    public function capture(Operation $operation, Closure $run): int
    {
        $rows = $run();

        $this->capture->summary($operation, $rows);

        return $rows;
    }
}
