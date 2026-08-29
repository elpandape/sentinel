<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Contracts\Buffer;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Tests\Fixtures\AuditedSubject;
use ElPandaPe\Sentinel\Tests\Fixtures\FailingLedger;
use Illuminate\Queue\Events\WorkerStopping;

use function ElPandaPe\Sentinel\Tests\verifier;

beforeEach(function (): void {
    config()->set('sentinel.mode', 'buffered');
    config()->set('sentinel.buffer.store', 'memory');
    config()->set('sentinel.buffer.size', 500);
});

it('settles what is waiting when the request ends', function (): void {
    new AuditedSubject()->forceFill(['name' => 'Ada'])->save();

    expect(Audit::query()->count())->toBe(0);

    app()->terminate();

    expect(Audit::query()->count())->toBe(1)
        ->and(app(Buffer::class)->size())->toBe(0);
});

it('settles what is waiting when the worker stops', function (): void {
    new AuditedSubject()->forceFill(['name' => 'Ada'])->save();
    new AuditedSubject()->forceFill(['name' => 'Grace'])->save();

    event(new WorkerStopping);

    expect(Audit::query()->count())->toBe(2)
        ->and(verifier()->verifyStream('global')->isIntact())->toBeTrue();
});

it('leaves the buffer alone under a mode that does not have one', function (): void {
    config()->set('sentinel.mode', 'sync');

    new AuditedSubject()->forceFill(['name' => 'Ada'])->save();

    app()->terminate();

    expect(Audit::query()->count())->toBe(1);
});

it('reports a shutdown flush that failed instead of throwing at nobody', function (): void {
    new AuditedSubject()->forceFill(['name' => 'Ada'])->save();

    app()->instance(Ledger::class, new FailingLedger);

    app()->terminate();

    expect(app(Buffer::class)->size())->toBe(1)
        ->and(Audit::query()->count())->toBe(0);
});

it('settles nothing and complains about nothing when the buffer is empty', function (): void {
    app()->terminate();
    event(new WorkerStopping);

    expect(Audit::query()->count())->toBe(0);
});
