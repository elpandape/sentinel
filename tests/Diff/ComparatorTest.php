<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Diff\Diff;

use function ElPandaPe\Sentinel\Tests\diffEntries;

it('sees no change when the two structures are the same', function (): void {
    expect(diffEntries(['a' => 1, 'b' => [2, 3]], ['a' => 1, 'b' => [2, 3]]))->toBeEmpty();
});

it('does not call a different key order a change', function (): void {
    expect(diffEntries(['b' => 2, 'a' => 1], ['a' => 1, 'b' => 2]))->toBeEmpty();
});

it('replaces the root when the compared values are not structures', function (): void {
    expect(diffEntries('Lima', 'Arequipa'))
        ->toBe([['path' => '', 'op' => 'replace', 'old' => 'Lima', 'new' => 'Arequipa']]);
});

it('adds the key that appears and removes the key that goes', function (): void {
    expect(diffEntries(['a' => 1], ['b' => 2]))->toBe([
        ['path' => '/a', 'op' => 'remove', 'old' => 1, 'new' => null],
        ['path' => '/b', 'op' => 'add', 'old' => null, 'new' => 2],
    ]);
});

it('tells a key that turned null apart from a key that went away', function (): void {
    expect(diffEntries(['a' => 1], ['a' => null]))
        ->toBe([['path' => '/a', 'op' => 'replace', 'old' => 1, 'new' => null]]);
});

it('refuses to coerce two values that look alike', function (mixed $old, mixed $new): void {
    expect(diffEntries(['a' => $old], ['a' => $new]))
        ->toBe([['path' => '/a', 'op' => 'replace', 'old' => $old, 'new' => $new]]);
})->with([
    'string one and int one' => ['1', 1],
    'float one and int one' => [1.0, 1],
    'false and zero' => [false, 0],
    'empty string and null' => ['', null],
    'zero and empty string' => [0, ''],
]);

it('descends into a nested map and names the leaf that moved', function (): void {
    $before = ['profile' => ['address' => ['city' => 'Lima', 'zip' => '15001']]];
    $after = ['profile' => ['address' => ['city' => 'Arequipa', 'zip' => '15001']]];

    expect(diffEntries($before, $after))->toBe([
        ['path' => '/profile/address/city', 'op' => 'replace', 'old' => 'Lima', 'new' => 'Arequipa'],
    ]);
});

it('carries a whole subtree in the entry that adds it', function (): void {
    expect(diffEntries([], ['profile' => ['city' => 'Lima']]))->toBe([
        ['path' => '/profile', 'op' => 'add', 'old' => null, 'new' => ['city' => 'Lima']],
    ]);
});

it('escapes the two characters a json pointer reserves', function (): void {
    expect(diffEntries(['a/b' => 1, 'c~d' => 1], ['a/b' => 2, 'c~d' => 2]))->toBe([
        ['path' => '/a~1b', 'op' => 'replace', 'old' => 1, 'new' => 2],
        ['path' => '/c~0d', 'op' => 'replace', 'old' => 1, 'new' => 2],
    ]);
});

it('leaves a dot alone, because a pointer does not reserve it', function (): void {
    expect(diffEntries(['a.b' => 1], ['a.b' => 2]))
        ->toBe([['path' => '/a.b', 'op' => 'replace', 'old' => 1, 'new' => 2]]);
});

it('treats a map that became a list as one replacement', function (): void {
    expect(diffEntries(['a' => ['x' => 1]], ['a' => [1]]))
        ->toBe([['path' => '/a', 'op' => 'replace', 'old' => ['x' => 1], 'new' => [1]]]);
});

it('sees no change between two empty arrays, whatever they meant', function (): void {
    expect(diffEntries(['a' => []], ['a' => []]))->toBeEmpty();
});

it('adds key by key from an empty side, which is what a creation compares against', function (): void {
    expect(diffEntries([], ['name' => 'Ada', 'score' => 1]))->toBe([
        ['path' => '/name', 'op' => 'add', 'old' => null, 'new' => 'Ada'],
        ['path' => '/score', 'op' => 'add', 'old' => null, 'new' => 1],
    ]);
});

it('removes key by key towards an empty side, which is what a deletion compares against', function (): void {
    expect(diffEntries(['name' => 'Ada'], []))->toBe([
        ['path' => '/name', 'op' => 'remove', 'old' => 'Ada', 'new' => null],
    ]);
});

it('normalizes both sides before comparing them', function (): void {
    $before = ['at' => new DateTimeImmutable('2026-08-26 10:00:00.000000', new DateTimeZone('+00:00'))];
    $after = ['at' => '2026-08-26T10:00:00.000000+00:00'];

    expect(diffEntries($before, $after))->toBeEmpty();
});

it('emits its entries in the same order every time', function (): void {
    $before = ['z' => 1, 'a' => 1, 'm' => 1];
    $after = ['z' => 2, 'a' => 2, 'm' => 2];

    expect(array_column(diffEntries($before, $after), 'path'))->toBe(['/a', '/m', '/z']);
});

it('is empty and countable straight off the comparison', function (): void {
    expect(Diff::between(['a' => 1], ['a' => 1])->isEmpty())->toBeTrue()
        ->and(Diff::between(['a' => 1], ['a' => 2]))->toHaveCount(1);
});

it('replaces outright when only one of the two sides is a structure', function (): void {
    expect(diffEntries(['a' => 1], 'x'))
        ->toBe([['path' => '', 'op' => 'replace', 'old' => ['a' => 1], 'new' => 'x']])
        ->and(diffEntries('x', ['a' => 1]))
        ->toBe([['path' => '', 'op' => 'replace', 'old' => 'x', 'new' => ['a' => 1]]]);
});

it('builds a pointer segment from a key that is not a string', function (): void {
    expect(diffEntries([5 => 1, 'a' => 1], [5 => 2, 'a' => 1]))
        ->toBe([['path' => '/5', 'op' => 'replace', 'old' => 1, 'new' => 2]]);
});
