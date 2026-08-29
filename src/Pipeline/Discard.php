<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Pipeline;

use ElPandaPe\Sentinel\Exceptions\DiscardException;

/**
 * Returning null from a stage is the mechanism; this is what turns it into something
 * `AuditDiscarded` can carry, and what makes discarding illegal anywhere else.
 *
 * A pass suspends the one it started inside instead of replacing it. A stage and a listener are
 * both free to touch an audited model, and that begins a pass within the open one: replacing it,
 * the inner end() left the outer closed, so the next stage asking to discard threw out of the
 * application's own save() saying the ledger had already assigned a sequence — which had not
 * happened — and the stage and reason of one entry could be read as another's.
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

    /**
     * @var list<array{bool, class-string|null, string|null}>
     */
    private array $suspended = [];

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
        $this->suspended[] = [$this->running, $this->stage, $this->reason];

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
        $discarded = $this->stage === null
            ? null
            : new Discarded($this->stage, $this->reason ?? self::UNSPECIFIED);

        [$this->running, $this->stage, $this->reason] = array_pop($this->suspended) ?? [false, null, null];

        return $discarded;
    }
}
