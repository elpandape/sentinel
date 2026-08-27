<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Pipeline\Stages;

use Closure;
use ElPandaPe\Sentinel\Contracts\Transformer;
use ElPandaPe\Sentinel\Data\AuditData;

/**
 * The snapshot builder already sorts what it builds; context and metadata reach the entry
 * from anywhere and nobody sorted those. Two entries carrying the same facts should read
 * the same way, whichever order the source happened to produce them in.
 *
 * `changes` is left alone: its shape is the operation contract of the diff engine, and an
 * empty list there already means "compared, nothing moved" — not "nothing to compare".
 */
final readonly class NormalizeData implements Transformer
{
    /**
     * @param  Closure(AuditData): ?AuditData  $next
     */
    public function handle(AuditData $audit, Closure $next): ?AuditData
    {
        $audit->before = $this->sorted($audit->before);
        $audit->after = $this->sorted($audit->after);
        $audit->metadata = $this->sorted($audit->metadata);
        $audit->context = $this->sort($audit->context);

        return $next($audit);
    }

    /**
     * @param  array<string, mixed>|null  $value
     * @return array<string, mixed>|null
     */
    private function sorted(?array $value): ?array
    {
        return $value === null ? null : $this->sort($value);
    }

    /**
     * A list keeps the order it arrived in: there, the position is the meaning.
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, mixed>  $value
     * @return array<TKey, mixed>
     */
    private function sort(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->sort($item);
            }
        }

        return $value;
    }
}
