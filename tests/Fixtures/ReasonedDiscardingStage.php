<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Fixtures;

use Closure;
use ElPandaPe\Sentinel\Contracts\Transformer;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Pipeline\Discard;

final readonly class ReasonedDiscardingStage implements Transformer
{
    public const string REASON = 'the fixture said so';

    public function __construct(private Discard $discard) {}

    /**
     * @param  Closure(AuditData): ?AuditData  $next
     */
    public function handle(AuditData $audit, Closure $next): ?AuditData
    {
        $this->discard->because(self::REASON);

        return null;
    }
}
