<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Pipeline;

use Closure;
use ElPandaPe\Sentinel\Contracts\Transformer;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Events\AuditDiscarded;
use ElPandaPe\Sentinel\Pipeline\Stages\EncryptSensitiveData;
use ElPandaPe\Sentinel\Pipeline\Stages\EnforcePolicies;
use ElPandaPe\Sentinel\Pipeline\Stages\FilterUnchanged;
use ElPandaPe\Sentinel\Pipeline\Stages\MaskSensitiveData;
use ElPandaPe\Sentinel\Pipeline\Stages\NormalizeData;
use ElPandaPe\Sentinel\Pipeline\Stages\ResolveContext;
use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;

/**
 * Capture builds the entry, this transforms it, the ledger gives it identity. Every stage
 * runs here and not behind the queue or the buffer, because a sensitive value must never
 * exist untransformed — not even for the moment it waits to be flushed.
 */
final readonly class Pipeline
{
    /**
     * @var list<class-string<Transformer>>
     */
    public const array DEFAULT_STAGES = [
        FilterUnchanged::class,
        ResolveContext::class,
        NormalizeData::class,
        MaskSensitiveData::class,
        EncryptSensitiveData::class,
        EnforcePolicies::class,
    ];

    public function __construct(
        private Container $container,
        private Config $config,
        private Discard $discard,
        private Dispatcher $events,
    ) {}

    public function process(AuditData $audit): ?AuditData
    {
        $this->discard->begin();

        try {
            $result = $this->stack()($audit);
        } finally {
            $discarded = $this->discard->end();
        }

        if ($discarded instanceof Discarded) {
            $this->events->dispatch(AuditDiscarded::of($audit, $discarded));
        }

        return $result;
    }

    /**
     * @return Closure(AuditData): ?AuditData
     */
    private function stack(): Closure
    {
        $next = static fn (AuditData $audit): AuditData => $audit;

        foreach (array_reverse($this->config->pipelineStages(self::DEFAULT_STAGES)) as $stage) {
            $next = fn (AuditData $audit): ?AuditData => $this->handle($stage, $audit, $next);
        }

        return $next;
    }

    /**
     * @param  class-string<Transformer>  $stage
     * @param  Closure(AuditData): ?AuditData  $next
     */
    private function handle(string $stage, AuditData $audit, Closure $next): ?AuditData
    {
        /** @var Transformer $transformer */
        $transformer = $this->container->make($stage);

        return $transformer->handle($audit, $next) ?? $this->discard->at($stage);
    }
}
