<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Diff\Diff;
use ElPandaPe\Sentinel\Diff\DiffException;

it('emits a strict patch with a test in front of what it overwrites', function (): void {
    $diff = Diff::between(['a' => 1, 'b' => 2], ['a' => 9, 'c' => 3]);

    expect($diff->toJsonPatch())->toBe([
        ['op' => 'test', 'path' => '/a', 'value' => 1],
        ['op' => 'replace', 'path' => '/a', 'value' => 9],
        ['op' => 'test', 'path' => '/b', 'value' => 2],
        ['op' => 'remove', 'path' => '/b'],
        ['op' => 'add', 'path' => '/c', 'value' => 3],
    ]);
});

it('emits the bare patch when the caller does not want the tests', function (): void {
    $diff = Diff::between(['a' => 1], ['a' => 9]);

    expect($diff->toJsonPatch(tests: false))->toBe([['op' => 'replace', 'path' => '/a', 'value' => 9]]);
});

it('never gives a removal a value, because the rfc does not', function (): void {
    expect(Diff::between(['a' => 1], [])->toJsonPatch(tests: false))
        ->toBe([['op' => 'remove', 'path' => '/a']]);
});

it('round trips without losing the old value when the tests travel', function (): void {
    $diff = Diff::between(['a' => 1, 'b' => 2, 'd' => null], ['a' => 9, 'c' => 3, 'd' => null]);

    expect(Diff::fromJsonPatch($diff->toJsonPatch())->toArray())->toBe($diff->toArray());
});

it('says the old value is missing rather than calling it null', function (): void {
    $diff = Diff::between(['a' => 1], ['a' => 9]);

    expect(Diff::fromJsonPatch($diff->toJsonPatch(tests: false))->toArray())
        ->toBe([['path' => '/a', 'op' => 'replace', 'new' => 9]]);
});

it('rebuilds an addition whose value is null', function (): void {
    expect(Diff::fromJsonPatch([['op' => 'add', 'path' => '/a', 'value' => null]])->toArray())
        ->toBe([['path' => '/a', 'op' => 'add', 'old' => null, 'new' => null]]);
});

it('ignores a test that guards nothing', function (): void {
    expect(Diff::fromJsonPatch([['op' => 'test', 'path' => '/a', 'value' => 1]])->toArray())->toBeEmpty();
});

it('does not let a test guard an operation on another path', function (): void {
    $patch = [
        ['op' => 'test', 'path' => '/a', 'value' => 1],
        ['op' => 'replace', 'path' => '/b', 'value' => 9],
    ];

    expect(Diff::fromJsonPatch($patch)->toArray())->toBe([['path' => '/b', 'op' => 'replace', 'new' => 9]]);
});

it('rebuilds a removal guarded by its test', function (): void {
    $patch = [
        ['op' => 'test', 'path' => '/a', 'value' => 1],
        ['op' => 'remove', 'path' => '/a'],
    ];

    expect(Diff::fromJsonPatch($patch)->toArray())
        ->toBe([['path' => '/a', 'op' => 'remove', 'old' => 1, 'new' => null]]);
});

it('refuses an operation the format cannot express', function (): void {
    Diff::fromJsonPatch([['op' => 'move', 'from' => '/a', 'path' => '/b']]);
})->throws(DiffException::class, 'move');

it('refuses a malformed operation', function (mixed $operation): void {
    Diff::fromJsonPatch([$operation]);
})->throws(DiffException::class)->with([
    'not an array' => 'replace',
    'no op' => [['path' => '/a', 'value' => 1]],
    'no path' => [['op' => 'add', 'value' => 1]],
    'add with no value' => [['op' => 'add', 'path' => '/a']],
    'replace with no value' => [['op' => 'replace', 'path' => '/a']],
    'op not a string' => [['op' => 7, 'path' => '/a', 'value' => 1]],
]);
