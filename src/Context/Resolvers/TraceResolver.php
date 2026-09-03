<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Context\Resolvers;

use ElPandaPe\Sentinel\Contracts\Resolver;
use ElPandaPe\Sentinel\Support\Config;
use ElPandaPe\Sentinel\Telemetry\TraceContext;
use ElPandaPe\Sentinel\Telemetry\Tracer;

/**
 * Which trace the entry belongs to, and which service wrote it. The order the answer is looked up
 * in is the Tracer's, not this class's: the envelope that crosses the queue and the accessor the
 * application reads have to reach the same trace this does.
 *
 * With telemetry off nothing here runs, which is what keeps the write path at the cost it had for
 * an installation that does not trace.
 */
final readonly class TraceResolver implements Resolver
{
    public function __construct(
        private Config $config,
        private Tracer $tracer,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function resolve(): array
    {
        if (! $this->config->telemetryEnabled()) {
            return [];
        }

        $service = $this->config->serviceName();
        $resolved = $service === null ? [] : ['service_name' => $service];

        $trace = $this->tracer->current();

        if (! $trace instanceof TraceContext) {
            return $resolved;
        }

        $resolved['trace_id'] = $trace->traceId();
        $resolved['span_id'] = $trace->spanId();

        $tracestate = $trace->tracestate();

        if ($this->config->storesTracestate() && $tracestate !== null) {
            $resolved['tracestate'] = $tracestate;
        }

        return $resolved;
    }
}
