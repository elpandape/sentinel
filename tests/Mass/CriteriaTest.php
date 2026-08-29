<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Mass\Criteria;
use ElPandaPe\Sentinel\Tests\Fixtures\Coordinates;
use ElPandaPe\Sentinel\Tests\Fixtures\SubjectStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

use function ElPandaPe\Sentinel\Tests\massCriteria;
use function ElPandaPe\Sentinel\Tests\massWheres;
use function ElPandaPe\Sentinel\Tests\sentinelConfig;

it('writes a comparison as its column, its operator and its value', function (): void {
    $criteria = massCriteria(static fn (Builder $query): Builder => $query->where('active', '=', true));

    expect(massWheres($criteria))->toBe([
        ['type' => 'basic', 'boolean' => 'and', 'column' => 'active', 'operator' => '=', 'value' => true],
    ]);
});

it('keeps the boolean that joins one clause to the next', function (): void {
    $criteria = massCriteria(static fn (Builder $query): Builder => $query
        ->where('active', true)
        ->orWhere('status', 'draft'));

    expect(array_column(massWheres($criteria), 'boolean'))->toBe(['and', 'or']);
});

it('records a set as its size and a sample of itself', function (): void {
    $criteria = massCriteria(
        static fn (Builder $query): Builder => $query->whereIn('id', range(1, 5000)),
        ['mass_operations.sample' => 3],
    );

    expect(massWheres($criteria))->toBe([
        ['type' => 'in', 'boolean' => 'and', 'column' => 'id', 'count' => 5000, 'values' => [1, 2, 3]],
    ]);
});

it('writes a short set whole, because the sample is a ceiling and not a quota', function (): void {
    $criteria = massCriteria(static fn (Builder $query): Builder => $query->whereNotIn('id', [7, 9]));

    expect(massWheres($criteria))->toBe([
        ['type' => 'not_in', 'boolean' => 'and', 'column' => 'id', 'count' => 2, 'values' => [7, 9]],
    ]);
});

it('writes a nullity check as the column alone', function (): void {
    $criteria = massCriteria(static fn (Builder $query): Builder => $query->whereNull('deleted_at'));

    expect(massWheres($criteria))->toBe([
        ['type' => 'null', 'boolean' => 'and', 'column' => 'deleted_at'],
    ]);
});

it('tells a range from the range it excludes', function (): void {
    $criteria = massCriteria(static fn (Builder $query): Builder => $query->whereNotBetween('price', [10, 20]));

    expect(massWheres($criteria))->toBe([
        ['type' => 'between', 'boolean' => 'and', 'column' => 'price', 'not' => true, 'values' => [10, 20]],
    ]);
});

it('writes a column comparison as two columns, which are the query own vocabulary', function (): void {
    $criteria = massCriteria(static fn (Builder $query): Builder => $query->whereColumn('name', '!=', 'email'));

    expect(massWheres($criteria))->toBe([
        ['type' => 'column', 'boolean' => 'and', 'first' => 'name', 'operator' => '!=', 'second' => 'email'],
    ]);
});

it('goes into a nested group instead of flattening it, because the grouping is the criteria', function (): void {
    $criteria = massCriteria(static fn (Builder $query): Builder => $query
        ->where('active', true)
        ->where(static function (Builder $group): void {
            $group->where('status', 'draft')->orWhere('status', 'pending');
        }));

    expect(massWheres($criteria)[1])->toBe([
        'type' => 'nested',
        'boolean' => 'and',
        'wheres' => [
            ['type' => 'basic', 'boolean' => 'and', 'column' => 'status', 'operator' => '=', 'value' => 'draft'],
            ['type' => 'basic', 'boolean' => 'or', 'column' => 'status', 'operator' => '=', 'value' => 'pending'],
        ],
    ]);
});

it('records a raw fragment as its shape and never as its body', function (): void {
    $criteria = massCriteria(static fn (Builder $query): Builder => $query->whereRaw("email = 'ada@example.com'"));

    expect(massWheres($criteria))->toBe([['type' => 'raw', 'boolean' => 'and']])
        ->and(json_encode($criteria))->not->toContain('ada@example.com');
});

it('records a subquery as its shape and never as its body', function (): void {
    $criteria = massCriteria(static fn (Builder $query): Builder => $query
        ->whereExists(static fn (Builder $inner): Builder => $inner
            ->from('fixture_authors')
            ->where('code', 'confidential')));

    expect(massWheres($criteria))->toBe([['type' => 'exists', 'boolean' => 'and']])
        ->and(json_encode($criteria))->not->toContain('confidential');
});

it('records a clause it has never seen as its shape alone', function (): void {
    $criteria = massCriteria(static fn (Builder $query): Builder => $query->whereJsonContains('options', ['tier' => 'gold']));

    expect(massWheres($criteria))->toBe([['type' => 'json_contains', 'boolean' => 'and']])
        ->and(json_encode($criteria))->not->toContain('gold');
});

it('keeps the column and drops the value when the value is not something to write down', function (): void {
    $criteria = massCriteria(static fn (Builder $query): Builder => $query->where('options', new Coordinates(1.5, 2.5)));

    expect(massWheres($criteria))->toBe([
        ['type' => 'basic', 'boolean' => 'and', 'column' => 'options', 'operator' => '='],
    ]);
});

it('drops the sample of a set holding one value it cannot write, and keeps the count', function (): void {
    $criteria = massCriteria(static fn (Builder $query): Builder => $query
        ->whereIn('options', [1, new Coordinates(1.5, 2.5)]));

    expect(massWheres($criteria))->toBe([
        ['type' => 'in', 'boolean' => 'and', 'column' => 'options', 'count' => 2],
    ]);
});

it('writes an enum by its value and a date by the format the snapshots use', function (): void {
    $criteria = massCriteria(static fn (Builder $query): Builder => $query
        ->where('status', SubjectStatus::Published)
        ->where('published_at', '<', new DateTimeImmutable('2026-08-29 10:00:00', new DateTimeZone('UTC'))));

    expect(array_column(massWheres($criteria), 'value'))
        ->toBe(['published', '2026-08-29T10:00:00.000000+00:00']);
});

it('falls back on the shape alone when a column comparison compares expressions', function (): void {
    $criteria = massCriteria(static fn (Builder $query): Builder => $query
        ->whereColumn(DB::raw('lower(name)'), '=', 'email'));

    expect(massWheres($criteria))->toBe([['type' => 'column', 'boolean' => 'and']]);
});

it('keeps a range it cannot write the bounds of, because the column and the sense still say something', function (): void {
    $criteria = massCriteria(static fn (Builder $query): Builder => $query
        ->whereBetween('price', [new Coordinates(1.0, 2.0), 20]));

    expect(massWheres($criteria))->toBe([
        ['type' => 'between', 'boolean' => 'and', 'column' => 'price', 'not' => false],
    ]);
});

it('writes a null inside a set as the null it is', function (): void {
    $criteria = massCriteria(static fn (Builder $query): Builder => $query->whereIn('status', [null, 'draft']));

    expect(massWheres($criteria)[0]['values'])->toBe([null, 'draft']);
});

it('leaves out a join whose table is a query rather than a name', function (): void {
    $criteria = massCriteria(static fn (Builder $query): Builder => $query
        ->joinSub(
            DB::table('fixture_authors')->select('id'),
            'authors',
            'authors.id',
            '=',
            'fixture_audited_subjects.id',
        ));

    expect($criteria)->not->toHaveKey('joins');
});

it('records the tables a join brought in, because a join is part of what was aimed at', function (): void {
    $criteria = massCriteria(static fn (Builder $query): Builder => $query
        ->join('fixture_authors', 'fixture_authors.id', '=', 'fixture_audited_subjects.id')
        ->where('active', true));

    expect($criteria['joins'])->toBe([['type' => 'inner', 'table' => 'fixture_authors']]);
});

it('says nothing about joins when there are none', function (): void {
    expect(massCriteria(static fn (Builder $query): Builder => $query->where('active', true)))
        ->not->toHaveKey('joins');
});

it('records an unfiltered operation as one with no clauses, which is the fact worth having', function (): void {
    expect(massCriteria(static fn (Builder $query): Builder => $query))->toBe(['wheres' => []]);
});

it('records an upsert as the shape of what was sent, because it names its own rows', function (): void {
    expect(new Criteria(sentinelConfig())->ofRows(['id', 'name'], ['id'], ['name'], 3))
        ->toBe(['columns' => ['id', 'name'], 'unique_by' => ['id'], 'update' => ['name'], 'rows' => 3]);
});

it('serialises the criteria of a query the package never touched', function (): void {
    $query = DB::table('fixture_audited_subjects')->where('name', 'Ada');

    expect(new Criteria(sentinelConfig())->of($query))->toHaveKey('wheres');
});
