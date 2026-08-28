<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The indexable projection of what an entry already carries. The lines themselves live inside
     * the entry's canonical changes, which is what the chain hashes, so this table is derivable
     * and a row of it is not evidence — it is an index over evidence.
     *
     * No foreign key and no identity of its own, for the same reasons the labels table has
     * neither: a line belongs to one entry, the entry says when it happened, and a cascade lives
     * badly with date partitioning and batched purging.
     *
     * related_id is string(64) like subject_id, so an int, a uuid and a ulid all fit without a
     * later migration. The three indexes are the three ways the history of a relation is asked
     * for: by the entry, by who was related, and by which relation.
     */
    public function up(): void
    {
        Schema::connection($this->getConnection())->create($this->table(), function (Blueprint $table): void {
            $table->char('audit_id', 26);
            $table->string('relation', 64);
            $table->string('operation', 16);
            $table->string('related_type', 255)->nullable();
            $table->string('related_id', 64)->nullable();
            $table->json('pivot_before')->nullable();
            $table->json('pivot_after')->nullable();

            $table->index('audit_id');
            $table->index(['related_type', 'related_id', 'audit_id']);
            $table->index(['relation', 'audit_id']);
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
        return $this->config()->table('audit_relations');
    }

    private function config(): Config
    {
        /** @var Config $config */
        $config = app(Config::class);

        return $config;
    }
};
