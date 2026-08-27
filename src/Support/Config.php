<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Support;

use Closure;
use ElPandaPe\Sentinel\Contracts\Masker;
use ElPandaPe\Sentinel\Contracts\Resolver;
use ElPandaPe\Sentinel\Contracts\Transformer;
use ElPandaPe\Sentinel\Enums\AuditEvent;
use ElPandaPe\Sentinel\Enums\Mode;
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Exceptions\ConfigurationException;
use Illuminate\Contracts\Config\Repository;

final readonly class Config
{
    public function __construct(private Repository $repository) {}

    public function enabled(): bool
    {
        return $this->boolean('enabled');
    }

    public function mode(): Mode
    {
        return $this->enum('mode', Mode::class);
    }

    public function table(string $name): string
    {
        return $this->string('tables.prefix').$this->string("tables.{$name}");
    }

    public function connection(): ?string
    {
        return $this->nullableString('database.connection');
    }

    /**
     * @template TModel of object
     *
     * @param  class-string<TModel>  $default
     * @return class-string<TModel>
     */
    public function model(string $name, string $default): string
    {
        $value = $this->repository->get("sentinel.models.{$name}");

        if ($value === null) {
            return $default;
        }

        if (! is_string($value)) {
            throw ConfigurationException::expected("models.{$name}", 'a class-string or null', get_debug_type($value));
        }

        if (! class_exists($value) || ! is_a($value, $default, true)) {
            throw ConfigurationException::invalidClass("models.{$name}", $value, $default);
        }

        return $value;
    }

    public function ledger(): string
    {
        return $this->string('ledger.default');
    }

    public function defaultSeverity(AuditEvent|string $event): Severity
    {
        $name = $event instanceof AuditEvent ? $event->value : $event;
        $overrides = $this->arrayValue('severity.events');

        if (! array_key_exists($name, $overrides)) {
            return $this->enum('severity.default', Severity::class);
        }

        $value = $overrides[$name];

        if (! is_string($value)) {
            throw ConfigurationException::expected("severity.events.{$name}", 'a string', get_debug_type($value));
        }

        return Severity::tryFrom($value)
            ?? throw ConfigurationException::unknown("severity.events.{$name}", $value, $this->accepted(Severity::class));
    }

    public function snapshotsEnabled(): bool
    {
        return $this->boolean('snapshots.enabled');
    }

    public function snapshotsIncludeHidden(): bool
    {
        return $this->boolean('snapshots.include_hidden');
    }

    public function integrityEnabled(): bool
    {
        return $this->boolean('integrity.enabled');
    }

    public function integrityAlgorithm(): string
    {
        $algorithm = $this->string('integrity.algorithm');

        return in_array($algorithm, hash_algos(), true)
            ? $algorithm
            : throw ConfigurationException::unknown('integrity.algorithm', $algorithm, implode(', ', hash_algos()));
    }

    public function streamStrategy(): string|Closure
    {
        $value = $this->value('integrity.stream');

        return is_string($value) || $value instanceof Closure
            ? $value
            : throw ConfigurationException::expected('integrity.stream', 'a string or a closure', get_debug_type($value));
    }

    public function complianceEnabled(): bool
    {
        return $this->boolean('compliance');
    }

    /**
     * @return array<string, string>
     */
    public function retention(): array
    {
        $policies = [];

        foreach ($this->arrayValue('retention') as $target => $period) {
            if (! is_string($target) || ! is_string($period)) {
                throw ConfigurationException::expected('retention', 'a map of string to string', get_debug_type($period));
            }

            $policies[$target] = $period;
        }

        return $policies;
    }

    /**
     * The defaults live here and not only in the publishable file because the config merge is
     * shallow: an installation that published sentinel.php while `resolvers` was still an empty
     * array would otherwise override the whole subtree and end up with no resolvers at all.
     *
     * @template TResolver of Resolver
     *
     * @param  class-string<TResolver>  $default
     * @return class-string<TResolver>
     */
    public function resolverClass(string $name, string $default): string
    {
        $value = $this->repository->get("sentinel.resolvers.{$name}.class");

        if ($value === null) {
            return $default;
        }

        if (! is_string($value)) {
            throw ConfigurationException::expected("resolvers.{$name}.class", 'a class-string or null', get_debug_type($value));
        }

        if (! class_exists($value) || ! is_a($value, Resolver::class, true)) {
            throw ConfigurationException::invalidClass("resolvers.{$name}.class", $value, Resolver::class);
        }

        /** @var class-string<TResolver> $value */
        return $value;
    }

    /**
     * An empty list falls back to the package order rather than to no pipeline at all: the
     * config published in v0.1.0 ships `pipeline => []`, and a shallow merge would leave those
     * installations transforming nothing. Dropping a stage means declaring the list without it.
     *
     * @param  list<class-string<Transformer>>  $default
     * @return list<class-string<Transformer>>
     */
    public function pipelineStages(array $default): array
    {
        $value = $this->repository->get('sentinel.pipeline');

        if ($value === null || $value === []) {
            return $default;
        }

        if (! is_array($value)) {
            throw ConfigurationException::expected('pipeline', 'a list of stage class-strings', get_debug_type($value));
        }

        $stages = [];

        foreach ($value as $stage) {
            if (! is_string($stage)) {
                throw ConfigurationException::expected('pipeline', 'a list of stage class-strings', get_debug_type($stage));
            }

            if (! class_exists($stage) || ! is_a($stage, Transformer::class, true)) {
                throw ConfigurationException::invalidClass('pipeline', $stage, Transformer::class);
            }

            $stages[] = $stage;
        }

        return $stages;
    }

    public function actorGuard(): ?string
    {
        $value = $this->repository->get('sentinel.resolvers.actor.guard');

        return $value === null || is_string($value)
            ? $value
            : throw ConfigurationException::expected('resolvers.actor.guard', 'a string or null', get_debug_type($value));
    }

    public function impersonatorSessionKey(): string
    {
        return $this->resolverString('impersonator.session_key', 'impersonated_by');
    }

    public function tenantUsing(): ?Closure
    {
        $value = $this->repository->get('sentinel.resolvers.tenant.using');

        return $value === null || $value instanceof Closure
            ? $value
            : throw ConfigurationException::expected('resolvers.tenant.using', 'a closure or null', get_debug_type($value));
    }

    public function requestIdHeader(): string
    {
        return $this->resolverString('request.header', 'X-Request-Id');
    }

    public function apiBoundary(): string|Closure
    {
        $value = $this->repository->get('sentinel.resolvers.request.api');

        return match (true) {
            $value === null => 'api/*',
            is_string($value) && $value !== '' => $value,
            $value instanceof Closure => $value,
            default => throw ConfigurationException::expected('resolvers.request.api', 'a route pattern or a closure', get_debug_type($value)),
        };
    }

    /**
     * @return list<string>
     */
    public function commandRedactions(): array
    {
        $value = $this->repository->get('sentinel.resolvers.command.redact');

        if ($value === null) {
            return ['password', 'token', 'secret'];
        }

        if (! is_array($value)) {
            throw ConfigurationException::expected('resolvers.command.redact', 'a list of strings', get_debug_type($value));
        }

        $needles = [];

        foreach ($value as $needle) {
            $needles[] = is_string($needle)
                ? $needle
                : throw ConfigurationException::expected('resolvers.command.redact', 'a list of strings', get_debug_type($needle));
        }

        return $needles;
    }

    /**
     * The declared list and the configured one are a union, not a fallback. The config list
     * is the only way to name a key that no model owns — an address, a session id, a console
     * argument — so it has to apply to entries a model never declared anything about.
     *
     * @param  list<string>  $declared
     * @return list<string>
     */
    public function redactedFields(array $declared): array
    {
        return $this->union($declared, 'security.redaction.fields');
    }

    /**
     * @param  list<string>  $declared
     * @return list<string>
     */
    public function hashedFields(array $declared): array
    {
        return $this->union($declared, 'security.hashing.fields');
    }

    /**
     * @param  class-string<Masker>  $default
     * @return class-string<Masker>
     */
    public function maskerClass(string $field, string $default): string
    {
        foreach (["security.redaction.maskers.{$field}", 'security.redaction.masker'] as $key) {
            $value = $this->repository->get("sentinel.{$key}");

            if ($value === null) {
                continue;
            }

            if (! is_string($value)) {
                throw ConfigurationException::expected($key, 'a class-string or null', get_debug_type($value));
            }

            if (! class_exists($value) || ! is_a($value, Masker::class, true)) {
                throw ConfigurationException::invalidClass($key, $value, Masker::class);
            }

            /** @var class-string<Masker> $value */
            return $value;
        }

        return $default;
    }

    public function hashingAlgorithm(): string
    {
        $algorithm = $this->repository->get('sentinel.security.hashing.algorithm') ?? 'sha256';

        if (! is_string($algorithm)) {
            throw ConfigurationException::expected('security.hashing.algorithm', 'a string', get_debug_type($algorithm));
        }

        return in_array($algorithm, hash_algos(), true)
            ? $algorithm
            : throw ConfigurationException::unknown('security.hashing.algorithm', $algorithm, implode(', ', hash_algos()));
    }

    /**
     * Derived from the application key when none is declared, so a digest is comparable
     * across every entry of one installation and across none of two.
     */
    public function hashingSalt(): string
    {
        $salt = $this->repository->get('sentinel.security.hashing.salt');

        if (is_string($salt) && $salt !== '') {
            return $salt;
        }

        if ($salt !== null && $salt !== '') {
            throw ConfigurationException::expected('security.hashing.salt', 'a non-empty string or null', get_debug_type($salt));
        }

        $key = $this->repository->get('app.key');

        return is_string($key) && $key !== ''
            ? hash_hmac('sha256', 'sentinel:hashing', $key)
            : throw ConfigurationException::missingApplicationKey('security.hashing.salt');
    }

    public function redactionMask(): string
    {
        $value = $this->repository->get('sentinel.security.redaction.mask');

        return match (true) {
            $value === null || $value === '' => '*',
            is_string($value) => $value,
            default => throw ConfigurationException::expected('security.redaction.mask', 'a string', get_debug_type($value)),
        };
    }

    /**
     * @param  list<string>  $declared
     * @return list<string>
     */
    private function union(array $declared, string $key): array
    {
        $value = $this->repository->get("sentinel.{$key}");

        if ($value === null) {
            return $declared;
        }

        if (! is_array($value)) {
            throw ConfigurationException::expected($key, 'a list of field names', get_debug_type($value));
        }

        $fields = $declared;

        foreach ($value as $field) {
            $fields[] = is_string($field)
                ? $field
                : throw ConfigurationException::expected($key, 'a list of field names', get_debug_type($field));
        }

        return array_values(array_unique($fields));
    }

    private function resolverString(string $key, string $default): string
    {
        $value = $this->repository->get("sentinel.resolvers.{$key}");

        return match (true) {
            $value === null => $default,
            is_string($value) && $value !== '' => $value,
            default => throw ConfigurationException::expected("resolvers.{$key}", 'a non-empty string or null', get_debug_type($value)),
        };
    }

    private function boolean(string $key): bool
    {
        $value = $this->value($key);

        return is_bool($value)
            ? $value
            : throw ConfigurationException::expected($key, 'a boolean', get_debug_type($value));
    }

    private function string(string $key): string
    {
        $value = $this->value($key);

        return is_string($value)
            ? $value
            : throw ConfigurationException::expected($key, 'a string', get_debug_type($value));
    }

    private function nullableString(string $key): ?string
    {
        $value = $this->repository->get("sentinel.{$key}");

        if ($value === null || is_string($value)) {
            return $value;
        }

        throw ConfigurationException::expected($key, 'a string or null', get_debug_type($value));
    }

    /**
     * @return array<array-key, mixed>
     */
    private function arrayValue(string $key): array
    {
        $value = $this->value($key);

        return is_array($value)
            ? $value
            : throw ConfigurationException::expected($key, 'an array', get_debug_type($value));
    }

    /**
     * @template T of \BackedEnum
     *
     * @param  class-string<T>  $enum
     * @return T
     */
    private function enum(string $key, string $enum): object
    {
        $value = $this->string($key);

        return $enum::tryFrom($value)
            ?? throw ConfigurationException::unknown($key, $value, $this->accepted($enum));
    }

    /**
     * @param  class-string<\BackedEnum>  $enum
     */
    private function accepted(string $enum): string
    {
        return implode(', ', array_map(
            static fn (\BackedEnum $case): string => (string) $case->value,
            $enum::cases(),
        ));
    }

    private function value(string $key): mixed
    {
        $value = $this->repository->get("sentinel.{$key}");

        return $value ?? throw ConfigurationException::missing($key);
    }
}
