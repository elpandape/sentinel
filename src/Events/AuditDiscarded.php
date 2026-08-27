<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Events;

use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Pipeline\Discarded;
use Illuminate\Support\Str;

/**
 * Identity only. FilterUnchanged runs before redaction and encryption, so an event
 * carrying before, after, changes or metadata would be the exact route by which the
 * plaintext escaped the pipeline that exists to transform it.
 */
final readonly class AuditDiscarded
{
    /**
     * @param  class-string  $stage
     */
    public function __construct(
        public string $auditType,
        public string $event,
        public ?string $subjectType,
        public ?string $subjectId,
        public string $stage,
        public string $reason,
    ) {}

    public static function of(AuditData $audit, Discarded $discarded): self
    {
        return new self(
            $audit->audit_type,
            $audit->event,
            $audit->subject_type,
            $audit->subject_id,
            $discarded->stage,
            $discarded->reason,
        );
    }

    /**
     * A reason the package ships is a translation key; one an application stage gives is
     * whatever it wrote, and it comes back untouched.
     */
    public function message(): string
    {
        $key = 'sentinel::sentinel.discarded.'.$this->reason;

        $line = trans($key, [
            'event' => $this->event,
            'type' => $this->subjectType ?? $this->auditType,
            'id' => $this->subjectId ?? '?',
            'stage' => Str::afterLast($this->stage, '\\'),
        ]);

        return is_string($line) && $line !== $key ? $line : $this->reason;
    }
}
