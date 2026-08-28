<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Capture;

use ElPandaPe\Sentinel\Data\RelationLine;
use ElPandaPe\Sentinel\Enums\AuditEvent;
use ElPandaPe\Sentinel\Enums\RelationOperation;
use ElPandaPe\Sentinel\Exceptions\ConfigurationException;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Support\AuditPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * The other side of a relation change, where there is no pivot to wrap. When a child moves the
 * foreign key of a belongsTo, the parent it left and the parent it joined both lived a change of
 * relation, and nothing announces either: the only event is the child's own update, and by the
 * time it fires the previous value is still there to be read.
 *
 * Two entries and not one. subject_id holds one subject, so the outgoing parent and the incoming
 * one do not fit in the same entry — and the pair is exactly what a hand-over is.
 *
 * The line is the one v0.11.0 froze, with the child as the related record and no pivot on either
 * side, because there is no pivot. The api in metadata is the foreign key itself: there was no
 * method to intercept, the fact is that the column changed.
 */
final readonly class ParentCapture
{
    public const string API = 'foreign_key';

    public function __construct(private RelationCapture $relations) {}

    public function record(Model $child): void
    {
        if (! $this->relations->recording()) {
            return;
        }

        foreach (AuditPolicy::of($child)->parents as $relation => $collection) {
            $this->handOver($child, $this->belongsTo($child, $relation), $collection);
        }
    }

    /**
     * @param  BelongsTo<Model, Model>  $relation
     */
    private function handOver(Model $child, BelongsTo $relation, string $collection): void
    {
        $key = $relation->getForeignKeyName();
        $left = $this->reference($child->getRawOriginal($key));
        $joined = $this->reference($child->getAttributes()[$key] ?? null);

        if ($left === $joined) {
            return;
        }

        $this->write($child, $relation, $collection, RelationOperation::Detach, $left);
        $this->write($child, $relation, $collection, RelationOperation::Attach, $joined);
    }

    /**
     * @param  BelongsTo<Model, Model>  $relation
     */
    private function write(
        Model $child,
        BelongsTo $relation,
        string $collection,
        RelationOperation $operation,
        ?string $reference,
    ): ?Audit {
        $parent = $reference === null ? null : $this->parent($relation, $reference);

        if (! $parent instanceof Model) {
            return null;
        }

        return $this->relations->record(
            $parent,
            self::API,
            $operation === RelationOperation::Detach ? AuditEvent::Detached : AuditEvent::Attached,
            [new RelationLine(
                $collection,
                $operation,
                $child->getMorphClass(),
                $this->reference($child->getKey()),
            )],
        );
    }

    /**
     * The foreign key names the parent outright when it points at the primary key, which is why a
     * parent that has since been deleted still gets its entry. When it points at another column
     * the parent has to be read, because subject_id is the primary key wherever it appears — and
     * an end that resolves to nobody is an end there is nothing to say about.
     *
     * @param  BelongsTo<Model, Model>  $relation
     */
    private function parent(BelongsTo $relation, string $reference): ?Model
    {
        $related = $relation->getRelated();
        $owner = $relation->getOwnerKeyName();

        return $owner === $related->getKeyName()
            ? $related->newInstance()->forceFill([$owner => $reference])
            : $related->newQuery()->where($owner, $reference)->first();
    }

    /**
     * @return BelongsTo<Model, Model>
     */
    private function belongsTo(Model $child, string $relation): BelongsTo
    {
        $declared = method_exists($child, $relation) ? $child->{$relation}() : null;

        return $declared instanceof BelongsTo && ! $declared instanceof MorphTo
            ? $declared
            : throw ConfigurationException::notAParent($child::class, $relation);
    }

    private function reference(mixed $value): ?string
    {
        return is_string($value) || is_int($value) ? (string) $value : null;
    }
}
