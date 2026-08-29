<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Contracts\Buffer;
use ElPandaPe\Sentinel\Dispatch\BufferStrategy;
use ElPandaPe\Sentinel\Dispatch\Dispatcher;
use ElPandaPe\Sentinel\Dispatch\QueueStrategy;
use ElPandaPe\Sentinel\Dispatch\SyncStrategy;
use ElPandaPe\Sentinel\Enums\Mode;
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

it('has a strategy for every mode the configuration accepts', function (Mode $mode, string $strategy): void {
    config()->set('sentinel.mode', $mode->value);
    config()->set('sentinel.buffer.store', 'memory');

    app(Dispatcher::class)->dispatch(auditData());

    expect(app($strategy))->toBeInstanceOf($strategy);
})->with([
    'sync' => [Mode::Sync, SyncStrategy::class],
    'queue' => [Mode::Queue, QueueStrategy::class],
    'buffered' => [Mode::Buffered, BufferStrategy::class],
]);

it('reads the strategy again on every entry, so the mode can change between two of them', function (): void {
    app(Dispatcher::class)->dispatch(auditData());

    config()->set('sentinel.mode', 'buffered');
    config()->set('sentinel.buffer.store', 'memory');
    config()->set('sentinel.buffer.size', 500);

    app(Dispatcher::class)->dispatch(auditData(['occurred_at' => new DateTimeImmutable]));

    expect(Audit::query()->count())->toBe(1)
        ->and(app(Buffer::class)->size())->toBe(1);
});

it('resolves the synchronous strategy through the container, so an application may replace it', function (): void {
    expect(app(SyncStrategy::class))->toBeInstanceOf(SyncStrategy::class);
});
