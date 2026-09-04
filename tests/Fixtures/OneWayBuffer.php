<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Fixtures;

use DateTimeImmutable;
use ElPandaPe\Sentinel\Contracts\Buffer;
use ElPandaPe\Sentinel\Data\AuditData;
use RuntimeException;

/**
 * A buffer that gives entries up and will not take them back. It is the shape of the only failure
 * on which the buffered mode loses a fact outright, rather than deferring it.
 */
final readonly class OneWayBuffer implements Buffer
{
    public const string REASON = 'the buffer will not take the batch back';

    public function __construct(private Buffer $buffer) {}

    public function push(AuditData $audit): void
    {
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
        throw new RuntimeException(self::REASON);
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
