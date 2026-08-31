<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Enums;

/**
 * What sentinel:prune does with a range it is allowed to retire.
 *
 * Archive is the default, because the action that loses nothing is the one an operator should get
 * for forgetting a flag. Delete keeps meaning exactly what it meant when it was the only one there
 * was, which is why v0.19.0 refused to have a default at all.
 */
enum PruneAction: string
{
    case Archive = 'archive';

    case Delete = 'delete';
}
