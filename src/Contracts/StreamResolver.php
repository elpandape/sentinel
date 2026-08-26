<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Contracts;

use ElPandaPe\Sentinel\Data\AuditData;

interface StreamResolver
{
    public function resolve(AuditData $audit): string;
}
