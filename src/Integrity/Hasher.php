<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Integrity;

use ElPandaPe\Sentinel\Contracts\Canonicalizer;
use ElPandaPe\Sentinel\Exceptions\ConfigurationException;
use ElPandaPe\Sentinel\Models\Audit;

final readonly class Hasher
{
    // The prefix parts are separated so ("a", 11) and ("a1", 1) cannot produce the same link.
    public const string SEPARATOR = "\x1f";

    public function __construct(private Canonicalizer $canonicalizer) {}

    public function hash(Audit $audit): string
    {
        $prefix = implode(self::SEPARATOR, [
            $audit->payload_version,
            $audit->stream,
            $audit->sequence,
            $audit->previous_hash ?? '',
        ]);

        return $this->digest(
            $prefix.self::SEPARATOR.$this->canonicalizer->canonicalize(CanonicalPayload::from($audit)),
            $audit->algorithm,
        );
    }

    /**
     * Bytes that are already whatever they need to be. Folding a range has nothing to canonicalize
     * and no entry to read the algorithm off, so it arrives here instead — which is what keeps one
     * check of hash_algos() from becoming two that drift apart.
     */
    public function digest(string $bytes, string $algorithm): string
    {
        return in_array($algorithm, hash_algos(), true)
            ? hash($algorithm, $bytes)
            : throw ConfigurationException::unknown('integrity.algorithm', $algorithm, implode(', ', hash_algos()));
    }
}
