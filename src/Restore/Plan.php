<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Restore;

use ElPandaPe\Sentinel\Enums\Omission;

/**
 * What a restoration would write, and what it would not. Decided before anything is touched, so
 * a restoration that turns out to be impossible costs a read and no write at all.
 *
 * The keys come out sorted, and that is not tidiness. They arrive in the order the engine handed
 * back a json column, and MySQL returns an object's keys by length and then alphabetically where
 * the other two keep insertion order. Handing that order on would make the result of the same
 * restoration differ by database, and it travels into the entry's own metadata.
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
        ksort($skipped);

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
        $keys = array_keys($this->applying);

        sort($keys);

        return $keys;
    }
}
