<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Integrity;

use ElPandaPe\Sentinel\Contracts\Signer;
use ElPandaPe\Sentinel\Exceptions\ConfigurationException;
use ElPandaPe\Sentinel\Exceptions\SignatureException;
use ElPandaPe\Sentinel\Support\Config;

/**
 * Signers by identifier, because the identifier is what makes rotation possible: an entry records
 * which key signed it, so yesterday's entries keep verifying with yesterday's key while today's are
 * written with today's. Retiring a key is leaving it here and moving on from it, not removing it.
 *
 * A key this ring cannot resolve is answered with null and not with an exception. That is the whole
 * point of the distinction the report draws: an unresolvable identifier is something the verifier
 * cannot decide, and calling it an invalid signature would be a verdict nobody is entitled to.
 * Signing is the other way round — being asked to sign with a key that is not here is a
 * configuration error, and it is loud.
 */
final class Signers
{
    private const string ACCEPTED = 'hmac, openssl, null';

    /**
     * @var array<string, ?Signer>
     */
    private array $resolved = [];

    public function __construct(private readonly Config $config) {}

    /**
     * What signs the next entry. Signing switched off is a `NullSigner` rather than a null, so the
     * write path has one shape: it asks for a signature and stores it when there is one.
     */
    public function current(): Signer
    {
        if (! $this->config->signatureEnabled()) {
            return new NullSigner;
        }

        $keyId = $this->config->signatureKeyId();

        return $this->for($keyId) ?? throw SignatureException::unknownKey($keyId);
    }

    public function for(string $keyId): ?Signer
    {
        return $this->resolved[$keyId] ??= $this->build($keyId);
    }

    private function build(string $keyId): ?Signer
    {
        return match ($driver = $this->config->signatureDriver()) {
            'hmac' => $this->hmac($keyId),
            'openssl' => $this->openssl($keyId),
            'null' => $keyId === NullSigner::KEY_ID ? new NullSigner : null,
            default => throw ConfigurationException::unknown('integrity.signature.signer', $driver, self::ACCEPTED),
        };
    }

    /**
     * The application key is the fallback for the default identifier only. Any other one is named
     * on purpose, and silently verifying it with a key it did not name would make the identifier
     * recorded in the entry a lie.
     */
    private function hmac(string $keyId): ?Signer
    {
        $secret = $this->config->signatureKey($keyId)
            ?? ($keyId === 'default' ? $this->config->derivedSigningSecret() : null);

        return $secret === null
            ? null
            : new HmacSigner($keyId, $secret, $this->config->signatureAlgorithm());
    }

    /**
     * The private half belongs to whoever is signing now, so it hangs off the configuration rather
     * than off the identifier: a node holding the ring and no private key resolves every key here
     * and cannot sign with any of them.
     */
    private function openssl(string $keyId): ?Signer
    {
        $public = $this->config->signatureKey($keyId);

        if ($public === null) {
            return null;
        }

        return new OpenSslSigner(
            $keyId,
            $public,
            $keyId === $this->config->signatureKeyId() ? $this->config->signaturePrivateKey() : null,
            $this->config->signatureAlgorithm(),
        );
    }
}
