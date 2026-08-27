<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Contracts;

use Closure;
use ElPandaPe\Sentinel\Data\AuditData;

interface Transformer
{
    /**
     * @param  Closure(AuditData): ?AuditData  $next
     */
    public function handle(AuditData $audit, Closure $next): ?AuditData;
}
