<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Pipeline\Stages;

use Closure;
use ElPandaPe\Sentinel\Contracts\Transformer;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Security\Fields;
use ElPandaPe\Sentinel\Security\Keyring;
use ElPandaPe\Sentinel\Support\Config;
use ElPandaPe\Sentinel\Support\PolicyRegistry;

/**
 * The ciphertext replaces the value inline, in the same key. Wrapping the field in an object
 * would fork the canonical payload and cost a payload_version for nothing: the shape of a
 * snapshot does not change, the value does.
 *
 * The hash is then computed over the ciphertext, never over the plaintext, so verification
 * runs in an environment that holds no key at all.
 */
final readonly class EncryptSensitiveData implements Transformer
{
    public function __construct(
        private PolicyRegistry $policies,
        private Config $config,
        private Keyring $keyring,
    ) {}

    /**
     * @param  Closure(AuditData): ?AuditData  $next
     */
    public function handle(AuditData $audit, Closure $next): ?AuditData
    {
        $declared = $this->config->encryptedFields($this->policies->for($audit->subject_type)->encrypted);

        if ($declared === []) {
            return $next($audit);
        }

        $keyId = $this->keyring->current();
        $encrypter = $this->keyring->for($keyId);

        $encrypted = Fields::protect(
            $audit,
            $declared,
            static fn (mixed $value): mixed => $value === null ? null : $encrypter->encrypt($value),
        );

        if ($encrypted !== []) {
            $audit->encryption = ['fields' => $encrypted, 'key_id' => $keyId];
        }

        return $next($audit);
    }
}
