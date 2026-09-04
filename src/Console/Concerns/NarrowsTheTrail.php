<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Console\Concerns;

use ElPandaPe\Sentinel\Query\AuditQuery;

/**
 * The narrowing two commands offer over the same three options, written once.
 *
 * It is the Query API and not a second query language, which is the point: what an application
 * writes in code is what an operator writes on the command line, so there is one thing to learn
 * and one thing to keep correct.
 */
trait NarrowsTheTrail
{
    use ReadsOptions;

    private function narrowed(AuditQuery $query): AuditQuery
    {
        $tenant = $this->text('tenant');
        $type = $this->text('type');

        if ($tenant !== null) {
            $query = $query->forTenant($tenant);
        }

        if ($type !== null) {
            $query = $query->whereType($type);
        }

        return $query->take($this->number('limit') ?? 500);
    }
}
