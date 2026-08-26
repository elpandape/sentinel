<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Integrity;

use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Enums\IntegrityBreak;
use ElPandaPe\Sentinel\Events\IntegrityVerificationFailed;
use ElPandaPe\Sentinel\Models\Audit;
use Illuminate\Contracts\Events\Dispatcher;

final readonly class Verifier
{
    public function __construct(
        private Ledger $ledger,
        private Hasher $hasher,
        private Dispatcher $events,
    ) {}

    public function verifyEntry(Audit $audit): bool
    {
        return hash_equals($audit->hash, $this->hasher->hash($audit));
    }

    public function verifyStream(string $name, ?int $from = null, ?int $to = null): VerificationResult
    {
        $expected = $from ?? 1;
        $previous = null;
        $checked = 0;

        foreach ($this->ledger->stream($name)->range($expected, $to) as $audit) {
            $broken = match (true) {
                $audit->sequence !== $expected => IntegrityBreak::SequenceGap,
                ! $this->verifyEntry($audit) => IntegrityBreak::HashMismatch,
                $this->unlinked($audit, $previous, $checked) => IntegrityBreak::LinkMismatch,
                default => null,
            };

            if ($broken !== null) {
                return $this->announce($name, $checked, $broken, min($audit->sequence, $expected), $audit->id);
            }

            $previous = $audit->hash;
            $expected++;
            $checked++;
        }

        return VerificationResult::intact($name, $checked);
    }

    // The first entry of a walk has no known predecessor unless it is the first of the chain.
    private function unlinked(Audit $audit, ?string $previous, int $checked): bool
    {
        return $checked === 0
            ? $audit->sequence === 1 && $audit->previous_hash !== null
            : $audit->previous_hash !== $previous;
    }

    private function announce(string $stream, int $checked, IntegrityBreak $reason, int $sequence, string $auditId): VerificationResult
    {
        $this->events->dispatch(new IntegrityVerificationFailed($stream, $reason, $sequence, $auditId));

        return VerificationResult::broken($stream, $checked, $reason, $sequence, $auditId);
    }
}
