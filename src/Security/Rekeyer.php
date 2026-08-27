<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Security;

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Enums\AuditEvent;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Support\Config;

/**
 * Rotation writes; it never rewrites. The original entry is protected by its own hash and
 * the model is immutable by contract, so re-encrypting it in place would break the chain
 * it belongs to. What happens instead is a new entry carrying the same values under the
 * new key, pointing back at the one it stands in for.
 *
 * The original keeps verifying, and stays readable for as long as its key stays on the
 * keyring. It does not go through the pipeline: its values are already transformed, and
 * running them through again would encrypt what is already ciphertext.
 */
final readonly class Rekeyer
{
    public const string AUDIT_TYPE = 'security';

    public function __construct(
        private Ledger $ledger,
        private Keyring $keyring,
        private Config $config,
    ) {}

    public function rekey(Audit $audit, ?string $keyId = null): ?Audit
    {
        $target = $keyId ?? $this->keyring->current();
        $fields = $this->fields($audit);
        $source = $this->keyId($audit);

        if ($fields === [] || $source === null || $source === $target) {
            return null;
        }

        $data = $this->carry($audit, $target);

        Fields::protect($data, $fields, fn (mixed $value): mixed => $this->translate($value, $source, $target));

        return $this->ledger->write($data);
    }

    private function translate(mixed $value, string $source, string $target): mixed
    {
        return is_string($value)
            ? $this->keyring->for($target)->encrypt($this->keyring->for($source)->decrypt($value))
            : $value;
    }

    private function carry(Audit $audit, string $target): AuditData
    {
        return new AuditData(
            audit_type: self::AUDIT_TYPE,
            event: AuditEvent::Rekeyed->value,
            severity: $this->config->defaultSeverity(AuditEvent::Rekeyed),
            occurred_at: CarbonImmutable::now(),
            source: $audit->source,
            subject_type: $audit->subject_type,
            subject_id: $audit->subject_id,
            tenant_id: $audit->tenant_id,
            context: $audit->context,
            before: $audit->before,
            after: $audit->after,
            changes: $audit->changes,
            metadata: [...$audit->metadata ?? [], 'rekeyed' => [
                'audit_id' => $audit->id,
                'from' => $this->keyId($audit),
                'to' => $target,
            ]],
            encryption: ['fields' => $this->fields($audit), 'key_id' => $target],
            source_audit_id: $audit->id,
        );
    }

    /**
     * @return list<string>
     */
    private function fields(Audit $audit): array
    {
        $fields = $audit->encryption['fields'] ?? null;

        return is_array($fields) ? array_values(array_filter($fields, is_string(...))) : [];
    }

    private function keyId(Audit $audit): ?string
    {
        $keyId = $audit->encryption['key_id'] ?? null;

        return is_string($keyId) ? $keyId : null;
    }
}
