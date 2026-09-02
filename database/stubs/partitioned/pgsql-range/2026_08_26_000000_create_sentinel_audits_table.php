<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Support\AuditSchema;
use ElPandaPe\Sentinel\Support\Config;
use ElPandaPe\Sentinel\Support\PartitionedTable;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The audit table divided by the month it was recorded in, on PostgreSQL 16.
     *
     * This REPLACES the base migration rather than following it: publishing it puts a file with the
     * same name in the application's migrations directory, and the package stops loading its own.
     * Run it on a new installation, before there is a first entry. For a table that already holds
     * data, the upgrade notes describe the procedure; the package converts nothing on its own,
     * because turning a large table into a partitioned one is a maintenance window and a decision
     * that is not a package's to make.
     *
     * Two keys change shape, and the README says so without dressing it up. Both engines require
     * every unique key of a partitioned table to carry the partitioning column, so `(stream,
     * sequence)` and `capture_id` gain `created_at` and stop being enforced ACROSS partitions. The
     * chain does not depend on the engine for its ordering — the ledger assigns the sequence and
     * sentinel:verify re-derives every hash — but the safety net is narrower here than on a flat
     * table, and that is the price of the division.
     *
     * The DEFAULT partition is not optional. Without it an insert whose created_at falls outside
     * every declared range fails, and that failure lands in the write path of the application. With
     * it, a forgotten sentinel:partitions degrades to one fat partition instead. It has its own
     * cost, stated in the README: attaching a new range to a table whose default partition already
     * holds rows makes PostgreSQL scan it first.
     */
    public function up(): void
    {
        $connection = $this->connection();
        $schema = new AuditSchema;

        $statements = new PartitionedTable()->statements(
            $connection,
            $this->table(),
            static function (Blueprint $table) use ($schema): void {
                $schema->columns($table);

                $table->primary(['id', 'created_at']);
                $table->unique(['stream', 'sequence', 'created_at']);
                $table->unique(['capture_id', 'created_at']);

                $schema->indexes($table);
            },
            'partition by range (created_at)',
        );

        foreach ($statements as $statement) {
            $connection->statement($statement);
        }

        $connection->statement("create table {$this->table()}_default partition of {$this->table()} default");

        foreach ($this->months() as $month) {
            $connection->statement(
                "create table {$this->table()}_p{$month->format('Y_m')} partition of {$this->table()}"
                ." for values from ('{$month->format('Y-m-d')}') to ('{$month->addMonth()->format('Y-m-d')}')",
            );
        }
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists($this->table());
    }

    public function getConnection(): ?string
    {
        return $this->config()->connection();
    }

    /**
     * This month and the three after it, so a new installation writes for a quarter before the
     * maintenance command has to have run even once.
     *
     * @return list<CarbonImmutable>
     */
    private function months(): array
    {
        $first = CarbonImmutable::now()->startOfMonth();

        return array_map(static fn (int $ahead): CarbonImmutable => $first->addMonths($ahead), [0, 1, 2, 3]);
    }

    private function connection(): Connection
    {
        return Schema::connection($this->getConnection())->getConnection();
    }

    private function table(): string
    {
        return $this->config()->table('audits');
    }

    private function config(): Config
    {
        /** @var Config $config */
        $config = app(Config::class);

        return $config;
    }
};
