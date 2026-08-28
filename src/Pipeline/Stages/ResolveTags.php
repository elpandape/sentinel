<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Pipeline\Stages;

use Closure;
use ElPandaPe\Sentinel\Contracts\Transformer;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Exceptions\ConfigurationException;
use ElPandaPe\Sentinel\Support\Config;
use ElPandaPe\Sentinel\Support\PolicyRegistry;

/**
 * What an entry is classified as, gathered before anything can discard it: what the model
 * declared, what the caller already put there, and what the configuration gives everything.
 *
 * An over-long label is refused here rather than at the ledger. Here it is still attributable
 * to whoever declared it; there it would arrive as a constraint violation on a write that had
 * already sealed a chain.
 */
final readonly class ResolveTags implements Transformer
{
    public const int MAX_LENGTH = 64;

    public function __construct(
        private PolicyRegistry $policies,
        private Config $config,
    ) {}

    /**
     * @param  Closure(AuditData): ?AuditData  $next
     */
    public function handle(AuditData $audit, Closure $next): ?AuditData
    {
        if ($this->config->tagsEnabled()) {
            $audit->tags = $this->labels($audit);
        }

        return $next($audit);
    }

    /**
     * @return list<string>
     */
    private function labels(AuditData $audit): array
    {
        $tags = $this->config->tags([
            ...$this->policies->for($audit->subject_type)->tags,
            ...$audit->tags,
        ]);

        foreach ($tags as $tag) {
            if (mb_strlen($tag) > self::MAX_LENGTH) {
                throw ConfigurationException::tagTooLong($tag, self::MAX_LENGTH);
            }
        }

        return $tags;
    }
}
