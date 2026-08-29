<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Mass;

use ElPandaPe\Sentinel\Contracts\MassStrategy;
use ElPandaPe\Sentinel\Enums\MassMode;
use ElPandaPe\Sentinel\Mass\Strategies\Hybrid;
use ElPandaPe\Sentinel\Mass\Strategies\Individual;
use ElPandaPe\Sentinel\Mass\Strategies\Summary;
use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Contracts\Container\Container;

/**
 * The mode a query asked for, or the one the configuration set. Resolved per operation rather than
 * held, for the reason the dispatcher resolves its own strategy per entry: the mode is
 * configuration, and configuration is allowed to change between two of them.
 */
final readonly class Strategies
{
    public function __construct(
        private Container $container,
        private Config $config,
    ) {}

    public function for(?MassMode $mode): MassStrategy
    {
        return match ($mode ?? $this->config->massMode()) {
            MassMode::Summary => $this->container->make(Summary::class),
            MassMode::Individual => $this->container->make(Individual::class),
            MassMode::Hybrid => $this->container->make(Hybrid::class),
        };
    }
}
