<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * An anchor over a range of one stream: the root the range folded to, signed. It holds no copy
     * of anything in sentinel_audits, so losing this table costs speed and never evidence — the
     * chain still verifies, only by walking every entry instead of every anchor.
     *
     * There is no column for the anchor before it. The fold covers that root, and which anchor it
     * was is derived from the range itself, because the ranges are contiguous windows: the previous
     * one is the row whose sequence_to is this one's sequence_from minus one.
     */
    public function up(): void
    {
        Schema::connection($this->getConnection())->create($this->table(), function (Blueprint $table): void {
            $table->char('id', 26)->primary();

            $table->string('stream', 64);
            $table->unsignedBigInteger('sequence_from');
            $table->unsignedBigInteger('sequence_to');

            $table->char('root_hash', 64);
            $table->string('algorithm', 32);
            $table->text('signature')->nullable();
            $table->string('key_id', 64)->nullable();

            $table->dateTime('created_at', 6);

            // Where an anchor starts is derived in code under a lock; this is the last arbiter of
            // the race, exactly as the chain's own unique index is for a sequence.
            $table->unique(['stream', 'sequence_from']);

            // Both reads that matter come off the far end: the last anchor of a stream, and the
            // anchor that ends where the next one begins.
            $table->index(['stream', 'sequence_to']);
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
        return $this->config()->table('checkpoints');
    }

    private function config(): Config
    {
        /** @var Config $config */
        $config = app(Config::class);

        return $config;
    }
};
