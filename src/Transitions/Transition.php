<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Transitions;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Support\Reference;

/**
 * One step of a lifeline: which column moved, between which two states, on whose say-so, why, and
 * how long the record had been in the state it just left.
 *
 * The elapsed time is not stored anywhere and is not meant to be. No prior art persists it, and
 * the reason holds here too: it is a fact about two entries rather than about either of them, and
 * an entry that carried it would be wrong the moment an earlier one was archived away.
 */
final readonly class Transition
{
    public function __construct(
        public Audit $entry,
        public string $attribute,
        public bool|float|int|string|null $from,
        public bool|float|int|string|null $to,
        public ?string $reason,
        public ?Reference $actor,
        public CarbonImmutable $occurredAt,
        public ?CarbonInterval $since,
    ) {}

    public static function of(Audit $entry, ?self $previous): self
    {
        $described = $entry->metadata['transition'] ?? null;
        $attribute = is_array($described) && is_string($described['attribute'] ?? null) ? $described['attribute'] : '';
        $reason = is_array($described) && is_string($described['reason'] ?? null) ? $described['reason'] : null;

        $line = $attribute === '' ? null : $entry->diffFor($attribute)->toArray()[0] ?? null;

        return new self(
            $entry,
            $attribute,
            self::state($line['old'] ?? null),
            self::state($line['new'] ?? null),
            $reason,
            self::actor($entry),
            $entry->occurred_at,
            $previous?->occurredAt->diffAsCarbonInterval($entry->occurred_at),
        );
    }

    private static function state(mixed $value): bool|float|int|string|null
    {
        return State::represents($value) ? State::of($value) : null;
    }

    private static function actor(Audit $entry): ?Reference
    {
        return $entry->actor_type === null || $entry->actor_id === null
            ? null
            : new Reference($entry->actor_type, $entry->actor_id);
    }
}
