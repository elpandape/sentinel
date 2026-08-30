<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Archive;

/**
 * How far the manifest accounts for an absence that starts at a given sequence, and which row said
 * so. `reaches` is the last sequence explained; a manifest that explains nothing answers with the
 * sequence before the one it was asked about, so the caller compares numbers rather than nulls.
 *
 * It is a claim and never a proof. Nothing in the manifest is hashed or signed, so what this
 * carries is an explanation that something else has to stand behind.
 */
final readonly class Claim
{
    private function __construct(public int $reaches, public ?string $archiveId) {}

    public static function of(int $reaches, string $archiveId): self
    {
        return new self($reaches, $archiveId);
    }

    public static function none(int $from): self
    {
        return new self($from - 1, null);
    }

    public function explains(int $sequence): bool
    {
        return $sequence <= $this->reaches;
    }
}
