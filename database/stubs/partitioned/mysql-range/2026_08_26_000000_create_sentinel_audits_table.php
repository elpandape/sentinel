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
     * The audit table divided by the day it was recorded in, on MySQL 9. It REPLACES the base
     * migration, and like the PostgreSQL one it is for a new installation rather than a conversion.
     *
     * MySQL loses more here than PostgreSQL does, and it is worth knowing before choosing it. Its
     * partitions are not tables, so there is no per-partition index to fall back on: `ERROR 1503`
     * rejects any unique key that does not carry the partitioning column, and that is the end of it.
     * `(stream, sequence)` and `capture_id` therefore gain `created_at` and are enforced only within
     * a day. What still holds the chain is the ledger's own sequence assignment and sentinel:verify.
     *
     * MAXVALUE is what keeps a forgotten sentinel:partitions from becoming a failed write. The
     * command extends the calendar by reorganising it, which is the only way to add a range in front
     * of a catch-all that already exists.
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
            "partition by range (to_days(created_at)) ({$this->calendar()})",
        );

        foreach ($statements as $statement) {
            $connection->statement($statement);
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
     * This month and the three after it, then the catch-all. Written into the create rather than
     * added afterwards because MySQL takes its partition list there and nowhere else.
     */
    private function calendar(): string
    {
        $first = CarbonImmutable::now()->startOfMonth();

        $ranges = array_map(
            static fn (int $ahead): string => sprintf(
                'partition p%s values less than (to_days(\'%s\'))',
                $first->addMonths($ahead)->format('Y_m'),
                $first->addMonths($ahead + 1)->format('Y-m-d'),
            ),
            [0, 1, 2, 3],
        );

        return implode(', ', [...$ranges, 'partition pmax values less than maxvalue']);
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
