<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Restore;

use Carbon\CarbonImmutable;
use Closure;
use ElPandaPe\Sentinel\Capture\Recorder;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Data\RelationLine;
use ElPandaPe\Sentinel\Diff\Diff;
use ElPandaPe\Sentinel\Enums\AuditEvent;
use ElPandaPe\Sentinel\Enums\Omission;
use ElPandaPe\Sentinel\Enums\RelationOperation;
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Events\AuditRestored;
use ElPandaPe\Sentinel\Events\AuditRestoring;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Sentinel;
use ElPandaPe\Sentinel\Snapshot\SnapshotBuilder;
use ElPandaPe\Sentinel\Support\AuditPolicy;
use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Putting a record back the way an entry found it, as one more entry rather than an erasure. The
 * history stays append-only: v1 created, v2 changed, v3 changed, v4 restored from v1.
 *
 * Auditing is paused for the save itself, so the one fact on the trail is the restoration and not
 * a restoration plus an update describing the same movement backwards. It is paused through the
 * package's own switch, which restores the previous state in a finally — prior art has a static
 * flag that stays raised when the save throws, and silently stops auditing everything after it.
 *
 * A paused model is not governed either, which is what settles a restoration that moves a declared
 * state column: it does not go through the state machine, and it is not a transition. A lifeline
 * answers which states the workflow moved through, and a correction made by an operator is not one
 * of them. What moved is still on the entry, in its changes.
 *
 * A restoration is recorded even inside withoutAuditing(), which is the one caller of the recorder
 * that does not ask whether recording is on. That switch says not to audit what the application is
 * about to do; a restoration is not that. It is the engine writing its own trail back into the
 * business model, and a trail that can put a record back without saying so is one that misleads by
 * omission about the only thing it does that is not merely observing.
 */
final readonly class Restorer
{
    public const string AUDIT_TYPE = 'restore';

    public function __construct(
        private Sentinel $sentinel,
        private Recorder $recorder,
        private Planner $planner,
        private RelationPlanner $relations,
        private SnapshotBuilder $snapshots,
        private Config $config,
        private Dispatcher $events,
    ) {}

    /**
     * @param  list<string>|null  $fields
     */
    public function restore(Audit $audit, ?array $fields = null): RestoreResult
    {
        $subject = Subject::of($audit);

        if (! $subject instanceof Model) {
            return RestoreResult::refused(Omission::SubjectMissing);
        }

        $plan = $this->planner->for($audit, $subject, $fields);

        if ($plan->refused instanceof Omission) {
            return RestoreResult::refused($plan->refused);
        }

        // Nothing to move is not a restoration. Restoring the same entry twice lands here, and
        // writing an entry for it would put a link in the chain that describes no change at all.
        if ($plan->applying === []) {
            return RestoreResult::of([], $plan->skipped);
        }

        if ($this->events->until(new AuditRestoring($audit, $subject, $plan->keys())) === false) {
            return RestoreResult::refused(Omission::Cancelled);
        }

        return $this->apply($audit, $subject, $plan);
    }

    /**
     * The relation as this entry portrays it: what it attached stays attached with the pivot it
     * left behind, what it detached stays detached. One entry again, however many pivot rows it
     * takes — the operation was one, the same way a sync() that touched three of them was.
     */
    public function restoreRelationship(Audit $audit, string $name): RestoreResult
    {
        $subject = Subject::of($audit);

        if (! $subject instanceof Model) {
            return RestoreResult::refused(Omission::SubjectMissing);
        }

        $plan = $this->relations->for($audit, $subject, $name);

        if ($plan->refused instanceof Omission) {
            return RestoreResult::refused($plan->refused);
        }

        if ($plan->applying === []) {
            return RestoreResult::of([], $plan->skipped);
        }

        if ($this->events->until(new AuditRestoring($audit, $subject, $plan->keys(), $name)) === false) {
            return RestoreResult::refused(Omission::Cancelled);
        }

        return $this->reattach($audit, $subject, $plan, $name);
    }

    private function apply(Audit $audit, Model $subject, Plan $plan): RestoreResult
    {
        return $this->settle($audit, $subject, $plan, function () use ($audit, $subject, $plan): AuditData {
            $before = $this->snapshots->build($subject, $subject->getAttributes());

            $this->sentinel->withoutAuditing(static function () use ($subject, $plan): void {
                $subject->forceFill($plan->applying)->save();
            });

            return $this->entry($audit, $subject, $plan, $before);
        });
    }

    private function reattach(Audit $audit, Model $subject, Plan $plan, string $name): RestoreResult
    {
        return $this->settle($audit, $subject, $plan, function () use ($audit, $subject, $plan, $name): AuditData {
            $lines = [];

            $this->sentinel->withoutAuditing(function () use ($subject, $plan, $name, &$lines): void {
                $relation = $subject->{$name}();

                if ($relation instanceof BelongsToMany) {
                    $lines = $this->rewire($relation, $name, $plan);
                }
            });

            return $this->relationEntry($audit, $subject, $plan, $lines);
        });
    }

    /**
     * The whole restoration is one database transaction: either the applicable set goes back and
     * the ledger settles the entry, or the record is untouched. The entry is asked for by callback
     * because the ledger may be waiting for that same commit — with the deferral on, which is the
     * default, every restoration takes that path, since the transaction opened here is one.
     *
     * What the call returns is what was true when it returned, which inside a transaction of the
     * application's own is a result with no entry: the record moved, the entry is still waiting for
     * a commit that has not happened. The event does not have that problem, because it is announced
     * from a commit callback registered after the ledger's own — so whichever branch wrote the
     * entry, it exists by the time a listener is told a restoration did.
     *
     * @param  Closure(): AuditData  $entry
     */
    private function settle(Audit $audit, Model $subject, Plan $plan, Closure $entry): RestoreResult
    {
        $connection = $subject->getConnection();
        $result = RestoreResult::of($plan->keys(), $plan->skipped);

        $connection->transaction(function () use ($audit, $subject, $plan, $entry, $connection, &$result): void {
            $data = $entry();

            $this->recorder->record($data, $subject, settled: static function (Audit $written) use ($plan, &$result): void {
                $result = RestoreResult::of($plan->keys(), $plan->skipped, $written);
            });

            $connection->afterCommit(function () use ($audit, $subject, &$result): void {
                $this->events->dispatch(new AuditRestored($audit, $subject, $result));
            });
        });

        return $result;
    }

    /**
     * @param  BelongsToMany<Model, Model, Pivot>  $relation
     * @return list<RelationLine>
     */
    private function rewire(BelongsToMany $relation, string $name, Plan $plan): array
    {
        $lines = [];

        foreach ($plan->applying as $step) {
            /** @var array{operation: string, id: string, pivot: array<string, mixed>, was: array<string, mixed>|null} $step */
            $operation = RelationOperation::from($step['operation']);

            match ($operation) {
                RelationOperation::Attach => $relation->attach($step['id'], $step['pivot']),
                RelationOperation::Detach => $relation->detach($step['id']),
                RelationOperation::Update => $relation->updateExistingPivot($step['id'], $step['pivot']),
            };

            $lines[] = new RelationLine(
                $name,
                $operation,
                $relation->getRelated()->getMorphClass(),
                $step['id'],
                $step['was'],
                $this->relations->pivot($relation, $step['id']),
            );
        }

        return $lines;
    }

    /**
     * @param  list<RelationLine>  $lines
     */
    private function relationEntry(Audit $audit, Model $subject, Plan $plan, array $lines): AuditData
    {
        $key = $subject->getKey();

        return new AuditData(
            audit_type: self::AUDIT_TYPE,
            event: AuditEvent::Restore->value,
            severity: $this->severity($subject),
            occurred_at: CarbonImmutable::now(),
            subject_type: $subject->getMorphClass(),
            subject_id: is_string($key) || is_int($key) ? (string) $key : null,
            changes: RelationLine::canonical($lines),
            metadata: ['restore' => $this->summary($plan)],
            source_audit_id: $audit->id,
        );
    }

    /**
     * @param  array<string, mixed>  $before
     */
    private function entry(Audit $audit, Model $subject, Plan $plan, array $before): AuditData
    {
        $after = $this->snapshots->build($subject, $subject->getAttributes());
        $retained = $this->snapshots->retains($subject);
        $key = $subject->getKey();

        return new AuditData(
            audit_type: self::AUDIT_TYPE,
            event: AuditEvent::Restore->value,
            severity: $this->severity($subject),
            occurred_at: CarbonImmutable::now(),
            subject_type: $subject->getMorphClass(),
            subject_id: is_string($key) || is_int($key) ? (string) $key : null,
            before: $retained ? $before : null,
            after: $retained ? $after : null,
            changes: Diff::between($before, $after)->toArray(),
            metadata: ['restore' => $this->summary($plan)],
            source_audit_id: $audit->id,
        );
    }

    /**
     * What the restoration decided, inside the payload the chain seals. An entry that said only
     * what it applied would leave the reader unable to tell a field that was never asked for from
     * one that a lost key refused. The plan hands both back in a fixed order, which is what keeps
     * the same restoration producing the same bytes on every engine.
     *
     * A list of pairs and not a map keyed by field, because the security stages match a protected
     * field by key name at any depth of metadata: keyed by field, the reason a redacted field was
     * skipped got masked in place of the field, and a hashed one got a digest — the transformation
     * landing on the explanation rather than on anything worth hiding, sealed where nothing can
     * correct it afterwards.
     *
     * @return array{applied: list<string>, skipped: list<array{field: string, reason: string}>}
     */
    private function summary(Plan $plan): array
    {
        $skipped = [];

        foreach ($plan->skipped as $field => $reason) {
            $skipped[] = ['field' => $field, 'reason' => $reason->value];
        }

        return ['applied' => $plan->keys(), 'skipped' => $skipped];
    }

    private function severity(Model $subject): Severity
    {
        return AuditPolicy::of($subject)->severity ?? $this->config->defaultSeverity(AuditEvent::Restore);
    }
}
