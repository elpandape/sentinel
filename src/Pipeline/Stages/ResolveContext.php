<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Pipeline\Stages;

use Closure;
use ElPandaPe\Sentinel\Context\ContextEngine;
use ElPandaPe\Sentinel\Contracts\Transformer;
use ElPandaPe\Sentinel\Data\AuditData;

/**
 * The engine keeps resolving; this only says where in the order it happens. Wrapping it
 * rather than reimplementing it is what keeps one answer to "what was the context".
 */
final readonly class ResolveContext implements Transformer
{
    public function __construct(private ContextEngine $engine) {}

    /**
     * @param  Closure(AuditData): ?AuditData  $next
     */
    public function handle(AuditData $audit, Closure $next): ?AuditData
    {
        return $next(($this->engine)($audit));
    }
}
