<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Models;

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Data\RelationLine;
use ElPandaPe\Sentinel\Database\Factories\AuditFactory;
use ElPandaPe\Sentinel\Diff\Diff;
use ElPandaPe\Sentinel\Diff\DiffException;
use ElPandaPe\Sentinel\Diff\Pointer;
use ElPandaPe\Sentinel\Enums\ContentState;
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Enums\SignatureState;
use ElPandaPe\Sentinel\Enums\Source;
use ElPandaPe\Sentinel\Exceptions\ImmutableAuditException;
use ElPandaPe\Sentinel\Integrity\Verifier;
use ElPandaPe\Sentinel\Ledger\ChangedFieldPredicate;
use ElPandaPe\Sentinel\Query\Comparison;
use ElPandaPe\Sentinel\Restore\Restorer;
use ElPandaPe\Sentinel\Restore\RestoreResult;
use ElPandaPe\Sentinel\Support\AuditCollection;
use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Database\Eloquent\Attributes\CollectedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * @property-read AuditTransaction|null $transaction
 */
#[CollectedBy(AuditCollection::class)]
class Audit extends Model
{
    /** @use HasFactory<AuditFactory> */
    use HasFactory;

    use HasUlids;

    public const UPDATED_AT = null;

    /**
     * How the package renders an instant, wherever it renders one. Public because an anchor is
     * serialized outside this model and two definitions of it would agree until they did not.
     */
    public const string SERIALIZED_AT = 'Y-m-d\\TH:i:s.uP';

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
     * The business operation this entry belongs to, when it belongs to one. It is what turns the
     * identifier from something entries merely share into the name of what they were doing.
     *
     * @return BelongsTo<AuditTransaction, $this>
     */
    public function transaction(): BelongsTo
    {
        return $this->belongsTo(AuditTransaction::class, 'transaction_id');
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
            return $this->carriesRelationLines($changes)
                ? RelationLine::asDiff($changes)
                : Diff::fromEntries($changes);
        }

        /** @var array<string, mixed>|null $before */
        $before = $this->getAttribute('before');

        /** @var array<string, mixed>|null $after */
        $after = $this->getAttribute('after');

        return Diff::between($before ?? [], $after ?? []);
    }

    /**
     * The entry as data, and a frozen contract from v0.15.0: the keys of the top level and of the
     * integrity block only ever grow. A key is never renamed and never reinterpreted — a shape
     * that has to change arrives beside the old one, which stays until v2.
     *
     * changes keeps the pointer list the column already holds rather than a map keyed by field,
     * because a map cannot represent /profile/address/city without flattening it, and flattening
     * collides with an attribute literally named that.
     *
     * Nothing here inherits the order an engine happened to store a JSON column in. What the
     * package writes it also orders; what it did not write goes back untouched, which is the only
     * honest answer for it.
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
            'changes' => $this->serialized(),
            'before' => $this->before,
            'after' => $this->after,
            'metadata' => $this->metadata,
            'tags' => $this->tags->map(static fn (AuditTag $tag): string => $tag->tag)->sort()->values()->all(),
            'context' => $this->context,
            'transaction_id' => $this->transaction_id,
            'request_id' => $this->request_id,
            'trace_id' => $this->trace_id,
            'span_id' => $this->span_id,
            'source_audit_id' => $this->source_audit_id,
            'criteria' => $this->criteria,
            'affected_rows' => $this->affected_rows,
            'integrity' => [
                'stream' => $this->stream,
                'sequence' => $this->sequence,
                'algorithm' => $this->algorithm,
                'payload_version' => $this->payload_version,
                'previous_hash' => $this->previous_hash,
                'hash' => $this->hash,
                'signature' => $this->signature,
                'signature_key_id' => $this->signature_key_id,
                'verified' => null,
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

    /**
     * Put the record this entry is about back the way this entry found it — all of it, or only
     * the fields named. The trail is not rewritten: the restoration is a new entry pointing at
     * this one, and what comes back says what it applied and what it declined to.
     *
     * @param  list<string>|null  $fields
     */
    public function restore(?array $fields = null): RestoreResult
    {
        /** @var Restorer $restorer */
        $restorer = app(Restorer::class);

        return $restorer->restore($this, $fields);
    }

    /**
     * Put a relation back the way this entry found it, from the lines it recorded: what it
     * attached stays attached with the pivot it left, what it detached stays detached.
     */
    public function restoreRelationship(string $relation): RestoreResult
    {
        /** @var Restorer $restorer */
        $restorer = app(Restorer::class);

        return $restorer->restoreRelationship($this, $relation);
    }

    /**
     * Whether this row still reproduces its own hash, and only that. The signature is a second
     * question with four answers, so it is asked separately rather than folded into a boolean that
     * would have to call an unsigned entry a failure.
     *
     * A tombstone answers false. It no longer reproduces the hash it carries, which is what this
     * method has always meant, and erring towards the alarm is the point: calling it true would rest
     * on redacted_hash, a column no signature covers, and would report a row somebody emptied by hand
     * as healthy. What it was is answered by verifyContent().
     */
    public function verifyIntegrity(): bool
    {
        /** @var Verifier $verifier */
        $verifier = app(Verifier::class);

        return $verifier->verifyEntry($this);
    }

    /**
     * What this row's content says about itself: sealed, redacted, or altered. It is asked next to
     * verifyIntegrity() rather than through it, the way verifySignature() was added in v0.18.0 —
     * three states do not fit in one bool, and widening the bool would reinterpret a published
     * contract by leaning on a column nobody signs.
     */
    public function verifyContent(): ContentState
    {
        /** @var Verifier $verifier */
        $verifier = app(Verifier::class);

        return $verifier->verifyContent($this);
    }

    public function verifySignature(): SignatureState
    {
        /** @var Verifier $verifier */
        $verifier = app(Verifier::class);

        return $verifier->verifySignature($this);
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
     * A relation entry's changes are its lines, and they go out as lines rather than as the diff
     * they can be read as: this is the serialised entry, not a rendering of it. They go out in the
     * package's key order all the same, because the order they come back in is the engine's.
     *
     * A column holding neither shape goes back exactly as it was found. Only a row this package
     * did not write can be in that state, and refusing to serialise it would let one such row stop
     * a whole page of the trail from being read.
     *
     * @return array<array-key, mixed>|null
     */
    private function serialized(): ?array
    {
        /** @var array<array-key, mixed>|null $changes */
        $changes = $this->getAttribute('changes');

        if ($changes === null) {
            return null;
        }

        if ($this->carriesRelationLines($changes)) {
            return array_values(array_map(
                RelationLine::ordered(...),
                array_filter($changes, is_array(...)),
            ));
        }

        try {
            return $this->diff()->toArray();
        } catch (DiffException) {
            return $changes;
        }
    }

    /**
     * Whether what this entry holds is relation lines rather than diff entries, asked of the
     * lines themselves and not of the entry's type. A relation line says relation and operation
     * where a diff entry says path and op, so the two are never mistakable — and a restoration
     * that put a relation back is an entry of a different type carrying the same lines.
     *
     * @param  array<array-key, mixed>  $changes
     */
    private function carriesRelationLines(array $changes): bool
    {
        $first = reset($changes);

        return is_array($first) && array_key_exists('relation', $first) && array_key_exists('operation', $first);
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
