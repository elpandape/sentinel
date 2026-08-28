<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Enums\Filter;
use ElPandaPe\Sentinel\Exceptions\LedgerException;
use ElPandaPe\Sentinel\Exceptions\QueryException;
use ElPandaPe\Sentinel\Facades\Sentinel;
use ElPandaPe\Sentinel\Ledger\ChangedFieldPredicate;
use ElPandaPe\Sentinel\Ledger\DatabaseLedger;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Query\AuditQuery;
use ElPandaPe\Sentinel\Tests\Fixtures\LimitedLedger;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\auditQuery;
use function ElPandaPe\Sentinel\Tests\changing;
use function ElPandaPe\Sentinel\Tests\diffEntries;

beforeEach(function (): void {
    $ledger = app(DatabaseLedger::class);
    $ledger->write(auditData(changing('mailed', '/email')));
    $ledger->write(auditData(changing('verified', '/email_verified_at')));
    $ledger->write(auditData(changing('listed', '/emails')));
    $ledger->write(auditData(changing('moved', '/profile/address/city')));
    $ledger->write(auditData(changing('named', '/profile')));
    $ledger->write(auditData(changing('buried', '/name')));
});

it('finds the entries that changed a root field', function (): void {
    expect(Sentinel::audits()->whereFieldChanged('email')->get()->pluck('event')->all())->toBe(['mailed']);
});

it('does not read a longer field name as the one asked for', function (): void {
    expect(Sentinel::audits()->whereFieldChanged('email')->get()->pluck('event')->all())
        ->not->toContain('verified', 'listed');
});

it('finds a change beneath the field asked for', function (): void {
    expect(Sentinel::audits()->whereFieldChanged('profile')->get()->pluck('event')->all())
        ->toBe(['moved', 'named']);
});

it('does not read an ancestor as the field asked for', function (): void {
    expect(Sentinel::audits()->whereFieldChanged('profile.address.city')->get()->pluck('event')->all())
        ->toBe(['moved']);
});

it('reads dot notation as the pointer the diff wrote', function (): void {
    expect(Sentinel::audits()->whereFieldChanged('profile.address.city')->get()->pluck('event')->all())
        ->toBe(Sentinel::audits()->whereFieldChanged('/profile/address/city')->get()->pluck('event')->all());
});

it('answers the same thing the diff of that entry answers', function (): void {
    $moved = Sentinel::audits()->whereFieldChanged('profile')->get()->firstOrFail();

    expect($moved->diffFor('profile')->isEmpty())->toBeFalse();
});

it('leaves out an entry whose diff is empty and one that has none', function (): void {
    app(DatabaseLedger::class)->write(auditData(['event' => 'quiet', 'changes' => []]));
    app(DatabaseLedger::class)->write(auditData(['event' => 'silent']));

    expect(Sentinel::audits()->whereFieldChanged('email')->get()->pluck('event')->all())->toBe(['mailed']);
});

it('never reads a path buried inside another change as that change', function (): void {
    app(DatabaseLedger::class)->write(auditData([
        'event' => 'nested',
        'changes' => [['path' => '/label', 'op' => 'replace', 'old' => ['path' => '/email'], 'new' => null]],
    ]));

    expect(Sentinel::audits()->whereFieldChanged('email')->get()->pluck('event')->all())->toBe(['mailed']);
});

it('leaves a wildcard character in a field name inert', function (): void {
    $ledger = app(DatabaseLedger::class);
    $ledger->write(auditData(changing('underscored', '/a_b')));
    $ledger->write(auditData(changing('literal', '/axb')));

    expect(Sentinel::audits()->whereFieldChanged('a_b')->get()->pluck('event')->all())->toBe(['underscored']);
});

it('tells one letter case from another', function (): void {
    app(DatabaseLedger::class)->write(auditData(changing('capitalised', '/Email')));

    expect(Sentinel::audits()->whereFieldChanged('email')->get()->pluck('event')->all())->toBe(['mailed']);
});

it('exposes the same reading through the relation a model already has', function (): void {
    expect(Audit::query()->field('email')->pluck('event')->all())->toBe(['mailed'])
        ->and(Audit::query()->field('profile')->pluck('event')->all())->toBe(['moved', 'named']);
});

it('refuses a query narrowed by no field at all', function (): void {
    expect(fn (): AuditQuery => Sentinel::audits()->whereFieldChanged(''))
        ->toThrow(QueryException::class, 'asks nothing of an entry');
});

it('refuses the field filter to a driver written before it was published', function (): void {
    expect(fn (): AuditQuery => auditQuery(new LimitedLedger)->whereFieldChanged('email'))
        ->toThrow(LedgerException::class, 'cannot filter by field, so whereFieldChanged() is not part of');
});

it('refuses to answer on an engine it has no predicate for', function (): void {
    expect(fn (): array => app(ChangedFieldPredicate::class)->for('sqlsrv', 'changes', '/email'))
        ->toThrow(LedgerException::class, 'has no field predicate for the [sqlsrv] engine');
});

it('binds the wanted pointer and its descendant prefix, in that order', function (): void {
    [, $bindings] = app(ChangedFieldPredicate::class)->for('sqlite', 'changes', '/email');

    expect($bindings)->toBe(['/email', '/email/', '/email/']);
});

it('speaks each engine in its own json dialect', function (): void {
    $predicate = app(ChangedFieldPredicate::class);

    expect($predicate->for('sqlite', 'changes', '/a')[0])->toContain('json_valid', 'json_each')
        ->and($predicate->for('pgsql', 'changes', '/a')[0])->toContain('jsonb_typeof', 'jsonb_array_elements')
        ->and($predicate->for('mysql', 'changes', '/a')[0])->toContain('json_table', 'utf8mb4_bin');
});

it('names whereFieldChanged as the method that reaches the field filter', function (): void {
    expect(Filter::FieldChanged->method())->toBe('whereFieldChanged')
        ->and(Filter::assumed())->not->toContain(Filter::FieldChanged);
});

it('holds the diff and the filter to one reading of the same field', function (): void {
    $entries = diffEntries(['profile' => ['city' => 'Lima']], ['profile' => ['city' => 'Arequipa']]);
    app(DatabaseLedger::class)->write(auditData(['event' => 'compared', 'changes' => $entries]));

    expect(Sentinel::audits()->whereFieldChanged('profile')->get()->pluck('event')->all())
        ->toContain('compared');
});
