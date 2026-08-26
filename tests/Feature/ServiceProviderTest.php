<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Context\ExecutionContext;
use ElPandaPe\Sentinel\Facades\Sentinel as SentinelFacade;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Sentinel;
use ElPandaPe\Sentinel\SentinelServiceProvider;
use ElPandaPe\Sentinel\Support\Config;
use ElPandaPe\Sentinel\Tests\Fixtures\CustomAudit;
use Illuminate\Container\Container;
use Illuminate\Contracts\Translation\Loader;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\ServiceProvider;
use Orchestra\Testbench\Foundation\Application as TestbenchApplication;

it('merges the package configuration', function (): void {
    expect(config('sentinel.tables.prefix'))->toBe('sentinel_')
        ->and(config('sentinel.integrity.stream'))->toBe('global');
});

it('binds the manager and its collaborators', function (): void {
    expect(app(Sentinel::class))->toBeInstanceOf(Sentinel::class)
        ->and(app(Sentinel::class)->config())->toBeInstanceOf(Config::class)
        ->and(app(Sentinel::class)->context())->toBeInstanceOf(ExecutionContext::class);
});

it('shares one config instance', function (): void {
    expect(app(Config::class))->toBe(app(Config::class));
});

it('scopes the manager and the context so they reset between requests', function (): void {
    $manager = app(Sentinel::class);
    $context = app(ExecutionContext::class);

    app()->forgetScopedInstances();

    expect(app(Sentinel::class))->not->toBe($manager)
        ->and(app(ExecutionContext::class))->not->toBe($context);
});

it('registers the translation namespace in both languages', function (): void {
    /** @var Loader $loader */
    $loader = Lang::getLoader();

    expect($loader->namespaces())->toHaveKey('sentinel')
        ->and($loader->namespaces()['sentinel'].'/en')->toBeDirectory()
        ->and($loader->namespaces()['sentinel'].'/es')->toBeDirectory();
});

it('publishes the configuration, the translations and the migration', function (): void {
    expect(ServiceProvider::publishableGroups())->toContain('sentinel-config', 'sentinel-lang', 'sentinel-migrations')
        ->and(ServiceProvider::pathsToPublish(SentinelServiceProvider::class, 'sentinel-config'))->not->toBeEmpty()
        ->and(ServiceProvider::pathsToPublish(SentinelServiceProvider::class, 'sentinel-lang'))->not->toBeEmpty()
        ->and(ServiceProvider::pathsToPublish(SentinelServiceProvider::class, 'sentinel-migrations'))->not->toBeEmpty();
});

it('loads the package migration when the application published none', function (): void {
    /** @var Migrator $migrator */
    $migrator = app('migrator');

    $paths = array_map(static fn (string $path): string => (string) realpath($path), $migrator->paths());

    expect($paths)->toContain(dirname(__DIR__, 2).'/database/migrations');
});

it('resolves the package audit model when nothing overrides it', function (): void {
    expect(app(Audit::class))->toBeInstanceOf(Audit::class)
        ->and(app(Audit::class))->not->toBeInstanceOf(CustomAudit::class);
});

it('resolves the audit model the configuration names', function (): void {
    config()->set('sentinel.models.audit', CustomAudit::class);

    expect(app(Audit::class))->toBeInstanceOf(CustomAudit::class);
});

it('skips the package migration when the application published a copy', function (): void {
    $directory = sys_get_temp_dir().'/sentinel-'.uniqid();
    mkdir($directory.'/migrations', recursive: true);
    touch($directory.'/migrations/2030_01_01_000000_create_sentinel_audits_table.php');

    $host = app();

    $isolated = TestbenchApplication::create(
        resolvingCallback: static fn (Application $app): mixed => $app->useDatabasePath($directory),
    );

    new SentinelServiceProvider($isolated)->boot();

    $paths = array_map(
        static fn (string $path): string => (string) realpath($path),
        $isolated->make('migrator')->paths(),
    );

    $isolated->flush();
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($host);
    Container::setInstance($host);

    unlink($directory.'/migrations/2030_01_01_000000_create_sentinel_audits_table.php');
    rmdir($directory.'/migrations');
    rmdir($directory);

    expect($paths)->not->toContain(dirname(__DIR__, 2).'/database/migrations');
});

it('ships the migration under the name the guard looks for', function (): void {
    expect(glob(dirname(__DIR__, 2).'/database/migrations/*_create_sentinel_audits_table.php'))
        ->toHaveCount(1);
});

it('records by default and stops when paused', function (): void {
    expect(SentinelFacade::isRecording())->toBeTrue();

    SentinelFacade::pause();
    expect(SentinelFacade::isRecording())->toBeFalse();

    SentinelFacade::resume();
    expect(SentinelFacade::isRecording())->toBeTrue();
});

it('stops recording when the configuration disables it', function (): void {
    config()->set('sentinel.enabled', false);

    expect(SentinelFacade::isRecording())->toBeFalse();
});

it('suspends recording for the duration of a callback', function (): void {
    $inside = SentinelFacade::withoutAuditing(fn (): bool => SentinelFacade::isRecording());

    expect($inside)->toBeFalse()
        ->and(SentinelFacade::isRecording())->toBeTrue();
});

it('keeps recording paused after a nested suspension', function (): void {
    SentinelFacade::pause();

    SentinelFacade::withoutAuditing(fn (): bool => true);

    expect(SentinelFacade::isRecording())->toBeFalse();
});

it('restores recording when the callback throws', function (): void {
    expect(fn (): mixed => SentinelFacade::withoutAuditing(function (): never {
        throw new RuntimeException('boom');
    }))->toThrow(RuntimeException::class)
        ->and(SentinelFacade::isRecording())->toBeTrue();
});

it('scopes extra context through the facade', function (): void {
    $inside = SentinelFacade::withContext(
        ['reason' => 'Approved by finance'],
        fn (): mixed => SentinelFacade::context()->get('reason'),
    );

    expect($inside)->toBe('Approved by finance')
        ->and(SentinelFacade::context()->all())->toBeEmpty();
});
