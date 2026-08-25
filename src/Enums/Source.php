<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Enums;

enum Source: string
{
    case Http = 'http';
    case Api = 'api';
    case Cli = 'cli';
    case Queue = 'queue';
    case Job = 'job';
    case Scheduler = 'scheduler';
    case Console = 'console';
    case System = 'system';
}
