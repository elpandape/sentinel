<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Dispatch\Dispatcher;
use ElPandaPe\Sentinel\Dispatch\SyncStrategy;
use ElPandaPe\Sentinel\Exceptions\ConfigurationException;
use ElPandaPe\Sentinel\Models\Audit;

use function ElPandaPe\Sentinel\Tests\auditData;

it('settles the entry in the request when the mode is sync', function (): void {
    $written = app(Dispatcher::class)->dispatch(auditData());

    expect($written)->toBeInstanceOf(Audit::class)
        ->and($written?->sequence)->toBe(1);
});

it('hands the settled entry to the callback that asked for it', function (): void {
    $handed = null;

    app(Dispatcher::class)->dispatch(auditData(), settled: function (Audit $audit) use (&$handed): void {
        $handed = $audit->id;
    });

    expect($handed)->toBeString();
});

it('refuses a mode this release has no strategy for, naming the version it arrives in', function (string $mode, string $version): void {
    config()->set('sentinel.mode', $mode);

    expect(fn (): ?Audit => app(Dispatcher::class)->dispatch(auditData()))
        ->toThrow(ConfigurationException::class, "arrives in {$version}");
})->with([
    'queue' => ['queue', 'v0.16.0'],
    'buffered' => ['buffered', 'v0.16.1'],
]);

it('leaves the trail empty when it refuses the mode', function (): void {
    config()->set('sentinel.mode', 'buffered');

    rescue(fn (): ?Audit => app(Dispatcher::class)->dispatch(auditData()), report: false);

    expect(Audit::query()->count())->toBe(0);
});

it('reads the strategy again on every entry, so the mode can change between two of them', function (): void {
    app(Dispatcher::class)->dispatch(auditData());

    config()->set('sentinel.mode', 'queue');

    expect(fn (): ?Audit => app(Dispatcher::class)->dispatch(auditData()))
        ->toThrow(ConfigurationException::class)
        ->and(Audit::query()->count())->toBe(1);
});

it('resolves the synchronous strategy through the container, so an application may replace it', function (): void {
    expect(app(SyncStrategy::class))->toBeInstanceOf(SyncStrategy::class);
});
