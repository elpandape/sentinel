<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Partitions;

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Retention\Duration;
use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Database\Connection;

/**
 * Keeps a divided table divided: the months in front of it exist, and the ones behind it go.
 *
 * Idempotent by construction. What should exist is derived from the clock, what does exist is read
 * from the catalogue, and only the difference is issued — so a second run in the same minute writes
 * nothing and says so.
 *
 * Retirement is deliberately timid. A partition is dropped when its month is behind the cutoff AND
 * it holds no entries, which is the state sentinel:prune leaves it in after archiving the range. A
 * partition that still holds entries is kept and the report says why: dropping it would remove a
 * range of the trail as a catalogue operation, without archiving it and without recording that it
 * went. Under compliance mode that is not even offered — the guard is unconditional there, because
 * the one thing compliance forbids is a range leaving with nothing to answer for it.
 */
final readonly class Maintainer
{
    public function __construct(
        private Grammar $grammar,
        private Calendar $calendar,
        private Config $config,
    ) {}

    public function maintain(
        Connection $connection,
        string $table,
        int $ahead,
        ?Duration $keep,
        bool $force,
        bool $dryRun,
        CarbonImmutable $now,
    ): Maintenance {
        $driver = $connection->getDriverName();

        if (! $this->grammar->divides($driver)) {
            return new Maintenance($table, divided: false);
        }

        $existing = $this->existing($connection, $driver, $table);

        if ($existing === []) {
            return new Maintenance($table, divided: false);
        }

        $created = $this->create($connection, $driver, $table, $existing, $ahead, $dryRun, $now);
        ['retired' => $retired, 'kept' => $kept] = $this->retire(
            $connection, $driver, $table, $existing, $keep, $force, $dryRun, $now,
        );

        return new Maintenance($table, divided: true, created: $created, retired: $retired, kept: $kept);
    }

    /**
     * @return list<Partition>
     */
    private function existing(Connection $connection, string $driver, string $table): array
    {
        $partitions = [];

        foreach ($connection->select($this->grammar->partitions($driver), [$table]) as $row) {
            $name = is_object($row) ? ($row->name ?? null) : null;

            if (is_string($name) && $name !== '') {
                $partitions[] = Partition::named($name);
            }
        }

        return $partitions;
    }

    /**
     * @param  list<Partition>  $existing
     * @return list<string>
     */
    private function create(
        Connection $connection,
        string $driver,
        string $table,
        array $existing,
        int $ahead,
        bool $dryRun,
        CarbonImmutable $now,
    ): array {
        $wanted = $this->calendar->ahead($this->grammar->prefix($driver, $table), $now, $ahead);
        $created = [];

        foreach ($this->calendar->missing($wanted, $existing) as $partition) {
            if (! $dryRun) {
                foreach ($this->grammar->create($driver, $table, $partition) as $statement) {
                    $connection->statement($statement);
                }
            }

            $created[] = $partition->name;
        }

        return $created;
    }

    /**
     * @param  list<Partition>  $existing
     * @return array{retired: list<string>, kept: array<string, string>}
     */
    private function retire(
        Connection $connection,
        string $driver,
        string $table,
        array $existing,
        ?Duration $keep,
        bool $force,
        bool $dryRun,
        CarbonImmutable $now,
    ): array {
        if (! $keep instanceof Duration) {
            return ['retired' => [], 'kept' => []];
        }

        $retired = [];
        $kept = [];

        foreach ($this->calendar->behind($existing, $now, $keep) as $partition) {
            $entries = $this->entries($connection, $driver, $table, $partition);
            $reason = $this->refusal($entries, $force);

            if ($reason !== null) {
                $kept[$partition->name] = $reason;

                continue;
            }

            if (! $dryRun) {
                $connection->statement($this->grammar->retire($driver, $table, $partition));
            }

            $retired[] = $partition->name;
        }

        return ['retired' => $retired, 'kept' => $kept];
    }

    /**
     * Why a partition behind the cutoff stays, or null when nothing stops it going. An empty one
     * always goes: there is nothing to lose and nothing to archive.
     */
    private function refusal(int $entries, bool $force): ?string
    {
        return match (true) {
            $entries === 0 => null,
            $this->config->complianceEnabled() => 'unarchived',
            $force => null,
            default => 'occupied',
        };
    }

    private function entries(Connection $connection, string $driver, string $table, Partition $partition): int
    {
        $row = $connection->selectOne($this->grammar->count($driver, $table, $partition));

        return is_object($row) && is_numeric($row->total ?? null) ? (int) $row->total : 0;
    }
}
