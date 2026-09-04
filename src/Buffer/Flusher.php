<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Buffer;

use Carbon\CarbonImmutable;
use DateTimeImmutable;
use ElPandaPe\Sentinel\Contracts\Buffer;
use ElPandaPe\Sentinel\Data\AuditData;
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
 *
 * Announced whichever end gave way. The ledger is the end expected to, but the buffer is the end
 * that matters more: one that cannot be read is the case an operator has least chance of noticing,
 * and one that will not take a batch back is the only path on which this mode loses a fact
 * outright. All three go out as the same event, because the counts are what say what is at stake
 * and `reason` already says which end it was.
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

        while (($batch = $this->take($taken, $settled)) !== []) {
            $taken += count($batch);

            try {
                $settled += $this->settlement->settleBatch($batch)->count();
            } catch (Throwable $failure) {
                $returned = $this->putBack($batch);

                $this->events->dispatch(new BufferFlushFailed($taken, $settled, $returned, $failure));

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

    /**
     * The next batch, or the announcement that there will not be one.
     *
     * Nothing is in limbo when a read fails — what could not be handed over never left the buffer,
     * so nothing was taken and nothing needs putting back. What a silent one costs is different: a
     * flush that stopped without a word, on the one store this mode cannot work without.
     *
     * @return list<AuditData>
     */
    private function take(int $taken, int $settled): array
    {
        try {
            return $this->buffer->take($this->config->bufferSize());
        } catch (Throwable $failure) {
            $this->events->dispatch(new BufferFlushFailed($taken, $settled, 0, $failure));

            throw $failure;
        }
    }

    /**
     * How many of the batch made it back.
     *
     * A buffer that refuses it is the one path on which this mode loses a fact outright, and this
     * event is the only thing that will ever say so — so the refusal is swallowed here instead of
     * being allowed to replace the failure that caused it, and none returned is what turns the
     * batch into `skipped()`, which is the count that means lost.
     *
     * @param  list<AuditData>  $batch
     */
    private function putBack(array $batch): int
    {
        try {
            $this->buffer->putBack($batch);
        } catch (Throwable) {
            return 0;
        }

        return count($batch);
    }
}
