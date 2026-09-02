<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who read the trail, what they asked for, and how much came back.
     *
     * It is a projection and never the evidence. The evidence is an entry in sentinel_audits with
     * audit_type 'access', chained and hashed like any other, because an access log that can be
     * edited proves nothing about who looked. This table exists so that the same fact can be queried
     * — by actor, by date — without walking the chain, and it carries the entry's id so a reader can
     * go from a row here to the thing that makes it provable.
     *
     * Only written in compliance mode. An installation that has not asked for it does not pay for a
     * row per query.
     */
    public function up(): void
    {
        Schema::connection($this->getConnection())->create($this->table(), function (Blueprint $table): void {
            $table->char('id', 26)->primary();

            // The entry that proves this row. Not a foreign key: the entry can be pruned out from
            // under it, and the row is still a truthful record of a read that happened.
            $table->char('audit_id', 26);

            $table->string('actor_type', 255)->nullable();
            $table->string('actor_id', 64)->nullable();
            $table->string('tenant_id', 64)->nullable();

            $table->jsonb('query');
            $table->unsignedInteger('results');
            $table->jsonb('context');

            $table->dateTime('created_at', 6);

            // The question this table is for: what has this person been reading, and when.
            $table->index(['actor_type', 'actor_id', 'created_at']);
            $table->index('audit_id');
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
        return $this->config()->table('access_log');
    }

    private function config(): Config
    {
        /** @var Config $config */
        $config = app(Config::class);

        return $config;
    }
};
