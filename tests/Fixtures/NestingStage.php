<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Fixtures;

use Closure;
use ElPandaPe\Sentinel\Contracts\Transformer;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Enums\AuditEvent;

/**
 * A stage that writes to an audited model, which is what a policy stage looking something up or
 * a listener stamping a record does. The creation it makes travels the same pipeline, so it is
 * the guard against recursion rather than a flag.
 */
final readonly class NestingStage implements Transformer
{
    /**
     * @param  Closure(AuditData): ?AuditData  $next
     */
    public function handle(AuditData $audit, Closure $next): ?AuditData
    {
        if ($audit->event !== AuditEvent::Created->value) {
            new AuditedSubject()->forceFill(['name' => 'written from inside a pass'])->save();
        }

        return $next($audit);
    }
}
