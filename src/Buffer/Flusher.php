<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Buffer;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use ElPandaPe\Sentinel\Contracts\Buffer;
use ElPandaPe\Sentinel\Dispatch\Settlement;
use ElPandaPe\Sentinel\Events\BufferFlushFailed;
use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Throwable;

/**
 * What turns what is waiting into entries. Everything that vacates the buffer goes through here —
 * the two thresholds, the end of a request, the shutdown of a worker and the console command — so
 * "settled once" is a property of one method rather than of four callers agreeing.
 *
 * A failed batch is put back. Taking is destructive, so without that a ledger briefly unreachable
 * would turn a flush into the loss this mode is careful to bound: what is lost is what a process
 * dies holding, never what a write handed back.
 *
 * Put back and announced. Two of the five triggers catch the failure and say nothing at all, and a
 * third names the entry that arrived rather than the batch that did not land, so the one place that
 * knows how much was at stake is here.
 */
final readonly class Flusher
{
    public function __construct(
        private Buffer $buffer,
        private Settlement $settlement,
        private Config $config,
        private Events $events,
    ) {}

    /**
     * Everything waiting, in batches of the configured size, and how many entries landed.
     *
     * Batched rather than taken whole because a buffer nobody vacated for an hour is exactly the
     * one a flush must not try to settle in a single transaction — the size that bounds the loss
     * is also the size that bounds the write.
     */
    public function flush(): int
    {
        $taken = 0;
        $settled = 0;

        while (($batch = $this->buffer->take($this->config->bufferSize())) !== []) {
            $taken += count($batch);

            try {
                $settled += $this->settlement->settleBatch($batch)->count();
            } catch (Throwable $failure) {
                $this->buffer->putBack($batch);

                $this->events->dispatch(new BufferFlushFailed($taken, $settled, count($batch), $failure));

                throw $failure;
            }
        }

        return $settled;
    }

    /**
     * Whether either threshold has been reached. Evaluated when an entry arrives and not on a
     * clock, because nothing in PHP is watching one between requests: what bounds a buffer nobody
     * is writing to is the flush at the end of the request, the one at worker shutdown, and the
     * command.
     */
    public function due(): bool
    {
        if ($this->buffer->size() >= $this->config->bufferSize()) {
            return true;
        }

        $oldest = $this->buffer->oldest();

        return $oldest instanceof DateTimeImmutable
            && CarbonImmutable::instance($oldest)->addSeconds($this->config->bufferInterval())->isPast();
    }
}
