<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Context\Resolvers;

use ElPandaPe\Sentinel\Contracts\Resolver;
use Illuminate\Contracts\Foundation\Application;

final readonly class HostResolver implements Resolver
{
    public function __construct(private Application $app) {}

    /**
     * @return array<string, mixed>
     */
    public function resolve(): array
    {
        $hostname = gethostname();

        return [
            'hostname' => $hostname === false ? 'unknown' : $hostname,
            'environment' => (string) $this->app->environment(),
        ];
    }
}
