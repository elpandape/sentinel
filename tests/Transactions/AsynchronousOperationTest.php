<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Contracts\Ledger;
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Models\AuditTransaction;
use ElPandaPe\Sentinel\Tests\Fixtures\AuditedSubject;
use ElPandaPe\Sentinel\Tests\Fixtures\FailingLedger;
use Illuminate\Support\Facades\Bus;

beforeEach(function (): void {
    config()->set('sentinel.mode', 'queue');
});

it('counts what the operation handed over, since the entry settles in another process', function (): void {
    Bus::fake();

    Sentinel::transaction('close-invoices', static function (): void {
        new AuditedSubject()->forceFill(['name' => 'Ada'])->save();
        new AuditedSubject()->forceFill(['name' => 'Grace'])->save();
    });

    expect(AuditTransaction::query()->sole()->audits_count)->toBe(2)
        ->and(Audit::query()->count())->toBe(0);
});

it('carries the operation identifier all the way to the entry the worker writes', function (): void {
    config()->set('queue.default', 'sync');

    Sentinel::transaction('close-invoices', static function (): void {
        new AuditedSubject()->forceFill(['name' => 'Ada'])->save();
    });

    $header = AuditTransaction::query()->sole();

    expect(Audit::query()->sole()->transaction_id)->toBe($header->id)
        ->and($header->audits_count)->toBe(1);
});

it('counts nothing for a capture the pipeline discarded', function (): void {
    Bus::fake();

    Sentinel::transaction('close-invoices', static function (): void {
        $subject = new AuditedSubject()->forceFill(['name' => 'Ada']);
        $subject->save();
        $subject->save();
    });

    expect(AuditTransaction::query()->sole()->audits_count)->toBe(1);
});

it('counts nothing for a hand-over that was refused', function (): void {
    config()->set('queue.default', 'sync');
    config()->set('sentinel.on_write_failure', 'log');
    app()->instance(Ledger::class, new FailingLedger);

    Sentinel::transaction('close-invoices', static function (): void {
        new AuditedSubject()->forceFill(['name' => 'Ada'])->save();
    });

    expect(AuditTransaction::query()->sole()->audits_count)->toBe(0);
});
