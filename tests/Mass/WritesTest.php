<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Mass\Literal;
use ElPandaPe\Sentinel\Mass\Writes;
use ElPandaPe\Sentinel\Tests\Fixtures\Coordinates;
use ElPandaPe\Sentinel\Tests\Fixtures\SubjectStatus;
use Illuminate\Support\Facades\DB;

it('writes a literal column as a change with no old side, because nothing was read', function (): void {
    expect(Writes::of(['active' => false])->changes)
        ->toBe([['path' => '/active', 'op' => 'replace', 'new' => false]]);
});

it('names a column set from an expression and never its formula', function (): void {
    $writes = Writes::of(['score' => DB::raw('score + 1')]);

    expect($writes->changes)->toBeEmpty()
        ->and($writes->opaque)->toBe(['score']);
});

it('keeps the literal columns of an update that also writes an expression', function (): void {
    $writes = Writes::of(['active' => false, 'score' => DB::raw('score + 1')]);

    expect($writes->changes)->toBe([['path' => '/active', 'op' => 'replace', 'new' => false]])
        ->and($writes->opaque)->toBe(['score']);
});

it('sorts the columns, so the same write hashes the same however it was ordered', function (): void {
    $one = Writes::of(['status' => 'draft', 'active' => false]);
    $other = Writes::of(['active' => false, 'status' => 'draft']);

    expect($one->changes)->toBe($other->changes)
        ->and(array_column($one->changes, 'path'))->toBe(['/active', '/status']);
});

it('escapes a column name the way a pointer is escaped', function (): void {
    expect(Writes::of(['a/b' => 1])->changes[0]['path'])->toBe('/a~1b');
});

it('writes nothing at all for an operation that names no column', function (): void {
    expect(Writes::none()->changes)->toBeEmpty()
        ->and(Writes::none()->opaque)->toBeEmpty();
});

it('reduces an enum and a date the way the snapshots do', function (): void {
    $writes = Writes::of([
        'status' => SubjectStatus::Published,
        'published_at' => new DateTimeImmutable('2026-08-29 10:00:00', new DateTimeZone('UTC')),
    ]);

    expect(array_column($writes->changes, 'new'))
        ->toBe(['2026-08-29T10:00:00.000000+00:00', 'published']);
});

it('tells a null apart from a value there is no writing down', function (): void {
    expect(Literal::of(null))->toBe([null])
        ->and(Literal::of(new Coordinates(1.0, 2.0)))->toBeEmpty();
});
