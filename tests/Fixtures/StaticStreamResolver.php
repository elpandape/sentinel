<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Fixtures;

use ElPandaPe\Sentinel\Contracts\StreamResolver;
use ElPandaPe\Sentinel\Data\AuditData;

final class StaticStreamResolver implements StreamResolver
{
    public function resolve(AuditData $audit): string
    {
        return 'resolver:'.$audit->audit_type;
    }
}
