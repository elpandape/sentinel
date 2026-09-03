<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Telemetry;

/**
 * What a job carries across the queue so the entry a worker writes belongs to the trace of the
 * request that dispatched it, and to the business operation that was open at the time.
 *
 * It rides inside Laravel's own context, which the framework already dehydrates into every payload
 * and hydrates back in the worker. Registering a second payload hook of our own would mean a key
 * that another package's hook can overwrite, a callback list that is never cleared between boots,
 * and a queue connection resolved earlier in the boot pipeline than it can be.
 *
 * An absent envelope is the ordinary case — every job queued before the feature was switched on —
 * and reads as no trace rather than as an error.
 */
final class Envelope
{
    public const string KEY = 'sentinel:trace';

    private ?TraceContext $trace = null;

    private ?string $transactionId = null;

    /**
     * @return array<string, string>
     */
    public static function seal(?TraceContext $trace, ?string $transactionId): array
    {
        $sealed = [];

        if ($trace instanceof TraceContext) {
            $sealed['traceparent'] = $trace->traceparent();
            $tracestate = $trace->tracestate();

            if ($tracestate !== null) {
                $sealed['tracestate'] = $tracestate;
            }
        }

        if ($transactionId !== null) {
            $sealed['transaction_id'] = $transactionId;
        }

        return $sealed;
    }

    public function receive(mixed $carried): void
    {
        if (! is_array($carried)) {
            return;
        }

        $header = $carried['traceparent'] ?? null;
        $parent = is_string($header) ? TraceParent::parse($header) : null;

        if ($parent instanceof TraceParent) {
            $tracestate = $carried['tracestate'] ?? null;
            $this->trace = new TraceContext($parent, is_string($tracestate) ? $tracestate : null);
        }

        $transactionId = $carried['transaction_id'] ?? null;
        $this->transactionId = is_string($transactionId) ? $transactionId : null;
    }

    public function trace(): ?TraceContext
    {
        return $this->trace;
    }

    public function transactionId(): ?string
    {
        return $this->transactionId;
    }
}
