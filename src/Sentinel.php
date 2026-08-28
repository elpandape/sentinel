<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel;

use Closure;
use ElPandaPe\Sentinel\Context\ExecutionContext;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Integrity\VerificationResult;
use ElPandaPe\Sentinel\Integrity\Verifier;
use ElPandaPe\Sentinel\Query\AuditQuery;
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
        private readonly Ledger $ledger,
    ) {}

    /**
     * The way in to the trail, and the only one: every read goes through the ledger contract
     * from here, so a driver over something that is not a table answers the same query, and
     * so the version that has to log who read what has one place to log it.
     */
    public function audits(): AuditQuery
    {
        return new AuditQuery($this->ledger);
    }

    /**
     * Everything that happened, in the order it happened. One query over one table: the trail
     * holds every kind of entry, so a timeline is the unnarrowed read with the clock of the fact
     * in front — not a merge of sources in PHP.
     */
    public function timeline(): AuditQuery
    {
        return $this->audits()->byOccurrence();
    }

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
