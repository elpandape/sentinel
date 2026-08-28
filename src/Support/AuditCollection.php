<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Support;

use ElPandaPe\Sentinel\Models\Audit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * @extends Collection<int, Audit>
 */
final class AuditCollection extends Collection
{
    /**
     * Resolves what the entries point at, so rendering N of them costs a query per morph type
     * instead of a query per line. It lives here and not on the query surface because it changes
     * how entries come back hydrated rather than which ones come back, and a criterion a driver
     * cannot refuse is not a criterion.
     *
     * A recorded type that names no class is left unresolved rather than fatal. That is not an
     * edge case: an entry outliving the subject it describes is the normal one, and the trail
     * records the type as it was written, not as a promise that the class still exists.
     */
    public function loadReferences(): self
    {
        $this->load('tags');

        foreach (['subject' => 'subject_type', 'actor' => 'actor_type'] as $relation => $column) {
            $this->resolvable($column)->load($relation);
        }

        return $this;
    }

    private function resolvable(string $column): self
    {
        return $this->filter(static function (Audit $audit) use ($column): bool {
            $type = $audit->getAttribute($column);

            if (! is_string($type) || $type === '') {
                return false;
            }

            $class = Relation::getMorphedModel($type) ?? $type;

            return class_exists($class) && is_a($class, Model::class, true);
        });
    }
}
