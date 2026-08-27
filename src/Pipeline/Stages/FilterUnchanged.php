<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Pipeline\Stages;

use Closure;
use ElPandaPe\Sentinel\Contracts\Transformer;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Enums\AuditEvent;
use ElPandaPe\Sentinel\Pipeline\Discard;

/**
 * Runs first, on the plaintext. Two ciphertexts of the same value never match — the IV is
 * random — so a filter placed after encryption would report every field as changed.
 *
 * Only an update is filtered. A creation with no comparable fields still happened, and a
 * restore whose only changed column is excluded is still a restore.
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
        if ($audit->event !== AuditEvent::Updated->value || $audit->changes !== []) {
            return $next($audit);
        }

        $this->discard->because(self::REASON);

        return null;
    }
}
