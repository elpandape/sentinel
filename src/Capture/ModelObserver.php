<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Capture;

use ElPandaPe\Sentinel\Enums\AuditEvent;
use ElPandaPe\Sentinel\Snapshot\SnapshotBuilder;
use Illuminate\Database\Eloquent\Model;

final readonly class ModelObserver
{
    public function __construct(
        private ModelCapture $capture,
        private ParentCapture $parents,
        private SnapshotBuilder $snapshots,
    ) {}

    public function created(Model $model): void
    {
        $this->capture->record($model, AuditEvent::Created);
    }

    public function updating(Model $model): void
    {
        $this->capture->vet($model);
    }

    // A restore is an update that clears the deletion mark, and by the time eloquent
    // fires `restored` the original is already synced: the state in the bin is gone.
    public function updated(Model $model): void
    {
        $restorePoint = $this->restorePoint($model);

        $this->capture->record(
            $model,
            $restorePoint === null ? AuditEvent::Updated : AuditEvent::Restored,
            $restorePoint,
        );

        $this->parents->record($model);
    }

    // A force delete fires `deleted` on its way to `forceDeleted`. Only the second one is the entry.
    public function deleted(Model $model): void
    {
        if ($this->isForceDeleting($model)) {
            return;
        }

        $this->capture->record($model, AuditEvent::Deleted);
    }

    public function forceDeleted(Model $model): void
    {
        $this->capture->record($model, AuditEvent::ForceDeleted);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function restorePoint(Model $model): ?array
    {
        $column = $this->deletedAtColumn($model);

        if ($column === null || $model->getRawOriginal($column) === null || $model->getAttribute($column) !== null) {
            return null;
        }

        return $this->snapshots->build($model, $model->getRawOriginal());
    }

    private function deletedAtColumn(Model $model): ?string
    {
        if (! method_exists($model, 'getDeletedAtColumn')) {
            return null;
        }

        $column = $model->getDeletedAtColumn();

        return is_string($column) ? $column : null;
    }

    private function isForceDeleting(Model $model): bool
    {
        return method_exists($model, 'isForceDeleting') && $model->isForceDeleting();
    }
}
