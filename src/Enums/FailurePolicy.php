<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Enums;

/**
 * What a write that did not complete does to the request that caused it. A string and not a
 * boolean because the question has more than two answers waiting for it — reporting to a handler,
 * suspending the recorder, refusing further work — and a boolean would have to be replaced to say
 * any of them.
 */
enum FailurePolicy: string
{
    case Throw = 'throw';
    case Log = 'log';
}
