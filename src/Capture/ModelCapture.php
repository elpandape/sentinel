<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Capture;

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Diff\Diff;
use ElPandaPe\Sentinel\Diff\Pointer;
use ElPandaPe\Sentinel\Enums\AuditEvent;
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Sentinel;
use ElPandaPe\Sentinel\Snapshot\SnapshotBuilder;
use ElPandaPe\Sentinel\Snapshot\SnapshotPair;
use ElPandaPe\Sentinel\Support\AuditPolicy;
use ElPandaPe\Sentinel\Support\Config;
use ElPandaPe\Sentinel\Transitions\Machine;
use ElPandaPe\Sentinel\Transitions\State;
use ElPandaPe\Sentinel\Transitions\TransitionBuilder;
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
        $changes = $this->changes($pair);
        $moved = $this->moved($model, $event, $changes);

        return $this->recorder->record(new AuditData(
            audit_type: $moved === null ? self::AUDIT_TYPE : TransitionBuilder::AUDIT_TYPE,
            event: $moved === null ? $event->value : AuditEvent::Transition->value,
            severity: $this->severity($model, $moved === null ? $event : AuditEvent::Transition),
            occurred_at: CarbonImmutable::now(),
            subject_type: $model->getMorphClass(),
            subject_id: $this->key($model),
            before: $retained ? $pair->before : null,
            after: $retained ? $pair->after : null,
            changes: $changes,
            metadata: $moved === null ? null : ['transition' => ['attribute' => $moved]],
        ), $model);
    }

    /**
     * Asked before the save, not after it. By the time eloquent announces an update the row is
     * already written, so refusing there would leave the record holding a state the entry says
     * never happened — worse than not validating at all. Here the save is what gets abandoned.
     *
     * A model whose auditing is paused is not governed either: Sentinel refusing a move it would
     * not have recorded would be governing the workflow, which is the one thing it does not do.
     */
    public function vet(Model $model): void
    {
        if (! $this->sentinel->isRecording()) {
            return;
        }

        foreach (AuditPolicy::of($model)->transitions as $column) {
            $from = $model->getOriginal($column);
            $to = $model->getAttribute($column);

            if (! State::represents($from) || ! State::represents($to)) {
                continue;
            }

            $before = State::of($from);
            $after = State::of($to);

            if ($before !== $after) {
                Machine::allow($model, $column, $before, $after);
            }
        }
    }

    /**
     * The declared column that this update actually moved, or null when it moved none. Only an
     * update: a restore is the entry that says the record came back, and calling it a state
     * change because the update that revived it also touched the column would lose the more
     * important of the two facts.
     *
     * The whole diff stays on the entry either way. One save is one thing the application did,
     * and splitting it into a transition and an update would invent a second fact where there
     * was one — which is also what every prior art does, for the same reason.
     *
     * @param  list<array{path: string, op: string, old?: mixed, new: mixed}>|null  $changes
     */
    private function moved(Model $model, AuditEvent $event, ?array $changes): ?string
    {
        if ($event !== AuditEvent::Updated || $changes === null) {
            return null;
        }

        $paths = array_column($changes, 'path');

        foreach (AuditPolicy::of($model)->transitions as $column) {
            if (in_array(Pointer::of($column), $paths, true)) {
                return $column;
            }
        }

        return null;
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
