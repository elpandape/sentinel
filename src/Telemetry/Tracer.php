<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Telemetry;

use ElPandaPe\Sentinel\Context\ExecutionContext;
use ElPandaPe\Sentinel\Context\Runtime;
use ElPandaPe\Sentinel\Contracts\SpanContextProvider;
use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Http\Request;

/**
 * The precedence chain, in the one place that owns it: the active span of a registered SDK, then
 * the traceparent the caller sent, then a trace this process opens for itself, then nothing. Three
 * callers need the answer — the resolver that fills the columns, the envelope that crosses the
 * queue and the accessor the application reads — and three implementations of an order is three
 * chances for them to disagree about which trace an entry belongs to.
 *
 * Inside a worker there is no request to read, and the envelope a job carried takes the header's
 * place in that same order.
 *
 * A header that does not parse is treated as absent, not as an error and never as a trace to
 * invent: traceparent is a value the caller chooses and trace_id is indexed.
 */
final readonly class Tracer
{
    private const string ROOT = 'telemetry.root';

    public function __construct(
        private Runtime $runtime,
        private Config $config,
        private SpanContextProvider $spans,
        private ExecutionContext $context,
        private Envelope $envelope,
    ) {}

    public function current(): ?TraceContext
    {
        if (! $this->config->telemetryEnabled()) {
            return null;
        }

        return $this->spans->current() ?? $this->incoming() ?? $this->envelope->trace() ?? $this->root();
    }

    private function incoming(): ?TraceContext
    {
        $request = $this->runtime->request();

        if (! $request instanceof Request || ! $this->config->trustsIncomingTrace()) {
            return null;
        }

        $header = $request->headers->get('traceparent');
        $parent = is_string($header) ? TraceParent::parse($header) : null;

        return $parent instanceof TraceParent
            ? new TraceContext($parent, $this->tracestate($request->headers->get('tracestate')))
            : null;
    }

    /**
     * Memoised on the execution scope so every entry a command or a scheduled run writes shares one
     * trace_id. A worker gets a fresh scope per job, so one job's root never leaks into the next.
     */
    private function root(): ?TraceContext
    {
        if (! $this->config->opensRootTrace()) {
            return null;
        }

        $root = $this->context->memoize(self::ROOT, static fn (): array => [
            'traceparent' => TraceParent::root()->value(),
        ]);

        $header = $root['traceparent'] ?? null;
        $parent = is_string($header) ? TraceParent::parse($header) : null;

        return $parent instanceof TraceParent ? new TraceContext($parent) : null;
    }

    /**
     * Opaque, and only as much of it as the spec asks vendors to carry. The list is a header the
     * caller controls growing inside a column the hash covers.
     */
    private function tracestate(?string $value): ?string
    {
        return $value !== null && $value !== '' && strlen($value) <= TraceContext::TRACESTATE_LIMIT
            ? $value
            : null;
    }
}
