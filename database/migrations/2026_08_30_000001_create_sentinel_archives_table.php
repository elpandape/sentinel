<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One range that left the hot table. Unlike sentinel_checkpoints, this one is not derivable
     * again: the entries it accounts for are no longer in sentinel_audits, so losing it loses the
     * map to what happened rather than a shortcut to proving it.
     *
     * The five cold columns are nullable because a range can leave without being written anywhere,
     * and they are born here rather than added later: this package's rule is that a table is
     * created whole, so that no version has to ALTER one that by then holds millions of rows.
     * `compressed` names the codec rather than answering yes or no — a boolean cannot say what to
     * inflate a batch written two years ago with, which is why the anchors store `fold-sha256` and
     * not a flag.
     *
     * Neither index is unique. Registering a range twice is legal: a batch may be re-written after
     * a rehydration, and a cold destination that took an entry as it was sealed overlaps a later
     * prune that takes the range around it.
     */
    public function up(): void
    {
        Schema::connection($this->getConnection())->create($this->table(), function (Blueprint $table): void {
            $table->char('id', 26)->primary();

            $table->string('stream', 64);
            $table->unsignedBigInteger('sequence_from');
            $table->unsignedBigInteger('sequence_to');

            $table->unsignedInteger('records');

            $table->string('disk', 64)->nullable();
            $table->string('path', 512)->nullable();
            $table->string('checksum', 160)->nullable();
            $table->string('compressed', 32)->nullable();

            $table->dateTime('created_at', 6);

            // Where a range starts is what the verification asks for when it meets an absence; where
            // it ends is what tells the next question where to carry on from.
            $table->index(['stream', 'sequence_from']);
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
        return $this->config()->table('archives');
    }

    private function config(): Config
    {
        /** @var Config $config */
        $config = app(Config::class);

        return $config;
    }
};
