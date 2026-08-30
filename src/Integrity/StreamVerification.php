<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Integrity;

/**
 * What one walk of one stream found: whether the chain holds, and what it found out about the
 * signatures along the way. The two are separate answers on purpose — a chain can be perfectly
 * intact while every entry in it is unsigned, and calling that a failure would make the report
 * useless on every installation that has not switched signing on.
 *
 * An invalid signature is the one signature state that is a defect, so it arrives here in the same
 * shape a broken link does: the same reason vocabulary, the same point of rupture.
 */
final readonly class StreamVerification
{
    /**
     * @param  array<string, int>  $signatures  how many entries landed in each SignatureState
     */
    public function __construct(
        public VerificationResult $chain,
        public array $signatures,
        public ?VerificationResult $signature = null,
    ) {}

    public function isIntact(): bool
    {
        return $this->chain->isIntact() && ! $this->signature instanceof VerificationResult;
    }

    public function stream(): string
    {
        return $this->chain->stream;
    }

    /**
     * The first thing that was wrong, chain before signature: a stream whose links do not hold is
     * a bigger fact than one whose attestation does not, and a report that led with the smaller
     * one would bury it.
     */
    public function break(): ?VerificationResult
    {
        return $this->chain->isIntact() ? $this->signature : $this->chain;
    }
}
