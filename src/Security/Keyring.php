<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Security;

use ElPandaPe\Sentinel\Exceptions\EncryptionException;
use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Contracts\Encryption\Encrypter as EncrypterContract;
use Illuminate\Encryption\Encrypter;
use RuntimeException;

/**
 * Keys by identifier, because the identifier is what makes rotation possible: an entry
 * records which key wrote it, so yesterday's entries keep decrypting with yesterday's key
 * while today's are written with today's.
 *
 * Losing a key means losing the values it wrote. That is the correct behaviour: the keyring
 * belongs to the application and lives outside the database the entries do.
 */
final class Keyring
{
    private const string BASE64 = 'base64:';

    /**
     * @var array<string, EncrypterContract>
     */
    private array $encrypters = [];

    public function __construct(private readonly Config $config) {}

    public function current(): string
    {
        return $this->config->encryptionKeyId();
    }

    public function for(string $keyId): EncrypterContract
    {
        return $this->encrypters[$keyId] ??= $this->build($keyId);
    }

    private function build(string $keyId): EncrypterContract
    {
        $cipher = $this->config->encryptionCipher();

        try {
            return new Encrypter($this->key($keyId), $cipher);
        } catch (RuntimeException $exception) {
            throw EncryptionException::unusableKey($keyId, $cipher, $exception);
        }
    }

    private function key(string $keyId): string
    {
        $key = $this->config->encryptionKey($keyId);

        return str_starts_with($key, self::BASE64)
            ? (string) base64_decode(substr($key, strlen(self::BASE64)), true)
            : $key;
    }
}
