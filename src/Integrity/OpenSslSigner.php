<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Integrity;

use ElPandaPe\Sentinel\Contracts\Signer;
use ElPandaPe\Sentinel\Exceptions\SignatureException;

/**
 * The only configuration that survives its own administrator: the half that verifies is not the
 * half that signs, so the private key can live off the machine the entries do. A node given only
 * the public key proves every entry untouched and cannot write one.
 *
 * The signature is stored base64-encoded. The column is text and the export is JSON, so raw DER
 * bytes would survive neither.
 */
final readonly class OpenSslSigner implements Signer
{
    public function __construct(
        private string $keyId,
        private string $publicKey,
        private ?string $privateKey,
        private string $algorithm,
    ) {}

    public function sign(string $hash): string
    {
        if ($this->privateKey === null) {
            throw SignatureException::verifyOnly($this->keyId);
        }

        $key = openssl_pkey_get_private($this->privateKey);

        if ($key === false) {
            throw SignatureException::unusableKey($this->keyId, 'private');
        }

        $signature = '';

        return openssl_sign($hash, $signature, $key, $this->digest()) && is_string($signature)
            ? base64_encode($signature)
            : throw SignatureException::unsignable($this->keyId, $this->algorithm);
    }

    public function verify(string $hash, string $signature): bool
    {
        $key = openssl_pkey_get_public($this->publicKey);

        if ($key === false) {
            throw SignatureException::unusableKey($this->keyId, 'public');
        }

        $decoded = base64_decode($signature, true);

        return $decoded !== false && openssl_verify($hash, $decoded, $key, $this->digest()) === 1;
    }

    public function keyId(): string
    {
        return $this->keyId;
    }

    /**
     * The configuration validates the algorithm against hash_algos(), which is a longer list than
     * the one OpenSSL signs with — crc32b is a hash and is not a digest. Refusing here is what
     * keeps a name OpenSSL does not know from reaching it and being answered with a warning.
     */
    private function digest(): string
    {
        return in_array($this->algorithm, openssl_get_md_methods(), true)
            ? $this->algorithm
            : throw SignatureException::unsignable($this->keyId, $this->algorithm);
    }
}
