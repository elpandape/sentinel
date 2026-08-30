<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Integrity;

use ElPandaPe\Sentinel\Contracts\EnumeratesStreams;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Contracts\Signer;
use ElPandaPe\Sentinel\Enums\IntegrityBreak;
use ElPandaPe\Sentinel\Enums\SignatureState;
use ElPandaPe\Sentinel\Events\IntegrityVerificationFailed;
use ElPandaPe\Sentinel\Exceptions\QueryException;
use ElPandaPe\Sentinel\Models\Audit;
use Illuminate\Contracts\Events\Dispatcher;

final readonly class Verifier
{
    public function __construct(
        private Ledger $ledger,
        private Hasher $hasher,
        private Signers $signers,
        private Dispatcher $events,
    ) {}

    public function verifyEntry(Audit $audit): bool
    {
        return hash_equals($audit->hash, $this->hasher->hash($audit));
    }

    /**
     * What the signature says, which is a different question from whether the entry reproduces its
     * own hash. An entry written before signing was switched on carries none and is reported as
     * carrying none; one whose key left the ring is reported as unresolvable, never as forged. The
     * verifier is only entitled to call a signature invalid when it held the key that could have
     * made it valid.
     */
    public function verifySignature(Audit $audit): SignatureState
    {
        $signature = $audit->signature;

        if ($signature === null) {
            return SignatureState::Unsigned;
        }

        $signer = $this->signers->for($audit->signature_key_id ?? '');

        if (! $signer instanceof Signer) {
            return SignatureState::UnknownKey;
        }

        return $signer->verify($audit->hash, $signature)
            ? SignatureState::Signed
            : SignatureState::Invalid;
    }

    public function verifyStream(string $name, ?int $from = null, ?int $to = null): VerificationResult
    {
        return $this->verify($name, $from, $to)->chain;
    }

    /**
     * One walk, both answers. The signature of every entry is checked here rather than behind a
     * flag, because a verification that skipped it would be claiming the trail sound while holding
     * an attestation it never opened — and when nothing is signed the check costs one null test a
     * row.
     *
     * A broken link stops the walk: past it nothing can be said. A broken signature does not, since
     * the chain still holds and the rest of it is still worth reading.
     */
    public function verify(string $name, ?int $from = null, ?int $to = null): StreamVerification
    {
        $expected = $from ?? 1;
        $previous = null;
        $checked = 0;
        $signatures = [];
        $forged = null;

        foreach ($this->ledger->stream($name)->range($expected, $to) as $audit) {
            $broken = match (true) {
                $audit->sequence !== $expected => IntegrityBreak::SequenceGap,
                ! $this->verifyEntry($audit) => IntegrityBreak::HashMismatch,
                $this->unlinked($audit, $previous, $checked) => IntegrityBreak::LinkMismatch,
                default => null,
            };

            if ($broken !== null) {
                return new StreamVerification(
                    $this->announce($name, $checked, $broken, min($audit->sequence, $expected), $audit->id),
                    $signatures,
                    $forged,
                );
            }

            $state = $this->verifySignature($audit);
            $signatures[$state->value] = ($signatures[$state->value] ?? 0) + 1;

            if ($state === SignatureState::Invalid && ! $forged instanceof VerificationResult) {
                $forged = $this->announce($name, $checked, IntegrityBreak::SignatureMismatch, $audit->sequence, $audit->id);
            }

            $previous = $audit->hash;
            $expected++;
            $checked++;
        }

        return new StreamVerification(VerificationResult::intact($name, $checked), $signatures, $forged);
    }

    /**
     * The whole trail, one stream at a time. A ledger that cannot name its chains is refused rather
     * than reported empty: answering "nothing is broken" about a list nobody could produce is the
     * one answer that would be read as reassurance and mean nothing.
     */
    public function verifyEverything(): IntegrityReport
    {
        if (! $this->ledger instanceof EnumeratesStreams) {
            throw QueryException::cannotEnumerateStreams($this->ledger::class);
        }

        return new IntegrityReport(array_map(
            fn (string $stream): StreamVerification => $this->verify($stream),
            $this->ledger->streams(),
        ));
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
