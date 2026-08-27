<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests;

use ElPandaPe\Sentinel\SentinelServiceProvider;
use ElPandaPe\Sentinel\Tests\Fixtures\ActingUser;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [SentinelServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('auth.providers.users.model', ActingUser::class);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->artisan('migrate:fresh')->run();

        createFixtureTables();
    }
}
