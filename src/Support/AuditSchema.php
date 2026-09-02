<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Support;

use Illuminate\Database\Schema\Blueprint;

/**
 * The shape of the audit table, in one place.
 *
 * It exists because v0.20.0 publishes partitioned alternatives to the base migration, and an
 * alternative that carried its own copy of forty-odd columns would be a different table wearing
 * the same name the first time one of the two was edited. What the alternatives really change is
 * three lines — the primary key, the two unique keys and the partitioning clause — so those are
 * the three things they state themselves, and everything else they ask for here.
 *
 * The keys are deliberately NOT here. They are exactly what an engine constrains under
 * partitioning: both MySQL and PostgreSQL refuse a unique key that does not carry the partitioning
 * column, so a stub that could not say its own is a stub that could not exist.
 */
final readonly class AuditSchema
{
    public function columns(Blueprint $table): void
    {
        $table->char('id', 26);

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
    }

    /**
     * The indexes that do not depend on how the table is divided, so a partitioned table gets the
     * same ones. Each becomes a local index on every partition, which is what serves a filter that
     * does not name the partitioning column.
     */
    public function indexes(Blueprint $table): void
    {
        $table->index(['subject_type', 'subject_id', 'id']);
        $table->index(['actor_type', 'actor_id', 'id']);
        $table->index(['tenant_id', 'created_at']);
        $table->index('transaction_id');
        $table->index('request_id');
        $table->index('trace_id');
        $table->index(['audit_type', 'created_at']);
        $table->index('event');
        $table->index(['severity', 'created_at']);
    }
}
