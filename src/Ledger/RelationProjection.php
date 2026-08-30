<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Ledger;

use ElPandaPe\Sentinel\Models\Audit;

/**
 * The one definition of how a relation line inside `changes` becomes a row of the projection. The
 * ledger writes through it and the verification re-derives through it, for the reason CanonicalPayload
 * gives about columns: a second copy of a mapping is a second mapping, and the two drift in silence.
 *
 * Which entries have lines is asked of the lines and not of the entry's type: a restoration that put
 * a relation back carries the same lines under a type of its own, and asking whoever reads the
 * projection to know that would make it a list of producers.
 */
final readonly class RelationProjection
{
    /**
     * @return list<array<string, mixed>>
     */
    public function rowsFor(Audit $audit): array
    {
        /** @var array<array-key, mixed> $lines */
        $lines = $audit->getAttribute('changes') ?? [];

        $rows = [];

        foreach ($lines as $line) {
            if (is_array($line) && array_key_exists('relation', $line) && array_key_exists('operation', $line)) {
                $rows[] = $this->row($audit->id, $line);
            }
        }

        return $rows;
    }

    /**
     * @param  array<array-key, mixed>  $line
     * @return array<string, mixed>
     */
    private function row(string $audit, array $line): array
    {
        return [
            'audit_id' => $audit,
            'relation' => $this->text($line, 'relation'),
            'operation' => $this->text($line, 'operation'),
            'related_type' => $this->text($line, 'related_type'),
            'related_id' => $this->text($line, 'related_id'),
            'pivot_before' => $this->json($line, 'pivot_before'),
            'pivot_after' => $this->json($line, 'pivot_after'),
        ];
    }

    /**
     * @param  array<array-key, mixed>  $line
     */
    private function text(array $line, string $key): ?string
    {
        $value = $line[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param  array<array-key, mixed>  $line
     */
    private function json(array $line, string $key): ?string
    {
        $value = $line[$key] ?? null;

        return is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR) : null;
    }
}
