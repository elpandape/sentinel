<?php

declare(strict_types=1);

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
     * The audit table divided by tenant, on PostgreSQL 16. It REPLACES the base migration.
     *
     * This is the division that keeps the chain's guarantee, and the way it keeps it is not the
     * obvious one. Putting `tenant_id` in the primary key would work on paper and breaks twice in
     * practice: PostgreSQL promotes every column of a primary key to NOT NULL, so an entry recorded
     * with no tenant — a command, a queue worker, the scheduler — stops being writable; and filling
     * it with a sentinel is worse, because `tenant_id` is inside the canonical payload, so an empty
     * string where the hash was sealed over null makes the entry fail its own verification.
     *
     * So there is no primary key, and the unique keys are LOCAL to each partition. With
     * `stream = tenant` — the multi-tenant default the README recommends, and the reason to choose
     * this stub — every entry of a stream lives in one partition, which makes a per-partition
     * `unique (stream, sequence)` exactly the guarantee the flat table had. `tenant_id` stays
     * nullable, the payload is untouched, and an entry with no tenant lands in a partition of its
     * own instead of failing.
     *
     * Every partition a hand adds later has to carry those two indexes. The command does not create
     * them here — it maintains ranges, not lists, because it cannot invent the names of tenants —
     * so the README spells out the two statements that go with a new tenant.
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

                // Not a primary key: one would carry tenant_id and make it NOT NULL. The identifier
                // is a ULID minted by this package, so what a primary key would add is the seek.
                $table->index('id');

                $schema->indexes($table);
            },
            'partition by list (tenant_id)',
        );

        foreach ($statements as $statement) {
            $connection->statement($statement);
        }

        $this->partition("{$this->table()}_untenanted", 'for values in (null)');
        $this->partition("{$this->table()}_default", 'default');
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
     * A partition and the two unique indexes that make it hold the chain. They are created on the
     * partition and not on the parent, because the parent would refuse them: a unique key declared
     * there has to carry the partitioning column, which is the whole thing this stub is avoiding.
     */
    private function partition(string $name, string $bounds): void
    {
        $connection = $this->connection();

        $connection->statement("create table {$name} partition of {$this->table()} {$bounds}");
        $connection->statement("create unique index {$name}_stream_sequence on {$name} (stream, sequence)");
        $connection->statement("create unique index {$name}_capture on {$name} (capture_id)");
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
