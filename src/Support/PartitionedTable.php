<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Support;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;

/**
 * A create-table the schema builder compiled, with a partitioning clause on the end of it.
 *
 * Neither engine's grammar can say `partition by`, and neither will accept it after the fact: it is
 * part of how the table is defined and there is no ALTER that adds it. The alternative to this would
 * be a stub that writes forty columns of raw DDL per engine, which is three more copies of the
 * schema and three more things to forget when a column is added.
 *
 * So the blueprint builds the statements and this pins the clause to the one that creates the table.
 * The rest — the keys, the indexes — are separate statements in every grammar and are left alone.
 */
final readonly class PartitionedTable
{
    /**
     * @param  Closure(Blueprint): void  $define
     * @return list<string>
     */
    public function statements(Connection $connection, string $table, Closure $define, string $clause): array
    {
        $blueprint = new Blueprint($connection, $table);
        $blueprint->create();

        $define($blueprint);

        $statements = [];

        /** @var string $sql */
        foreach ($blueprint->toSql() as $sql) {
            $statements[] = str_starts_with($sql, 'create table') ? "{$sql} {$clause}" : $sql;
        }

        return $statements;
    }
}
