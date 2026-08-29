<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Fixtures;

use DateTimeImmutable;
use ElPandaPe\Sentinel\Contracts\Buffer;
use ElPandaPe\Sentinel\Data\AuditData;

/**
 * A buffer that counts what it is asked. Reading how full a store is costs a round trip to it, so
 * a batch that asked once per entry would be a batch that gave back what it went there to save.
 */
final class CountingBuffer implements Buffer
{
    public int $pushes = 0;

    public int $sizes = 0;

    public function __construct(private readonly Buffer $buffer) {}

    public function push(AuditData $audit): void
    {
        $this->pushes++;

        $this->buffer->push($audit);
    }

    /**
     * @return list<AuditData>
     */
    public function take(int $limit): array
    {
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
        $this->sizes++;

        return $this->buffer->size();
    }

    public function oldest(): ?DateTimeImmutable
    {
        return $this->buffer->oldest();
    }
}
