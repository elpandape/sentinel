<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Restore;

use ElPandaPe\Sentinel\Enums\Omission;
use ElPandaPe\Sentinel\Models\Audit;

/**
 * What a restoration did, and what it declined to do. Never a boolean: a restoration that put
 * back four fields out of six is neither a success nor a failure, and answering true would hide
 * the two that a masked value or a dropped column left behind.
 *
 * applied carries keys and not values. What was written is on the record and inside the entry
 * this result names, and handing the values around a second time is how a redacted value that
 * the pipeline transformed on the way in escapes on the way out.
 */
final readonly class RestoreResult
{
    /**
     * @param  list<string>  $applied
     * @param  array<string, Omission>  $skipped
     */
    private function __construct(
        public array $applied,
        public array $skipped,
        public ?Omission $refused = null,
        public ?Audit $entry = null,
    ) {}

    /**
     * @param  list<string>  $applied
     * @param  array<string, Omission>  $skipped
     */
    public static function of(array $applied, array $skipped, ?Audit $entry = null): self
    {
        return new self($applied, $skipped, null, $entry);
    }

    /**
     * Nothing was applied and nothing was written, because the entry or the record it is about
     * cannot answer for itself. It is a result and not an exception: asking to restore something
     * that cannot be restored is a question with an answer, not a programming error.
     */
    public static function refused(Omission $reason): self
    {
        return new self([], [], $reason);
    }

    /**
     * Why this key is not in applied. A refused restoration answers for every key, because none
     * of them got as far as being considered on its own.
     */
    public function reason(string $key): ?Omission
    {
        return $this->skipped[$key] ?? $this->refused;
    }
}
