<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Events;

use Throwable;

/**
 * A flush that did not empty the buffer, and how much of it is where. The five triggers reach it
 * through one method, so this is announced once for all of them rather than by each — and it is the
 * only announcement two of them make at all.
 *
 * It names a batch, not an entry, which is why it carries no identity: nothing here was ever
 * written, so there is no coordinate to hand out. `AuditWriteFailed` still goes out on the threshold
 * trigger and names the entry that arrived, which by design is the one that is safe; this one
 * names what was actually at stake.
 *
 * `taken`, `settled` and `skipped` are the whole flush; `returned` is the batch that failed, because
 * a batch is put back whole. `skipped` is derived so the four cannot contradict each other.
 */
final readonly class BufferFlushFailed
{
    public function __construct(
        public int $taken,
        public int $settled,
        public int $returned,
        public Throwable $reason,
    ) {}

    public function skipped(): int
    {
        return $this->taken - $this->settled - $this->returned;
    }

    public function message(): string
    {
        $line = trans('sentinel::sentinel.buffer.flush_failed', [
            'reason' => $this->reason->getMessage(),
            'taken' => $this->taken,
            'settled' => $this->settled,
            'skipped' => $this->skipped(),
            'returned' => $this->returned,
        ]);

        return is_string($line) ? $line : $this->reason->getMessage();
    }
}
