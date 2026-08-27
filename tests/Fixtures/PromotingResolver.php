<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Fixtures;

use ElPandaPe\Sentinel\Contracts\Resolver;

final class PromotingResolver implements Resolver
{
    /**
     * @return array<string, mixed>
     */
    public function resolve(): array
    {
        return ['tenant_id' => 'acme', 'district' => 'north'];
    }
}
