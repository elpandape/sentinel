<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Fixtures;

use Closure;
use ElPandaPe\Sentinel\Contracts\Transformer;
use ElPandaPe\Sentinel\Data\AuditData;
use RuntimeException;

final readonly class ThrowingStage implements Transformer
{
    public const string REASON = 'the fixture threw';

    /**
     * @param  Closure(AuditData): ?AuditData  $next
     */
    public function handle(AuditData $audit, Closure $next): ?AuditData
    {
        throw new RuntimeException(self::REASON);
    }
}
