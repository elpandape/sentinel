<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Events\AuditCreated;
use ElPandaPe\Sentinel\Events\AuditCreating;
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Pipeline\Discard;
use ElPandaPe\Sentinel\Tests\Fixtures\AuditedSubject;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Sentinel\Tests\auditsTable;

it('says an entry is about to be written before it has one', function (): void {
    $seen = null;

    app(Dispatcher::class)->listen(AuditCreating::class, static function (AuditCreating $event) use (&$seen): void {
        $seen = $event->audit;
    });

    new AuditedSubject()->forceFill(['name' => 'Ada'])->save();

    expect($seen?->event)->toBe('created')
        ->and($seen?->subject_type)->toBe(AuditedSubject::class);
});

it('hands over the entry with its place in the chain once it has one', function (): void {
    $seen = null;

    app(Dispatcher::class)->listen(AuditCreated::class, static function (AuditCreated $event) use (&$seen): void {
        $seen = $event->entry;
    });

    new AuditedSubject()->forceFill(['name' => 'Ada'])->save();

    expect($seen)->toBeInstanceOf(Audit::class)
        ->and($seen?->sequence)->toBe(1)
        ->and($seen?->hash)->not->toBeNull()
        ->and($seen?->exists)->toBeTrue();
});

it('announces each of them once per entry', function (): void {
    $creating = 0;
    $created = 0;

    app(Dispatcher::class)->listen(AuditCreating::class, static function () use (&$creating): void {
        $creating++;
    });

    app(Dispatcher::class)->listen(AuditCreated::class, static function () use (&$created): void {
        $created++;
    });

    $record = new AuditedSubject()->forceFill(['name' => 'Ada']);
    $record->save();
    $record->update(['name' => 'Grace']);
    $record->delete();

    expect([$creating, $created])->toBe([3, 3]);
});

it('waits for the commit before saying anything was created', function (): void {
    $created = null;

    app(Dispatcher::class)->listen(AuditCreated::class, static function (AuditCreated $event) use (&$created): void {
        $created = $event->entry;
    });

    DB::transaction(static function () use (&$created): void {
        new AuditedSubject()->forceFill(['name' => 'Ada'])->save();

        expect($created)->toBeNull();
    });

    expect($created)->toBeInstanceOf(Audit::class);
});

it('says nothing was created when the transaction rolled back', function (): void {
    $creating = 0;
    $created = 0;

    app(Dispatcher::class)->listen(AuditCreating::class, static function () use (&$creating): void {
        $creating++;
    });

    app(Dispatcher::class)->listen(AuditCreated::class, static function () use (&$created): void {
        $created++;
    });

    rescue(static function (): void {
        DB::transaction(static function (): void {
            new AuditedSubject()->forceFill(['name' => 'Ada'])->save();

            throw new RuntimeException('undo');
        });
    }, report: false);

    expect([$creating, $created])->toBe([0, 0]);
});

it('refuses a discard asked for once the ledger is at the door', function (): void {
    app(Dispatcher::class)->listen(AuditCreating::class, static function (): void {
        app(Discard::class)->because('too late');
    });

    expect(static fn (): mixed => new AuditedSubject()->forceFill(['name' => 'Ada'])->save())
        ->toThrow(LogicException::class, 'verifyIntegrity()');
});

it('leaves the chain contiguous after a discard was refused that late', function (): void {
    $record = new AuditedSubject()->forceFill(['name' => 'Ada']);
    $record->save();

    app(Dispatcher::class)->listen(AuditCreating::class, static function (): void {
        app(Discard::class)->because('too late');
    });

    rescue(static fn (): mixed => $record->update(['name' => 'Grace']), report: false);

    Sentinel::withoutAuditing(static fn (): mixed => null);

    expect(DB::table(auditsTable())->orderBy('sequence')->pluck('sequence')->all())->toBe([1])
        ->and(Sentinel::verifyIntegrity('global')->isIntact())->toBeTrue();
});
