<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Fixtures;

use Closure;
use ElPandaPe\Sentinel\Contracts\Transformer;
use ElPandaPe\Sentinel\Data\AuditData;

final readonly class StampingStage implements Transformer
{
    public const string STAMP = 'first';

    /**
     * @param  Closure(AuditData): ?AuditData  $next
     */
    public function handle(AuditData $audit, Closure $next): ?AuditData
    {
        $audit->metadata = [...$audit->metadata ?? [], 'stamps' => [...Stamps::on($audit), self::STAMP]];

        return $next($audit);
    }
}
