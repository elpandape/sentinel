<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Enums\Filter;
use ElPandaPe\Sentinel\Exceptions\LedgerException;
use ElPandaPe\Sentinel\Exceptions\QueryException;
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Ledger\DatabaseLedger;
use ElPandaPe\Sentinel\Query\AuditQuery;
use ElPandaPe\Sentinel\Query\TagCriteria;
use ElPandaPe\Sentinel\Tests\Fixtures\LimitedLedger;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\auditQuery;

beforeEach(function (): void {
    $ledger = app(DatabaseLedger::class);
    $ledger->write(auditData(['event' => 'billed', 'tags' => ['billing', 'refund']]));
    $ledger->write(auditData(['event' => 'shipped', 'tags' => ['billing']]));
    $ledger->write(auditData(['event' => 'ignored']));
});

it('narrows to the entries carrying one label', function (): void {
    expect(Sentinel::audits()->whereTag('billing')->get()->pluck('event')->all())
        ->toBe(['billed', 'shipped']);
});

it('narrows to the entries carrying every label named', function (): void {
    expect(Sentinel::audits()->whereTag(['billing', 'refund'])->get()->pluck('event')->all())
        ->toBe(['billed']);
});

it('reads a repeated call as one question with both labels', function (): void {
    expect(Sentinel::audits()->whereTag('billing')->whereTag('refund')->get()->pluck('event')->all())
        ->toBe(Sentinel::audits()->whereTag(['billing', 'refund'])->get()->pluck('event')->all());
});

it('narrows to the entries carrying any of the labels named', function (): void {
    expect(Sentinel::audits()->whereAnyTag(['refund', 'absent'])->get()->pluck('event')->all())
        ->toBe(['billed']);
});

it('reads both spellings together as both questions at once', function (): void {
    expect(Sentinel::audits()->whereTag('billing')->whereAnyTag(['refund', 'absent'])->get()->pluck('event')->all())
        ->toBe(['billed']);
});

it('answers with nothing for a label no entry carries', function (): void {
    expect(Sentinel::audits()->whereTag('absent')->get())->toBeEmpty();
});

it('leaves an unlabelled entry out of every label query', function (): void {
    expect(Sentinel::audits()->whereAnyTag(['billing', 'refund'])->get()->pluck('event')->all())
        ->not->toContain('ignored');
});

it('refuses a query narrowed by no label at all', function (): void {
    expect(fn (): AuditQuery => Sentinel::audits()->whereTag([]))
        ->toThrow(QueryException::class, 'asks nothing of an entry')
        ->and(fn (): AuditQuery => Sentinel::audits()->whereAnyTag([]))
        ->toThrow(QueryException::class, 'asks nothing of an entry');
});

it('hands back a new query rather than narrowing the one it was given', function (): void {
    $query = Sentinel::audits();

    $query->whereTag('billing');

    expect($query->tags)->toBeNull();
});

it('refuses the label filter to a driver written before it was published', function (): void {
    expect(fn (): AuditQuery => auditQuery(new LimitedLedger)->whereTag('billing'))
        ->toThrow(LedgerException::class, 'cannot filter by tag, so whereTag() is not part of');
});

it('names whereTag as the method that reaches the label filter', function (): void {
    expect(Filter::Tag->method())->toBe('whereTag')
        ->and(Filter::assumed())->not->toContain(Filter::Tag);
});

it('says a label once when the same one is asked for twice', function (): void {
    expect(new TagCriteria()->requiring(['billing'])->requiring(['billing'])->all)->toBe(['billing'])
        ->and(new TagCriteria()->including(['billing'])->including(['billing'])->any)->toBe(['billing']);
});
