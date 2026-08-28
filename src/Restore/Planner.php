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

        $state = $this->portrait($audit);

        if ($state === null) {
            return Plan::refused(Omission::EntryStateless);
        }

        return $this->weigh($audit, $subject, $state, $fields ?? array_keys($state), $fields === null);
    }

    /**
     * The state this entry portrays. An entry is a photograph of the record at a moment, and
     * restoring it is going back to that moment: the caller points at a row of the timeline and
     * says "back to this one", not "back to whatever came before this one" — which for the first
     * entry of a record would be nothing at all.
     *
     * A deletion is the exception that proves it. Its after is empty because there is no record
     * left to photograph, so the state it portrays is the one on its before.
     *
     * @return array<string, mixed>|null
     */
    private function portrait(Audit $audit): ?array
    {
        foreach ([$audit->after, $audit->before] as $state) {
            if ($state !== null && $state !== []) {
                return $state;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $state
     * @param  list<string>  $fields
     */
    private function weigh(Audit $audit, Model $subject, array $state, array $fields, bool $whole): Plan
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
                ! array_key_exists($field, $state) => Omission::UnrecordedField,
                $field === $subject->getKeyName() => Omission::IdentityField,
                ! in_array($field, $columns, true) => Omission::UnknownField,
                default => $this->protected($field, $protections),
            };

            if ($refusal instanceof Omission) {
                $skipped[$field] = $refusal;

                continue;
            }

            $value = $this->readable($audit, $field, $state[$field], $policy);

            match (true) {
                $value instanceof Omission => $skipped[$field] = $value,
                ($current[$field] ?? null) === $value => $skipped[$field] = Omission::Unchanged,
                default => $applying[$field] = $value,
            };
        }

        return Plan::of($this->revived($subject, $state, $applying, $whole), $skipped);
    }

    /**
     * A record whose portrait does not show it deleted comes back out of the bin. Finding it there
     * and then declining to bring it back would make the search that reached past the soft-delete
     * scope pointless — and an entry written before the deletion is exactly the one a caller
     * points at to undo one.
     *
     * Only when the whole state was asked for. A caller who named the fields named what they
     * wanted, and reviving the record was not one of them.
     *
     * @param  array<string, mixed>  $state
     * @param  array<string, mixed>  $applying
     * @return array<string, mixed>
     */
    private function revived(Model $subject, array $state, array $applying, bool $whole): array
    {
        $column = method_exists($subject, 'getDeletedAtColumn') ? $subject->getDeletedAtColumn() : null;

        if (! $whole || ! is_string($column) || $subject->getAttribute($column) === null || ($state[$column] ?? null) !== null) {
            return $applying;
        }

        return [...$applying, $column => null];
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
