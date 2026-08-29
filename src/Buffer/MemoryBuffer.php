<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Buffer;

use DateTimeImmutable;
use ElPandaPe\Sentinel\Contracts\Buffer;
use ElPandaPe\Sentinel\Data\AuditData;

/**
 * The whole contract over a plain array: a second implementation for the contract to be read
 * against, and a buffer for a suite that does not need a server.
 *
 * It keeps everything it is given and nothing survives the process, which is a stronger version of
 * what the buffered mode already asks you to accept. Reachable by configuration all the same, and
 * named for what it is, because a driver that silently stood in for Redis would be the one thing
 * this mode cannot afford: durability nobody chose.
 */
final class MemoryBuffer implements Buffer
{
    /**
     * @var list<AuditData>
     */
    private array $waiting = [];

    public function push(AuditData $audit): void
    {
        $this->waiting[] = $audit;
    }

    /**
     * @return list<AuditData>
     */
    public function take(int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        $taken = array_slice($this->waiting, 0, $limit);

        $this->waiting = array_slice($this->waiting, $limit);

        return $taken;
    }

    public function size(): int
    {
        return count($this->waiting);
    }

    public function oldest(): ?DateTimeImmutable
    {
        return $this->waiting[0]->occurred_at ?? null;
    }
}
