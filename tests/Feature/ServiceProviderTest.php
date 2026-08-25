<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Context\ExecutionContext;
use ElPandaPe\Sentinel\Facades\Sentinel as SentinelFacade;
use ElPandaPe\Sentinel\Sentinel;
use ElPandaPe\Sentinel\SentinelServiceProvider;
use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Contracts\Translation\Loader;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\ServiceProvider;

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

it('publishes the configuration and the translations', function (): void {
    expect(ServiceProvider::publishableGroups())->toContain('sentinel-config', 'sentinel-lang')
        ->and(ServiceProvider::pathsToPublish(SentinelServiceProvider::class, 'sentinel-config'))->not->toBeEmpty()
        ->and(ServiceProvider::pathsToPublish(SentinelServiceProvider::class, 'sentinel-lang'))->not->toBeEmpty();
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
