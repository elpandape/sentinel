<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Fixtures;

use ElPandaPe\Sentinel\Data\AuditData;

final readonly class Stamps
{
    /**
     * @return list<string>
     */
    public static function on(AuditData $audit): array
    {
        $stamps = $audit->metadata['stamps'] ?? [];

        return is_array($stamps) ? array_values(array_filter($stamps, is_string(...))) : [];
    }
}
