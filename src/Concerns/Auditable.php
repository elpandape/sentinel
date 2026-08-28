<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Concerns;

use ElPandaPe\Sentinel\Capture\ModelObserver;
use ElPandaPe\Sentinel\Capture\Relations\AuditedBelongsToMany;
use ElPandaPe\Sentinel\Capture\Relations\AuditedMorphToMany;
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Exceptions\ConfigurationException;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Query\AuditQuery;
use ElPandaPe\Sentinel\Sentinel;
use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait Auditable
{
    /**
     * The eloquent events the package listens to. Not five: a force delete fires
     * `deleted` on its way to `forceDeleted`, and by the time `restored` fires the
     * original is already synced, so both are derived from the four below.
     *
     * @var list<string>
     */
    private const array AUDITED_EVENTS = ['created', 'updated', 'deleted', 'forceDeleted'];

    public static function bootAuditable(): void
    {
        // Model::observe() instantiates the model, which cannot happen while it is booting.
        foreach (self::AUDITED_EVENTS as $event) {
            static::registerModelEvent($event, [ModelObserver::class, $event]);
        }
    }

    /**
     * @return MorphMany<Audit, $this>
     */
    public function audits(): MorphMany
    {
        /** @var MorphMany<Audit, $this> $audits */
        $audits = $this->morphMany($this->auditModel(), 'subject')->orderBy('id');

        return $audits;
    }

    /**
     * What this relation has done over time, as a query rather than a result: it composes with
     * every other filter and pages like any other read, so asking who was ever a lead on this team
     * and asking it one page at a time are the same call with one more method on it.
     */
    public function relationHistory(string $relation): AuditQuery
    {
        /** @var Sentinel $sentinel */
        $sentinel = app(Sentinel::class);

        return $sentinel->audits()->for($this)->whereRelation($relation);
    }

    public function latestAudit(): ?Audit
    {
        return $this->audits()->reorder()->orderByDesc('id')->first();
    }

    /**
     * @return list<string>
     */
    public function auditIncluded(): array
    {
        return $this->auditFields('auditInclude');
    }

    /**
     * @return list<string>
     */
    public function auditExcluded(): array
    {
        return $this->auditFields('auditExclude');
    }

    /**
     * @return list<string>
     */
    public function auditRedacted(): array
    {
        return $this->auditFields('auditRedact');
    }

    /**
     * @return list<string>
     */
    public function auditEncrypted(): array
    {
        return $this->auditFields('auditEncrypt');
    }

    /**
     * @return list<string>
     */
    public function auditHashed(): array
    {
        return $this->auditFields('auditHash');
    }

    /**
     * @return list<string>
     */
    public function auditTags(): array
    {
        return $this->auditFields('auditTags');
    }

    /**
     * The columns whose movement is a state change rather than an edit. A column named here
     * cannot also be excluded, redacted, encrypted, hashed or left out of a declared include
     * list — Support\AuditPolicy is where that is refused, because it is the one place that
     * sees every list at once.
     *
     * @return list<string>
     */
    public function auditTransitions(): array
    {
        return $this->auditFields('auditTransitions');
    }

    /**
     * A map and not a list: the entry hangs off the parent, so its line needs the name the parent
     * gives that collection, and a list would have to invent it.
     *
     * @return array<string, string>
     */
    public function auditParents(): array
    {
        $value = $this->auditProperty('auditParents');

        if ($value === null) {
            return [];
        }

        if (! is_array($value)) {
            throw ConfigurationException::expected('auditParents', 'a map of relation name to relation name', get_debug_type($value));
        }

        $parents = [];

        foreach ($value as $relation => $collection) {
            if (! is_string($relation) || ! is_string($collection) || $collection === '') {
                throw ConfigurationException::expected('auditParents', 'a map of relation name to relation name', get_debug_type($collection));
            }

            $parents[$relation] = $collection;
        }

        return $parents;
    }

    public function auditSnapshotsEnabled(): bool
    {
        $value = $this->auditProperty('auditSnapshots');

        return match (true) {
            $value === null => true,
            is_bool($value) => $value,
            default => throw ConfigurationException::expected('auditSnapshots', 'a boolean', get_debug_type($value)),
        };
    }

    public function auditSeverity(): ?Severity
    {
        $value = $this->auditProperty('auditSeverity');

        return match (true) {
            $value === null => null,
            $value instanceof Severity => $value,
            is_string($value) => Severity::tryFrom($value) ?? throw ConfigurationException::unknown(
                'auditSeverity',
                $value,
                'info, notice, warning, critical',
            ),
            default => throw ConfigurationException::expected('auditSeverity', 'a Severity or its value', get_debug_type($value)),
        };
    }

    /**
     * Eloquent fires no event for a pivot change, so the relation itself is what gets wrapped.
     * Every many-to-many a model declares comes through one of these two factories —
     * morphedByMany() is morphToMany() with the keys reversed — which is what lets
     * $user->roles()->sync([...]) keep working exactly as written.
     *
     * @template TRelatedModel of Model
     * @template TDeclaringModel of Model
     *
     * @param  Builder<TRelatedModel>  $query
     * @param  TDeclaringModel  $parent
     * @return AuditedBelongsToMany<TRelatedModel, TDeclaringModel>
     */
    protected function newBelongsToMany(
        Builder $query,
        Model $parent,
        $table,
        $foreignPivotKey,
        $relatedPivotKey,
        $parentKey,
        $relatedKey,
        $relationName = null,
    ): AuditedBelongsToMany {
        return new AuditedBelongsToMany(
            $query,
            $parent,
            $table,
            $foreignPivotKey,
            $relatedPivotKey,
            $parentKey,
            $relatedKey,
            $relationName,
        );
    }

    /**
     * @template TRelatedModel of Model
     * @template TDeclaringModel of Model
     *
     * @param  Builder<TRelatedModel>  $query
     * @param  TDeclaringModel  $parent
     * @return AuditedMorphToMany<TRelatedModel, TDeclaringModel>
     */
    protected function newMorphToMany(
        Builder $query,
        Model $parent,
        $name,
        $table,
        $foreignPivotKey,
        $relatedPivotKey,
        $parentKey,
        $relatedKey,
        $relationName = null,
        $inverse = false,
    ): AuditedMorphToMany {
        return new AuditedMorphToMany(
            $query,
            $parent,
            $name,
            $table,
            $foreignPivotKey,
            $relatedPivotKey,
            $parentKey,
            $relatedKey,
            $relationName,
            $inverse,
        );
    }

    /**
     * @return list<string>
     */
    private function auditFields(string $property): array
    {
        $value = $this->auditProperty($property);

        if ($value === null) {
            return [];
        }

        if (! is_array($value)) {
            throw ConfigurationException::expected($property, 'a list of strings', get_debug_type($value));
        }

        foreach ($value as $field) {
            if (! is_string($field)) {
                throw ConfigurationException::expected($property, 'a list of strings', get_debug_type($field));
            }
        }

        return array_values($value);
    }

    private function auditProperty(string $property): mixed
    {
        return property_exists($this, $property) ? $this->{$property} : null;
    }

    /**
     * @return class-string<Audit>
     */
    private function auditModel(): string
    {
        /** @var Config $config */
        $config = app(Config::class);

        return $config->model('audit', Audit::class);
    }
}
