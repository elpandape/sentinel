<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * No foreign key, on purpose: date partitioning and batched purging both live badly with a
     * cascade, so cleaning up after a purged entry is the explicit job of whoever purges. The
     * unique pair is the key — a labelled entry never repeats a label — and the reversed index
     * is what makes a search by label a seek instead of a pass over the table.
     */
    public function up(): void
    {
        Schema::connection($this->getConnection())->create($this->table(), function (Blueprint $table): void {
            $table->char('audit_id', 26);
            $table->string('tag', 64);

            $table->unique(['audit_id', 'tag']);
            $table->index(['tag', 'audit_id']);
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
        return $this->config()->table('audit_tags');
    }

    private function config(): Config
    {
        /** @var Config $config */
        $config = app(Config::class);

        return $config;
    }
};
