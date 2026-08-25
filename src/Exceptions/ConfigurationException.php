<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Exceptions;

use InvalidArgumentException;

final class ConfigurationException extends InvalidArgumentException
{
    public static function missing(string $key): self
    {
        return new self("Sentinel configuration key [sentinel.{$key}] is not set.");
    }

    public static function expected(string $key, string $type, string $given): self
    {
        return new self("Sentinel configuration key [sentinel.{$key}] must be {$type}, {$given} given.");
    }

    public static function unknown(string $key, string $value, string $accepted): self
    {
        return new self("Sentinel configuration key [sentinel.{$key}] has unknown value [{$value}]. Accepted: {$accepted}.");
    }
}
