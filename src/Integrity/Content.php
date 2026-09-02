<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Integrity;

use ElPandaPe\Sentinel\Enums\ContentState;
use ElPandaPe\Sentinel\Models\Audit;

/**
 * The one definition of what an entry's content says about itself, kept apart from the verifier so
 * the archive can ask it too. The writer proving a batch and the rehydrator checking one before it
 * inserts need exactly this rule, and a second copy of it in either of them would be a place for the
 * two to drift — the kind of drift that ends with a redacted entry archived as tampered.
 *
 * It depends on nothing but the hasher, which is what lets the archive use it without the verifier's
 * ledger and manifest coming along.
 */
final readonly class Content
{
    public function __construct(private Hasher $hasher) {}

    public function of(Audit $audit): ContentState
    {
        $rehashed = $this->hasher->hash($audit);

        if ($audit->redacted_at === null) {
            return hash_equals($audit->hash, $rehashed)
                ? ContentState::Sealed
                : ContentState::Altered;
        }

        $redacted = $audit->redacted_hash;

        return $redacted !== null && hash_equals($redacted, $rehashed)
            ? ContentState::Redacted
            : ContentState::Altered;
    }

    /**
     * Whether the entry still reproduces the hash it is entitled to reproduce — the original one, or
     * the second one once it has been redacted. It is what the archive asks: a batch is refused for
     * an entry that reproduces neither, and a tombstone reproduces the second.
     */
    public function holds(Audit $audit): bool
    {
        return $this->of($audit) !== ContentState::Altered;
    }
}
