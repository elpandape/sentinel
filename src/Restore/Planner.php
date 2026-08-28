<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Restore;

use ElPandaPe\Sentinel\Enums\Omission;
use ElPandaPe\Sentinel\Integrity\Verifier;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Security\Keyring;
use ElPandaPe\Sentinel\Snapshot\SnapshotBuilder;
use ElPandaPe\Sentinel\Support\AuditPolicy;
use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Whether each field of an entry can go back on the record, and why not when it cannot.
 *
 * Two refusals are about the entry as a whole and are absolute. A redacted entry was emptied on
 * purpose, and a tampered one no longer reproduces its own hash: restoring is the only thing the
 * package does that writes into the business model out of what the ledger holds, so an entry that
 * cannot answer for itself writes nothing at all. Every other refusal is about one field and lets
 * the rest through — a column a later migration dropped is an accident of the schema, not a reason
 * to abandon the five fields that are still there.
 *
 * Both sides of every comparison are snapshots, built by the one builder that decides what a value
 * looks like when it is written down. Comparing the stored string against the live carbon instance
 * would report a change on every date the record holds.
 */
final readonly class Planner
{
    public function __construct(
        private SnapshotBuilder $snapshots,
        private Config $config,
        private Keyring $keyring,
        private Verifier $verifier,
    ) {}

    /**
     * @param  list<string>|null  $fields  null asks for everything the entry recorded
     */
    public function for(Audit $audit, ?Model $subject, ?array $fields = null): Plan
    {
        if (! $subject instanceof Model) {
            return Plan::refused(Omission::SubjectMissing);
        }

        if ($audit->redacted_at !== null) {
            return Plan::refused(Omission::EntryRedacted);
        }

        if (! $this->verifier->verifyEntry($audit)) {
            return Plan::refused(Omission::EntryTampered);
        }

        $before = $audit->before;

        if ($before === null || $before === []) {
            return Plan::refused(Omission::EntryStateless);
        }

        return $this->weigh($audit, $subject, $before, $fields ?? array_keys($before));
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  list<string>  $fields
     */
    private function weigh(Audit $audit, Model $subject, array $before, array $fields): Plan
    {
        $policy = AuditPolicy::of($subject);
        $current = $this->snapshots->build($subject, $subject->getAttributes());
        $columns = $subject->getConnection()->getSchemaBuilder()->getColumnListing($subject->getTable());

        $protections = [
            Omission::RedactedField->value => $this->config->redactedFields($policy->redacted),
            Omission::HashedField->value => $this->config->hashedFields($policy->hashed),
        ];

        $applying = [];
        $skipped = [];

        foreach ($fields as $field) {
            $refusal = match (true) {
                ! array_key_exists($field, $before) => Omission::UnrecordedField,
                $field === $subject->getKeyName() => Omission::IdentityField,
                ! in_array($field, $columns, true) => Omission::UnknownField,
                default => $this->protected($field, $protections),
            };

            if ($refusal instanceof Omission) {
                $skipped[$field] = $refusal;

                continue;
            }

            $value = $this->readable($audit, $field, $before[$field], $policy);

            match (true) {
                $value instanceof Omission => $skipped[$field] = $value,
                ($current[$field] ?? null) === $value => $skipped[$field] = Omission::Unchanged,
                default => $applying[$field] = $value,
            };
        }

        return Plan::of($applying, $skipped);
    }

    /**
     * @param  array<string, list<string>>  $protections
     */
    private function protected(string $field, array $protections): ?Omission
    {
        foreach ($protections as $reason => $fields) {
            if (in_array($field, $fields, true)) {
                return Omission::from($reason);
            }
        }

        return null;
    }

    /**
     * The value as the record would hold it again, or why it cannot be one. A field the model
     * declares encrypted but that this entry stored in the clear predates the declaration, and it
     * goes back as it is: how an entry was written decides how it is read, not what the model
     * says today.
     */
    private function readable(Audit $audit, string $field, mixed $value, AuditPolicy $policy): mixed
    {
        $encryption = $audit->encryption;
        $encrypted = is_array($encryption['fields'] ?? null) ? $encryption['fields'] : [];
        $keyId = $encryption['key_id'] ?? null;

        if (! in_array($field, $this->config->encryptedFields($policy->encrypted), true) || ! in_array($field, $encrypted, true)) {
            return $value;
        }

        try {
            return $this->keyring->for(is_string($keyId) ? $keyId : $this->keyring->current())->decrypt(is_string($value) ? $value : '');
        } catch (Throwable) {
            return Omission::KeyUnavailable;
        }
    }
}
