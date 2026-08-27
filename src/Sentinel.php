<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel;

use Closure;
use ElPandaPe\Sentinel\Context\ExecutionContext;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Integrity\VerificationResult;
use ElPandaPe\Sentinel\Integrity\Verifier;
use ElPandaPe\Sentinel\Support\Config;
use ElPandaPe\Sentinel\Support\Policies;

final class Sentinel
{
    private bool $paused = false;

    public function __construct(
        private readonly Config $config,
        private readonly ExecutionContext $context,
        private readonly Verifier $verifier,
        private readonly Policies $policies,
    ) {}

    /**
     * The last word on whether an entry settles. A policy that returns false discards it,
     * before the ledger has given it a sequence and therefore without leaving a gap.
     *
     * @param  Closure(AuditData): bool  $policy
     */
    public function filter(Closure $policy): void
    {
        $this->policies->add($policy);
    }

    public function verifyIntegrity(string $stream, ?int $from = null, ?int $to = null): VerificationResult
    {
        return $this->verifier->verifyStream($stream, $from, $to);
    }

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
