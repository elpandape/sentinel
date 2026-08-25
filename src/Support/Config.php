<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Support;

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

    public function integrityEnabled(): bool
    {
        return $this->boolean('integrity.enabled');
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
