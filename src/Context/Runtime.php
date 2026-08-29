<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Context;

use Closure;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Http\Request;

/**
 * What this process is doing right now. Every signal is latched from an event the
 * framework already fires, so the resolvers read a fact instead of inspecting the world,
 * and every source in the matrix can be produced without a server, a worker or a
 * scheduler behind it.
 *
 * A signal that can nest suspends the one it started inside instead of replacing it. Artisan
 * announces a command run from inside another the same way it announces the outer one, so
 * assigning on the way in and clearing on the way out would leave the outer command believing it
 * had ended — and every entry captured after that would name no command, be filed under the wrong
 * source, and be impossible to tell from one nothing produced.
 */
final class Runtime
{
    private ?Request $request = null;

    private ?string $command = null;

    /**
     * @var array<array-key, mixed>
     */
    private array $arguments = [];

    /**
     * @var list<array{string|null, array<array-key, mixed>}>
     */
    private array $commands = [];

    private bool $scheduled = false;

    private ?Job $job = null;

    /**
     * @var list<Job|null>
     */
    private array $jobs = [];

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
        $this->commands[] = [$this->command, $this->arguments];

        $this->command = $command;
        $this->arguments = $arguments;
    }

    public function leftCommand(): void
    {
        [$this->command, $this->arguments] = array_pop($this->commands) ?? [null, []];
    }

    public function enteredSchedule(): void
    {
        $this->scheduled = true;
    }

    public function enteredJob(Job $job): void
    {
        $this->jobs[] = $this->job;

        $this->job = $job;
    }

    public function leftJob(): void
    {
        $this->job = array_pop($this->jobs);
    }

    public function assignRequestId(string $id): void
    {
        $this->requestId = $id;
    }

    /**
     * For as long as the callback runs, an entry captured is one this package is settling rather
     * than one the application produced. A scope and not a latch: the write it marks happens inside
     * a process that was doing something else before it and goes on doing it afterwards.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function whileWritingAudit(Closure $callback): mixed
    {
        $writing = $this->writingAudit;

        $this->writingAudit = true;

        try {
            return $callback();
        } finally {
            $this->writingAudit = $writing;
        }
    }
}
