<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel;

use ElPandaPe\Sentinel\Context\ContextEngine;
use ElPandaPe\Sentinel\Context\ExecutionContext;
use ElPandaPe\Sentinel\Context\Runtime;
use ElPandaPe\Sentinel\Contracts\Canonicalizer;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Exceptions\ConfigurationException;
use ElPandaPe\Sentinel\Integrity\JsonCanonicalizer;
use ElPandaPe\Sentinel\Ledger\DatabaseLedger;
use ElPandaPe\Sentinel\Ledger\FanoutLedger;
use ElPandaPe\Sentinel\Ledger\MemoryLedger;
use ElPandaPe\Sentinel\Ledger\NullLedger;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Models\AuditTransaction;
use ElPandaPe\Sentinel\Pipeline\Discard;
use ElPandaPe\Sentinel\Security\Keyring;
use ElPandaPe\Sentinel\Security\Maskers;
use ElPandaPe\Sentinel\Support\Config;
use ElPandaPe\Sentinel\Support\PackageMigrations;
use ElPandaPe\Sentinel\Support\Policies;
use ElPandaPe\Sentinel\Support\PolicyRegistry;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Routing\Events\Routing;
use Illuminate\Support\ServiceProvider;

final class SentinelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sentinel.php', 'sentinel');

        $this->app->singleton(Config::class, static fn (Application $app): Config => new Config(
            $app->make(Repository::class),
        ));

        $this->app->singleton(Canonicalizer::class, JsonCanonicalizer::class);

        // Not scoped: a policy is a decision of the application, not of the request it was registered in.
        $this->app->singleton(Policies::class);

        $this->app->bind(Audit::class, static function (Application $app): Audit {
            $model = $app->make(Config::class)->model('audit', Audit::class);

            return new $model;
        });

        $this->app->bind(AuditTransaction::class, static function (Application $app): AuditTransaction {
            $model = $app->make(Config::class)->model('transaction', AuditTransaction::class);

            return new $model;
        });

        // Scoped like the manager: a ledger with no store keeps its chain on the instance.
        $this->app->scoped(Ledger::class, fn (Application $app): Ledger => $this->driver(
            $app,
            $app->make(Config::class)->ledger(),
            'ledger.default',
        ));

        // Scoped: execution context and recording state belong to one request or job, not to the worker.
        $this->app->scoped(ContextEngine::class);
        $this->app->scoped(Discard::class);
        $this->app->scoped(Keyring::class);
        $this->app->scoped(Maskers::class);
        $this->app->scoped(PolicyRegistry::class);
        $this->app->scoped(ExecutionContext::class);
        $this->app->scoped(Runtime::class);
        $this->app->scoped(Sentinel::class);
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'sentinel');

        $this->latchRuntimeSignals();

        $this->loadMigrationsFrom($this->migrations()->unpublished());

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/sentinel.php' => $this->app->configPath('sentinel.php'),
            ], 'sentinel-config');

            $this->publishes([
                __DIR__.'/../resources/lang' => $this->app->langPath('vendor/sentinel'),
            ], 'sentinel-lang');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
            ], 'sentinel-migrations');
        }
    }

    /**
     * The signals a source is decided by, each one an event the framework already fires.
     * The runtime is resolved inside every listener because it is scoped to the request
     * and the listener outlives it.
     */
    private function latchRuntimeSignals(): void
    {
        $app = $this->app;
        $runtime = static fn (): Runtime => $app->make(Runtime::class);

        $events = $this->app->make(Dispatcher::class);

        $events->listen(Routing::class, static function (Routing $event) use ($runtime): void {
            $runtime()->enteredRequest($event->request);
        });

        $events->listen(CommandStarting::class, static function (CommandStarting $event) use ($runtime): void {
            $runtime()->enteredCommand($event->command, [
                ...$event->input->getArguments(),
                ...$event->input->getOptions(),
            ]);
        });

        $events->listen(CommandFinished::class, static function () use ($runtime): void {
            $runtime()->leftCommand();
        });

        $events->listen(ScheduledTaskStarting::class, static function () use ($runtime): void {
            $runtime()->enteredSchedule();
        });

        $events->listen(JobProcessing::class, static function (JobProcessing $event) use ($runtime): void {
            $runtime()->enteredJob($event->job);
        });

        $events->listen(JobProcessed::class, static function () use ($runtime): void {
            $runtime()->leftJob();
        });
    }

    private function driver(Application $app, string $name, string $key): Ledger
    {
        return match ($name) {
            'database' => $app->make(DatabaseLedger::class),
            'memory' => $app->make(MemoryLedger::class),
            'null' => $app->make(NullLedger::class),
            'fanout' => $this->fanout($app),
            default => throw ConfigurationException::unknown($key, $name, 'database, fanout, memory, null'),
        };
    }

    /**
     * The first destination is the primary and the only one that seals: the rest are built
     * the same way but only ever receive what it sealed.
     */
    private function fanout(Application $app): FanoutLedger
    {
        $config = $app->make(Config::class);
        $destinations = $config->fanoutDestinations();
        $key = 'ledger.ledgers.fanout.destinations';

        return new FanoutLedger(
            $this->driver($app, array_shift($destinations), $key),
            array_map(fn (string $name): Ledger => $this->driver($app, $name, $key), $destinations),
            $config->fanoutPolicy(),
            $app->make(Dispatcher::class),
        );
    }

    private function migrations(): PackageMigrations
    {
        return new PackageMigrations(
            __DIR__.'/../database/migrations',
            $this->app->databasePath('migrations'),
        );
    }
}
