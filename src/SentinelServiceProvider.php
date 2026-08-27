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
use ElPandaPe\Sentinel\Ledger\NullLedger;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Pipeline\Discard;
use ElPandaPe\Sentinel\Security\Keyring;
use ElPandaPe\Sentinel\Security\Maskers;
use ElPandaPe\Sentinel\Support\Config;
use ElPandaPe\Sentinel\Support\Policies;
use ElPandaPe\Sentinel\Support\PolicyRegistry;
use ElPandaPe\Sentinel\Support\PublishedMigration;
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

        // Scoped like the manager: a NullLedger keeps its chain on the instance.
        $this->app->scoped(Ledger::class, static function (Application $app): Ledger {
            $driver = $app->make(Config::class)->ledger();

            return match ($driver) {
                'database' => $app->make(DatabaseLedger::class),
                'null' => $app->make(NullLedger::class),
                default => throw ConfigurationException::unknown('ledger.default', $driver, 'database, null'),
            };
        });

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

        if (! $this->publishedMigration()->exists()) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

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

            $this->publishes([
                __DIR__.'/../database/factories' => $this->app->databasePath('factories'),
            ], 'sentinel-factories');
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

    private function publishedMigration(): PublishedMigration
    {
        return new PublishedMigration(
            $this->app->databasePath('migrations'),
            'create_sentinel_audits_table',
        );
    }
}
