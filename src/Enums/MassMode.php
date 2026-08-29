<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Enums;

enum MassMode: string
{
    case Summary = 'summary';
    case Individual = 'individual';
    case Hybrid = 'hybrid';
}
