<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Transitions;

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Capture\Recorder;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Diff\Change;
use ElPandaPe\Sentinel\Diff\Pointer;
use ElPandaPe\Sentinel\Enums\AuditEvent;
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Exceptions\ConfigurationException;
use ElPandaPe\Sentinel\Exceptions\QueryException;
use ElPandaPe\Sentinel\Sentinel;
use ElPandaPe\Sentinel\Support\AuditPolicy;
use ElPandaPe\Sentinel\Support\Config;
use ElPandaPe\Sentinel\Support\Reference;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * A state change stated outright, rather than inferred from a column that happens to have moved.
 * It is an entry of its own kind — not an update that a reader has to recognise — so the lifeline
 * of a document is something the trail answers instead of something a diff has to be mined for.
 *
 * The terminal is record() and it is explicit, for the reason PendingEvent gives: with the write
 * waiting for a commit, the entry does not exist yet when the call comes back, so it can promise
 * nothing about it. A builder whose modifiers all chain cannot have one of them close the call
 * either — reason() is a modifier like the rest.
 */
final class TransitionBuilder
{
    public const string AUDIT_TYPE = 'transition';

    private readonly string $subjectId;

    private readonly bool|float|int|string|null $from;

    private readonly bool|float|int|string|null $to;

    private ?string $attribute = null;

    private ?string $reason = null;

    private ?Reference $actor = null;

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
        private readonly Model $subject,
        bool|float|int|string|UnitEnum|null $from,
        bool|float|int|string|UnitEnum|null $to,
        private readonly Sentinel $sentinel,
        private readonly Recorder $recorder,
        private readonly Config $config,
    ) {
        $key = $subject->getKey();

        if (! is_string($key) && ! is_int($key)) {
            throw QueryException::unsavedModel($subject::class);
        }

        $this->subjectId = (string) $key;
        $this->from = State::of($from);
        $this->to = State::of($to);
    }

    /**
     * Which column moved. It is what the change line is filed under, so whereFieldChanged('status')
     * reaches a transition the same way it reaches any other change to that column.
     */
    public function on(string $attribute): self
    {
        $this->attribute = $attribute;

        return $this;
    }

    public function reason(string $reason): self
    {
        $this->reason = $reason;

        return $this;
    }

    public function actor(object|string $actor, int|string|null $id = null): self
    {
        $this->actor = Reference::to($actor, $id);

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

        $attribute = $this->attribute ?? $this->declared() ?? $this->config->transitionAttribute();

        Machine::allow($this->subject, $attribute, $this->from, $this->to);

        $this->recorder->record(
            new AuditData(
                audit_type: self::AUDIT_TYPE,
                event: AuditEvent::Transition->value,
                severity: $this->severity ?? $this->config->defaultSeverity(AuditEvent::Transition),
                occurred_at: CarbonImmutable::now(),
                subject_type: $this->subject->getMorphClass(),
                subject_id: $this->subjectId,
                changes: [new Change(Pointer::of($attribute), 'replace', $this->from, $this->to)->toArray()],
                metadata: $this->described($attribute),
                tags: $this->tags,
            ),
            $this->subject,
            $this->actor,
        );
    }

    /**
     * A model that declares one state column has named it already, and repeating it at every call
     * site would be a second place to get it wrong. Two or more and there is nothing to infer: the
     * call has to say which one moved, because falling through to the configured default would
     * file the change under a column that did not move — silently, and on a model that had gone to
     * the trouble of declaring its own.
     */
    private function declared(): ?string
    {
        $columns = AuditPolicy::of($this->subject)->transitions;

        return match (count($columns)) {
            0 => null,
            1 => $columns[0],
            default => throw ConfigurationException::ambiguousTransition($this->subject::class, $columns),
        };
    }

    /**
     * The reason and the column travel together under one key rather than loose in metadata,
     * because the caller's own metadata() lands in the same array and a bare `reason` there is
     * theirs, not ours.
     *
     * @return array<string, mixed>
     */
    private function described(string $attribute): array
    {
        $transition = ['attribute' => $attribute];

        if ($this->reason !== null) {
            $transition['reason'] = $this->reason;
        }

        return [...$this->metadata ?? [], 'transition' => $transition];
    }
}
