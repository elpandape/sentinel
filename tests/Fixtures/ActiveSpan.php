<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Fixtures;

use ElPandaPe\Sentinel\Contracts\SpanContextProvider;
use ElPandaPe\Sentinel\Telemetry\TraceContext;

/**
 * A tracer that is inside a span, standing in for an SDK the test suite does not install.
 */
final readonly class ActiveSpan implements SpanContextProvider
{
    public function __construct(private ?TraceContext $context) {}

    public function current(): ?TraceContext
    {
        return $this->context;
    }
}
