<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Redaction;

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Archive\Manifest;
use ElPandaPe\Sentinel\Capture\Recorder;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Enums\AuditEvent;
use ElPandaPe\Sentinel\Exceptions\RedactionException;
use ElPandaPe\Sentinel\Integrity\Hasher;
use ElPandaPe\Sentinel\Integrity\Verifier;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Support\Config;
use ElPandaPe\Sentinel\Support\Reference;

/**
 * The only sanctioned write over an entry that is already sealed.
 *
 * It empties the six content columns of the canonical payload — not three: `changes` carries the
 * literal values, `context` carries ip, user agent, url, route and method, and `criteria` carries the
 * bindings of a mass operation. For a relation entry the content lives ONLY in `changes`, so emptying
 * before/after/metadata would redact nothing at all.
 *
 * What survives is the entry's existence, its position, the link to its neighbours and the original
 * `hash`, which the next entry's `previous_hash` still points at. What does not survive is the proof
 * of what it said. The second hash over what is left proves that the remains are the ones the
 * redaction left; it proves nothing against someone who can write the row, because nobody signs it.
 * The proof that a redaction was declared and not clandestine is the trail entry, which is chained
 * and signed like any other — and which an attacker simply does not write.
 *
 * The row is written through the query builder because the model refuses updates by contract. That is
 * the same door an unsanctioned write would use, and nothing in the row tells them apart. It is said
 * out loud here rather than left to be discovered.
 */
final readonly class Redactor
{
    public const string AUDIT_TYPE = 'security';

    /**
     * The content of the canonical payload. Emptying anything less leaves the values somewhere else in
     * the same row.
     */
    public const array CONTENT = ['context', 'before', 'after', 'changes', 'metadata', 'criteria'];

    public function __construct(
        private Audit $audits,
        private Hasher $hasher,
        private Verifier $verifier,
        private Manifest $archives,
        private Recorder $recorder,
        private Config $config,
    ) {}

    public function redact(Audit $audit, string $reason, ?Reference $actor = null): Tombstone
    {
        if ($audit->redacted_at !== null) {
            return Tombstone::of($audit);
        }

        $this->refuseWhatIsNotHere($audit);

        if (! $this->verifier->verifyEntry($audit)) {
            throw RedactionException::unverifiable($audit->stream, $audit->sequence);
        }

        $at = CarbonImmutable::now();
        $redacted = $this->emptied($audit, $reason, $at);

        $this->write($audit, $redacted);
        $this->forgetSatellites($audit);

        return Tombstone::of($redacted, $this->trail($audit, $reason, $at, $actor));
    }

    /**
     * A range that already left the hot table has no row to empty, and this version cannot reach the
     * batch that holds its content. Refusing by name is what keeps the operator from reading "redacted"
     * about a redaction that updated zero rows.
     *
     * The question goes to `holds()` and not to `batchesIn()`: a range retired with `--action=delete`
     * has a manifest row with no cold columns, which `batchesIn()` skips and `holds()` still answers.
     */
    private function refuseWhatIsNotHere(Audit $audit): void
    {
        if (! $this->archives->holds($audit->stream, $audit->sequence, $audit->sequence)) {
            return;
        }

        foreach ($this->archives->batchesIn($audit->stream, $audit->sequence, $audit->sequence) as $batch) {
            throw RedactionException::archived($audit->stream, $audit->sequence, $batch->disk, $batch->path);
        }

        throw RedactionException::retired($audit->stream, $audit->sequence);
    }

    /**
     * The entry as the redaction leaves it, in memory, so the second hash is taken over exactly what is
     * about to be stored. `context` is NOT NULL in the schema and goes to an empty array; the other five
     * go to null. The canonicaliser renders the two differently, so one byte of disagreement between
     * this and the verifier would report every tombstone as tampering.
     */
    private function emptied(Audit $audit, string $reason, CarbonImmutable $at): Audit
    {
        $redacted = clone $audit;

        $redacted->context = [];

        foreach (array_diff(self::CONTENT, ['context']) as $column) {
            $redacted->setAttribute($column, null);
        }

        $redacted->redacted_at = $at;
        $redacted->redaction_reason = $reason;
        $redacted->redacted_hash = $this->hasher->hash($redacted);

        return $redacted;
    }

    private function write(Audit $audit, Audit $redacted): void
    {
        $this->audits->newQuery()
            ->where('id', $audit->id)
            ->update([
                'context' => json_encode([], JSON_THROW_ON_ERROR),
                'before' => null,
                'after' => null,
                'changes' => null,
                'metadata' => null,
                'criteria' => null,
                'redacted_at' => $redacted->redacted_at,
                'redaction_reason' => $redacted->redaction_reason,
                'redacted_hash' => $redacted->redacted_hash,
            ]);
    }

    /**
     * The labels and the relation lines of a redacted entry, which live in their own tables and are as
     * much content as the columns are: a label can name a person and a relation line names what the
     * entry pointed at. Only the purge removed them until now, and only when removing the entry too.
     */
    private function forgetSatellites(Audit $audit): void
    {
        $audit->tags()->delete();
        $audit->relations()->delete();
    }

    /**
     * Recorded through the recorder without asking whether recording is on, the way a restoration is
     * and for the same reason: `withoutAuditing()` says not to audit what the application is about to
     * do, and destroying the contents of an entry is not that. Without this, one wrapped call would
     * destroy content and leave no trace at all.
     */
    private function trail(Audit $audit, string $reason, CarbonImmutable $at, ?Reference $actor): ?Audit
    {
        return $this->recorder->record(new AuditData(
            audit_type: self::AUDIT_TYPE,
            event: AuditEvent::Redacted->value,
            severity: $this->config->defaultSeverity(AuditEvent::Redacted),
            occurred_at: $at,
            subject_type: $audit->subject_type,
            subject_id: $audit->subject_id,
            tenant_id: $audit->tenant_id,
            metadata: ['redaction' => [
                'audit_id' => $audit->id,
                'stream' => $audit->stream,
                'sequence' => $audit->sequence,
                'reason' => $reason,
            ]],
            source_audit_id: $audit->id,
        ), null, $actor);
    }
}
