<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Capture\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * @template TRelatedModel of Model
 * @template TDeclaringModel of Model
 *
 * @extends BelongsToMany<TRelatedModel, TDeclaringModel, Pivot>
 */
final class AuditedBelongsToMany extends BelongsToMany
{
    use RecordsRelationChanges;
}
