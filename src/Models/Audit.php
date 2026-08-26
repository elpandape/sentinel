<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Models;

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Database\Factories\AuditFactory;
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Enums\Source;
use ElPandaPe\Sentinel\Exceptions\ImmutableAuditException;
use ElPandaPe\Sentinel\Integrity\Verifier;
use ElPandaPe\Sentinel\Support\AuditCollection;
use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Database\Eloquent\Attributes\CollectedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property string $id
 * @property string $stream
 * @property int $sequence
 * @property string $audit_type
 * @property string $event
 * @property Severity $severity
 * @property string|null $subject_type
 * @property string|null $subject_id
 * @property string|null $actor_type
 * @property string|null $actor_id
 * @property string|null $impersonator_type
 * @property string|null $impersonator_id
 * @property string|null $tenant_id
 * @property string|null $transaction_id
 * @property string|null $request_id
 * @property string|null $trace_id
 * @property string|null $span_id
 * @property Source $source
 * @property int|null $version
 * @property array<string, mixed> $context
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 * @property array<array-key, mixed>|null $changes
 * @property array<string, mixed>|null $metadata
 * @property int $payload_version
 * @property array<string, mixed>|null $encryption
 * @property string $algorithm
 * @property string|null $previous_hash
 * @property string $hash
 * @property string|null $signature
 * @property string|null $signature_key_id
 * @property string|null $capture_id
 * @property string|null $source_audit_id
 * @property array<array-key, mixed>|null $criteria
 * @property int|null $affected_rows
 * @property CarbonImmutable|null $redacted_at
 * @property string|null $redaction_reason
 * @property string|null $redacted_hash
 * @property CarbonImmutable $occurred_at
 * @property CarbonImmutable $created_at
 * @property-read Model|null $subject
 * @property-read Model|null $actor
 * @property-read Model|null $impersonator
 */
#[CollectedBy(AuditCollection::class)]
class Audit extends Model
{
    /** @use HasFactory<AuditFactory> */
    use HasFactory;

    use HasUlids;

    public const UPDATED_AT = null;

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function impersonator(): MorphTo
    {
        return $this->morphTo();
    }

    public function verifyIntegrity(): bool
    {
        /** @var Verifier $verifier */
        $verifier = app(Verifier::class);

        return $verifier->verifyEntry($this);
    }

    public function getTable(): string
    {
        return $this->config()->table('audits');
    }

    public function getConnectionName(): ?string
    {
        return $this->config()->connection();
    }

    // The column is datetime(6): without the microseconds Eloquent would truncate what the schema declares.
    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s.u';
    }

    /**
     * @return list<string>
     */
    public function getGuarded(): array
    {
        return [];
    }

    protected static function booted(): void
    {
        static::updating(static function (Audit $audit): void {
            throw ImmutableAuditException::update($audit->id);
        });

        static::deleting(static function (Audit $audit): void {
            throw ImmutableAuditException::delete($audit->id);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'severity' => Severity::class,
            'source' => Source::class,
            'version' => 'integer',
            'context' => 'array',
            'before' => 'array',
            'after' => 'array',
            'changes' => 'array',
            'metadata' => 'array',
            'payload_version' => 'integer',
            'encryption' => 'array',
            'criteria' => 'array',
            'affected_rows' => 'integer',
            'redacted_at' => 'immutable_datetime',
            'occurred_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): AuditFactory
    {
        return AuditFactory::new();
    }

    private function config(): Config
    {
        /** @var Config $config */
        $config = app(Config::class);

        return $config;
    }
}
