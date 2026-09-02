<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Enums\Filter;
use ElPandaPe\Sentinel\Ledger\ContextPredicate;
use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The index behind whereIp() and whereRoute(), published rather than shipped.
     *
     * Both filters read inside `context`, which no index of this schema covers, so without this
     * they answer by scanning — correctly, and as refiners. What is bought here is the seek, and
     * what it costs is measured: fifteen per cent per write on PostgreSQL 16 and twenty-one on
     * MySQL 9, over a table with the thirty columns and twelve indexes the base migration creates.
     * That is why it is not loaded automatically. An installation that never asks where an entry
     * was recorded from should not pay it, and one that asks every day should not have to build
     * the index itself.
     *
     * The expression is not written here. It comes from ContextPredicate, which is the same object
     * the driver asks when it compiles the filter — two copies of a JSON path is an index that
     * silently stops being used the day one of them is edited.
     *
     * MySQL gets a generated column because it cannot index an expression the query writes without
     * a matching cast, and the column is VIRTUAL INVISIBLE for two separate reasons. VIRTUAL,
     * because STORED rewrites the table to add itself and then widens every row. INVISIBLE, because
     * a generated column that answers `select *` would ride along in the attributes of an entry
     * read back out, and the next thing that inserts that entry somewhere else — a fanout to a
     * second destination, a rehydration — would be handing MySQL a value for a column it computes
     * itself, which it refuses.
     */
    public function up(): void
    {
        $connection = $this->connection();

        foreach ([Filter::Ip, Filter::Route] as $filter) {
            $connection->getDriverName() === 'mysql'
                ? $this->generatedColumn($filter)
                : $this->expressionIndex($connection, $filter);
        }
    }

    public function down(): void
    {
        $connection = $this->connection();

        foreach ([Filter::Ip, Filter::Route] as $filter) {
            $connection->getDriverName() === 'mysql'
                ? $this->dropGeneratedColumn($filter)
                : $connection->statement("drop index {$this->name($filter)}");
        }
    }

    public function getConnection(): ?string
    {
        return $this->config()->connection();
    }

    /**
     * The reading goes in doubled parentheses: PostgreSQL reads a single pair as a column list and
     * refuses the operator inside it, and SQLite takes the extra pair without noticing.
     */
    private function expressionIndex(Connection $connection, Filter $filter): void
    {
        $connection->statement(
            "create index {$this->name($filter)} on {$this->table()} (({$this->reading($connection, $filter)}))",
        );
    }

    private function generatedColumn(Filter $filter): void
    {
        $connection = $this->connection();

        $this->schema()->table($this->table(), function (Blueprint $table) use ($connection, $filter): void {
            $table->string($this->column($filter), 255)
                ->virtualAs($this->reading($connection, $filter))
                ->invisible()
                ->nullable();

            $table->index($this->column($filter), $this->name($filter));
        });
    }

    private function dropGeneratedColumn(Filter $filter): void
    {
        $this->schema()->table($this->table(), function (Blueprint $table) use ($filter): void {
            $table->dropIndex($this->name($filter));
            $table->dropColumn($this->column($filter));
        });
    }

    private function reading(Connection $connection, Filter $filter): string
    {
        return new ContextPredicate()->expression(
            $connection->getDriverName(),
            $connection->getQueryGrammar()->wrap('context'),
            $filter,
        );
    }

    private function column(Filter $filter): string
    {
        return "context_{$filter->value}";
    }

    /**
     * Named by hand, because the generated name is built from a column that only one of the three
     * engines has, and the table prefix in front of it is the user's to choose.
     */
    private function name(Filter $filter): string
    {
        return "{$this->table()}_context_{$filter->value}_index";
    }

    private function connection(): Connection
    {
        return $this->schema()->getConnection();
    }

    private function schema(): Illuminate\Database\Schema\Builder
    {
        return Schema::connection($this->getConnection());
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
