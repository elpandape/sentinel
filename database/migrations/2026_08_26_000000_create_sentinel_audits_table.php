<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection($this->getConnection())->create($this->table(), function (Blueprint $table): void {
            $table->char('id', 26)->primary();

            $table->string('stream', 64);
            $table->unsignedBigInteger('sequence');

            $table->string('audit_type', 32);
            $table->string('event', 64);
            $table->string('severity', 8);

            $table->string('subject_type')->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->string('actor_type')->nullable();
            $table->string('actor_id', 64)->nullable();
            $table->string('impersonator_type')->nullable();
            $table->string('impersonator_id', 64)->nullable();

            $table->string('tenant_id', 64)->nullable();
            $table->char('transaction_id', 26)->nullable();
            $table->string('request_id', 64)->nullable();
            $table->string('trace_id', 32)->nullable();
            $table->string('span_id', 16)->nullable();

            $table->string('source', 16);
            $table->unsignedInteger('version')->nullable();

            $table->jsonb('context');
            $table->jsonb('before')->nullable();
            $table->jsonb('after')->nullable();
            $table->jsonb('changes')->nullable();
            $table->jsonb('metadata')->nullable();

            $table->unsignedSmallInteger('payload_version')->default(1);
            $table->jsonb('encryption')->nullable();
            $table->string('algorithm', 16)->default('sha256');
            $table->char('previous_hash', 64)->nullable();
            $table->char('hash', 64);
            $table->text('signature')->nullable();
            $table->string('signature_key_id', 64)->nullable();

            $table->char('capture_id', 26)->nullable();
            $table->char('source_audit_id', 26)->nullable();
            $table->jsonb('criteria')->nullable();
            $table->unsignedBigInteger('affected_rows')->nullable();
            $table->dateTime('redacted_at', 6)->nullable();
            $table->string('redaction_reason')->nullable();
            $table->char('redacted_hash', 64)->nullable();

            $table->dateTime('occurred_at', 6);
            $table->dateTime('created_at', 6);

            $table->unique(['stream', 'sequence']);
            $table->unique('capture_id');
            $table->index(['subject_type', 'subject_id', 'id']);
            $table->index(['actor_type', 'actor_id', 'id']);
            $table->index(['tenant_id', 'created_at']);
            $table->index('transaction_id');
            $table->index('request_id');
            $table->index('trace_id');
            $table->index(['audit_type', 'created_at']);
            $table->index('event');
            $table->index(['severity', 'created_at']);
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
        return $this->config()->table('audits');
    }

    private function config(): Config
    {
        /** @var Config $config */
        $config = app(Config::class);

        return $config;
    }
};
