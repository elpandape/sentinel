<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Capture;

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Data\RelationLine;
use ElPandaPe\Sentinel\Enums\AuditEvent;
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Pipeline\Pipeline;
use ElPandaPe\Sentinel\Sentinel;
use ElPandaPe\Sentinel\Support\AuditPolicy;
use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Database\Eloquent\Model;

/**
 * One business operation, one entry. A sync() that attaches two and detaches one is a single thing
 * the application did, and splitting it into three entries would describe the implementation rather
 * than the decision.
 *
 * The subject is the parent — the model the relation hangs off — so a relation entry lands in the
 * same trail as that model's own changes and orders alongside them.
 *
 * event has three names for six APIs, which is what the data model publishes, so the API that was
 * actually called travels in metadata. That is inside the canonical payload, so the record of how
 * the change was made is as tamper-evident as the change itself.
 */
final readonly class RelationCapture
{
    public const string AUDIT_TYPE = 'relation';

    public function __construct(
        private Sentinel $sentinel,
        private Ledger $ledger,
        private Config $config,
        private Pipeline $pipeline,
    ) {}

    /**
     * Asked before the pivot rows are photographed, not after: reading them twice around an
     * operation is the cost of auditing it, and an installation that is not recording should not
     * pay it.
     */
    public function recording(): bool
    {
        return $this->sentinel->isRecording();
    }

    /**
     * @param  list<RelationLine>  $lines
     */
    public function record(Model $parent, string $api, AuditEvent $event, array $lines): ?Audit
    {
        $audit = $this->pipeline->process(new AuditData(
            audit_type: self::AUDIT_TYPE,
            event: $event->value,
            severity: $this->severity($parent, $event),
            occurred_at: CarbonImmutable::now(),
            subject_type: $parent->getMorphClass(),
            subject_id: $this->key($parent),
            changes: RelationLine::canonical($lines),
            metadata: ['api' => $api],
        ));

        return $audit instanceof AuditData ? $this->ledger->write($audit) : null;
    }

    private function key(Model $parent): ?string
    {
        $key = $parent->getKey();

        return is_string($key) || is_int($key) ? (string) $key : null;
    }

    private function severity(Model $parent, AuditEvent $event): Severity
    {
        return AuditPolicy::of($parent)->severity ?? $this->config->defaultSeverity($event);
    }
}
