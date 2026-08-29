<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use ElPandaPe\Sentinel\Buffer\MemoryBuffer;
use ElPandaPe\Sentinel\Capture\Recorder;
use ElPandaPe\Sentinel\Contracts\Buffer;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Dispatch\Dispatcher;
use ElPandaPe\Sentinel\Enums\Mode;
use ElPandaPe\Sentinel\Events\Audited;
use ElPandaPe\Sentinel\Events\AuditWriteFailed;
use ElPandaPe\Sentinel\Jobs\SettleAudit;
use ElPandaPe\Sentinel\Tests\Fixtures\CountingBuffer;
use ElPandaPe\Sentinel\Tests\Fixtures\FailingLedger;
use Illuminate\Contracts\Events\Dispatcher as Events;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\auditsTable;
use function ElPandaPe\Sentinel\Tests\frozenUlid;
use function ElPandaPe\Sentinel\Tests\ledger;
use function ElPandaPe\Sentinel\Tests\verifier;

it('settles a batch as one chain and hands every entry back', function (): void {
    $written = app(Dispatcher::class)->dispatchMany([auditData(), auditData(), auditData()]);

    expect($written)->toHaveCount(3)
        ->and($written->pluck('sequence')->all())->toBe([1, 2, 3])
        ->and(verifier()->verifyStream('global')->isIntact())->toBeTrue();
});

it('reads the tail of the stream once for the whole batch, which is why a batch exists', function (): void {
    DB::flushQueryLog();
    DB::enableQueryLog();

    app(Dispatcher::class)->dispatchMany([auditData(), auditData(), auditData()]);

    $tails = array_filter(DB::getRawQueryLog(), static function (array $query): bool {
        $sql = strtolower((string) $query['raw_query']);

        return str_contains($sql, 'sequence') && str_contains($sql, 'desc');
    });

    expect($tails)->toHaveCount(1);
});

it('announces one journey per entry, not one per batch', function (): void {
    $announced = 0;

    app(Events::class)->listen(Audited::class, static function () use (&$announced): void {
        $announced++;
    });

    app(Dispatcher::class)->dispatchMany([auditData(), auditData()]);

    expect($announced)->toBe(2);
});

it('does nothing at all for a batch with nothing in it', function (): void {
    expect(app(Dispatcher::class)->dispatchMany([]))->toBeEmpty()
        ->and(DB::table(auditsTable())->count())->toBe(0);
});

it('refuses only the entry that already had one, and announces the rest', function (): void {
    $landed = frozenUlid('LANDED01');
    ledger()->write(auditData(['capture_id' => $landed]));

    $announced = 0;
    app(Events::class)->listen(Audited::class, static function () use (&$announced): void {
        $announced++;
    });

    $written = app(Dispatcher::class)->dispatchMany([
        auditData(['capture_id' => $landed]),
        auditData(['capture_id' => frozenUlid('FRESH001')]),
    ]);

    expect($written)->toHaveCount(1)
        ->and($announced)->toBe(1)
        ->and(DB::table(auditsTable())->count())->toBe(2);
});

it('reports a batch that could not settle once, not once per entry', function (): void {
    config()->set('sentinel.on_write_failure', 'log');
    app()->instance(Ledger::class, new FailingLedger);

    $failures = 0;
    app(Events::class)->listen(AuditWriteFailed::class, static function () use (&$failures): void {
        $failures++;
    });

    $written = app(Dispatcher::class)->dispatchMany([auditData(), auditData(), auditData()]);

    expect($written)->toBeEmpty()->and($failures)->toBe(1);
});

it('propagates a failed batch to the caller when the policy says to throw', function (): void {
    app()->instance(Ledger::class, new FailingLedger);

    expect(static fn (): mixed => app(Dispatcher::class)->dispatchMany([auditData(), auditData()]))
        ->toThrow(RuntimeException::class, FailingLedger::REASON);
});

it('queues one job per entry, because that is what the queue mode is', function (): void {
    config()->set('sentinel.mode', Mode::Queue->value);
    Bus::fake();

    app(Dispatcher::class)->dispatchMany([auditData(), auditData(), auditData()]);

    Bus::assertDispatchedTimes(SettleAudit::class, 3);
});

it('buffers the whole batch instead of writing any of it', function (): void {
    config()->set('sentinel.mode', Mode::Buffered->value);
    config()->set('sentinel.buffer.store', 'memory');

    $now = CarbonImmutable::now();

    app(Dispatcher::class)->dispatchMany([
        auditData(['occurred_at' => $now]),
        auditData(['occurred_at' => $now]),
        auditData(['occurred_at' => $now]),
    ]);

    expect(app(Buffer::class)->size())->toBe(3)
        ->and(DB::table(auditsTable())->count())->toBe(0);
});

it('reads the thresholds once for the batch rather than once per entry', function (): void {
    config()->set('sentinel.mode', Mode::Buffered->value);
    config()->set('sentinel.buffer.store', 'memory');

    $now = CarbonImmutable::now();
    $counted = new CountingBuffer(app(MemoryBuffer::class));
    app()->instance(Buffer::class, $counted);

    app(Dispatcher::class)->dispatchMany([
        auditData(['occurred_at' => $now]),
        auditData(['occurred_at' => $now]),
        auditData(['occurred_at' => $now]),
    ]);

    expect($counted->pushes)->toBe(3)->and($counted->sizes)->toBe(1);
});

it('holds the batch for the commit and lands it whole when the transaction ends', function (): void {
    DB::transaction(static function (): void {
        app(Dispatcher::class)->dispatchMany([auditData(), auditData(), auditData()]);
    });

    expect(DB::table(auditsTable())->count())->toBe(3)
        ->and(verifier()->verifyStream('global')->isIntact())->toBeTrue();
});

it('writes nothing at all for a batch the rollback threw away', function (): void {
    rescue(static function (): void {
        DB::transaction(static function (): void {
            app(Dispatcher::class)->dispatchMany([auditData(), auditData()]);

            throw new RuntimeException('undo');
        });
    }, report: false);

    expect(DB::table(auditsTable())->count())->toBe(0);
});

it('queues the deferred batch one job at a time', function (): void {
    config()->set('sentinel.mode', Mode::Queue->value);
    Bus::fake();

    DB::transaction(static function (): void {
        app(Dispatcher::class)->dispatchMany([auditData(), auditData()]);
    });

    Bus::assertDispatchedTimes(SettleAudit::class, 2);
});

it('buffers the deferred batch rather than refusing the request it can no longer reach', function (): void {
    config()->set('sentinel.mode', Mode::Buffered->value);
    config()->set('sentinel.buffer.store', 'memory');

    $now = CarbonImmutable::now();

    DB::transaction(static function () use ($now): void {
        app(Dispatcher::class)->dispatchMany([
            auditData(['occurred_at' => $now]),
            auditData(['occurred_at' => $now]),
        ]);
    });

    expect(app(Buffer::class)->size())->toBe(2);
});

it('reports a deferred batch that failed without throwing out of a transaction that committed', function (): void {
    app()->instance(Ledger::class, new FailingLedger);

    $failures = 0;
    app(Events::class)->listen(AuditWriteFailed::class, static function () use (&$failures): void {
        $failures++;
    });

    DB::transaction(static function (): void {
        app(Dispatcher::class)->dispatchMany([auditData(), auditData()]);
    });

    expect($failures)->toBe(1);
});

it('runs the pipeline once per entry and lands what survives it', function (): void {
    $written = app(Recorder::class)->recordMany([auditData(), auditData()]);

    expect($written)->toHaveCount(2)
        ->and($written->pluck('capture_id')->filter()->all())->toHaveCount(2);
});

it('drops the entries a policy refused and settles the ones it did not', function (): void {
    app(ElPandaPe\Sentinel\Sentinel::class)->filter(
        static fn (ElPandaPe\Sentinel\Data\AuditData $audit): bool => $audit->event !== 'deleted',
    );

    $written = app(Recorder::class)->recordMany([
        auditData(['event' => 'created']),
        auditData(['event' => 'deleted']),
    ]);

    expect($written)->toHaveCount(1)
        ->and($written->first()?->event)->toBe('created');
});
