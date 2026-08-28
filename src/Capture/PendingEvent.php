<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Capture;

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Sentinel;
use ElPandaPe\Sentinel\Support\Config;
use ElPandaPe\Sentinel\Support\Reference;
use Illuminate\Database\Eloquent\Model;

/**
 * A fact the application states outright, rather than one Eloquent announced. It goes through the
 * same pipeline and the same ledger as an update: there is no shortcut for entries that do not
 * come from a model, and nothing about the chain is different because a human named the event.
 *
 * The terminal is explicit and returns nothing. Writing on destruction would be unpredictable
 * around exceptions, and returning the entry would be a promise this cannot keep: with the write
 * deferred to a commit, the entry does not exist yet when record() comes back, so a null return
 * would have to mean both "the pipeline discarded it" and "it has not been written yet".
 */
final class PendingEvent
{
    public const string AUDIT_TYPE = 'custom';

    private ?Reference $actor = null;

    private ?Model $subject = null;

    private ?Severity $severity = null;

    /**
     * @var list<string>
     */
    private array $tags = [];

    /**
     * @var array<string, mixed>|null
     */
    private ?array $metadata = null;

    public function __construct(
        private readonly string $name,
        private readonly Sentinel $sentinel,
        private readonly Recorder $recorder,
        private readonly Config $config,
    ) {}

    public function actor(object|string $actor, int|string|null $id = null): self
    {
        $this->actor = Reference::to($actor, $id);

        return $this;
    }

    /**
     * A fact with no subject stays subjectless. Some things that happen are not about a record,
     * and giving them one would be inventing it.
     */
    public function subject(Model $subject): self
    {
        $this->subject = $subject;

        return $this;
    }

    public function severity(Severity $severity): self
    {
        $this->severity = $severity;

        return $this;
    }

    /**
     * @param  list<string>  $tags
     */
    public function tags(array $tags): self
    {
        $this->tags = $tags;

        return $this;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function metadata(array $metadata): self
    {
        $this->metadata = $metadata;

        return $this;
    }

    public function record(): void
    {
        if (! $this->sentinel->isRecording()) {
            return;
        }

        $this->recorder->record(
            new AuditData(
                audit_type: self::AUDIT_TYPE,
                event: $this->name,
                severity: $this->severity ?? $this->config->defaultSeverity($this->name),
                occurred_at: CarbonImmutable::now(),
                subject_type: $this->subject?->getMorphClass(),
                subject_id: $this->key(),
                metadata: $this->metadata,
                tags: $this->tags,
            ),
            $this->subject,
            $this->actor,
        );
    }

    private function key(): ?string
    {
        $key = $this->subject?->getKey();

        return is_string($key) || is_int($key) ? (string) $key : null;
    }
}
