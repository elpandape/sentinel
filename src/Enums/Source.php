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

    /**
     * Not a signal like the eight above it, and the only one nothing resolves: it is written by the
     * importer and by nothing else. An entry carrying it did not happen inside this application at
     * all — another package recorded it, and this one copied it in. The distinction is worth a case
     * of its own because it is the difference between a fact this trail witnessed and a fact it was
     * told about, and because the Restore Engine has to be able to see it.
     */
    case Import = 'import';
}
