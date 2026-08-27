<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Capture;

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Enums\AuditEvent;
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Enums\Source;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Sentinel;
use ElPandaPe\Sentinel\Snapshot\SnapshotBuilder;
use ElPandaPe\Sentinel\Support\AuditPolicy;
use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Database\Eloquent\Model;

final readonly class ModelCapture
{
    public const string AUDIT_TYPE = 'model';

    public function __construct(
        private Sentinel $sentinel,
        private Ledger $ledger,
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

        return $this->ledger->write(new AuditData(
            audit_type: self::AUDIT_TYPE,
            event: $event->value,
            severity: $this->severity($model, $event),
            // Context resolution lands in v0.6.0; until then an entry says where it did not come from.
            source: Source::System,
            occurred_at: CarbonImmutable::now(),
            subject_type: $model->getMorphClass(),
            subject_id: $this->key($model),
            before: $pair->before,
            after: $pair->after,
        ));
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
