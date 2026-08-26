<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Exceptions;

use RuntimeException;

final class ImmutableAuditException extends RuntimeException
{
    public static function update(string $id): self
    {
        return new self(sprintf(
            'Audit [%s] cannot be updated: an entry is a link in a hash chain, and rewriting it breaks every entry that follows.',
            $id,
        ));
    }

    public static function delete(string $id): self
    {
        return new self(sprintf(
            'Audit [%s] cannot be deleted: removing an entry leaves a hole its chain has no way to describe.',
            $id,
        ));
    }
}
