<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Pipeline;

use Closure;
use ElPandaPe\Sentinel\Contracts\Transformer;
use ElPandaPe\Sentinel\Data\AuditData;
use ElPandaPe\Sentinel\Events\AuditDiscarded;
use ElPandaPe\Sentinel\Events\Auditing;
use ElPandaPe\Sentinel\Pipeline\Stages\EncryptSensitiveData;
use ElPandaPe\Sentinel\Pipeline\Stages\EnforcePolicies;
use ElPandaPe\Sentinel\Pipeline\Stages\FilterUnchanged;
use ElPandaPe\Sentinel\Pipeline\Stages\MaskSensitiveData;
use ElPandaPe\Sentinel\Pipeline\Stages\NormalizeData;
use ElPandaPe\Sentinel\Pipeline\Stages\ResolveContext;
use ElPandaPe\Sentinel\Pipeline\Stages\ResolveTags;
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
        ResolveTags::class,
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
            $result = $this->announce($this->stack()($audit));
        } finally {
            $discarded = $this->discard->end();
        }

        if ($discarded instanceof Discarded) {
            $this->events->dispatch(AuditDiscarded::of($audit, $discarded));
        }

        return $result;
    }

    /**
     * The application's own say, after every stage and inside the same pass, so that refusing here
     * leaves by the door a stage leaves by and with a reason of the listener's choosing. Last for
     * the reason the last stage is last: an entry offered before the transformations is an entry
     * offered in the clear.
     *
     * The subject is put back. It decides which chain signs the entry and what the entry is about,
     * and both were settled before a listener saw it.
     */
    private function announce(?AuditData $audit): ?AuditData
    {
        if (! $audit instanceof AuditData) {
            return null;
        }

        $subject = [$audit->subject_type, $audit->subject_id];

        try {
            if ($this->events->until(new Auditing($audit)) === false) {
                $this->discard->because(Auditing::REASON);

                return $this->discard->at(Auditing::class);
            }
        } finally {
            [$audit->subject_type, $audit->subject_id] = $subject;
        }

        return $audit;
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
