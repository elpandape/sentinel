<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Enums\Source;
use ElPandaPe\Sentinel\Events\Audited;
use ElPandaPe\Sentinel\Events\AuditWriteFailed;
use ElPandaPe\Sentinel\Jobs\SettleAudit;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Tests\Fixtures\AuditedSubject;
use ElPandaPe\Sentinel\Tests\Fixtures\FailingLedger;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Sentinel\Tests\auditsTable;
use function ElPandaPe\Sentinel\Tests\httpRequest;
use function ElPandaPe\Sentinel\Tests\verifier;

beforeEach(function (): void {
    config()->set('sentinel.mode', 'queue');
});

it('hands the entry to a job instead of writing it in the request', function (): void {
    Bus::fake();

    new AuditedSubject()->forceFill(['name' => 'Ada'])->save();

    Bus::assertDispatched(SettleAudit::class);

    expect(DB::table(auditsTable())->count())->toBe(0);
});

it('closes the journey of the capturing process without an entry to show for it', function (): void {
    Bus::fake();

    $seen = null;

    app(Dispatcher::class)->listen(Audited::class, static function (Audited $event) use (&$seen): void {
        $seen = $event;
    });

    new AuditedSubject()->forceFill(['name' => 'Ada'])->save();

    expect($seen?->audit->event)->toBe('created')
        ->and($seen?->entry)->toBeNull();
});

it('settles the entry when the worker runs the job', function (): void {
    config()->set('queue.default', 'sync');

    new AuditedSubject()->forceFill(['name' => 'Ada'])->save();

    $entry = Audit::query()->sole();

    expect($entry->event)->toBe('created')
        ->and($entry->sequence)->toBe(1)
        ->and($entry->previous_hash)->toBeNull()
        ->and(verifier()->verifyEntry($entry))->toBeTrue();
});

it('keeps the context of the request that captured it, not of the worker that wrote it', function (): void {
    config()->set('queue.default', 'sync');
    httpRequest('/invoices/500');

    new AuditedSubject()->forceFill(['name' => 'Ada'])->save();

    $entry = Audit::query()->sole();

    expect($entry->source)->toBe(Source::Http)
        ->and($entry->context['url'] ?? null)->toContain('/invoices/500');
});

it('takes the identifier the capture stamped all the way to the entry', function (): void {
    config()->set('queue.default', 'sync');

    new AuditedSubject()->forceFill(['name' => 'Ada'])->save();

    expect(Audit::query()->sole()->capture_id)->toBeString()->toHaveLength(26);
});

it('keeps the clock of the fact to the microsecond across the boundary', function (): void {
    config()->set('queue.default', 'sync');

    $before = new DateTimeImmutable;

    new AuditedSubject()->forceFill(['name' => 'Ada'])->save();

    $entry = Audit::query()->sole();

    expect($entry->occurred_at->format('u'))->not->toBe('000000')
        ->and($entry->occurred_at->getTimestamp())->toBeGreaterThanOrEqual($before->getTimestamp());
});

it('leaves neither an entry nor a job behind when the transaction rolled back', function (): void {
    Bus::fake();

    rescue(static function (): void {
        DB::transaction(static function (): void {
            new AuditedSubject()->forceFill(['name' => 'Ada'])->save();

            throw new RuntimeException('rolled back');
        });
    }, report: false);

    Bus::assertNothingDispatched();

    expect(DB::table(auditsTable())->count())->toBe(0);
});

it('waits for the commit before the job leaves at all', function (): void {
    Bus::fake();

    DB::transaction(static function (): void {
        new AuditedSubject()->forceFill(['name' => 'Ada'])->save();

        Bus::assertNothingDispatched();
    });

    Bus::assertDispatched(SettleAudit::class);
});

it('dispatches on the connection and the queue that were named', function (): void {
    Bus::fake();

    config()->set('queue.connections.audits', ['driver' => 'sync']);
    config()->set('sentinel.queue.connection', 'audits');
    config()->set('sentinel.queue.queue', 'trail');

    new AuditedSubject()->forceFill(['name' => 'Ada'])->save();

    Bus::assertDispatched(
        SettleAudit::class,
        static fn (SettleAudit $job): bool => $job->connection === 'audits' && $job->queue === 'trail',
    );
});

it('lets the application default decide when neither is named', function (): void {
    Bus::fake();

    new AuditedSubject()->forceFill(['name' => 'Ada'])->save();

    Bus::assertDispatched(
        SettleAudit::class,
        static fn (SettleAudit $job): bool => $job->connection === null && $job->queue === null,
    );
});

it('says a write did not complete once, and not once per process that saw it', function (): void {
    config()->set('queue.default', 'sync');
    config()->set('sentinel.on_write_failure', 'log');
    app()->instance(Ledger::class, new FailingLedger);

    $announced = 0;

    app(Dispatcher::class)->listen(AuditWriteFailed::class, static function () use (&$announced): void {
        $announced++;
    });

    new AuditedSubject()->forceFill(['name' => 'Ada'])->save();

    expect($announced)->toBe(1)
        ->and(DB::table(auditsTable())->count())->toBe(0);
});

it('lets a queue that will not take the job decide what the request costs', function (): void {
    config()->set('queue.default', 'sync');
    app()->instance(Ledger::class, new FailingLedger);

    expect(static fn (): mixed => new AuditedSubject()->forceFill(['name' => 'Ada'])->save())
        ->toThrow(RuntimeException::class, FailingLedger::REASON);
});

it('never lets a failure out of a transaction that already committed', function (): void {
    config()->set('queue.default', 'sync');
    app()->instance(Ledger::class, new FailingLedger);

    $announced = 0;

    app(Dispatcher::class)->listen(AuditWriteFailed::class, static function () use (&$announced): void {
        $announced++;
    });

    DB::transaction(static function (): void {
        new AuditedSubject()->forceFill(['name' => 'Ada'])->save();
    });

    expect($announced)->toBe(1)
        ->and(DB::table(auditsTable())->count())->toBe(0);
});
