<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Enums;

/**
 * Why a stream released nothing. Every case is a state a sound installation can be in, which is why
 * none of them is a failure and none of them is in IntegrityBreak — the same line CheckpointState
 * draws.
 *
 * It exists because the unit of retention is the anchored window and not the entry: an operator who
 * declared a ninety-day policy and sees nothing purged is owed the reason, and there are four
 * different ones. Without this the honest report and the broken configuration look identical.
 */
enum RetentionHold: string
{
    case Undeclared = 'undeclared';

    case Unanchored = 'unanchored';

    case Tail = 'tail';

    case Retained = 'retained';

    public function message(string $stream, int $sequence, string $held): string
    {
        return (string) trans('sentinel::sentinel.retention.'.$this->value, [
            'stream' => $stream,
            'sequence' => $sequence,
            'held' => $held,
        ]);
    }
}
