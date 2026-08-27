<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Fixtures;

use ElPandaPe\Sentinel\Contracts\Resolver;

final class SubstituteResolver implements Resolver
{
    /**
     * @return array<string, mixed>
     */
    public function resolve(): array
    {
        return ['substituted' => true];
    }
}
