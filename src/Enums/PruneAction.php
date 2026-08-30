<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Enums;

/**
 * What sentinel:prune does with a range it is allowed to retire. One case, and it is named rather
 * than assumed: this release can only remove, and the action that writes the range out somewhere
 * first arrives next. Naming it now means the flag an operator writes today keeps meaning what it
 * meant when the safer action becomes the default.
 */
enum PruneAction: string
{
    case Delete = 'delete';
}
