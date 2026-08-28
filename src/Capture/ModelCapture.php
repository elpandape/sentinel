<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Capture;

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Diff\Diff;
use ElPandaPe\Sentinel\Enums\AuditEvent;
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Sentinel;
use ElPandaPe\Sentinel\Snapshot\SnapshotBuilder;
use ElPandaPe\Sentinel\Snapshot\SnapshotPair;
use ElPandaPe\Sentinel\Support\AuditPolicy;
use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Database\Eloquent\Model;

final readonly class ModelCapture
{
    public const string AUDIT_TYPE = 'model';

    public function __construct(
        private Sentinel $sentinel,
        private Recorder $recorder,
        private SnapshotBuilder $snapshots,
        private Config $config,
    ) {}

    /**
     * @param  array<string, mixed>|null  $restorePoint
     */
    public function record(Model $model, AuditEvent $event, ?array $restorePoint = null): ?Audit
    {
        if (! $this->sentinel->isRecording()) {
            return null;
        }

        $pair = $this->snapshots->pair($model, $event, $restorePoint);
        $retained = $this->snapshots->retains($model);

        return $this->recorder->record(new AuditData(
            audit_type: self::AUDIT_TYPE,
            event: $event->value,
            severity: $this->severity($model, $event),
            occurred_at: CarbonImmutable::now(),
            subject_type: $model->getMorphClass(),
            subject_id: $this->key($model),
            before: $retained ? $pair->before : null,
            after: $retained ? $pair->after : null,
            changes: $this->changes($pair),
        ), $model);
    }

    /**
     * Null keeps meaning "does not apply": an event with no pair has nothing to compare.
     * An empty list means the comparison ran and found nothing.
     *
     * @return list<array{path: string, op: string, old?: mixed, new: mixed}>|null
     */
    private function changes(SnapshotPair $pair): ?array
    {
        if ($pair->before === null && $pair->after === null) {
            return null;
        }

        return Diff::between($pair->before ?? [], $pair->after ?? [])->toArray();
    }

    private function key(Model $model): ?string
    {
        $key = $model->getKey();

        return is_string($key) || is_int($key) ? (string) $key : null;
    }

    private function severity(Model $model, AuditEvent $event): Severity
    {
        return AuditPolicy::of($model)->severity ?? $this->config->defaultSeverity($event);
    }
}
