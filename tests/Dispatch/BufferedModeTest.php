<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Buffer\Flusher;
use ElPandaPe\Sentinel\Contracts\Buffer;
use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Enums\Source;
use ElPandaPe\Sentinel\Events\Audited;
use ElPandaPe\Sentinel\Events\AuditWriteFailed;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Tests\Fixtures\AuditedSubject;
use ElPandaPe\Sentinel\Tests\Fixtures\FailingLedger;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Sentinel\Tests\auditsTable;
use function ElPandaPe\Sentinel\Tests\httpRequest;
use function ElPandaPe\Sentinel\Tests\verifier;

beforeEach(function (): void {
    config()->set('sentinel.mode', 'buffered');
    config()->set('sentinel.buffer.store', 'memory');
});

it('holds the entry in the buffer instead of writing it', function (): void {
    new AuditedSubject()->forceFill(['name' => 'Ada'])->save();

    expect(app(Buffer::class)->size())->toBe(1)
        ->and(DB::table(auditsTable())->count())->toBe(0);
});

it('closes the journey of the capturing process without an entry to show for it', function (): void {
    $seen = null;

    app(Dispatcher::class)->listen(Audited::class, static function (Audited $event) use (&$seen): void {
        $seen = $event;
    });

    new AuditedSubject()->forceFill(['name' => 'Ada'])->save();

    expect($seen?->audit->event)->toBe('created')
        ->and($seen?->entry)->toBeNull();
});

it('settles everything waiting when the batch fills up', function (): void {
    config()->set('sentinel.buffer.size', 3);

    foreach (['Ada', 'Grace', 'Barbara'] as $name) {
        new AuditedSubject()->forceFill(['name' => $name])->save();
    }

    expect(app(Buffer::class)->size())->toBe(0)
        ->and(Audit::query()->count())->toBe(3)
        ->and(verifier()->verifyStream('global')->isIntact())->toBeTrue();
});

it('settles what has waited longer than the interval allows', function (): void {
    config()->set('sentinel.buffer.size', 500);
    config()->set('sentinel.buffer.flush_interval', 60);

    new AuditedSubject()->forceFill(['name' => 'Ada'])->save();

    expect(Audit::query()->count())->toBe(0);

    $this->travel(61)->seconds();

    new AuditedSubject()->forceFill(['name' => 'Grace'])->save();

    expect(Audit::query()->count())->toBe(2)
        ->and(app(Buffer::class)->size())->toBe(0);
});

it('keeps the context of the request that captured it, not of the flush that settled it', function (): void {
    config()->set('sentinel.buffer.size', 1);
    httpRequest('/invoices/500');

    new AuditedSubject()->forceFill(['name' => 'Ada'])->save();

    $entry = Audit::query()->sole();

    expect($entry->source)->toBe(Source::Http)
        ->and($entry->context['url'] ?? null)->toContain('/invoices/500');
});

it('waits for the commit before the entry even reaches the buffer', function (): void {
    DB::transaction(static function (): void {
        new AuditedSubject()->forceFill(['name' => 'Ada'])->save();

        expect(app(Buffer::class)->size())->toBe(0);
    });

    expect(app(Buffer::class)->size())->toBe(1);
});

it('buffers nothing at all when the transaction rolled back', function (): void {
    rescue(static function (): void {
        DB::transaction(static function (): void {
            new AuditedSubject()->forceFill(['name' => 'Ada'])->save();

            throw new RuntimeException('rolled back');
        });
    }, report: false);

    expect(app(Buffer::class)->size())->toBe(0)
        ->and(DB::table(auditsTable())->count())->toBe(0);
});

it('keeps the batch when the ledger refuses it, so nothing is lost to a failed write', function (): void {
    config()->set('sentinel.buffer.size', 1);
    config()->set('sentinel.on_write_failure', 'log');
    app()->instance(Ledger::class, new FailingLedger);

    new AuditedSubject()->forceFill(['name' => 'Ada'])->save();

    expect(app(Buffer::class)->size())->toBe(1)
        ->and(DB::table(auditsTable())->count())->toBe(0);
});

it('says a write did not complete when the flush could not settle it', function (): void {
    config()->set('sentinel.buffer.size', 1);
    config()->set('sentinel.on_write_failure', 'log');
    app()->instance(Ledger::class, new FailingLedger);

    $announced = 0;

    app(Dispatcher::class)->listen(AuditWriteFailed::class, static function () use (&$announced): void {
        $announced++;
    });

    new AuditedSubject()->forceFill(['name' => 'Ada'])->save();

    expect($announced)->toBe(1);
});

it('lets the configured policy decide what a failed flush costs the request', function (): void {
    config()->set('sentinel.buffer.size', 1);
    app()->instance(Ledger::class, new FailingLedger);

    expect(static fn (): mixed => new AuditedSubject()->forceFill(['name' => 'Ada'])->save())
        ->toThrow(RuntimeException::class, FailingLedger::REASON);
});

it('settles a buffer larger than one batch in as many batches as it takes', function (): void {
    config()->set('sentinel.buffer.size', 2);

    $buffer = app(Buffer::class);

    foreach (range(1, 5) as $n) {
        $buffer->push(ElPandaPe\Sentinel\Tests\auditData(['capture_id' => str_pad((string) $n, 26, '0', STR_PAD_LEFT)]));
    }

    expect(app(Flusher::class)->flush())->toBe(5)
        ->and($buffer->size())->toBe(0)
        ->and(verifier()->verifyStream('global')->isIntact())->toBeTrue();
});
