<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Contracts;

use Closure;
use ElPandaPe\Sentinel\Mass\Operation;

/**
 * What a query that asked to be audited leaves behind. One implementation per mode, and the mode
 * is the only thing that separates them: the statement is the same statement, and what changes is
 * how much is read around it and how many entries come out.
 *
 * The statement is handed over rather than run here, because when it runs is part of what the mode
 * decides — a mode that describes rows one by one has to read them first, and inside the same
 * transaction, or it would describe a set that the update had already moved on from.
 *
 * @phpstan-type Statement Closure(): int
 */
interface MassStrategy
{
    /**
     * @param  Operation<covariant \Illuminate\Database\Eloquent\Model>  $operation
     * @param  Closure(): int  $run  the statement, answering how many rows it touched
     */
    public function capture(Operation $operation, Closure $run): int;
}
