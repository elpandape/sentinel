<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Security;

use ElPandaPe\Sentinel\Contracts\Canonicalizer;
use ElPandaPe\Sentinel\Support\Config;

/**
 * Irreversible on purpose: the digest answers "did it change" and nothing else. The value
 * goes through the same canonicalizer the chain uses, so two runs agree byte for byte and
 * the representation is decided in one place.
 *
 * The salt is per installation and stable by definition — rotating it breaks no chain, but
 * every value hashed before it stops comparing to every value hashed after.
 */
final readonly class Digester
{
    private const string SEPARATOR = "\x1f";

    public function __construct(
        private Config $config,
        private Canonicalizer $canonicalizer,
    ) {}

    public function digest(mixed $value): mixed
    {
        return $value === null ? null : hash(
            $this->config->hashingAlgorithm(),
            $this->config->hashingSalt().self::SEPARATOR.$this->canonicalizer->canonicalize(['value' => $value]),
        );
    }
}
