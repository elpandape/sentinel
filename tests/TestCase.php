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

        // Fixed rather than generated: a digest salt derived from a key that moves between
        // runs would make every hashing assertion compare a value to a different value.
        $app['config']->set('app.key', 'base64:'.base64_encode(str_pad('sentinel-test-key', 32, '0')));
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->artisan('migrate:fresh')->run();

        createFixtureTables();
    }
}
