<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Context;

use Closure;

final class ExecutionContext
{
    /**
     * @var array<string, mixed>
     */
    private array $data = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $memo = [];

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return $this->data;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function merge(array $data): void
    {
        $this->data = [...$this->data, ...$data];
    }

    public function forget(string $key): void
    {
        unset($this->data[$key]);
    }

    public function flush(): void
    {
        $this->data = [];
        $this->memo = [];
    }

    /**
     * The scope of a memo is the container scope, not the callback scope: pushing context
     * by hand says nothing about the host the entry was written on.
     *
     * @param  Closure(): array<string, mixed>  $resolve
     * @return array<string, mixed>
     */
    public function memoize(string $key, Closure $resolve): array
    {
        return $this->memo[$key] ??= $resolve();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function scope(array $data, Closure $callback): mixed
    {
        $restore = $this->data;

        $this->merge($data);

        try {
            return $callback();
        } finally {
            $this->data = $restore;
        }
    }
}
