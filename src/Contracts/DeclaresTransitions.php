<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Contracts;

/**
 * A model that knows which moves between its states are legal. Implementing it is optional and
 * changes nothing about how a transition is recorded — only whether one that was never meant to
 * happen is allowed to become an entry.
 *
 * A contract and not a property-array, because a real state machine is logic: whether an invoice
 * may go from pending to paid can depend on the invoice. And one method rather than the two
 * exceptions a workflow package raises — Sentinel asks, it does not execute, so "not registered"
 * and "not allowed right now" reach it as the same boolean.
 */
interface DeclaresTransitions
{
    public function allowsTransition(string $attribute, bool|float|int|string|null $from, bool|float|int|string|null $to): bool;
}
