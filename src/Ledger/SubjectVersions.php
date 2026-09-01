<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Ledger;

use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Models\Audit;

/**
 * Where a subject's history has got to, for a driver that has no table to ask.
 *
 * `DatabaseLedger` reads the highest version of a subject back out of the rows, so it heals itself:
 * an entry that arrives already numbered is in the table before the next one is numbered. A driver
 * that keeps a counter instead has to be told, and until this existed none of them were — so an
 * append() followed by a write() handed out a number the appended entry already held, permanently
 * and without a sound. That is what `seen()` is for, and it is now part of what the published
 * contract asks of every driver.
 *
 * One instance per driver instance, never shared: two ledgers counting one subject through the same
 * object would each be numbering a chain the other cannot see.
 */
final class SubjectVersions
{
    /**
     * @var array<string, int>
     */
    private array $counted = [];

    public function next(AuditData $audit): ?int
    {
        $key = $this->keyOf($audit->subject_type, $audit->subject_id);

        return $key === null ? null : $this->counted[$key] = ($this->counted[$key] ?? 0) + 1;
    }

    /**
     * An entry the driver took without numbering it. The counter moves to whichever is higher, so a
     * range restored out of order leaves it where the subject's history actually ends.
     */
    public function seen(Audit $audit): void
    {
        $key = $this->keyOf($audit->subject_type, $audit->subject_id);

        if ($key !== null && $audit->version !== null) {
            $this->counted[$key] = max($this->counted[$key] ?? 0, $audit->version);
        }
    }

    private function keyOf(?string $type, ?string $id): ?string
    {
        return $type === null || $id === null ? null : $type.'|'.$id;
    }
}
