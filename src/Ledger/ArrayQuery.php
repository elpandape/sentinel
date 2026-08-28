<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Ledger;

use ElPandaPe\Sentinel\Diff\Pointer;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Models\AuditTag;
use ElPandaPe\Sentinel\Query\AuditQuery;

/**
 * Resolves a query over entries a driver already holds: the same criteria a SQL driver
 * compiles into a where clause, answered by walking what is there. A ledger with no query
 * language of its own reaches for this instead of growing one, which is what keeps the
 * published filters answerable by a backend nobody has written yet.
 */
final readonly class ArrayQuery
{
    /**
     * @param  list<Audit>  $entries
     * @return list<Audit>
     */
    public function resolve(array $entries, AuditQuery $query): array
    {
        $matched = [];

        foreach ($entries as $audit) {
            if ($this->matches($audit, $query)) {
                $matched[] = $audit;
            }
        }

        usort($matched, fn (Audit $first, Audit $second): int => $this->chronologically($first, $second, $query->byOccurrence));

        if ($query->newestFirst) {
            $matched = array_reverse($matched);
        }

        return array_slice($matched, $query->offset ?? 0, $query->limit);
    }

    private function matches(Audit $audit, AuditQuery $query): bool
    {
        return ($query->subject?->matches($audit->subject_type, $audit->subject_id) ?? true)
            && ($query->actor?->matches($audit->actor_type, $audit->actor_id) ?? true)
            && ($query->period?->covers($audit->created_at) ?? true)
            && ($query->tags?->matches($this->labelsOf($audit)) ?? true)
            && ($query->relations?->matches($this->linesOf($audit)) ?? true)
            && ($query->changedField === null || $this->touches($audit, $query->changedField))
            && ($query->versions === [] || in_array($audit->version, $query->versions, true))
            && $this->equals($query->event, $audit->event)
            && $this->equals($query->severity?->value, $audit->severity->value)
            && $this->equals($query->source?->value, $audit->source->value)
            && $this->equals($query->tenantId, $audit->tenant_id)
            && $this->equals($query->transactionId, $audit->transaction_id)
            && $this->equals($query->traceId, $audit->trace_id);
    }

    private function touches(Audit $audit, string $pointer): bool
    {
        $changes = $audit->getAttribute('changes');

        foreach (is_array($changes) ? $changes : [] as $change) {
            $path = is_array($change) ? ($change['path'] ?? null) : null;

            if (is_string($path) && Pointer::covers($pointer, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function linesOf(Audit $audit): array
    {
        $changes = $audit->getAttribute('changes');

        return is_array($changes) ? $changes : [];
    }

    /**
     * @return list<string>
     */
    private function labelsOf(Audit $audit): array
    {
        return $audit->relationLoaded('tags')
            ? array_values(array_map(static fn (AuditTag $tag): string => $tag->tag, $audit->tags->all()))
            : [];
    }

    private function equals(?string $wanted, ?string $actual): bool
    {
        return $wanted === null || $wanted === $actual;
    }

    /**
     * The identifier breaks the tie rather than the insertion order: a ULID sorts by the
     * instant it was minted, so two entries stamped in the same microsecond still come back
     * in the order they were written, and they come back in that order on every driver.
     */
    private function chronologically(Audit $first, Audit $second, bool $byOccurrence): int
    {
        $byClock = $byOccurrence
            ? $first->occurred_at <=> $second->occurred_at
            : $first->created_at <=> $second->created_at;

        return $byClock === 0 ? $first->id <=> $second->id : $byClock;
    }
}
