<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Context;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Request;

/**
 * What this process is doing right now. Every signal is latched from an event the
 * framework already fires, so the resolvers read a fact instead of inspecting the world,
 * and every source in the matrix can be produced without a server, a worker or a
 * scheduler behind it.
 */
final class Runtime
{
    private ?Request $request = null;

    private ?string $command = null;

    /**
     * @var array<array-key, mixed>
     */
    private array $arguments = [];

    private bool $scheduled = false;

    private ?Job $job = null;

    private ?string $requestId = null;

    private bool $writingAudit = false;

    public function request(): ?Request
    {
        return $this->request;
    }

    public function command(): ?string
    {
        return $this->command;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function arguments(): array
    {
        return $this->arguments;
    }

    public function scheduled(): bool
    {
        return $this->scheduled;
    }

    public function job(): ?Job
    {
        return $this->job;
    }

    public function requestId(): ?string
    {
        return $this->requestId;
    }

    public function writingAudit(): bool
    {
        return $this->writingAudit;
    }

    public function enteredRequest(Request $request): void
    {
        $this->request = $request;
    }

    /**
     * @param  array<array-key, mixed>  $arguments
     */
    public function enteredCommand(string $command, array $arguments): void
    {
        $this->command = $command;
        $this->arguments = $arguments;
    }

    public function leftCommand(): void
    {
        $this->command = null;
        $this->arguments = [];
    }

    public function enteredSchedule(): void
    {
        $this->scheduled = true;
    }

    public function enteredJob(Job $job): void
    {
        $this->job = $job;
    }

    public function leftJob(): void
    {
        $this->job = null;
    }

    public function assignRequestId(string $id): void
    {
        $this->requestId = $id;
    }

    public function writingAuditEntry(): void
    {
        $this->writingAudit = true;
    }
}
