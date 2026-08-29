<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Data;

use DateTimeImmutable;
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Enums\Source;
use ElPandaPe\Sentinel\Exceptions\DispatchException;

/**
 * The entry as the capture knows it. Fields are named after their columns because
 * the canonical payload is too, and a translation layer between capture and hash
 * is where silent integrity bugs come from.
 */
final class AuditData
{
    private const string CLOCK = 'Y-m-d\\TH:i:s.uP';

    /**
     * @var list<string>
     */
    private const array LEDGERS_OWN = ['sequence', 'hash', 'previous_hash'];

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<array-key, mixed>|null  $changes
     * @param  array<string, mixed>|null  $metadata
     * @param  array<string, mixed>|null  $encryption
     * @param  array<array-key, mixed>|null  $criteria
     */
    public function __construct(
        public string $audit_type,
        public string $event,
        public Severity $severity,
        public DateTimeImmutable $occurred_at,
        public Source $source = Source::System,
        public ?string $stream = null,
        public ?string $subject_type = null,
        public ?string $subject_id = null,
        public ?string $actor_type = null,
        public ?string $actor_id = null,
        public ?string $impersonator_type = null,
        public ?string $impersonator_id = null,
        public ?string $tenant_id = null,
        public ?string $transaction_id = null,
        public ?string $request_id = null,
        public ?string $trace_id = null,
        public ?string $span_id = null,
        public array $context = [],
        public ?array $before = null,
        public ?array $after = null,
        public ?array $changes = null,
        public ?array $metadata = null,
        public ?array $encryption = null,
        public ?array $criteria = null,
        public ?int $affected_rows = null,
        public ?string $source_audit_id = null,
        public ?string $capture_id = null,
        /** @var list<string> */
        public array $tags = [],
    ) {}

    /**
     * The entry as something that can cross a process boundary. Written out by hand rather than
     * serialised as an object, because a worker running last week's code has to be able to read a
     * payload written by this week's: PHP would hand it an object with an uninitialised property
     * and fail on first access, where this drops what it does not recognise and fills in what is
     * missing with the same defaults the constructor has.
     *
     * The clock goes out with microseconds and an offset. It is inside the canonical payload, so a
     * round trip that rounded it would produce a different hash for the same fact depending on
     * which mode wrote it.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'audit_type' => $this->audit_type,
            'event' => $this->event,
            'severity' => $this->severity->value,
            'occurred_at' => $this->occurred_at->format(self::CLOCK),
            'source' => $this->source->value,
            'stream' => $this->stream,
            'subject_type' => $this->subject_type,
            'subject_id' => $this->subject_id,
            'actor_type' => $this->actor_type,
            'actor_id' => $this->actor_id,
            'impersonator_type' => $this->impersonator_type,
            'impersonator_id' => $this->impersonator_id,
            'tenant_id' => $this->tenant_id,
            'transaction_id' => $this->transaction_id,
            'request_id' => $this->request_id,
            'trace_id' => $this->trace_id,
            'span_id' => $this->span_id,
            'context' => $this->context,
            'before' => $this->before,
            'after' => $this->after,
            'changes' => $this->changes,
            'metadata' => $this->metadata,
            'encryption' => $this->encryption,
            'criteria' => $this->criteria,
            'affected_rows' => $this->affected_rows,
            'source_audit_id' => $this->source_audit_id,
            'capture_id' => $this->capture_id,
            'tags' => $this->tags,
        ];
    }

    /**
     * The entry as it arrives from wherever it waited. A payload naming a stream position is
     * refused outright: the sequence, the hash and the link are the ledger's to assign, inside the
     * same operation as the write, and a capture that proposed one would be proposing where in the
     * chain a fact belongs before the chain has been read.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(array $payload): self
    {
        foreach (self::LEDGERS_OWN as $column) {
            if (array_key_exists($column, $payload)) {
                throw DispatchException::proposedItsOwnPlaceInTheChain($column);
            }
        }

        return new self(
            audit_type: self::required($payload, 'audit_type'),
            event: self::required($payload, 'event'),
            severity: Severity::tryFrom(self::text($payload, 'severity') ?? '') ?? Severity::Info,
            occurred_at: new DateTimeImmutable(self::required($payload, 'occurred_at')),
            source: Source::tryFrom(self::text($payload, 'source') ?? '') ?? Source::System,
            stream: self::text($payload, 'stream'),
            subject_type: self::text($payload, 'subject_type'),
            subject_id: self::text($payload, 'subject_id'),
            actor_type: self::text($payload, 'actor_type'),
            actor_id: self::text($payload, 'actor_id'),
            impersonator_type: self::text($payload, 'impersonator_type'),
            impersonator_id: self::text($payload, 'impersonator_id'),
            tenant_id: self::text($payload, 'tenant_id'),
            transaction_id: self::text($payload, 'transaction_id'),
            request_id: self::text($payload, 'request_id'),
            trace_id: self::text($payload, 'trace_id'),
            span_id: self::text($payload, 'span_id'),
            context: self::keyed($payload, 'context') ?? [],
            before: self::keyed($payload, 'before'),
            after: self::keyed($payload, 'after'),
            changes: self::map($payload, 'changes'),
            metadata: self::keyed($payload, 'metadata'),
            encryption: self::keyed($payload, 'encryption'),
            criteria: self::map($payload, 'criteria'),
            affected_rows: self::count($payload, 'affected_rows'),
            source_audit_id: self::text($payload, 'source_audit_id'),
            capture_id: self::text($payload, 'capture_id'),
            tags: self::labels($payload),
        );
    }

    /**
     * What the entry cannot be read without: what kind of fact it is, what happened, and when. A
     * missing enum falls back to its default instead — those are lists that grow, and a worker that
     * has not learned a new severity yet should lose the shade of meaning rather than the entry.
     *
     * @param  array<string, mixed>  $payload
     */
    private static function required(array $payload, string $key): string
    {
        return self::text($payload, $key) ?? throw DispatchException::incompletePayload($key);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function text(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * The lists the entry carries: the diff and the criteria of a mass operation, both of which are
     * lists of objects rather than maps.
     *
     * @param  array<string, mixed>  $payload
     * @return array<array-key, mixed>|null
     */
    private static function map(array $payload, string $key): ?array
    {
        $value = $payload[$key] ?? null;

        return is_array($value) ? $value : null;
    }

    /**
     * The maps: the two snapshots, the context, the metadata and the encryption block. Their keys
     * are field names, so they are strings wherever this package wrote them — and where it did not,
     * the entry goes on carrying whatever it arrived with rather than being reshaped on the way in.
     * Reshaping is what would make the same fact hash differently depending on which mode wrote it.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    private static function keyed(array $payload, string $key): ?array
    {
        $value = $payload[$key] ?? null;

        if (! is_array($value)) {
            return null;
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function count(array $payload, string $key): ?int
    {
        $value = $payload[$key] ?? null;

        return is_int($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private static function labels(array $payload): array
    {
        $value = $payload['tags'] ?? null;

        return is_array($value) ? array_values(array_filter($value, is_string(...))) : [];
    }
}
