<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Fixtures;

use DateTimeImmutable;
use ElPandaPe\Sentinel\Contracts\Buffer;
use ElPandaPe\Sentinel\Data\AuditData;
use RuntimeException;

/**
 * A buffer that hands over a number of batches and then cannot be reached at all. The count is
 * what tells apart a flush that never started from one that stopped partway.
 */
final class UnreadableBuffer implements Buffer
{
    public const string REASON = 'the buffer is unreachable';

    private int $reads = 0;

    public function __construct(private readonly Buffer $buffer, private readonly int $until = 0) {}

    public function push(AuditData $audit): void
    {
        $this->buffer->push($audit);
    }

    /**
     * @return list<AuditData>
     */
    public function take(int $limit): array
    {
        if ($this->reads++ >= $this->until) {
            throw new RuntimeException(self::REASON);
        }

        return $this->buffer->take($limit);
    }

    /**
     * @param  list<AuditData>  $audits
     */
    public function putBack(array $audits): void
    {
        $this->buffer->putBack($audits);
    }

    public function size(): int
    {
        return $this->buffer->size();
    }

    public function oldest(): ?DateTimeImmutable
    {
        return $this->buffer->oldest();
    }
}
