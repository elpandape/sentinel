<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Events;

use Throwable;

/**
 * A secondary destination of a fanout refused an entry the primary had already sealed, under
 * a policy that does not make it critical. It carries what is needed to go and find the entry
 * that is now in one place and not in another, and nothing of what the entry said.
 */
final readonly class LedgerDestinationFailed
{
    /**
     * @param  class-string  $destination
     */
    public function __construct(
        public string $destination,
        public string $stream,
        public int $sequence,
        public string $auditId,
        public Throwable $reason,
    ) {}

    public function message(): string
    {
        return (string) trans('sentinel::sentinel.ledger.destination_failed', [
            'destination' => $this->destination,
            'stream' => $this->stream,
            'sequence' => $this->sequence,
            'id' => $this->auditId,
            'reason' => $this->reason->getMessage(),
        ]);
    }
}
