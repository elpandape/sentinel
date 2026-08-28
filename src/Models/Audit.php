<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Models;

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Database\Factories\AuditFactory;
use ElPandaPe\Sentinel\Diff\Diff;
use ElPandaPe\Sentinel\Diff\Pointer;
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Enums\Source;
use ElPandaPe\Sentinel\Exceptions\ImmutableAuditException;
use ElPandaPe\Sentinel\Integrity\Verifier;
use ElPandaPe\Sentinel\Ledger\ChangedFieldPredicate;
use ElPandaPe\Sentinel\Query\Comparison;
use ElPandaPe\Sentinel\Support\AuditCollection;
use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Database\Eloquent\Attributes\CollectedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
 * @property-read Collection<int, AuditTag> $tags
 * @property-read Collection<int, AuditRelation> $relations
 */
#[CollectedBy(AuditCollection::class)]
class Audit extends Model
{
    /** @use HasFactory<AuditFactory> */
    use HasFactory;

    use HasUlids;

    public const UPDATED_AT = null;

    private const string SERIALIZED_AT = 'Y-m-d\\TH:i:s.uP';

    /**
     * @return HasMany<AuditTag, $this>
     */
    public function tags(): HasMany
    {
        return $this->hasMany(AuditTag::class, 'audit_id')->orderBy('tag');
    }

    /**
     * The projection of this entry's relation lines, in the order the entry canonicalised them.
     *
     * @return HasMany<AuditRelation, $this>
     */
    public function relations(): HasMany
    {
        return $this->hasMany(AuditRelation::class, 'audit_id')
            ->orderBy('relation')
            ->orderBy('related_type')
            ->orderBy('related_id');
    }

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

    /**
     * An entry written before the diff existed still answers: the comparison runs on read
     * from the two states it stored, and the row is not touched.
     *
     * The attributes are read through getAttribute because eloquent declares a $changes
     * property of its own — inside the model, $this->changes is the dirty set of the last
     * save, not the column.
     */
    public function diff(): Diff
    {
        /** @var array<array-key, mixed>|null $changes */
        $changes = $this->getAttribute('changes');

        if ($changes !== null) {
            return Diff::fromEntries($changes);
        }

        /** @var array<string, mixed>|null $before */
        $before = $this->getAttribute('before');

        /** @var array<string, mixed>|null $after */
        $after = $this->getAttribute('after');

        return Diff::between($before ?? [], $after ?? []);
    }

    /**
     * The entry as data. Not a frozen contract yet: keys can move in any minor until the version
     * that declares it stable and pins it with a snapshot. What is tested here is the behaviour,
     * not the shape.
     *
     * changes keeps the pointer list the column already holds rather than a map keyed by field,
     * because a map cannot represent /profile/address/city without flattening it, and flattening
     * collides with an attribute literally named that.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'audit_type' => $this->audit_type,
            'event' => $this->event,
            'severity' => $this->severity->value,
            'source' => $this->source->value,
            'subject' => $this->reference($this->subject_type, $this->subject_id),
            'actor' => $this->reference($this->actor_type, $this->actor_id),
            'impersonator' => $this->reference($this->impersonator_type, $this->impersonator_id),
            'tenant_id' => $this->tenant_id,
            'version' => $this->version,
            'changes' => $this->getAttribute('changes') === null ? null : $this->diff()->toArray(),
            'before' => $this->before,
            'after' => $this->after,
            'metadata' => $this->metadata,
            'tags' => $this->tags->map(static fn (AuditTag $tag): string => $tag->tag)->values()->all(),
            'context' => $this->context,
            'transaction_id' => $this->transaction_id,
            'request_id' => $this->request_id,
            'trace_id' => $this->trace_id,
            'span_id' => $this->span_id,
            'integrity' => [
                'stream' => $this->stream,
                'sequence' => $this->sequence,
                'algorithm' => $this->algorithm,
                'payload_version' => $this->payload_version,
                'previous_hash' => $this->previous_hash,
                'hash' => $this->hash,
                'signature_key_id' => $this->signature_key_id,
            ],
            'occurred_at' => $this->occurred_at->format(self::SERIALIZED_AT),
            'created_at' => $this->created_at->format(self::SERIALIZED_AT),
        ];
    }

    /**
     * What changed between this entry and another about the same subject. Comparing entries of
     * different subjects is refused where the comparison is built, rather than answered with an
     * empty diff that would read as agreement.
     */
    public function comparedTo(self $other): Comparison
    {
        return Comparison::between($this, $other);
    }

    public function diffFor(string $path): Diff
    {
        return $this->diff()->for($path);
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

    /**
     * The same reading of "touched this field" the query surface publishes, reachable from the
     * relation a model already has: $user->audits()->field('email')->get(). One implementation,
     * so the two cannot drift.
     *
     * @param  Builder<$this>  $query
     */
    #[Scope]
    protected function field(Builder $query, string $path): void
    {
        $connection = $query->getModel()->getConnection();

        [$sql, $bindings] = app(ChangedFieldPredicate::class)->for(
            $connection->getDriverName(),
            $connection->getQueryGrammar()->wrap($query->getModel()->qualifyColumn('changes')),
            Pointer::of($path),
        );

        // The only value interpolated into that SQL is the column name the grammar just escaped.
        /** @phpstan-ignore argument.type */
        $query->whereRaw($sql, $bindings);
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

    /**
     * @return array{type: string, id: string}|null
     */
    private function reference(?string $type, ?string $id): ?array
    {
        return $type === null || $id === null ? null : ['type' => $type, 'id' => $id];
    }

    private function config(): Config
    {
        /** @var Config $config */
        $config = app(Config::class);

        return $config;
    }
}
