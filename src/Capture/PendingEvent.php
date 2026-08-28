<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Capture;

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Exceptions\ConfigurationException;
use ElPandaPe\Sentinel\Exceptions\QueryException;
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

    /**
     * What the event column holds. Refused here rather than at the ledger, for the reason the
     * label guard gives: there it would arrive as a constraint violation on a write that had
     * already sealed a chain. Worse than the labels, in fact — the name is inside the canonical
     * payload, so an engine that truncates instead of raising leaves an entry whose stored hash
     * covers a value the row no longer holds, and it never verifies again.
     */
    public const int MAX_NAME_LENGTH = 64;

    private ?Reference $actor = null;

    private ?Model $subject = null;

    private ?string $subjectId = null;

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
    ) {
        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw ConfigurationException::eventTooLong($name, self::MAX_NAME_LENGTH);
        }
    }

    public function actor(object|string $actor, int|string|null $id = null): self
    {
        $this->actor = Reference::to($actor, $id);

        return $this;
    }

    /**
     * A fact with no subject stays subjectless. Some things that happen are not about a record,
     * and giving them one would be inventing it.
     *
     * A model with no key is refused rather than half-recorded: an entry naming the type and not
     * the row says it is about something nobody can go and look at, which is worse than saying
     * nothing. It is the same refusal Reference::to() makes for an actor.
     */
    public function subject(Model $subject): self
    {
        $key = $subject->getKey();

        if (! is_string($key) && ! is_int($key)) {
            throw QueryException::unsavedModel($subject::class);
        }

        $this->subject = $subject;
        $this->subjectId = (string) $key;

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
                subject_id: $this->subjectId,
                metadata: $this->metadata,
                tags: $this->tags,
            ),
            $this->subject,
            $this->actor,
        );
    }
}
