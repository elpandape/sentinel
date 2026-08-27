<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Context;

use ElPandaPe\Sentinel\Context\Resolvers\ActorResolver;
use ElPandaPe\Sentinel\Context\Resolvers\CommandResolver;
use ElPandaPe\Sentinel\Context\Resolvers\HostResolver;
use ElPandaPe\Sentinel\Context\Resolvers\ImpersonatorResolver;
use ElPandaPe\Sentinel\Context\Resolvers\JobResolver;
use ElPandaPe\Sentinel\Context\Resolvers\RequestResolver;
use ElPandaPe\Sentinel\Context\Resolvers\SessionResolver;
use ElPandaPe\Sentinel\Context\Resolvers\SourceResolver;
use ElPandaPe\Sentinel\Context\Resolvers\TenantResolver;
use ElPandaPe\Sentinel\Context\Resolvers\TraceResolver;
use ElPandaPe\Sentinel\Contracts\Resolver;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Enums\Source;
use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Contracts\Container\Container;

/**
 * The single entry point for context. v0.7.0 wraps this as a pipeline stage rather than
 * resolving anything of its own: two orders of resolution would be two answers.
 */
final readonly class ContextEngine
{
    /**
     * @var array<string, class-string<Resolver>>
     */
    private const array RESOLVERS = [
        'source' => SourceResolver::class,
        'host' => HostResolver::class,
        'request' => RequestResolver::class,
        'session' => SessionResolver::class,
        'command' => CommandResolver::class,
        'trace' => TraceResolver::class,
        'actor' => ActorResolver::class,
        'impersonator' => ImpersonatorResolver::class,
        'tenant' => TenantResolver::class,
        'job' => JobResolver::class,
    ];

    /**
     * What cannot change inside one request. The rest runs on every capture, because an actor
     * can log in, a tenant can be switched and a worker hands one job over to the next.
     *
     * @var list<string>
     */
    private const array MEMOIZED = ['source', 'host', 'request', 'session', 'command'];

    /**
     * The columns a resolver may fill. Every other key it returns lands inside the payload,
     * and the manual context can reach the payload but never these.
     *
     * @var list<string>
     */
    private const array PROMOTED = [
        'actor_type',
        'actor_id',
        'impersonator_type',
        'impersonator_id',
        'tenant_id',
        'request_id',
        'trace_id',
        'span_id',
        'source',
    ];

    public function __construct(
        private Container $container,
        private Config $config,
        private ExecutionContext $context,
    ) {}

    public function __invoke(AuditData $audit): AuditData
    {
        $resolved = $this->resolved();

        $audit->actor_type = $this->column($resolved, 'actor_type');
        $audit->actor_id = $this->column($resolved, 'actor_id');
        $audit->impersonator_type = $this->column($resolved, 'impersonator_type');
        $audit->impersonator_id = $this->column($resolved, 'impersonator_id');
        $audit->tenant_id = $this->column($resolved, 'tenant_id');
        $audit->request_id = $this->column($resolved, 'request_id');
        $audit->trace_id = $this->column($resolved, 'trace_id');
        $audit->span_id = $this->column($resolved, 'span_id');
        $audit->source = ($resolved['source'] ?? null) instanceof Source ? $resolved['source'] : Source::System;

        $audit->context = [...$this->payload($resolved), ...$this->context->all()];

        return $audit;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolved(): array
    {
        $resolved = [];

        foreach (self::RESOLVERS as $name => $default) {
            $resolved = [...$resolved, ...$this->run($name, $default)];
        }

        return $resolved;
    }

    /**
     * @param  class-string<Resolver>  $default
     * @return array<string, mixed>
     */
    private function run(string $name, string $default): array
    {
        $resolve = function () use ($name, $default): array {
            /** @var Resolver $resolver */
            $resolver = $this->container->make($this->config->resolverClass($name, $default));

            return $resolver->resolve();
        };

        return in_array($name, self::MEMOIZED, true)
            ? $this->context->memoize($name, $resolve)
            : $resolve();
    }

    /**
     * @param  array<string, mixed>  $resolved
     * @return array<string, mixed>
     */
    private function payload(array $resolved): array
    {
        return array_diff_key($resolved, array_flip(self::PROMOTED));
    }

    /**
     * Every column is assigned on every pass, absent value included. That is what makes a second
     * pass produce the same entry as the first instead of leaving the first one's leftovers.
     *
     * @param  array<string, mixed>  $resolved
     */
    private function column(array $resolved, string $key): ?string
    {
        $value = $resolved[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
