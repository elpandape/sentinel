<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Exceptions;

use RuntimeException;

final class SignatureException extends RuntimeException
{
    public static function unknownKey(string $keyId): self
    {
        return new self(
            "Sentinel is configured to sign with key [{$keyId}], which is not on [sentinel.integrity.signature.keys]. "
            .'Entries already signed with a key that left the ring keep verifying only while the key stays on it.',
        );
    }

    public static function verifyOnly(string $keyId): self
    {
        return new self(
            "Sentinel cannot sign with key [{$keyId}]: [sentinel.integrity.signature.private_key] is not set. "
            .'A node that holds only the public half verifies what others wrote and writes nothing itself.',
        );
    }

    public static function unusableKey(string $keyId, string $half): self
    {
        return new self("Sentinel could not read the {$half} key for [{$keyId}]. OpenSSL takes a PEM string or a file:// path to one.");
    }

    public static function unsignable(string $keyId, string $algorithm): self
    {
        return new self(
            "Sentinel cannot sign with key [{$keyId}] under [{$algorithm}]. Either OpenSSL does not know that digest, "
            .'or the key signs the message itself and refuses to be handed one that was already hashed, as EdDSA keys do.',
        );
    }
}
