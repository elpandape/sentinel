<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Dispatch;

use ElPandaPe\Sentinel\Models\Audit;

/**
 * What became of an entry the dispatcher handed to a mode. Three answers and not two, because
 * "there is no entry here" and "there will be no entry anywhere" are different facts and only
 * the mode knows which one it is: in a synchronous mode a missing entry is a write that failed,
 * and in an asynchronous one it is the normal outcome of a hand-off that worked.
 *
 * Collapsing them into a nullable entry is what would make the process that captured unable to
 * tell a queued audit from a lost one.
 */
final readonly class Handover
{
    private function __construct(
        public bool $accepted,
        public ?Audit $entry,
    ) {}

    /**
     * The entry exists, in this process, with a sequence and a hash.
     */
    public static function settled(Audit $entry): self
    {
        return new self(true, $entry);
    }

    /**
     * The mode took it and will settle it elsewhere. Nothing here can name the entry yet, and
     * nothing here is going to.
     */
    public static function accepted(): self
    {
        return new self(true, null);
    }

    /**
     * It did not settle and it is not going to. The failure has already been announced by
     * whoever knew what it was.
     */
    public static function refused(): self
    {
        return new self(false, null);
    }
}
