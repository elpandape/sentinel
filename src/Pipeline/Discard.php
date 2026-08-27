<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Pipeline;

use ElPandaPe\Sentinel\Exceptions\DiscardException;

/**
 * Returning null from a stage is the mechanism; this is what turns it into something
 * `AuditDiscarded` can carry, and what makes discarding illegal anywhere else.
 */
final class Discard
{
    private const string UNSPECIFIED = 'unspecified';

    private bool $running = false;

    /**
     * @var class-string|null
     */
    private ?string $stage = null;

    private ?string $reason = null;

    public function because(string $reason): void
    {
        if (! $this->running) {
            throw DiscardException::outsideThePipeline($reason);
        }

        $this->reason ??= $reason;
    }

    public function running(): bool
    {
        return $this->running;
    }

    public function begin(): void
    {
        $this->running = true;
        $this->stage = null;
        $this->reason = null;
    }

    /**
     * The first stage to return null owns the discard: the ones wrapping it only see that
     * null travelling back out.
     *
     * @param  class-string  $stage
     */
    public function at(string $stage): null
    {
        $this->stage ??= $stage;

        return null;
    }

    public function end(): ?Discarded
    {
        $this->running = false;

        return $this->stage === null
            ? null
            : new Discarded($this->stage, $this->reason ?? self::UNSPECIFIED);
    }
}
