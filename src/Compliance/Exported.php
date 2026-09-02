<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Compliance;

/**
 * An export and what proves it. The manifest travels beside the body rather than inside it: putting
 * the digest into the bytes it digests is the one shape that cannot work.
 */
final readonly class Exported
{
    public function __construct(
        public string $body,
        public string $format,
        public int $entries,
        public string $digest,
        public string $signature,
        public string $keyId,
    ) {}

    /**
     * @return array<string, string|int>
     */
    public function manifest(): array
    {
        return [
            'format' => $this->format,
            'entries' => $this->entries,
            'digest' => $this->digest,
            'signature' => $this->signature,
            'signature_key_id' => $this->keyId,
        ];
    }
}
