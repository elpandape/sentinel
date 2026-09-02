<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Partitions;

use ElPandaPe\Sentinel\Exceptions\ConfigurationException;

/**
 * What each engine calls partition maintenance, said in its own words.
 *
 * Like ChangedFieldPredicate it takes the driver name rather than a connection, so all three
 * dialects are readable and testable without three databases — and so the two a given run cannot
 * reach are still code somebody looked at.
 *
 * The two engines do not divide the work the same way and it is not worth pretending they do.
 * PostgreSQL's partitions are tables: one is created beside the parent and attached to it, and
 * dropping the table is what retires it. MySQL's are not: the list lives inside the table
 * definition, so adding a month means reorganising the catch-all that stands in front of it, and
 * that is why the statement mentions MAXVALUE twice — the range being added and the catch-all
 * being put back.
 *
 * SQLite gets nothing, and that is the answer rather than a gap. It does not partition, so a
 * maintenance run there has nothing to maintain and says so.
 */
final readonly class Grammar
{
    public const string CATCH_ALL = 'pmax';

    public function partitions(string $driver): string
    {
        return match ($driver) {
            'pgsql' => 'select c.relname as name'
                .' from pg_class parent'
                .' join pg_inherits i on i.inhparent = parent.oid'
                .' join pg_class c on c.oid = i.inhrelid'
                .' where parent.relname = ? order by c.relname',
            'mysql' => 'select partition_name as name'
                .' from information_schema.partitions'
                .' where table_schema = database() and table_name = ? and partition_name is not null'
                .' order by partition_ordinal_position',
            default => throw ConfigurationException::doesNotPartition($driver),
        };
    }

    /**
     * @return list<string>
     */
    public function create(string $driver, string $table, Partition $partition): array
    {
        $from = $partition->from?->format('Y-m-d') ?? '';
        $to = $partition->to?->format('Y-m-d') ?? '';

        return match ($driver) {
            'pgsql' => [
                "create table {$partition->name} partition of {$table} for values from ('{$from}') to ('{$to}')",
            ],
            'mysql' => [
                "alter table {$table} reorganize partition ".self::CATCH_ALL.' into ('
                ."partition {$partition->name} values less than (to_days('{$to}')), "
                .'partition '.self::CATCH_ALL.' values less than maxvalue)',
            ],
            default => throw ConfigurationException::doesNotPartition($driver),
        };
    }

    public function retire(string $driver, string $table, Partition $partition): string
    {
        return match ($driver) {
            'pgsql' => "drop table {$partition->name}",
            'mysql' => "alter table {$table} drop partition {$partition->name}",
            default => throw ConfigurationException::doesNotPartition($driver),
        };
    }

    /**
     * How many entries the partition still holds. PostgreSQL is asked of the partition itself,
     * because it is a table; MySQL has to be told which partition of the parent to read.
     */
    public function count(string $driver, string $table, Partition $partition): string
    {
        return match ($driver) {
            'pgsql' => "select count(*) as total from {$partition->name}",
            'mysql' => "select count(*) as total from {$table} partition ({$partition->name})",
            default => throw ConfigurationException::doesNotPartition($driver),
        };
    }

    public function divides(string $driver): bool
    {
        return in_array($driver, ['pgsql', 'mysql'], true);
    }

    /**
     * What a partition of this table is called before its month. PostgreSQL's partitions are tables
     * and share the schema's namespace, so they carry the parent's name; MySQL's are named inside
     * the table definition and do not need it.
     */
    public function prefix(string $driver, string $table): string
    {
        return match ($driver) {
            'pgsql' => "{$table}_",
            'mysql' => '',
            default => throw ConfigurationException::doesNotPartition($driver),
        };
    }
}
