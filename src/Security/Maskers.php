<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Security;

use ElPandaPe\Sentinel\Contracts\Masker;
use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Contracts\Container\Container;

/**
 * One masker per field, because no single mask fits every kind of secret: an address wants
 * its shape kept, a national id wants none of it. The package imposes one default and lets
 * a field name override it.
 */
final class Maskers
{
    /**
     * @var array<string, Masker>
     */
    private array $maskers = [];

    public function __construct(
        private readonly Container $container,
        private readonly Config $config,
    ) {}

    public function for(string $field): Masker
    {
        return $this->maskers[$field] ??= $this->resolve($field);
    }

    private function resolve(string $field): Masker
    {
        $class = $this->config->maskerClass($field, PartialMasker::class);

        /** @var Masker $masker */
        $masker = $class === PartialMasker::class
            ? new PartialMasker($this->config->redactionMask())
            : $this->container->make($class);

        return $masker;
    }
}
