<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Pipeline\Stages;

use Closure;
use ElPandaPe\Sentinel\Capture\RelationCapture;
use ElPandaPe\Sentinel\Contracts\Transformer;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Enums\AuditEvent;
use ElPandaPe\Sentinel\Pipeline\Discard;

/**
 * Runs first, on the plaintext. Two ciphertexts of the same value never match — the IV is
 * random — so a filter placed after encryption would report every field as changed.
 *
 * What is filtered is an entry whose comparison ran and came back empty. For a model that means
 * an update and nothing else: a creation with no comparable fields still happened, and a restore
 * whose only changed column is excluded is still a restore. For a relation it means any of them,
 * because a sync that attaches nothing and detaches nothing did nothing at all — and it has to be
 * dropped here, before the ledger, or it would spend a sequence number on a non-event and leave
 * the chain with a link that says nothing happened.
 */
final readonly class FilterUnchanged implements Transformer
{
    public const string REASON = 'unchanged';

    public function __construct(private Discard $discard) {}

    /**
     * @param  Closure(AuditData): ?AuditData  $next
     */
    public function handle(AuditData $audit, Closure $next): ?AuditData
    {
        if ($audit->changes !== [] || ! $this->comparison($audit)) {
            return $next($audit);
        }

        $this->discard->because(self::REASON);

        return null;
    }

    private function comparison(AuditData $audit): bool
    {
        return $audit->event === AuditEvent::Updated->value
            || $audit->audit_type === RelationCapture::AUDIT_TYPE;
    }
}
