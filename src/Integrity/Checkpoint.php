<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Integrity;

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Models\AuditCheckpoint;

/**
 * One anchor, as something that can leave the database. It is deliberately not the model: an
 * application that publishes an anchor to another service, to WORM storage or to a third-party
 * timestamper is publishing a fact about a range, not a row of a table it does not have.
 *
 * What it carries is everything a verifier needs and nothing it does not: the range, the root, the
 * construction that produced it, and the signature with the identifier of the key that made it.
 * Whoever holds the verifying half of that key can check the anchor without reaching the trail at
 * all — which is the whole point of exporting one.
 */
final readonly class Checkpoint
{
    public function __construct(
        public string $stream,
        public int $from,
        public int $to,
        public string $rootHash,
        public string $algorithm,
        public ?string $signature,
        public ?string $keyId,
        public CarbonImmutable $createdAt,
    ) {}

    public static function of(AuditCheckpoint $row): self
    {
        return new self(
            $row->stream,
            $row->sequence_from,
            $row->sequence_to,
            $row->root_hash,
            $row->algorithm,
            $row->signature,
            $row->key_id,
            $row->created_at,
        );
    }

    public function covers(int $sequence): bool
    {
        return $sequence >= $this->from && $sequence <= $this->to;
    }

    public function length(): int
    {
        return $this->to - $this->from + 1;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'stream' => $this->stream,
            'sequence_from' => $this->from,
            'sequence_to' => $this->to,
            'root_hash' => $this->rootHash,
            'algorithm' => $this->algorithm,
            'signature' => $this->signature,
            'key_id' => $this->keyId,
            'created_at' => $this->createdAt->format(Audit::SERIALIZED_AT),
        ];
    }
}
