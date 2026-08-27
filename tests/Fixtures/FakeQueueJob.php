<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Fixtures;

use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Jobs\Job;

/**
 * Answers the four questions JobResolver asks. A real queue driver is a different test,
 * in a different version.
 */
final class FakeQueueJob extends Job implements JobContract
{
    private readonly string $body;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(string $name, array $payload, string $queue, private readonly int $tries)
    {
        $this->queue = $queue;
        $this->body = (string) json_encode(['job' => $name, 'displayName' => $name, ...$payload]);
    }

    public function attempts(): int
    {
        return $this->tries;
    }

    public function getJobId(): string
    {
        return 'fake-job';
    }

    public function getRawBody(): string
    {
        return $this->body;
    }
}
