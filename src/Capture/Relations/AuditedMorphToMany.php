<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Capture\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Both directions of the polymorphic relation come through here: morphedByMany() is morphToMany()
 * with the keys the other way round, so the one factory covers the two.
 *
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends MorphToMany<TRelatedModel, TDeclaringModel>
 */
final class AuditedMorphToMany extends MorphToMany
{
    use RecordsRelationChanges;
}
