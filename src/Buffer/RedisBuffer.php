<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Buffer;

use DateTimeImmutable;
use ElPandaPe\Sentinel\Contracts\Buffer;
use ElPandaPe\Sentinel\Data\AuditData;
use Illuminate\Redis\Connections\Connection;

/**
 * The buffer the mode is named after. Entries wait in one list, oldest at the head, and a flush
 * takes from that head — so the order they settle in is the order they were captured in, which is
 * the only thing about ordering this mode can offer.
 *
 * Taking uses a single popping command with a count, which Redis executes atomically: two flushes
 * running at once each get entries, and neither gets the other's. That is what keeps the common
 * case — a request terminating while the console command runs — from settling the same fact twice.
 */
final readonly class RedisBuffer implements Buffer
{
    /**
     * Typed against the base connection rather than the Redis contract, because that is what the
     * manager promises to hand back. It is the parent of every client the framework ships, so
     * nothing here is tied to phpredis or to predis.
     */
    public function __construct(
        private Connection $connection,
        private string $key,
    ) {}

    public function push(AuditData $audit): void
    {
        $this->connection->command('rpush', [$this->key, $this->encode($audit)]);
    }

    /**
     * @return list<AuditData>
     */
    public function take(int $limit): array
    {
        if ($limit < 1) {
            return [];
        }

        $taken = $this->connection->command('lpop', [$this->key, $limit]);

        return is_array($taken) ? $this->decode($taken) : [];
    }

    public function size(): int
    {
        $size = $this->connection->command('llen', [$this->key]);

        return is_int($size) ? $size : 0;
    }

    public function oldest(): ?DateTimeImmutable
    {
        $head = $this->connection->command('lindex', [$this->key, 0]);

        if (! is_string($head)) {
            return null;
        }

        $entries = $this->decode([$head]);

        return $entries === [] ? null : $entries[0]->occurred_at;
    }

    private function encode(AuditData $audit): string
    {
        return json_encode($audit->toPayload(), JSON_THROW_ON_ERROR);
    }

    /**
     * An element this package did not write is dropped rather than allowed to abort a flush. The
     * list is a queue on a shared server: something else writing to the same key is a configuration
     * mistake, and the entries behind it did nothing wrong.
     *
     * @param  array<array-key, mixed>  $elements
     * @return list<AuditData>
     */
    private function decode(array $elements): array
    {
        $entries = [];

        foreach ($elements as $element) {
            $payload = is_string($element) ? json_decode($element, true) : null;

            if (is_array($payload)) {
                /** @var array<string, mixed> $payload */
                $entries[] = AuditData::fromPayload($payload);
            }
        }

        return $entries;
    }
}
