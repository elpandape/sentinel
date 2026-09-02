<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Enums\Filter;
use ElPandaPe\Sentinel\Exceptions\LedgerException;
use ElPandaPe\Sentinel\Exceptions\QueryException;
use ElPandaPe\Sentinel\Ledger\DatabaseLedger;
use ElPandaPe\Sentinel\Ledger\MemoryLedger;
use ElPandaPe\Sentinel\Query\AuditQuery;
use ElPandaPe\Sentinel\Tests\Fixtures\NarrowLedger;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\auditQuery;

beforeEach(function (): void {
    $this->ledger = app(DatabaseLedger::class);

    $this->ledger->write(auditData([
        'event' => 'approved',
        'context' => ['ip' => '203.0.113.7', 'route' => 'invoices.approve', 'method' => 'POST'],
    ]));

    $this->ledger->write(auditData([
        'event' => 'viewed',
        'context' => ['ip' => '198.51.100.4', 'route' => 'invoices.show', 'method' => 'GET'],
    ]));

    $this->ledger->write(auditData(['event' => 'imported']));
});

it('narrows to the entries recorded from one address', function (): void {
    expect(auditQuery($this->ledger)->whereIp('203.0.113.7')->get()->pluck('event')->all())->toBe(['approved']);
});

it('narrows to the entries recorded from one route', function (): void {
    expect(auditQuery($this->ledger)->whereRoute('invoices.show')->get()->pluck('event')->all())->toBe(['viewed']);
});

it('leaves out an entry whose context recorded neither', function (): void {
    expect(auditQuery($this->ledger)->whereIp('203.0.113.7')->get())->toHaveCount(1)
        ->and(auditQuery($this->ledger)->get())->toHaveCount(3);
});

/**
 * MySQL's default collation is accent- and case-insensitive, so without the binary recheck this
 * would come back with the entry on every engine but two.
 */
it('matches the route exactly, whatever the engine collates by', function (): void {
    expect(auditQuery($this->ledger)->whereRoute('Invoices.Show')->get())->toBeEmpty()
        ->and(auditQuery($this->ledger)->whereRoute('INVOICES.SHOW')->get())->toBeEmpty();
});

it('rides the filter in front of it', function (): void {
    expect(auditQuery($this->ledger)->whereEvent('approved')->whereIp('203.0.113.7')->get())->toHaveCount(1)
        ->and(auditQuery($this->ledger)->whereEvent('viewed')->whereIp('203.0.113.7')->get())->toBeEmpty();
});

it('asks both at once and answers with the entry that satisfies the two', function (): void {
    expect(auditQuery($this->ledger)->whereIp('203.0.113.7')->whereRoute('invoices.approve')->get())->toHaveCount(1)
        ->and(auditQuery($this->ledger)->whereIp('203.0.113.7')->whereRoute('invoices.show')->get())->toBeEmpty();
});

it('answers the same question over a ledger with no database under it', function (): void {
    $ledger = app(MemoryLedger::class);

    $ledger->write(auditData(['event' => 'approved', 'context' => ['ip' => '203.0.113.7', 'route' => 'invoices.approve']]));
    $ledger->write(auditData(['event' => 'viewed', 'context' => ['ip' => '198.51.100.4']]));

    expect(auditQuery($ledger)->whereIp('203.0.113.7')->get()->pluck('event')->all())->toBe(['approved'])
        ->and(auditQuery($ledger)->whereRoute('invoices.approve')->get()->pluck('event')->all())->toBe(['approved'])
        ->and(auditQuery($ledger)->whereRoute('invoices.show')->get())->toBeEmpty();
});

it('refuses an empty value rather than handing back what never recorded one', function (string $method): void {
    auditQuery($this->ledger)->{$method}('');
})->with(['whereIp', 'whereRoute'])->throws(QueryException::class);

it('is refused by a driver that does not declare it, rather than quietly dropped', function (): void {
    expect(fn (): AuditQuery => auditQuery(new NarrowLedger(app(MemoryLedger::class)))->whereIp('203.0.113.7'))
        ->toThrow(LedgerException::class, 'cannot filter by ip, so whereIp() is not part of')
        ->and(fn (): AuditQuery => auditQuery(new NarrowLedger(app(MemoryLedger::class)))->whereRoute('invoices.show'))
        ->toThrow(LedgerException::class, 'cannot filter by route, so whereRoute() is not part of')
        ->and(Filter::assumed())->not->toContain(Filter::Ip)
        ->and(Filter::assumed())->not->toContain(Filter::Route);
});
