<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Compliance;

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Capture\Recorder;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Enums\AuditEvent;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Models\AuditAccess;
use ElPandaPe\Sentinel\Query\AuditQuery;
use ElPandaPe\Sentinel\Support\AuditCollection;
use ElPandaPe\Sentinel\Support\Config;
use ElPandaPe\Sentinel\Support\Reference;

/**
 * Records that somebody read the trail, in the two places one fact needs to live.
 *
 * The evidence is an ordinary entry with `audit_type = 'access'`: it consumes a sequence, it is
 * hashed, chained and signed like every other, and it is what makes a read provable. The row in
 * `sentinel_access_log` is the projection — the same fact shaped so it can be asked by actor and by
 * date without walking the chain. A read log that can be edited proves nothing, so the editable one
 * is deliberately the second copy and never the only one.
 *
 * It does not audit itself. Writing the entry is a write and not a read, so nothing here re-enters
 * the query surface; the reentrancy latch is the belt to that braces, and it is what keeps a future
 * caller from turning one read into an unbounded chain of them.
 *
 * Nothing is written unless compliance mode is on. An installation that did not ask for a row per
 * query does not pay for one.
 */
final class AccessLog
{
    public const string AUDIT_TYPE = 'access';

    private bool $recording = false;

    public function __construct(
        private readonly Config $config,
        private readonly Recorder $recorder,
        private readonly AuditAccess $log,
    ) {}

    public function read(AuditQuery $query, AuditCollection $entries): void
    {
        if (! $this->config->complianceEnabled() || $this->recording) {
            return;
        }

        $this->recording = true;

        try {
            $this->write($this->describe($query), $entries->count());
        } finally {
            $this->recording = false;
        }
    }

    /**
     * @param  array<string, mixed>  $asked
     */
    private function write(array $asked, int $results): void
    {
        $this->recorder->record(
            new AuditData(
                audit_type: self::AUDIT_TYPE,
                event: AuditEvent::Read->value,
                severity: $this->config->defaultSeverity(AuditEvent::Read),
                occurred_at: CarbonImmutable::now(),
                metadata: ['access' => ['query' => $asked, 'results' => $results]],
            ),
            settled: function (Audit $entry) use ($asked, $results): void {
                $this->project($entry, $asked, $results);
            },
        );
    }

    /**
     * The projection, written on whichever path the entry landed on. It hangs off the settled
     * callback rather than off the return value because inside a deferred transaction there is no
     * return value until the commit — and a read whose proof exists while its index does not is the
     * one shape this pair must never take.
     *
     * @param  array<string, mixed>  $asked
     */
    private function project(Audit $entry, array $asked, int $results): void
    {
        $this->log->newInstance()->forceFill([
            'audit_id' => $entry->id,
            'actor_type' => $entry->actor_type,
            'actor_id' => $entry->actor_id,
            'tenant_id' => $entry->tenant_id,
            'query' => $asked,
            'results' => $results,
            'context' => $entry->context,
            'created_at' => $entry->created_at,
        ])->save();
    }

    /**
     * What was asked for, read off the query's own published properties. It records the shape of the
     * question and not a rendered SQL string: the ledger that answered it may not have been a
     * database, and what an auditor needs to know is what was looked for.
     *
     * @return array<string, mixed>
     */
    private function describe(AuditQuery $query): array
    {
        return array_filter([
            'subject' => $this->named($query->subject),
            'actor' => $this->named($query->actor),
            'event' => $query->event,
            'severity' => $query->severity?->value,
            'source' => $query->source?->value,
            'tenant_id' => $query->tenantId,
            'transaction_id' => $query->transactionId,
            'trace_id' => $query->traceId,
            'audit_type' => $query->type,
            'changed_field' => $query->changedField,
            'versions' => $query->versions === [] ? null : $query->versions,
            'limit' => $query->limit,
            'offset' => $query->offset,
        ], static fn (mixed $value): bool => $value !== null);
    }

    private function named(?Reference $reference): ?string
    {
        return $reference instanceof Reference ? $reference->type.':'.$reference->id : null;
    }
}
