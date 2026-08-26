<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Ledger;

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Integrity\Hasher;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Support\Str;

final readonly class EntryBuilder
{
    public const int PAYLOAD_VERSION = 1;

    public function __construct(
        private Audit $model,
        private Hasher $hasher,
        private Config $config,
    ) {}

    public function build(AuditData $data, string $stream, int $sequence, ?string $previous, ?int $version): Audit
    {
        $audit = $this->model->newInstance();

        $audit->forceFill([
            'id' => (string) Str::ulid(),
            'stream' => $stream,
            'sequence' => $sequence,
            'audit_type' => $data->audit_type,
            'event' => $data->event,
            'severity' => $data->severity,
            'subject_type' => $data->subject_type,
            'subject_id' => $data->subject_id,
            'actor_type' => $data->actor_type,
            'actor_id' => $data->actor_id,
            'impersonator_type' => $data->impersonator_type,
            'impersonator_id' => $data->impersonator_id,
            'tenant_id' => $data->tenant_id,
            'transaction_id' => $data->transaction_id,
            'request_id' => $data->request_id,
            'trace_id' => $data->trace_id,
            'span_id' => $data->span_id,
            'source' => $data->source,
            'version' => $version,
            'context' => $data->context,
            'before' => $data->before,
            'after' => $data->after,
            'changes' => $data->changes,
            'metadata' => $data->metadata,
            'encryption' => $data->encryption,
            'criteria' => $data->criteria,
            'affected_rows' => $data->affected_rows,
            'source_audit_id' => $data->source_audit_id,
            'capture_id' => $data->capture_id,
            'payload_version' => self::PAYLOAD_VERSION,
            'algorithm' => $this->config->integrityAlgorithm(),
            'previous_hash' => $previous,
            'occurred_at' => $data->occurred_at,
            'created_at' => CarbonImmutable::now(),
        ]);

        $audit->hash = $this->hasher->hash($audit);
        $audit->syncOriginal();

        return $audit;
    }
}
