<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Restore;

use ElPandaPe\Sentinel\Enums\Omission;
use ElPandaPe\Sentinel\Enums\RelationOperation;
use ElPandaPe\Sentinel\Integrity\Verifier;
use ElPandaPe\Sentinel\Models\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * What a relation would have to become for the entry's portrait of it to be true again, one
 * related record at a time. The same rule as for a field: what the entry attached stays attached
 * with the pivot it left behind, what it detached stays detached.
 *
 * Pivot values are compared as strings. The three engines disagree about the type an integer comes
 * back as, and a comparison that reports a change because one side is 1 and the other is "1" would
 * be measuring the driver rather than the relation.
 */
final readonly class RelationPlanner
{
    public function __construct(private Verifier $verifier) {}

    public function for(Audit $audit, Model $subject, string $name): Plan
    {
        if ($audit->redacted_at !== null) {
            return Plan::refused(Omission::EntryRedacted);
        }

        if (! $this->verifier->verifyEntry($audit)) {
            return Plan::refused(Omission::EntryTampered);
        }

        $lines = $this->lines($audit, $name);

        if ($lines === []) {
            return Plan::refused(Omission::EntryStateless);
        }

        $declared = method_exists($subject, $name) ? $subject->{$name}() : null;

        return $declared instanceof BelongsToMany
            ? $this->weigh($declared, $lines)
            : Plan::of([], array_fill_keys(array_keys($lines), Omission::UnknownField));
    }

    /**
     * The pivot row as the entries record it: without the two foreign keys, which repeat the
     * subject of the entry and the related of the line and would otherwise make every comparison
     * against a stored line disagree on two columns that always match.
     *
     * @param  BelongsToMany<Model, Model, Pivot>  $relation
     * @return array<string, mixed>|null
     */
    public function pivot(BelongsToMany $relation, string $id): ?array
    {
        $row = $relation->newPivotStatementForId($id)->first();

        if ($row === null) {
            return null;
        }

        /** @var array<string, mixed> $pivot */
        $pivot = (array) $row;

        unset($pivot[$relation->getForeignPivotKeyName()], $pivot[$relation->getRelatedPivotKeyName()]);

        return $pivot;
    }

    /**
     * @param  BelongsToMany<Model, Model, Pivot>  $relation
     * @param  array<string, array{id: string, operation: string, pivot: array<string, mixed>}>  $lines
     */
    private function weigh(BelongsToMany $relation, array $lines): Plan
    {
        $applying = [];
        $skipped = [];

        foreach ($lines as $key => $line) {
            $pivot = $this->pivot($relation, $line['id']);

            $step = $line['operation'] === RelationOperation::Detach->value
                ? $this->unlink($line['id'], $pivot)
                : $this->link($relation, $line['id'], $pivot, $line['pivot']);

            match (true) {
                $step instanceof Omission => $skipped[$key] = $step,
                default => $applying[$key] = $step,
            };
        }

        return Plan::of($applying, $skipped);
    }

    /**
     * @param  array<string, mixed>|null  $pivot
     * @return array{operation: string, id: string, pivot: array<string, mixed>, was: array<string, mixed>|null}|Omission
     */
    private function unlink(string $id, ?array $pivot): array|Omission
    {
        return $pivot === null
            ? Omission::Unchanged
            : ['operation' => RelationOperation::Detach->value, 'id' => $id, 'pivot' => [], 'was' => $pivot];
    }

    /**
     * @param  BelongsToMany<Model, Model, Pivot>  $relation
     * @param  array<string, mixed>|null  $pivot
     * @param  array<string, mixed>  $wanted
     * @return array{operation: string, id: string, pivot: array<string, mixed>, was: array<string, mixed>|null}|Omission
     */
    private function link(BelongsToMany $relation, string $id, ?array $pivot, array $wanted): array|Omission
    {
        if ($relation->getRelated()->newQueryWithoutScopes()->whereKey($id)->doesntExist()) {
            return Omission::RelatedMissing;
        }

        if ($pivot === null) {
            return ['operation' => RelationOperation::Attach->value, 'id' => $id, 'pivot' => $wanted, 'was' => null];
        }

        return $this->carries($pivot, $wanted)
            ? Omission::Unchanged
            : ['operation' => RelationOperation::Update->value, 'id' => $id, 'pivot' => $wanted, 'was' => $pivot];
    }

    /**
     * @param  array<string, mixed>  $pivot
     * @param  array<string, mixed>  $wanted
     */
    private function carries(array $pivot, array $wanted): bool
    {
        return array_all(
            $wanted,
            fn (mixed $value, string $column): bool => $this->text($pivot[$column] ?? null) === $this->text($value),
        );
    }

    private function text(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_scalar($value) => (string) $value,
            default => (string) json_encode($value),
        };
    }

    /**
     * The lines of this entry that are about this relation, keyed by what the result will call
     * them: the relation and the record under it, which is the same pointer the diff of a relation
     * entry already uses.
     *
     * @return array<string, array{id: string, operation: string, pivot: array<string, mixed>}>
     */
    private function lines(Audit $audit, string $name): array
    {
        /** @var array<array-key, mixed> $changes */
        $changes = $audit->getAttribute('changes') ?? [];
        $lines = [];

        foreach ($changes as $line) {
            if (! is_array($line) || ($line['relation'] ?? null) !== $name) {
                continue;
            }

            $id = $line['related_id'] ?? null;
            $operation = $line['operation'] ?? null;
            $pivot = $line['pivot_after'] ?? null;

            if (! is_string($id) || ! is_string($operation)) {
                continue;
            }

            /** @var array<string, mixed> $wanted */
            $wanted = is_array($pivot) ? $pivot : [];

            $lines[$name.'/'.$id] = ['id' => $id, 'operation' => $operation, 'pivot' => $wanted];
        }

        return $lines;
    }
}
