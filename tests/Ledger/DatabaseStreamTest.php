<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Ledger\ArrayStream;
use ElPandaPe\Sentinel\Ledger\DatabaseStream;
use ElPandaPe\Sentinel\Models\Audit;

use function ElPandaPe\Sentinel\Tests\insertAudit;

beforeEach(function (): void {
    foreach (range(1, 7) as $sequence) {
        insertAudit(['stream' => 'alpha', 'sequence' => $sequence]);
    }

    insertAudit(['stream' => 'beta', 'sequence' => 1]);
});

it('walks a stream in sequence order and nothing else', function (): void {
    $stream = new DatabaseStream(new Audit, 'alpha');

    expect(collect($stream)->pluck('sequence')->all())->toBe([1, 2, 3, 4, 5, 6, 7])
        ->and($stream->name())->toBe('alpha');
});

it('bounds the walk to a range without reading the rest', function (): void {
    $stream = new DatabaseStream(new Audit, 'alpha')->range(3, 5);

    expect(collect($stream)->pluck('sequence')->all())->toBe([3, 4, 5]);
});

it('walks from a sequence to the end when the range has no upper bound', function (): void {
    expect(collect(new DatabaseStream(new Audit, 'alpha')->range(6))->pluck('sequence')->all())
        ->toBe([6, 7]);
});

it('leaves the original stream untouched when it is bounded', function (): void {
    $stream = new DatabaseStream(new Audit, 'alpha');
    $stream->range(3, 5);

    expect(collect($stream)->count())->toBe(7);
});

it('pages the walk instead of loading the chain at once', function (): void {
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    expect(collect(new DatabaseStream(new Audit, 'alpha', chunk: 2))->pluck('sequence')->all())
        ->toBe([1, 2, 3, 4, 5, 6, 7])
        ->and($queries)->toBe(4);
});

it('walks an empty stream without a single entry', function (): void {
    expect(collect(new DatabaseStream(new Audit, 'nonesuch'))->all())->toBeEmpty();
});

it('walks the entries an array stream was given, bounded the same way', function (): void {
    $audits = Audit::query()->where('stream', 'alpha')->orderBy('sequence')->get()->all();
    $stream = new ArrayStream('alpha', $audits);

    expect(collect($stream)->pluck('sequence')->all())->toBe([1, 2, 3, 4, 5, 6, 7])
        ->and(collect($stream->range(2, 3))->pluck('sequence')->all())->toBe([2, 3])
        ->and($stream->name())->toBe('alpha');
});
