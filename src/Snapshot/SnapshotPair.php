<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Snapshot;

/**
 * Null means the state does not apply to the event; an empty map means the state
 * applied and was empty. The difference is audited information.
 */
final readonly class SnapshotPair
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function __construct(public ?array $before = null, public ?array $after = null) {}
}
