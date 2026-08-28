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

    public static function invalidClass(string $key, string $value, string $expected): self
    {
        return new self("Sentinel configuration key [sentinel.{$key}] must be {$expected} or a subclass of it, [{$value}] given.");
    }

    public static function missingApplicationKey(string $key): self
    {
        return new self(
            "Sentinel needs [sentinel.{$key}] or an application key to derive it from, and found neither. "
            .'Run `php artisan key:generate`, or declare the value yourself.',
        );
    }

    public static function streamTooLong(string $name): self
    {
        return new self(sprintf(
            'Sentinel resolved the stream name [%s], longer than the 64 characters the column holds. A stream name is part of the hash prefix, so it is never truncated.',
            substr($name, 0, 80),
        ));
    }

    public static function tagTooLong(string $tag, int $limit): self
    {
        return new self(sprintf(
            'Sentinel was given the label [%s], longer than the %d characters the column holds. A label is a whole word or it is not the label that was meant, so it is never truncated.',
            substr($tag, 0, 80),
            $limit,
        ));
    }

    public static function notAParent(string $model, string $relation): self
    {
        return new self(
            "Sentinel cannot audit the parent side of [{$model}::{$relation}()]: \$auditParents names "
            .'belongsTo relations, and a morphTo is not one of them.',
        );
    }

    public static function streamEmpty(): self
    {
        return new self('Sentinel resolved an empty stream name; every entry belongs to a named chain.');
    }
}
