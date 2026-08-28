<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Transactions;

use Carbon\CarbonImmutable;
use Closure;
use ElPandaPe\Sentinel\Context\ContextEngine;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Models\AuditTransaction;
use Throwable;

/**
 * What business operation is running, for as long as it runs. Every entry captured inside one
 * takes its identifier, which is what turns a handful of entries that happen to share a request
 * into the record of a single thing the application decided to do.
 *
 * Scoped to the container, like the execution context: an operation belongs to one request or one
 * job, never to the worker that served it.
 *
 * It correlates; it does not atomise. Opening a scope opens no database transaction, and whether
 * to combine the two is the application's decision.
 */
final class TransactionScope
{
    private const string AUDIT_TYPE = 'transaction';

    private ?AuditTransaction $header = null;

    private int $depth = 0;

    private int $captured = 0;

    /**
     * @var list<string>
     */
    private array $nested = [];

    public function __construct(
        private readonly AuditTransaction $model,
        private readonly ContextEngine $context,
    ) {}

    public function run(string $name, Closure $callback): mixed
    {
        $header = $this->enter($name);

        try {
            $value = $callback();
        } catch (Throwable $failure) {
            $this->leave($header, $failure);

            throw $failure;
        }

        $this->leave($header, null);

        return $value;
    }

    /**
     * The identifier is stamped before the pipeline, because it belongs to the canonical payload
     * the chain seals. What the operation wrote is counted after it, because the pipeline is
     * allowed to discard: an update that changed nothing audited is not something the operation
     * produced, and counting it would describe a set of entries that does not exist.
     */
    public function stamp(AuditData $audit): void
    {
        if (! $this->header instanceof AuditTransaction) {
            return;
        }

        $audit->transaction_id = $this->header->id;
    }

    public function settled(): void
    {
        if ($this->header instanceof AuditTransaction) {
            $this->captured++;
        }
    }

    /**
     * The header of the outermost scope, or null when this call is nested inside one. A nested
     * call keeps the outer identifier and opens no header of its own: a business operation does
     * not split because its implementation reuses code that already wrapped itself.
     */
    private function enter(string $name): ?AuditTransaction
    {
        if ($this->depth++ > 0) {
            $this->nest($name);

            return null;
        }

        $this->captured = 0;
        $this->nested = [];

        return $this->header = $this->open($name);
    }

    private function leave(?AuditTransaction $header, ?Throwable $failure): void
    {
        $this->depth--;

        if (! $header instanceof AuditTransaction) {
            return;
        }

        $this->header = null;

        $header->finished_at = CarbonImmutable::now();
        $header->audits_count = $this->captured;
        $header->metadata = $this->metadata($failure);
        $header->save();
    }

    private function nest(string $name): void
    {
        if ($name === $this->header?->name || in_array($name, $this->nested, true)) {
            return;
        }

        $this->nested[] = $name;
    }

    /**
     * The operation as data, so the actor and the tenant of a header are resolved by the one
     * engine that answers that question for an entry. A second resolution here would be a second
     * answer to "who did this", and the two would drift.
     */
    private function open(string $name): AuditTransaction
    {
        $operation = ($this->context)(new AuditData(
            audit_type: self::AUDIT_TYPE,
            event: $name,
            severity: Severity::Info,
            occurred_at: CarbonImmutable::now(),
        ));

        $header = $this->model->newInstance();

        $header->forceFill([
            'name' => $name,
            'actor_type' => $operation->actor_type,
            'actor_id' => $operation->actor_id,
            'tenant_id' => $operation->tenant_id,
            'started_at' => $operation->occurred_at,
        ])->save();

        return $header;
    }

    /**
     * The class of a failure and not its message. A header does not go through the pipeline, so
     * nothing would redact what an exception message carries — and a message is where a domain
     * value ends up when someone interpolates it into one.
     *
     * @return array<string, mixed>|null
     */
    private function metadata(?Throwable $failure): ?array
    {
        $metadata = [];

        if ($this->nested !== []) {
            $metadata['nested'] = $this->nested;
        }

        if ($failure instanceof Throwable) {
            $metadata['failed'] = $failure::class;
        }

        return $metadata === [] ? null : $metadata;
    }
}
