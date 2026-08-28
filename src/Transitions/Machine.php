<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Transitions;

use ElPandaPe\Sentinel\Contracts\DeclaresTransitions;
use Illuminate\Database\Eloquent\Model;

/**
 * The one place a model is asked whether a move is one it makes. A model that declares no
 * machine consents to everything, and that is the default on purpose: Sentinel records the
 * transition, it does not govern the workflow.
 */
final class Machine
{
    public static function allow(Model $subject, string $attribute, bool|float|int|string|null $from, bool|float|int|string|null $to): void
    {
        if ($subject instanceof DeclaresTransitions && ! $subject->allowsTransition($attribute, $from, $to)) {
            throw IllegalTransition::between($subject, $attribute, $from, $to);
        }
    }
}
