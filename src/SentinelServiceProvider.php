<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel;

use ElPandaPe\Sentinel\Context\ExecutionContext;
use ElPandaPe\Sentinel\Contracts\Canonicalizer;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Exceptions\ConfigurationException;
use ElPandaPe\Sentinel\Integrity\JsonCanonicalizer;
use ElPandaPe\Sentinel\Ledger\DatabaseLedger;
use ElPandaPe\Sentinel\Ledger\NullLedger;
use ElPandaPe\Sentinel\Models\Audit;
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

        $this->app->singleton(Canonicalizer::class, JsonCanonicalizer::class);

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
