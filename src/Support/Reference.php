<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Support;

use ElPandaPe\Sentinel\Exceptions\QueryException;
use Illuminate\Database\Eloquent\Model;

/**
 * The pair a morph column holds: the type an entry recorded and the key recorded with it.
 * Taking the type and the key on their own is not a convenience — a hard-deleted subject has
 * no model left to hand over, and its trail is exactly what outlives it.
 *
 * It lives here rather than in Query so the query surface never names Eloquent: resolving a
 * morph alias is something every backend needs, not something a builder does.
 */
final readonly class Reference
{
    public function __construct(
        public string $type,
        public string $id,
    ) {}

    public static function to(object|string $target, int|string|null $id = null): self
    {
        if ($target instanceof Model) {
            $key = $target->getKey();

            return is_int($key) || is_string($key)
                ? new self($target->getMorphClass(), (string) $key)
                : throw QueryException::unsavedModel($target::class);
        }

        if (is_string($target)) {
            return $id === null
                ? throw QueryException::missingKey($target)
                : new self($target, (string) $id);
        }

        throw QueryException::unreferenceable($target::class);
    }

    public function matches(?string $type, ?string $id): bool
    {
        return $this->type === $type && $this->id === $id;
    }
}
