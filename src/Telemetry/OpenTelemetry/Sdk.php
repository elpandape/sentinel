<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Telemetry\OpenTelemetry;

use ElPandaPe\Sentinel\Contracts\SpanContextProvider;
use ElPandaPe\Sentinel\Telemetry\NullSpanContextProvider;
use OpenTelemetry\API\Trace\Span;

/**
 * Whether there is an SDK to talk to, and which reader that answer calls for. Deciding it here
 * rather than in the provider keeps the SDK's name inside this namespace, which is what the arch
 * test checks, and keeps both answers reachable from a test — the installed half is the one the
 * suite runs under, and the other would otherwise be a branch nobody could enter.
 */
final class Sdk
{
    public static function present(): bool
    {
        return class_exists(Span::class);
    }

    public static function reading(bool $present): SpanContextProvider
    {
        return $present ? new SdkSpanContextProvider : new NullSpanContextProvider;
    }
}
