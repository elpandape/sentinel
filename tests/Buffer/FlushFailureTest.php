<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Buffer\Flusher;
use ElPandaPe\Sentinel\Buffer\MemoryBuffer;
use ElPandaPe\Sentinel\Contracts\Buffer;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Events\BufferFlushFailed;
use ElPandaPe\Sentinel\Ledger\DatabaseLedger;
use ElPandaPe\Sentinel\Tests\Fixtures\AuditedSubject;
use ElPandaPe\Sentinel\Tests\Fixtures\FailingLedger;
use ElPandaPe\Sentinel\Tests\Fixtures\LateFailingLedger;
use ElPandaPe\Sentinel\Tests\Fixtures\OneWayBuffer;
use ElPandaPe\Sentinel\Tests\Fixtures\UnreadableBuffer;
use Illuminate\Queue\Events\WorkerStopping;
use Illuminate\Support\Facades\Event;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\frozenUlid;
use function ElPandaPe\Sentinel\Tests\ledger;

beforeEach(function (): void {
    config()->set('sentinel.mode', 'buffered');
    config()->set('sentinel.buffer.store', 'memory');
    config()->set('sentinel.buffer.size', 500);
    config()->set('sentinel.on_write_failure', 'log');
});

it('says a flush failed when the end of the request is what triggered it', function (): void {
    new AuditedSubject()->forceFill(['name' => 'Ada'])->save();

    app()->instance(Ledger::class, new FailingLedger);

    $announced = [];
    Event::listen(BufferFlushFailed::class, static function (BufferFlushFailed $event) use (&$announced): void {
        $announced[] = $event;
    });

    app()->terminate();

    expect($announced)->toHaveCount(1)
        ->and($announced[0]->returned)->toBe(1)
        ->and($announced[0]->reason->getMessage())->toBe(FailingLedger::REASON);
});

it('says a flush failed when the worker stopping is what triggered it', function (): void {
    new AuditedSubject()->forceFill(['name' => 'Ada'])->save();

    app()->instance(Ledger::class, new FailingLedger);

    $announced = [];
    Event::listen(BufferFlushFailed::class, static function (BufferFlushFailed $event) use (&$announced): void {
        $announced[] = $event;
    });

    event(new WorkerStopping);

    expect($announced)->toHaveCount(1)
        ->and($announced[0]->returned)->toBe(1);
});

it('says a flush failed when the size threshold is what triggered it', function (): void {
    config()->set('sentinel.buffer.size', 1);
    app()->instance(Ledger::class, new FailingLedger);

    $announced = [];
    Event::listen(BufferFlushFailed::class, static function (BufferFlushFailed $event) use (&$announced): void {
        $announced[] = $event;
    });

    new AuditedSubject()->forceFill(['name' => 'Ada'])->save();

    expect($announced)->toHaveCount(1)
        ->and($announced[0]->taken)->toBe(1);
});

it('says a flush failed when the interval is what triggered it', function (): void {
    config()->set('sentinel.buffer.flush_interval', 60);

    new AuditedSubject()->forceFill(['name' => 'Ada'])->save();

    app()->instance(Ledger::class, new FailingLedger);

    $announced = [];
    Event::listen(BufferFlushFailed::class, static function (BufferFlushFailed $event) use (&$announced): void {
        $announced[] = $event;
    });

    $this->travel(61)->seconds();

    new AuditedSubject()->forceFill(['name' => 'Grace'])->save();

    expect($announced)->toHaveCount(1)
        ->and($announced[0]->taken)->toBe(2);
});

it('says a flush failed when the command is what triggered it', function (): void {
    app(Buffer::class)->push(auditData());

    app()->instance(Ledger::class, new FailingLedger);

    $announced = [];
    Event::listen(BufferFlushFailed::class, static function (BufferFlushFailed $event) use (&$announced): void {
        $announced[] = $event;
    });

    $this->artisan('sentinel:flush')->assertExitCode(1);

    expect($announced)->toHaveCount(1);
});

it('balances what it took against what settled, what was skipped and what went back', function (): void {
    config()->set('sentinel.buffer.size', 2);

    $landed = frozenUlid('BALANCE1');

    ledger()->write(auditData(['capture_id' => $landed]));

    app()->instance(Ledger::class, new LateFailingLedger(app(DatabaseLedger::class), 1));

    $buffer = app(Buffer::class);
    $buffer->push(auditData(['capture_id' => $landed]));

    foreach (['BALANCE2', 'BALANCE3', 'BALANCE4'] as $suffix) {
        $buffer->push(auditData(['capture_id' => frozenUlid($suffix)]));
    }

    $announced = [];
    Event::listen(BufferFlushFailed::class, static function (BufferFlushFailed $event) use (&$announced): void {
        $announced[] = $event;
    });

    expect(static fn (): int => app(Flusher::class)->flush())->toThrow(LateFailingLedger::REASON)
        ->and($announced)->toHaveCount(1)
        ->and($announced[0]->taken)->toBe(4)
        ->and($announced[0]->settled)->toBe(1)
        ->and($announced[0]->skipped())->toBe(1)
        ->and($announced[0]->returned)->toBe(2)
        ->and($announced[0]->taken)->toBe($announced[0]->settled + $announced[0]->skipped() + $announced[0]->returned);
});

it('renders what it carries in the language the application is using', function (): void {
    $event = new BufferFlushFailed(4, 1, 2, new RuntimeException('the ledger is unreachable'));

    expect($event->message())->toContain('the ledger is unreachable')
        ->and($event->message())->toContain('4')
        ->and($event->message())->toContain('2');

    app()->setLocale('es');

    expect($event->message())->toContain('El buffer no se pudo asentar');
});

it('says a flush failed when the buffer could not even be read', function (): void {
    app()->instance(Buffer::class, new UnreadableBuffer(new MemoryBuffer));

    $announced = [];
    Event::listen(BufferFlushFailed::class, static function (BufferFlushFailed $event) use (&$announced): void {
        $announced[] = $event;
    });

    expect(static fn (): int => app(Flusher::class)->flush())->toThrow(UnreadableBuffer::REASON)
        ->and($announced)->toHaveCount(1)
        ->and($announced[0]->taken)->toBe(0)
        ->and($announced[0]->settled)->toBe(0)
        ->and($announced[0]->returned)->toBe(0)
        ->and($announced[0]->skipped())->toBe(0);
});

it('leaves nothing in limbo when the buffer stops answering partway through', function (): void {
    config()->set('sentinel.buffer.size', 1);

    $buffer = new UnreadableBuffer(new MemoryBuffer, 2);
    app()->instance(Buffer::class, $buffer);

    foreach (['READ1', 'READ2'] as $suffix) {
        $buffer->push(auditData(['capture_id' => frozenUlid($suffix)]));
    }

    $announced = [];
    Event::listen(BufferFlushFailed::class, static function (BufferFlushFailed $event) use (&$announced): void {
        $announced[] = $event;
    });

    expect(static fn (): int => app(Flusher::class)->flush())->toThrow(UnreadableBuffer::REASON)
        ->and($announced)->toHaveCount(1)
        ->and($announced[0]->taken)->toBe(2)
        ->and($announced[0]->settled)->toBe(2)
        ->and($announced[0]->returned)->toBe(0)
        ->and($announced[0]->skipped())->toBe(0);
});

it('counts a batch the buffer refuses to take back as lost, and still blames the ledger', function (): void {
    $buffer = new OneWayBuffer(new MemoryBuffer);
    app()->instance(Buffer::class, $buffer);
    app()->instance(Ledger::class, new FailingLedger);

    $buffer->push(auditData());

    $announced = [];
    Event::listen(BufferFlushFailed::class, static function (BufferFlushFailed $event) use (&$announced): void {
        $announced[] = $event;
    });

    expect(static fn (): int => app(Flusher::class)->flush())->toThrow(FailingLedger::REASON)
        ->and($announced)->toHaveCount(1)
        ->and($announced[0]->taken)->toBe(1)
        ->and($announced[0]->settled)->toBe(0)
        ->and($announced[0]->returned)->toBe(0)
        ->and($announced[0]->skipped())->toBe(1)
        ->and($announced[0]->reason->getMessage())->toBe(FailingLedger::REASON);
});
