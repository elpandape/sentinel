<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Enums;

enum FanoutPolicy: string
{
    /**
     * Every destination is critical: one refusing the entry fails the write. What the others
     * already took stays with them, because an entry is sealed before it is handed out and
     * nothing here can unseal it.
     */
    case Strict = 'strict';

    /**
     * Only the primary is critical. A secondary that refuses the entry raises
     * LedgerDestinationFailed and the write settles anyway.
     */
    case Primary = 'primary';
}
