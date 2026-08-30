<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Integrity;

use ElPandaPe\Sentinel\Contracts\Signer;

/**
 * Signs nothing and attests to nothing. An empty signature is what keeps the two columns null
 * rather than filled with a value that would read as a claim, and verify() refuses rather than
 * agrees: a signer with no key cannot tell a good signature from a bad one, and answering true
 * would be the one answer that is never right.
 */
final readonly class NullSigner implements Signer
{
    public const string KEY_ID = 'null';

    public function sign(string $hash): string
    {
        return '';
    }

    public function verify(string $hash, string $signature): bool
    {
        return false;
    }

    public function keyId(): string
    {
        return self::KEY_ID;
    }
}
