<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Enums;

enum Severity: string
{
    case Info = 'info';
    case Notice = 'notice';
    case Warning = 'warning';
    case Critical = 'critical';

    public function rank(): int
    {
        return match ($this) {
            self::Info => 0,
            self::Notice => 1,
            self::Warning => 2,
            self::Critical => 3,
        };
    }

    public function atLeast(self $floor): bool
    {
        return $this->rank() >= $floor->rank();
    }
}
