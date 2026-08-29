<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Events\AuditCreated;
use ElPandaPe\Sentinel\Events\Audited;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Tests\Fixtures\AuditedSubject;
use ElPandaPe\Sentinel\Tests\Fixtures\FailingLedger;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Sentinel\Tests\auditsTable;

it('says the capturing process is done with the entry', function (): void {
    $seen = null;

    app(Dispatcher::class)->listen(Audited::class, static function (Audited $event) use (&$seen): void {
        $seen = $event;
    });

    new AuditedSubject()->forceFill(['name' => 'Ada'])->save();

    expect($seen?->audit->event)->toBe('created')
        ->and($seen?->entry)->toBeInstanceOf(Audit::class);
});

it('carries the entry where the capture and the settlement are the same place', function (): void {
    $seen = null;

    app(Dispatcher::class)->listen(Audited::class, static function (Audited $event) use (&$seen): void {
        $seen = $event->entry;
    });

    new AuditedSubject()->forceFill(['name' => 'Ada'])->save();

    expect($seen?->sequence)->toBe(1)
        ->and($seen?->hash)->not->toBeNull();
});

it('arrives after the entry has identity, never before', function (): void {
    $order = [];

    app(Dispatcher::class)->listen(AuditCreated::class, static function () use (&$order): void {
        $order[] = 'created';
    });

    app(Dispatcher::class)->listen(Audited::class, static function () use (&$order): void {
        $order[] = 'audited';
    });

    new AuditedSubject()->forceFill(['name' => 'Ada'])->save();

    expect($order)->toBe(['created', 'audited']);
});

it('announces it once per entry', function (): void {
    $announced = 0;

    app(Dispatcher::class)->listen(Audited::class, static function () use (&$announced): void {
        $announced++;
    });

    $subject = new AuditedSubject()->forceFill(['name' => 'Ada']);
    $subject->save();
    $subject->forceFill(['name' => 'Grace'])->save();

    expect($announced)->toBe(2);
});

it('waits for the commit, like the write it closes', function (): void {
    $announced = 0;

    app(Dispatcher::class)->listen(Audited::class, static function () use (&$announced): void {
        $announced++;
    });

    DB::transaction(function () use (&$announced): void {
        new AuditedSubject()->forceFill(['name' => 'Ada'])->save();

        expect($announced)->toBe(0);
    });

    expect($announced)->toBe(1);
});

it('says nothing at all when the transaction rolled back', function (): void {
    $announced = 0;

    app(Dispatcher::class)->listen(Audited::class, static function () use (&$announced): void {
        $announced++;
    });

    rescue(static function (): void {
        DB::transaction(static function (): void {
            new AuditedSubject()->forceFill(['name' => 'Ada'])->save();

            throw new RuntimeException('rolled back');
        });
    }, report: false);

    expect($announced)->toBe(0)
        ->and(DB::table(auditsTable())->count())->toBe(0);
});

it('says nothing when the write did not complete, because that is what the failure event is for', function (): void {
    config()->set('sentinel.on_write_failure', 'log');
    app()->instance(Ledger::class, new FailingLedger);

    $announced = 0;

    app(Dispatcher::class)->listen(Audited::class, static function () use (&$announced): void {
        $announced++;
    });

    new AuditedSubject()->forceFill(['name' => 'Ada'])->save();

    expect($announced)->toBe(0);
});
