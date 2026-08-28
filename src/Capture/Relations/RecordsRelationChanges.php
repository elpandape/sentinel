<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Capture\Relations;

use Closure;
use ElPandaPe\Sentinel\Capture\RelationCapture;
use ElPandaPe\Sentinel\Data\RelationLine;
use ElPandaPe\Sentinel\Enums\AuditEvent;
use ElPandaPe\Sentinel\Enums\RelationOperation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Collection;

/**
 * Eloquent fires no event for any of this. attach() inserts and detach() deletes through the query
 * builder, never through the model, so there is nothing to listen to — the ecosystem treats it as a
 * documented limitation and asks the programmer to call a different method instead. Wrapping the
 * relation itself is what keeps $user->roles()->sync([...]) meaning what it always meant.
 *
 * Every operation is audited the same way: photograph the pivot rows, let the parent do its work,
 * photograph them again, and read the difference. Six APIs, one code path, and the answer describes
 * what happened rather than what was called — which is what makes a sync that attaches findable by
 * asking a relation for its attachments.
 *
 * The guard exists because sync() and toggle() call the operations they are built from: sync()
 * reaches attach(), detach() and updateExistingPivot(), and toggle() reaches attach() and detach().
 * Without it, one call would write an entry per inner step. It counts on the relation instance, not
 * statically: a relation is built fresh per call, and static mutable state is refused by an arch
 * test that has been in place since the package had nothing in it.
 *
 * @phpstan-require-extends BelongsToMany
 */
trait RecordsRelationChanges
{
    private int $auditing = 0;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function attach(mixed $ids, array $attributes = [], mixed $touch = true): void
    {
        $this->audited('attach', AuditEvent::Attached, $ids, function () use ($ids, $attributes, $touch): void {
            parent::attach($ids, $attributes, $touch);
        });
    }

    public function detach(mixed $ids = null, mixed $touch = true): int
    {
        /** @var int */
        return $this->audited('detach', AuditEvent::Detached, $ids, fn (): mixed => parent::detach($ids, $touch));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function updateExistingPivot(mixed $id, array $attributes, mixed $touch = true): int
    {
        /** @var int */
        return $this->audited(
            'update_existing_pivot',
            AuditEvent::Synced,
            $id,
            fn (): mixed => parent::updateExistingPivot($id, $attributes, $touch),
        );
    }

    /**
     * @param  array<array-key, mixed>|Collection<array-key, mixed>|int|Model|string  $ids
     * @return array{attached: array<array-key, mixed>, detached: array<array-key, mixed>, updated: array<array-key, mixed>}
     */
    public function sync(mixed $ids, mixed $detaching = true): array
    {
        /** @var array{attached: array<array-key, mixed>, detached: array<array-key, mixed>, updated: array<array-key, mixed>} */
        return $this->audited('sync', AuditEvent::Synced, null, fn (): mixed => parent::sync($ids, $detaching));
    }

    /**
     * Not delegated to sync(): the parent spells it as sync($ids, false), and letting it fall
     * through would record an operation the caller never asked for.
     *
     * @param  array<array-key, mixed>|Collection<array-key, mixed>|int|Model|string  $ids
     * @return array{attached: array<array-key, mixed>, detached: array<array-key, mixed>, updated: array<array-key, mixed>}
     */
    public function syncWithoutDetaching(mixed $ids): array
    {
        /** @var array{attached: array<array-key, mixed>, detached: array<array-key, mixed>, updated: array<array-key, mixed>} */
        return $this->audited(
            'sync_without_detaching',
            AuditEvent::Synced,
            null,
            fn (): mixed => parent::sync($ids, false),
        );
    }

    /**
     * @return array{attached: array<array-key, mixed>, detached: array<array-key, mixed>}
     */
    public function toggle(mixed $ids, mixed $touch = true): array
    {
        /** @var array{attached: array<array-key, mixed>, detached: array<array-key, mixed>} */
        return $this->audited('toggle', AuditEvent::Synced, null, fn (): mixed => parent::toggle($ids, $touch));
    }

    /**
     * A null scope means the operation can reach rows it was never handed — a sync detaches
     * everything outside its list, and a detach with no argument empties the relation — so the
     * photograph has to cover the whole relation rather than the ids in the call.
     *
     * @param  Closure(): mixed  $operation
     */
    private function audited(string $api, AuditEvent $event, mixed $scope, Closure $operation): mixed
    {
        $capture = $this->capture();

        if ($this->auditing > 0 || ! $capture->recording()) {
            return $operation();
        }

        $before = $this->pivotStates($scope);

        $this->auditing++;

        try {
            $result = $operation();
        } finally {
            $this->auditing--;
        }

        $capture->record($this->getParent(), $api, $event, $this->lines($before, $this->pivotStates($scope)));

        return $result;
    }

    /**
     * @param  array<array-key, array<string, mixed>>  $before
     * @param  array<array-key, array<string, mixed>>  $after
     * @return list<RelationLine>
     */
    private function lines(array $before, array $after): array
    {
        $lines = [];

        foreach ($before as $id => $state) {
            $lines[] = match (true) {
                ! array_key_exists($id, $after) => $this->line((string) $id, RelationOperation::Detach, $state, null),
                $after[$id] !== $state => $this->line((string) $id, RelationOperation::Update, $state, $after[$id]),
                default => null,
            };
        }

        foreach ($after as $id => $state) {
            if (! array_key_exists($id, $before)) {
                $lines[] = $this->line((string) $id, RelationOperation::Attach, null, $state);
            }
        }

        return array_values(array_filter($lines));
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    private function line(string $id, RelationOperation $operation, ?array $before, ?array $after): RelationLine
    {
        return new RelationLine(
            $this->getRelationName(),
            $operation,
            $this->getRelated()->getMorphClass(),
            $id,
            $before,
            $after,
        );
    }

    /**
     * What the pivot rows say right now, keyed by who they relate to. The two foreign keys are
     * dropped: they repeat the subject of the entry and the related of the line, and a value that
     * is already in two columns does not need to be in the payload a third time.
     *
     * @return array<array-key, array<string, mixed>>
     */
    private function pivotStates(mixed $scope): array
    {
        /** @var Collection<int, Pivot> $pivots */
        $pivots = $this->getCurrentlyAttachedPivotsForIds($scope);

        $states = [];

        foreach ($pivots as $pivot) {
            $attributes = $pivot->getAttributes();
            $related = $attributes[$this->getRelatedPivotKeyName()] ?? null;

            unset($attributes[$this->getForeignPivotKeyName()], $attributes[$this->getRelatedPivotKeyName()]);

            if (is_string($related) || is_int($related)) {
                $states[(string) $related] = $attributes;
            }
        }

        return $states;
    }

    private function capture(): RelationCapture
    {
        /** @var RelationCapture $capture */
        $capture = app(RelationCapture::class);

        return $capture;
    }
}
