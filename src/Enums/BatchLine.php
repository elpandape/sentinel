<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Enums;

/**
 * What a line of an archived batch is. It is named on every line and never implied by position: a
 * reader that inferred the kind from where a line sits could not be given a new kind later without
 * every older reader misreading it.
 *
 * Kinds may be added. None is ever renamed or reinterpreted — the rule Audit::toArray() already
 * publishes for the serialized entry, applied to the container that carries it.
 */
enum BatchLine: string
{
    case Header = 'batch';

    case Entry = 'entry';

    case Operation = 'operation';
}
