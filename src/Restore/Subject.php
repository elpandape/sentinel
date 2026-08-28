<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Restore;

use ElPandaPe\Sentinel\Models\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The record an entry is about, found the way the entry names it. Not through the morphTo
 * relation the model already has: that one applies the global scopes, and a record in the
 * recycle bin is exactly the one a restoration is most often asked about.
 *
 * A type that no longer resolves to a model answers the same as a record that was deleted for
 * good — from here there is nothing to write to either way, and the caller is told so rather
 * than handed a class-not-found from three frames down.
 */
final readonly class Subject
{
    public static function of(Audit $audit): ?Model
    {
        $type = $audit->subject_type;
        $id = $audit->subject_id;

        if ($type === null || $id === null) {
            return null;
        }

        $class = Relation::getMorphedModel($type) ?? $type;

        if (! is_a($class, Model::class, true)) {
            return null;
        }

        return new $class()->newQueryWithoutScopes()->whereKey($id)->first();
    }
}
