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

    /**
     * What the query asked for, or what the configuration says. Asked for separately because the
     * entry has to record the same answer the strategy was chosen by, and deciding it twice is how
     * the two would come to disagree.
     */
    public function mode(?MassMode $mode): MassMode
    {
        return $mode ?? $this->config->massMode();
    }

    public function for(?MassMode $mode): MassStrategy
    {
        return match ($this->mode($mode)) {
            MassMode::Summary => $this->container->make(Summary::class),
            MassMode::Individual => $this->container->make(Individual::class),
            MassMode::Hybrid => $this->container->make(Hybrid::class),
        };
    }
}
