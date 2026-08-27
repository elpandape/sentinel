<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Exceptions;

use RuntimeException;
use Throwable;

final class EncryptionException extends RuntimeException
{
    public static function unknownKey(string $keyId): self
    {
        return new self(sprintf(
            'Sentinel has no key [%s] on its keyring. Declare it under sentinel.security.encryption.keys, '
            .'or point sentinel.security.encryption.key_id at one that exists. A key that leaves the keyring '
            .'takes the values it wrote with it: entries keep verifying, and stop being readable.',
            $keyId,
        ));
    }

    public static function unusableKey(string $keyId, string $cipher, Throwable $previous): self
    {
        return new self(
            sprintf('Sentinel cannot use the key [%s] with cipher [%s]: %s', $keyId, $cipher, $previous->getMessage()),
            previous: $previous,
        );
    }
}
