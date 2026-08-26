<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel;

use ElPandaPe\Sentinel\Context\ExecutionContext;
use ElPandaPe\Sentinel\Support\Config;
use ElPandaPe\Sentinel\Support\PublishedMigration;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

final class SentinelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/sentinel.php', 'sentinel');

        $this->app->singleton(Config::class, static fn (Application $app): Config => new Config(
            $app->make(Repository::class),
        ));

        // Scoped: execution context and recording state belong to one request or job, not to the worker.
        $this->app->scoped(ExecutionContext::class);
        $this->app->scoped(Sentinel::class);
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'sentinel');

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

    private function publishedMigration(): PublishedMigration
    {
        return new PublishedMigration(
            $this->app->databasePath('migrations'),
            'create_sentinel_audits_table',
        );
    }
}
