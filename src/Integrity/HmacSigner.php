<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Integrity;

use ElPandaPe\Sentinel\Contracts\Signer;

/**
 * One secret signs and verifies, which is what makes this the cheap default and also what bounds
 * what it proves: whoever can verify can forge. It stands between an entry and someone who reached
 * the database without reaching the application — a stolen backup, a replica, a console — and it
 * stands nowhere at all against an attacker who can read the application key.
 */
final readonly class HmacSigner implements Signer
{
    public function __construct(
        private string $keyId,
        private string $secret,
        private string $algorithm,
    ) {}

    public function sign(string $hash): string
    {
        return hash_hmac($this->algorithm, $hash, $this->secret);
    }

    public function verify(string $hash, string $signature): bool
    {
        return hash_equals($this->sign($hash), $signature);
    }

    public function keyId(): string
    {
        return $this->keyId;
    }
}
