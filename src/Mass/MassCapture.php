<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Mass;

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Capture\Recorder;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Diff\Diff;
use ElPandaPe\Sentinel\Enums\AuditEvent;
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Snapshot\SnapshotBuilder;
use ElPandaPe\Sentinel\Support\AuditCollection;
use ElPandaPe\Sentinel\Support\AuditPolicy;
use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Database\Eloquent\Model;

/**
 * The entries a mass operation leaves. Two kinds, and they answer different questions: the summary
 * says what was aimed at and how much it hit, and an individual says what happened to one row.
 *
 * A summary has no subject id because there is no one row — there is a set — and that is the shape
 * the column was always going to take for this kind of entry rather than a gap in it. What it does
 * not have is a before: nothing was read, so there is no earlier state, and inventing one is the
 * thing this whole version is careful not to do.
 *
 * Neither kind repeats the other. An individual carries no criteria and no count, because the
 * summary it shares a transaction with carries both, and three thousand copies of one fact is not
 * three thousand facts.
 */
final readonly class MassCapture
{
    public const string AUDIT_TYPE = 'mass';

    public function __construct(
        private Recorder $recorder,
        private SnapshotBuilder $snapshots,
        private Config $config,
    ) {}

    /**
     * @param  Operation<covariant Model>  $operation
     */
    public function summary(Operation $operation, int $rows): ?Audit
    {
        $model = $operation->model();

        return $this->recorder->record(new AuditData(
            audit_type: self::AUDIT_TYPE,
            event: $operation->event->value,
            severity: $this->severity($model, $operation->event),
            occurred_at: CarbonImmutable::now(),
            subject_type: $model->getMorphClass(),
            changes: $operation->writes->changes === [] ? null : $operation->writes->changes,
            metadata: ['mass' => ['mode' => $operation->mode->value]],
            criteria: $operation->criteria,
            affected_rows: $rows,
        ), $model);
    }

    /**
     * One entry per row that was read before the statement ran, written in a single batch so the
     * chain is extended once rather than once per row.
     *
     * @param  Operation<covariant Model>  $operation
     * @param  list<Model>  $rows
     * @return AuditCollection<int, Audit>
     */
    public function individual(Operation $operation, array $rows): AuditCollection
    {
        $model = $operation->model();

        return $this->recorder->recordMany(
            array_map(fn (Model $row): AuditData => $this->row($operation, $row), $rows),
            $model,
        );
    }

    /**
     * @param  Operation<covariant Model>  $operation
     */
    private function row(Operation $operation, Model $row): AuditData
    {
        $before = $this->snapshots->build($row, $row->getAttributes());
        $after = $this->after($operation, $before);
        $retained = $this->snapshots->retains($row);

        return new AuditData(
            audit_type: self::AUDIT_TYPE,
            event: $operation->event->value,
            severity: $this->severity($row, $operation->event),
            occurred_at: CarbonImmutable::now(),
            subject_type: $row->getMorphClass(),
            subject_id: $this->key($row),
            before: $retained ? $before : null,
            after: $retained ? $after : null,
            changes: $after === null ? null : Diff::between($before, $after)->toArray(),
        );
    }

    /**
     * What the row became, or nothing at all. A delete leaves no later state, and an update writing
     * a column from an SQL expression leaves one this cannot compute: the expression is the
     * database's to evaluate, and an after carrying that column's earlier value would say it did
     * not move. One side or the other, never a mixture of the two.
     *
     * @param  Operation<covariant Model>  $operation
     * @param  array<string, mixed>  $before
     * @return array<string, mixed>|null
     */
    private function after(Operation $operation, array $before): ?array
    {
        if ($operation->event !== AuditEvent::Updated || $operation->writes->opaque !== []) {
            return null;
        }

        $after = $before;

        foreach ($operation->writes->literals as $column => $value) {
            if (array_key_exists($column, $after)) {
                $after[$column] = $value;
            }
        }

        return $after;
    }

    private function key(Model $row): ?string
    {
        $key = $row->getKey();

        return is_string($key) || is_int($key) ? (string) $key : null;
    }

    private function severity(Model $model, AuditEvent $event): Severity
    {
        return AuditPolicy::of($model)->severity ?? $this->config->defaultSeverity($event);
    }
}
