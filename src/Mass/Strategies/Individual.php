<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Mass\Strategies;

use Closure;
use ElPandaPe\Sentinel\Contracts\MassStrategy;
use ElPandaPe\Sentinel\Mass\Operation;

/**
 * An entry per row, with the state each one was actually in, and the summary over them saying what
 * the operation was and how much it reached.
 *
 * It costs what it costs: the rows are read before the statement runs and the whole set is held
 * while they are described. Never the default, and documented by the number rather than by an
 * adjective — an update over three thousand five hundred rows produces three thousand five hundred
 * and one entries, and an installation that wants that is one that asked for it.
 */
final readonly class Individual implements MassStrategy
{
    public function __construct(private RowByRow $rows) {}

    /**
     * @param  Operation<covariant \Illuminate\Database\Eloquent\Model>  $operation
     * @param  Closure(): int  $run
     */
    public function capture(Operation $operation, Closure $run): int
    {
        return $this->rows->within($operation, $run, null);
    }
}
