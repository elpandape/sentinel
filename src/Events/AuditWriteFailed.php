<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Events;

use ElPandaPe\Sentinel\Data\AuditData;
use Throwable;

/**
 * A deferred write that never landed. It is announced rather than thrown because the framework
 * runs commit callbacks in a loop with no guard around it: an exception here would stop every
 * later entry of the same transaction from being attempted at all, and would surface out of a
 * DB::transaction() that has already committed.
 *
 * Identity only, like AuditDiscarded, and for the same reason: the entry has been through the
 * pipeline, but an event is not the place to hand its payload around.
 *
 * It says the write did not complete, not that no entry exists. A fanout under the strict policy
 * rethrows after the primary has already sealed and stored the entry, so a listener that read this
 * as "nothing was written" would be wrong exactly when the chain is intact.
 */
final readonly class AuditWriteFailed
{
    public function __construct(
        public string $auditType,
        public string $event,
        public ?string $subjectType,
        public ?string $subjectId,
        public ?string $transactionId,
        public Throwable $failure,
    ) {}

    public static function of(AuditData $audit, Throwable $failure): self
    {
        return new self(
            $audit->audit_type,
            $audit->event,
            $audit->subject_type,
            $audit->subject_id,
            $audit->transaction_id,
            $failure,
        );
    }

    public function message(): string
    {
        $line = trans('sentinel::sentinel.ledger.write_failed', [
            'event' => $this->event,
            'type' => $this->subjectType ?? $this->auditType,
            'id' => $this->subjectId ?? '?',
            'reason' => $this->failure->getMessage(),
        ]);

        return is_string($line) ? $line : $this->failure->getMessage();
    }
}
