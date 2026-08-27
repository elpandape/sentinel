<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Integrity;

use ElPandaPe\Sentinel\Contracts\Canonicalizer;
use ElPandaPe\Sentinel\Exceptions\ConfigurationException;
use ElPandaPe\Sentinel\Models\Audit;

final readonly class Hasher
{
    // The prefix parts are separated so ("a", 11) and ("a1", 1) cannot produce the same link.
    private const string SEPARATOR = "\x1f";

    public function __construct(private Canonicalizer $canonicalizer) {}

    public function hash(Audit $audit): string
    {
        $algorithm = $audit->algorithm;

        if (! in_array($algorithm, hash_algos(), true)) {
            throw ConfigurationException::unknown('integrity.algorithm', $algorithm, implode(', ', hash_algos()));
        }

        $prefix = implode(self::SEPARATOR, [
            $audit->payload_version,
            $audit->stream,
            $audit->sequence,
            $audit->previous_hash ?? '',
        ]);

        return hash($algorithm, $prefix.self::SEPARATOR.$this->canonicalizer->canonicalize(
            CanonicalPayload::from($audit),
        ));
    }
}
