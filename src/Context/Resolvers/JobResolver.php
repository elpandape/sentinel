<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Context\Resolvers;

use ElPandaPe\Sentinel\Context\Runtime;
use ElPandaPe\Sentinel\Contracts\Resolver;

final readonly class JobResolver implements Resolver
{
    public function __construct(private Runtime $runtime) {}

    /**
     * @return array<string, mixed>
     */
    public function resolve(): array
    {
        $job = $this->runtime->job();

        if (! $job instanceof \Illuminate\Contracts\Queue\Job) {
            return [];
        }

        $context = [
            'job' => $job->resolveName(),
            'queue' => $job->getQueue(),
            'attempts' => $job->attempts(),
        ];

        $batchId = $job->payload()['batchId'] ?? null;

        if (is_string($batchId)) {
            $context['batch_id'] = $batchId;
        }

        return $context;
    }
}
