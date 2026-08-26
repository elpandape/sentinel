<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Integrity;

use Closure;
use ElPandaPe\Sentinel\Contracts\StreamResolver;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Exceptions\ConfigurationException;
use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\Eloquent\Relations\Relation;

final readonly class Stream
{
    public const int MAX_LENGTH = 64;

    private const string ACCEPTED = 'global, tenant, subject_type, a closure or a class implementing '.StreamResolver::class;

    public function __construct(private Config $config, private Container $container) {}

    public function resolve(AuditData $audit): string
    {
        return $this->guard($audit->stream ?? $this->fromStrategy($audit));
    }

    private function fromStrategy(AuditData $audit): string
    {
        $strategy = $this->config->streamStrategy();

        if ($strategy instanceof Closure) {
            $name = $strategy($audit);

            return is_string($name)
                ? $name
                : throw ConfigurationException::expected('integrity.stream', 'a closure returning a string', get_debug_type($name));
        }

        return match ($strategy) {
            'global' => 'global',
            'tenant' => $audit->tenant_id === null ? 'global' : 'tenant:'.$audit->tenant_id,
            'subject_type' => $audit->subject_type === null ? 'global' : 'type:'.$this->morphAlias($audit->subject_type),
            default => $this->fromResolver($strategy, $audit),
        };
    }

    private function morphAlias(string $subjectType): string
    {
        /** @var class-string<\Illuminate\Database\Eloquent\Model> $subjectType */
        return (string) Relation::getMorphAlias($subjectType);
    }

    private function fromResolver(string $strategy, AuditData $audit): string
    {
        if (! class_exists($strategy) || ! is_a($strategy, StreamResolver::class, true)) {
            throw ConfigurationException::unknown('integrity.stream', $strategy, self::ACCEPTED);
        }

        /** @var StreamResolver $resolver */
        $resolver = $this->container->make($strategy);

        return $resolver->resolve($audit);
    }

    private function guard(string $name): string
    {
        return match (true) {
            $name === '' => throw ConfigurationException::streamEmpty(),
            strlen($name) > self::MAX_LENGTH => throw ConfigurationException::streamTooLong($name),
            default => $name,
        };
    }
}
