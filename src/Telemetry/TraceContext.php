<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Telemetry;

/**
 * The trace an entry belongs to, as something the application can read and forward. It is what
 * `Sentinel::trace()` hands back so an outgoing call can carry the same `traceparent` the entry
 * was written under — the package exposes the context and the application decides what to do with
 * it, because injecting headers into someone else's HTTP client is a tracer's job and this is not
 * a tracer.
 *
 * `tracestate` travels verbatim or not at all. Section 3.4 of the spec is explicit: a vendor that
 * does not change `traceparent` must not change `tracestate` either, and this package opens no
 * spans of its own, so it has nothing to add to a list it does not participate in.
 */
final readonly class TraceContext
{
    /**
     * The spec asks vendors to propagate at least this much of a combined `tracestate`. Anything
     * past it is a header a client controls growing inside a column the hash covers, so the
     * package keeps the floor and drops what it cannot vouch for.
     */
    public const int TRACESTATE_LIMIT = 512;

    public function __construct(
        private TraceParent $parent,
        private ?string $tracestate = null,
    ) {}

    public function traceId(): string
    {
        return $this->parent->traceId;
    }

    public function spanId(): string
    {
        return $this->parent->spanId;
    }

    public function traceparent(): string
    {
        return $this->parent->value();
    }

    public function tracestate(): ?string
    {
        return $this->tracestate;
    }

    public function sampled(): bool
    {
        return $this->parent->sampled();
    }
}
