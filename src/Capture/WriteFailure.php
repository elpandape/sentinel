<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Capture;

use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Enums\FailurePolicy;
use ElPandaPe\Sentinel\Events\AuditWriteFailed;
use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Log\LogManager;
use Throwable;

/**
 * What becomes of a write that did not complete. One place, because the answer has to be the same
 * on both branches for the question "does a broken audit take the request down with it?" to have
 * an answer at all.
 *
 * The two branches differ in one thing only, and not by preference: a write deferred to a commit
 * cannot propagate anything. The framework runs commit callbacks in a bare foreach, so throwing
 * there would stop every later entry of the same transaction from being attempted, and would
 * surface out of a DB::transaction() that has already committed — reporting the failure of
 * something that succeeded.
 *
 * Announcing and recording are not the same thing. The event says a write did not complete and
 * goes out either way; the log entry stands in for the exception nobody is going to catch, so it
 * is written only where the failure is swallowed.
 */
final readonly class WriteFailure
{
    public function __construct(
        private Config $config,
        private Dispatcher $events,
        private LogManager $log,
    ) {}

    public function inRequest(AuditData $audit, Throwable $failure): void
    {
        $event = $this->announce($audit, $failure);

        if ($this->config->writeFailurePolicy() === FailurePolicy::Throw) {
            throw $failure;
        }

        $this->record($event, $failure);
    }

    public function afterCommit(AuditData $audit, Throwable $failure): void
    {
        $this->record($this->announce($audit, $failure), $failure);
    }

    /**
     * Identity and the failure, never the payload — the same rule the event carries.
     */
    private function announce(AuditData $audit, Throwable $failure): AuditWriteFailed
    {
        $event = AuditWriteFailed::of($audit, $failure);

        $this->events->dispatch($event);

        return $event;
    }

    /**
     * Only where the failure is swallowed. Propagating it and logging it would report the same
     * thing twice, and the log is what stands in for the exception nobody is going to see.
     */
    private function record(AuditWriteFailed $event, Throwable $failure): void
    {
        $this->log->channel($this->config->logChannel())->error($event->message(), [
            'audit_type' => $event->auditType,
            'event' => $event->event,
            'subject_type' => $event->subjectType,
            'subject_id' => $event->subjectId,
            'transaction_id' => $event->transactionId,
            'exception' => $failure,
        ]);
    }
}
