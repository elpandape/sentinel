<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Context;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * How a person becomes two columns. The morph alias is what a query filters on, so a
 * model answers with it and anything else answers with its class name.
 */
final class Identity
{
    public static function type(Authenticatable $user): string
    {
        return $user instanceof Model ? $user->getMorphClass() : $user::class;
    }

    public static function id(Authenticatable $user): ?string
    {
        $id = $user->getAuthIdentifier();

        return is_string($id) || is_int($id) ? (string) $id : null;
    }
}
