<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel;

use ElPandaPe\Sentinel\Buffer\Flusher;
use ElPandaPe\Sentinel\Buffer\MemoryBuffer;
use ElPandaPe\Sentinel\Buffer\RedisBuffer;
use ElPandaPe\Sentinel\Compliance\Requirements;
use ElPandaPe\Sentinel\Console\CheckpointCommand;
use ElPandaPe\Sentinel\Console\ExportCommand;
use ElPandaPe\Sentinel\Console\FlushCommand;
use ElPandaPe\Sentinel\Console\PartitionsCommand;
use ElPandaPe\Sentinel\Console\PruneCommand;
use ElPandaPe\Sentinel\Console\RedactCommand;
use ElPandaPe\Sentinel\Console\RekeyCommand;
use ElPandaPe\Sentinel\Console\VerifyCommand;
use ElPandaPe\Sentinel\Context\ContextEngine;
use ElPandaPe\Sentinel\Context\ExecutionContext;
use ElPandaPe\Sentinel\Context\Runtime;
use ElPandaPe\Sentinel\Contracts\Buffer;
use ElPandaPe\Sentinel\Contracts\Canonicalizer;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Contracts\SpanContextProvider;
use ElPandaPe\Sentinel\Enums\MassMode;
use ElPandaPe\Sentinel\Enums\Mode;
use ElPandaPe\Sentinel\Exceptions\ConfigurationException;
use ElPandaPe\Sentinel\Integrity\JsonCanonicalizer;
use ElPandaPe\Sentinel\Integrity\Signers;
use ElPandaPe\Sentinel\Ledger\ArchiveLedger;
use ElPandaPe\Sentinel\Ledger\DatabaseLedger;
use ElPandaPe\Sentinel\Ledger\FanoutLedger;
use ElPandaPe\Sentinel\Ledger\MemoryLedger;
use ElPandaPe\Sentinel\Ledger\NullLedger;
use ElPandaPe\Sentinel\Mass\AuditedQuery;
use ElPandaPe\Sentinel\Mass\Criteria;
use ElPandaPe\Sentinel\Mass\MassCapture;
use ElPandaPe\Sentinel\Mass\Strategies;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Models\AuditTransaction;
use ElPandaPe\Sentinel\Pipeline\Discard;
use ElPandaPe\Sentinel\Restore\Columns;
use ElPandaPe\Sentinel\Retention\Schedule;
use ElPandaPe\Sentinel\Security\Keyring;
use ElPandaPe\Sentinel\Security\Maskers;
use ElPandaPe\Sentinel\Support\Config;
use ElPandaPe\Sentinel\Support\PackageMigrations;
use ElPandaPe\Sentinel\Support\Policies;
use ElPandaPe\Sentinel\Support\PolicyRegistry;
use ElPandaPe\Sentinel\Telemetry\OpenTelemetry\Sdk;
use ElPandaPe\Sentinel\Transactions\TransactionScope;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\WorkerStopping;
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

        /*
         * Scoped, so a buffer with no store keeps its entries for one request and no longer, and so
         * the Redis one holds a connection for as long as the process that opened it is serving.
         */
        $this->app->scoped(Buffer::class, fn (Application $app): Buffer => $this->buffer($app));

        // Scoped like the manager: a ledger with no store keeps its chain on the instance.
        $this->app->scoped(Ledger::class, fn (Application $app): Ledger => $this->driver(
            $app,
            $app->make(Config::class)->ledger(),
            'ledger.default',
        ));

        // Scoped: execution context and recording state belong to one request or job, not to the worker.
        $this->app->scoped(Columns::class);
        $this->app->scoped(ContextEngine::class);
        $this->app->scoped(Discard::class);
        $this->app->scoped(Keyring::class);
        $this->app->scoped(Maskers::class);
        $this->app->scoped(PolicyRegistry::class);
        // Scoped, so the batch a request or a job is filling is the one the shutdown hook seals.
        $this->app->scoped(ArchiveLedger::class);
        $this->app->scoped(Schedule::class);
        $this->app->scoped(Signers::class);
        $this->app->scoped(ExecutionContext::class);
        $this->app->scoped(Runtime::class);
        $this->app->scoped(TransactionScope::class);
        $this->app->scoped(Sentinel::class);

        $this->app->bind(SpanContextProvider::class, fn (): SpanContextProvider => Sdk::reading(Sdk::present()));
    }

    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'sentinel');

        // Nothing to enforce before the package's own configuration is there: boot() can be called
        // on a provider whose register() never ran, and an installation with no configuration has
        // not declared compliance mode.
        if ($this->app->make('config')->has('sentinel.compliance')) {
            $this->app->make(Requirements::class)->enforce();
        }

        $this->openTheMassOperationDoor();

        $this->latchRuntimeSignals();

        $this->flushWhatIsWaiting();

        $this->sealWhatIsOpen();

        $this->loadMigrationsFrom($this->migrations()->unpublished());

        if ($this->app->runningInConsole()) {
            $this->commands([
                CheckpointCommand::class,
                ExportCommand::class,
                FlushCommand::class,
                PartitionsCommand::class,
                PruneCommand::class,
                RedactCommand::class,
                RekeyCommand::class,
                VerifyCommand::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/sentinel.php' => $this->app->configPath('sentinel.php'),
            ], 'sentinel-config');

            $this->publishes([
                __DIR__.'/../resources/lang' => $this->app->langPath('vendor/sentinel'),
            ], 'sentinel-lang');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
            ], 'sentinel-migrations');

            /*
             * Published rather than loaded, and that is the whole point of it: the index behind
             * whereIp() and whereRoute() costs fifteen per cent per write on PostgreSQL and
             * twenty-one on MySQL, and an installation that never asks those two questions should
             * not be paying for the answer. The two filters work either way; without it they scan.
             */
            $this->publishesMigrations([
                __DIR__.'/../database/stubs/json-indexes' => $this->app->databasePath('migrations'),
            ], 'sentinel-json-indexes');

            /*
             * Three alternatives to the base migration, and publishing one is how you choose it:
             * the file lands under the same name the package's own carries, and PackageMigrations
             * stops loading that one. They are for a new installation. Converting a table that
             * already holds entries is a maintenance window, and the upgrade notes describe it
             * rather than the package attempting it.
             */
            foreach (['pgsql-range', 'pgsql-tenant', 'mysql-range'] as $division) {
                $this->publishesMigrations([
                    __DIR__."/../database/stubs/partitioned/{$division}" => $this->app->databasePath('migrations'),
                ], "sentinel-partitioned-{$division}");
            }
        }
    }

    /**
     * The one way in to auditing a statement Eloquent fires no model event for. It is a macro and
     * not an override of the builder, because replacing the builder would put this package on the
     * path of every query an application makes, and the entire design of the feature is that it is
     * not: a query that does not call this costs exactly what it cost before.
     *
     * The name is global, which is what a macro is. A second package registering `auditing` on the
     * Eloquent builder would win or lose by boot order — see the README, which says so out loud
     * rather than leaving it to be discovered.
     */
    private function openTheMassOperationDoor(): void
    {
        $app = $this->app;

        Builder::macro('auditing', function (MassMode|string|null $mode = null) use ($app): AuditedQuery {
            /** @var Builder<Model> $this */
            AuditedQuery::guard($this);

            return new AuditedQuery(
                $this,
                is_string($mode) ? MassMode::tryFrom($mode) ?? throw ConfigurationException::unknown(
                    'auditing',
                    $mode,
                    'summary, individual, hybrid',
                ) : $mode,
                $app->make(Strategies::class),
                $app->make(Criteria::class),
                $app->make(MassCapture::class),
                $app->make(Sentinel::class),
            );
        });
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

    /**
     * The two moments a process that has been buffering is about to stop being one. Neither is a
     * threshold: they are what keeps the last few entries of a quiet request from waiting for a
     * next one that may never come.
     *
     * The request has `terminating`, which runs after the response has gone out. A worker does not
     * pass through it between jobs — it is one long-lived process — so the shutdown of the worker
     * is `WorkerStopping`, which both a clean stop and a signal go through; the signal handler
     * itself only raises a flag.
     *
     * Reported rather than thrown. By the time either of these runs there is nobody left to tell,
     * and a batch that failed is already back in the buffer waiting for the next trigger.
     */
    private function flushWhatIsWaiting(): void
    {
        $app = $this->app;

        $flush = static function () use ($app): void {
            if ($app->make(Config::class)->mode() !== Mode::Buffered) {
                return;
            }

            rescue(static fn (): int => $app->make(Flusher::class)->flush());
        };

        $app->terminating($flush);

        $this->app->make(Dispatcher::class)->listen(WorkerStopping::class, $flush);
    }

    /**
     * The batch a process is filling, written out before the process stops being one. The Ledger is
     * scoped, so an open batch is LOST rather than delayed when the scope tears down — the same
     * reason the buffer has its own two hooks, and these are those two.
     *
     * It asks whether the driver was ever resolved rather than resolving it. A request that archived
     * nothing must not build one on its way out, and an installation that never archives must not
     * pay for this at all.
     */
    private function sealWhatIsOpen(): void
    {
        $app = $this->app;

        $seal = static function () use ($app): void {
            if (! $app->resolved(ArchiveLedger::class)) {
                return;
            }

            rescue(static fn (): int => $app->make(ArchiveLedger::class)->seal());
        };

        $app->terminating($seal);

        $this->app->make(Dispatcher::class)->listen(WorkerStopping::class, $seal);
    }

    private function driver(Application $app, string $name, string $key): Ledger
    {
        return match ($name) {
            'archive' => $key === 'ledger.default'
                ? throw ConfigurationException::coldLedgerAsDefault()
                : $app->make(ArchiveLedger::class),
            'database' => $app->make(DatabaseLedger::class),
            'memory' => $app->make(MemoryLedger::class),
            'null' => $app->make(NullLedger::class),
            'fanout' => $this->fanout($app),
            default => throw ConfigurationException::unknown($key, $name, 'archive, database, fanout, memory, null'),
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

    /**
     * A store this release does not know is refused rather than served by the one that keeps
     * everything on the instance. An operator who asked for Redis and silently got the process has
     * been told nothing about the durability they actually have — which is the whole subject of
     * this mode.
     */
    private function buffer(Application $app): Buffer
    {
        $config = $app->make(Config::class);
        $store = $config->bufferStore();

        return match ($store) {
            'redis' => new RedisBuffer(
                $app->make(Redis::class)->connection($config->bufferConnection()),
                $config->bufferKey(),
            ),
            'memory' => new MemoryBuffer,
            default => throw ConfigurationException::unknown('buffer.store', $store, 'redis, memory'),
        };
    }

    private function migrations(): PackageMigrations
    {
        return new PackageMigrations(
            __DIR__.'/../database/migrations',
            $this->app->databasePath('migrations'),
        );
    }
}
