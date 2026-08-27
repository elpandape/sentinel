<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Enums\FanoutPolicy;
use ElPandaPe\Sentinel\Events\LedgerDestinationFailed;
use ElPandaPe\Sentinel\Exceptions\ConfigurationException;
use ElPandaPe\Sentinel\Exceptions\LedgerException;
use ElPandaPe\Sentinel\Ledger\FanoutLedger;
use ElPandaPe\Sentinel\Ledger\MemoryLedger;
use ElPandaPe\Sentinel\Ledger\NullLedger;
use ElPandaPe\Sentinel\Query\AuditQuery;
use ElPandaPe\Sentinel\Tests\Fixtures\FailingLedger;
use Illuminate\Support\Facades\Event;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\fanout;

it('hands every destination the entry the primary sealed', function (): void {
    $primary = app(MemoryLedger::class);
    $secondary = app(MemoryLedger::class);

    $written = fanout($primary, [$secondary])->write(auditData());

    expect($secondary->find($written->id)?->hash)->toBe($written->hash)
        ->and($secondary->find($written->id)?->sequence)->toBe($written->sequence);
});

it('lets only the primary number the chain', function (): void {
    $primary = app(MemoryLedger::class);
    $secondary = app(MemoryLedger::class);
    $secondary->write(auditData());
    $secondary->write(auditData());

    $written = fanout($primary, [$secondary])->write(auditData());

    expect($written->sequence)->toBe(1)
        ->and(collect($secondary->stream('global'))->pluck('sequence')->all())->toBe([1, 2, 1]);
});

it('fans a whole batch out entry by entry', function (): void {
    $secondary = app(MemoryLedger::class);

    fanout(app(MemoryLedger::class), [$secondary])->writeMany([auditData(), auditData(), auditData()]);

    expect(collect($secondary->stream('global'))->pluck('sequence')->all())->toBe([1, 2, 3]);
});

it('reads from the primary, which is whose chain the sequence belongs to', function (): void {
    $primary = app(MemoryLedger::class);
    $ledger = fanout($primary, [app(NullLedger::class)]);
    $written = $ledger->write(auditData());

    expect($ledger->find($written->id)?->hash)->toBe($written->hash)
        ->and(collect($ledger->stream('global'))->pluck('sequence')->all())->toBe([1]);
});

it('sends a query to the primary, which is the only one that answers for the chain', function (): void {
    expect(fn (): mixed => fanout(app(MemoryLedger::class), [app(NullLedger::class)])->query(new AuditQuery))
        ->toThrow(LedgerException::class);
});

it('fails the whole write under strict when any destination refuses', function (): void {
    expect(fn (): mixed => fanout(app(MemoryLedger::class), [new FailingLedger])->write(auditData()))
        ->toThrow(RuntimeException::class, FailingLedger::REASON);
});

it('settles under primary when a secondary refuses, and says which one did', function (): void {
    Event::fake([LedgerDestinationFailed::class]);

    $written = fanout(app(MemoryLedger::class), [new FailingLedger], FanoutPolicy::Primary)->write(auditData());

    expect($written->sequence)->toBe(1);

    Event::assertDispatched(LedgerDestinationFailed::class, fn (LedgerDestinationFailed $event): bool => $event->destination === FailingLedger::class
        && $event->auditId === $written->id
        && $event->stream === $written->stream
        && $event->sequence === 1);
});

it('keeps writing to the destinations after the one that refused', function (): void {
    $last = app(MemoryLedger::class);

    $written = fanout(app(MemoryLedger::class), [new FailingLedger, $last], FanoutPolicy::Primary)->write(auditData());

    expect($last->find($written->id)?->hash)->toBe($written->hash);
});

it('fails the write under either policy when the primary refuses', function (FanoutPolicy $policy): void {
    expect(fn (): mixed => fanout(new FailingLedger, [app(MemoryLedger::class)], $policy)->write(auditData()))
        ->toThrow(RuntimeException::class, FailingLedger::REASON);
})->with([[FanoutPolicy::Strict], [FanoutPolicy::Primary]]);

it('appends a sealed entry to every destination without resealing it', function (): void {
    $primary = app(MemoryLedger::class);
    $secondary = app(MemoryLedger::class);
    $sealed = $primary->write(auditData());

    fanout(app(MemoryLedger::class), [$secondary])->append($sealed);

    expect($secondary->find($sealed->id)?->hash)->toBe($sealed->hash);
});

it('says the destination failure out loud in the language of the application', function (): void {
    $event = new LedgerDestinationFailed(FailingLedger::class, 'global', 3, '01JXXXXXXXXXXXXXXXXXXXXXXX', new RuntimeException(FailingLedger::REASON));

    expect($event->message())->toContain(FailingLedger::class, 'global', '3', FailingLedger::REASON);
});

it('composes the destinations the configuration names, in the order it names them', function (): void {
    config()->set('sentinel.ledger.default', 'fanout');
    config()->set('sentinel.ledger.ledgers.fanout.destinations', ['memory', 'null']);
    app()->forgetScopedInstances();

    $ledger = app(Ledger::class);
    $written = $ledger->write(auditData());

    expect($ledger)->toBeInstanceOf(FanoutLedger::class)
        ->and($ledger->find($written->id)?->hash)->toBe($written->hash);
});

it('refuses a fanout that names itself as a destination', function (): void {
    config()->set('sentinel.ledger.default', 'fanout');
    config()->set('sentinel.ledger.ledgers.fanout.destinations', ['database', 'fanout']);
    app()->forgetScopedInstances();

    expect(fn (): Ledger => app(Ledger::class))
        ->toThrow(ConfigurationException::class, 'ledger.ledgers.fanout.destinations');
});

it('refuses a destination it does not know, naming the key that declared it', function (): void {
    config()->set('sentinel.ledger.default', 'fanout');
    config()->set('sentinel.ledger.ledgers.fanout.destinations', ['database', 'nonesuch']);
    app()->forgetScopedInstances();

    expect(fn (): Ledger => app(Ledger::class))
        ->toThrow(ConfigurationException::class, 'ledger.ledgers.fanout.destinations');
});
