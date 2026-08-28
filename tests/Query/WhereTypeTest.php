<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Enums\Filter;
use ElPandaPe\Sentinel\Exceptions\LedgerException;
use ElPandaPe\Sentinel\Exceptions\QueryException;
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Ledger\MemoryLedger;
use ElPandaPe\Sentinel\Query\AuditQuery;
use ElPandaPe\Sentinel\Tests\Fixtures\NarrowLedger;
use ElPandaPe\Sentinel\Tests\Fixtures\TransitioningSubject;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\auditQuery;

beforeEach(function (): void {
    $this->invoice = TransitioningSubject::query()->create(['name' => 'invoice', 'status' => 'draft']);
    $this->invoice->update(['status' => 'published']);

    Sentinel::event('invoice.approved')->subject($this->invoice)->record();
});

it('narrows to one kind of entry and leaves the others out', function (): void {
    expect(Sentinel::audits()->whereType('transition')->get())->toHaveCount(1)
        ->and(Sentinel::audits()->whereType('custom')->get())->toHaveCount(1)
        ->and(Sentinel::audits()->whereType('model')->get())->toHaveCount(1)
        ->and(Sentinel::audits()->get())->toHaveCount(3);
});

it('tells the kind of entry apart from the name of what happened', function (): void {
    $this->invoice->update(['name' => 'renamed']);
    Sentinel::event('updated')->subject($this->invoice)->record();

    expect(Sentinel::audits()->whereEvent('updated')->get())->toHaveCount(2)
        ->and(Sentinel::audits()->whereType('model')->whereEvent('updated')->get())->toHaveCount(1)
        ->and(Sentinel::audits()->whereType('custom')->whereEvent('updated')->get())->toHaveCount(1);
});

it('answers the same question over a ledger with no database under it', function (): void {
    $ledger = app(MemoryLedger::class);

    $ledger->write(auditData(['audit_type' => 'transition', 'event' => 'transition']));
    $ledger->write(auditData(['audit_type' => 'model', 'event' => 'updated']));

    expect(auditQuery($ledger)->whereType('transition')->get())->toHaveCount(1)
        ->and(auditQuery($ledger)->whereType('model')->get())->toHaveCount(1);
});

it('refuses an empty kind rather than handing back the whole trail', function (): void {
    Sentinel::audits()->whereType('');
})->throws(QueryException::class);

it('is refused by a driver that does not declare it, rather than quietly dropped', function (): void {
    expect(fn (): AuditQuery => auditQuery(new NarrowLedger(app(MemoryLedger::class)))->whereType('transition'))
        ->toThrow(LedgerException::class, 'cannot filter by type, so whereType() is not part of')
        ->and(Filter::assumed())->not->toContain(Filter::Type);
});
