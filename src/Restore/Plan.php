<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Restore;

use ElPandaPe\Sentinel\Enums\Omission;

/**
 * What a restoration would write, and what it would not. Decided before anything is touched, so
 * a restoration that turns out to be impossible costs a read and no write at all.
 */
final readonly class Plan
{
    /**
     * @param  array<string, mixed>  $applying
     * @param  array<string, Omission>  $skipped
     */
    private function __construct(
        public array $applying,
        public array $skipped,
        public ?Omission $refused = null,
    ) {}

    /**
     * @param  array<string, mixed>  $applying
     * @param  array<string, Omission>  $skipped
     */
    public static function of(array $applying, array $skipped): self
    {
        return new self($applying, $skipped);
    }

    public static function refused(Omission $reason): self
    {
        return new self([], [], $reason);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->applying);
    }
}
