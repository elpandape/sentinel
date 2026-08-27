<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Context\Resolvers;

use ElPandaPe\Sentinel\Context\Runtime;
use ElPandaPe\Sentinel\Contracts\Resolver;

/**
 * Takes the two identifiers if a traceparent already arrived. Reading tracestate,
 * asking the OTel SDK for the active span and propagating the trace into jobs is v0.21.0.
 */
final readonly class TraceResolver implements Resolver
{
    private const string TRACEPARENT = '/^[0-9a-f]{2}-(?<trace>[0-9a-f]{32})-(?<span>[0-9a-f]{16})-[0-9a-f]{2}$/';

    public function __construct(private Runtime $runtime) {}

    /**
     * @return array<string, mixed>
     */
    public function resolve(): array
    {
        $header = $this->runtime->request()?->headers->get('traceparent');

        if (! is_string($header) || preg_match(self::TRACEPARENT, $header, $parts) !== 1) {
            return [];
        }

        return $parts['trace'] === str_repeat('0', 32) || $parts['span'] === str_repeat('0', 16)
            ? []
            : ['trace_id' => $parts['trace'], 'span_id' => $parts['span']];
    }
}
