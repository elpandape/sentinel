<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Contracts;

/**
 * A ledger that can be asked whether it has already settled a capture. Declared and not required,
 * for the reason the Ledger contract states outright: idempotency by `capture_id` belongs to the
 * caller, because a store with no reliable read cannot honour it — and a contract nobody can
 * implement is a contract that gets ignored.
 *
 * Answering is not the same as guaranteeing. A driver that declares this promises to say what it
 * can see, not that nothing landed between the question and the write; what makes the write itself
 * idempotent is the unique index on the column. This exists so a retry costs one query instead of
 * one sealed chain thrown away.
 */
interface Deduplicates
{
    /**
     * @param  non-empty-list<string>  $captureIds
     * @return list<string>
     */
    public function settled(array $captureIds): array;
}
