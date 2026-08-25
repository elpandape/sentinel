<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Enums;

enum Mode: string
{
    case Sync = 'sync';
    case Queue = 'queue';
    case Buffered = 'buffered';
}
