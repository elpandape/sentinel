<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The timeline orders by the clock of the fact, and every index the table had ends in the
     * clock of the ledger. Measured over two hundred thousand entries, that order sorts outside
     * every index on all three engines, and putting an indexed filter in front only shrinks what
     * it sorts. These two remove the sort: the first for the whole trail, the second for the
     * timeline of one subject, which is the shape that actually gets run.
     */
    public function up(): void
    {
        Schema::connection($this->getConnection())->table($this->table(), function (Blueprint $table): void {
            $table->index(['occurred_at', 'id']);
            $table->index(['subject_type', 'subject_id', 'occurred_at', 'id'], $this->name('subject_occurred'));
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->table($this->table(), function (Blueprint $table): void {
            $table->dropIndex(['occurred_at', 'id']);
            $table->dropIndex($this->name('subject_occurred'));
        });
    }

    public function getConnection(): ?string
    {
        return $this->config()->connection();
    }

    /**
     * Named by hand because the generated name for four columns runs to sixty characters, and
     * the table prefix that precedes it is the user's to choose.
     */
    private function name(string $suffix): string
    {
        return "{$this->table()}_{$suffix}_index";
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
