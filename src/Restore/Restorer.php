<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Restore;

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Capture\Recorder;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Diff\Diff;
use ElPandaPe\Sentinel\Enums\AuditEvent;
use ElPandaPe\Sentinel\Enums\Omission;
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
 */
final readonly class Restorer
{
    public const string AUDIT_TYPE = 'restore';

    public function __construct(
        private Sentinel $sentinel,
        private Recorder $recorder,
        private Planner $planner,
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

        $result = $this->apply($audit, $subject, $plan);

        $this->events->dispatch(new AuditRestored($audit, $subject, $result));

        return $result;
    }

    /**
     * The whole restoration is one database transaction: either the applicable set goes back and
     * the ledger settles the entry, or the record is untouched. The entry is asked for by callback
     * because the ledger may be waiting for that same commit — with the deferral on, which is the
     * default, every restoration takes that path, since the transaction opened here is one.
     */
    private function apply(Audit $audit, Model $subject, Plan $plan): RestoreResult
    {
        $entry = null;

        $subject->getConnection()->transaction(function () use ($audit, $subject, $plan, &$entry): void {
            $before = $this->snapshots->build($subject, $subject->getAttributes());

            $this->sentinel->withoutAuditing(static function () use ($subject, $plan): void {
                $subject->forceFill($plan->applying)->save();
            });

            $this->recorder->record(
                $this->entry($audit, $subject, $plan, $before),
                $subject,
                settled: static function (Audit $written) use (&$entry): void {
                    $entry = $written;
                },
            );
        });

        return RestoreResult::of($plan->keys(), $plan->skipped, $entry);
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
     * one that a lost key refused.
     *
     * @return array{applied: list<string>, skipped: array<string, string>}
     */
    private function summary(Plan $plan): array
    {
        $applied = $plan->keys();
        $skipped = array_map(static fn (Omission $reason): string => $reason->value, $plan->skipped);

        sort($applied);
        ksort($skipped);

        return ['applied' => $applied, 'skipped' => $skipped];
    }

    private function severity(Model $subject): Severity
    {
        return AuditPolicy::of($subject)->severity ?? $this->config->defaultSeverity(AuditEvent::Restore);
    }
}
