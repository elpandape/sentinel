<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Context\Resolvers;

use Closure;
use ElPandaPe\Sentinel\Context\Runtime;
use ElPandaPe\Sentinel\Contracts\Resolver;
use ElPandaPe\Sentinel\Enums\Source;
use ElPandaPe\Sentinel\Exceptions\ConfigurationException;
use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;

/**
 * The order below is the contract: a queued write is still the request that queued it in
 * every other resolver, but here it is Source::Queue, because that is the process actually
 * writing the entry. Everything below it only applies once that is ruled out.
 */
final readonly class SourceResolver implements Resolver
{
    /**
     * @var list<string>
     */
    private const array SCHEDULER_COMMANDS = ['schedule:run', 'schedule:work', 'schedule:finish'];

    public function __construct(
        private Runtime $runtime,
        private Application $app,
        private Config $config,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function resolve(): array
    {
        $request = $this->runtime->request();

        return ['source' => match (true) {
            $this->runtime->writingAudit() => Source::Queue,
            $this->runtime->job() instanceof \Illuminate\Contracts\Queue\Job => Source::Job,
            $this->scheduled() => Source::Scheduler,
            $request instanceof Request => $this->boundary($request),
            $this->runtime->command() !== null => Source::Cli,
            $this->app->runningUnitTests() => Source::System,
            $this->app->runningInConsole() => Source::Console,
            default => Source::System,
        }];
    }

    private function scheduled(): bool
    {
        return $this->runtime->scheduled()
            || in_array($this->runtime->command(), self::SCHEDULER_COMMANDS, true);
    }

    private function boundary(Request $request): Source
    {
        $boundary = $this->config->apiBoundary();

        $isApi = $boundary instanceof Closure
            ? $this->matches($boundary, $request)
            : $request->is($boundary);

        return $isApi ? Source::Api : Source::Http;
    }

    private function matches(Closure $boundary, Request $request): bool
    {
        $result = $boundary($request);

        return is_bool($result)
            ? $result
            : throw ConfigurationException::expected(
                'resolvers.request.api',
                'a closure returning a boolean',
                get_debug_type($result),
            );
    }
}
