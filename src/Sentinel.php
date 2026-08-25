<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel;

use Closure;
use ElPandaPe\Sentinel\Context\ExecutionContext;
use ElPandaPe\Sentinel\Support\Config;

final class Sentinel
{
    private bool $paused = false;

    public function __construct(
        private readonly Config $config,
        private readonly ExecutionContext $context,
    ) {}

    public function config(): Config
    {
        return $this->config;
    }

    public function context(): ExecutionContext
    {
        return $this->context;
    }

    public function isRecording(): bool
    {
        return $this->config->enabled() && ! $this->paused;
    }

    public function pause(): void
    {
        $this->paused = true;
    }

    public function resume(): void
    {
        $this->paused = false;
    }

    public function withoutAuditing(Closure $callback): mixed
    {
        $paused = $this->paused;

        $this->paused = true;

        try {
            return $callback();
        } finally {
            $this->paused = $paused;
        }
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function withContext(array $context, Closure $callback): mixed
    {
        return $this->context->scope($context, $callback);
    }
}
