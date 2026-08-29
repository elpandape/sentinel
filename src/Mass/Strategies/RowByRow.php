<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Mass\Strategies;

use Closure;
use ElPandaPe\Sentinel\Mass\MassCapture;
use ElPandaPe\Sentinel\Mass\Operation;
use ElPandaPe\Sentinel\Sentinel;
use Illuminate\Database\Eloquent\Model;

/**
 * What the two modes that describe rows have in common, which turns out to be everything but the
 * ceiling. Individual has none and hybrid has one, and rather than two implementations of the same
 * careful sequence there is one of them with a limit that may be absent.
 *
 * The sequence is the whole reason this is not three lines. The rows are read before the statement
 * runs, because after an update the earlier state is gone and after a delete the row is; and the
 * read and the statement share one database transaction, because between a select and an update
 * under READ COMMITTED a row can arrive that no entry would describe.
 *
 * Every entry of the operation shares one correlation id, so a summary saying three thousand rows
 * moved and the three thousand entries saying how are one thing rather than a pile that happens to
 * be adjacent in time. An operation already running inside one keeps that one: a business
 * transaction does not split because of how its implementation writes rows.
 */
final readonly class RowByRow
{
    private const string OPERATION = 'mass.';

    public function __construct(
        private MassCapture $capture,
        private Sentinel $sentinel,
    ) {}

    /**
     * A ceiling of null reads everything. A ceiling of N reads N + 1 and stops: if the extra row
     * came back the set is over the limit, and that is known without a count of its own and without
     * ever holding the whole set in memory.
     *
     * @param  Operation<covariant Model>  $operation
     * @param  Closure(): int  $run
     */
    public function within(Operation $operation, Closure $run, ?int $ceiling): int
    {
        /** @var int $affected */
        $affected = $this->sentinel->transaction(
            self::OPERATION.$operation->event->value,
            fn (): int => $operation->model()->getConnection()->transaction(
                fn (): int => $this->settle($operation, $run, $ceiling),
            ),
        );

        return $affected;
    }

    /**
     * @param  Operation<covariant Model>  $operation
     * @param  Closure(): int  $run
     */
    private function settle(Operation $operation, Closure $run, ?int $ceiling): int
    {
        $rows = $this->read($operation, $ceiling);
        $affected = $run();

        $this->capture->summary($operation, $affected);

        if ($rows !== null) {
            $this->capture->individual($operation, $rows);
        }

        return $affected;
    }

    /**
     * The rows to describe, or null when there are more of them than the ceiling allows. Null is
     * not an empty read: a set that turned out to be too large is one this mode deliberately says
     * nothing row by row about, and an empty list would mean it looked and found nothing.
     *
     * @param  Operation<covariant Model>  $operation
     * @return list<Model>|null
     */
    private function read(Operation $operation, ?int $ceiling): ?array
    {
        $query = $operation->query->clone();

        if ($ceiling === null) {
            /** @var list<Model> $all */
            $all = $query->get()->all();

            return $all;
        }

        /** @var list<Model> $rows */
        $rows = $query->limit($ceiling + 1)->get()->all();

        return count($rows) > $ceiling ? null : $rows;
    }
}
