<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Context\Resolvers;

use ElPandaPe\Sentinel\Contracts\Resolver;
use ElPandaPe\Sentinel\Exceptions\ConfigurationException;
use ElPandaPe\Sentinel\Support\Config;

/**
 * Tenancy is a choice the application already made with some other package. This asks it
 * for the current key and stays ignorant of how it got there.
 */
final readonly class TenantResolver implements Resolver
{
    public function __construct(private Config $config) {}

    /**
     * @return array<string, mixed>
     */
    public function resolve(): array
    {
        $using = $this->config->tenantUsing();

        if (! $using instanceof \Closure) {
            return [];
        }

        $tenant = $using();

        return match (true) {
            $tenant === null => [],
            is_string($tenant) || is_int($tenant) => ['tenant_id' => (string) $tenant],
            default => throw ConfigurationException::expected('resolvers.tenant.using', 'a closure returning a string, an integer or null', get_debug_type($tenant)),
        };
    }
}
