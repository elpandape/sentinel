<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The header of a business operation. Its id is the transaction_id the entries carry, so the
     * correlation needs no join table and no foreign key: the column and the index have been on
     * sentinel_audits since the schema was written.
     *
     * The actor and the tenant use the same morph as the entries. A second way of naming the same
     * actor in a second table of the same package would mean resolving it by hand on every read.
     *
     * A header is not evidence. It says what the operation was called and when it ran; what
     * happened is in the entries, where the chain covers it. That is why this table can be
     * updated when the scope closes and sentinel_audits cannot.
     */
    public function up(): void
    {
        Schema::connection($this->getConnection())->create($this->table(), function (Blueprint $table): void {
            $table->char('id', 26)->primary();

            $table->string('name', 128);

            $table->string('actor_type')->nullable();
            $table->string('actor_id', 64)->nullable();
            $table->string('tenant_id', 64)->nullable();

            $table->dateTime('started_at', 6);
            $table->dateTime('finished_at', 6)->nullable();
            $table->unsignedInteger('audits_count')->default(0);

            $table->jsonb('metadata')->nullable();

            // The three ways an operation is asked for: by what it was called, by when it ran,
            // and by whose tenant ran it. The clock is the tail of the first and the third,
            // because a name or a tenant alone does not narrow a trail that only grows.
            $table->index(['name', 'started_at']);
            $table->index('started_at');
            $table->index(['tenant_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists($this->table());
    }

    public function getConnection(): ?string
    {
        return $this->config()->connection();
    }

    private function table(): string
    {
        return $this->config()->table('transactions');
    }

    private function config(): Config
    {
        /** @var Config $config */
        $config = app(Config::class);

        return $config;
    }
};
