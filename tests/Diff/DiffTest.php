<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Diff\Change;
use ElPandaPe\Sentinel\Diff\Diff;
use ElPandaPe\Sentinel\Diff\DiffException;
use ElPandaPe\Sentinel\Tests\Fixtures\DiffVectors;

it('carries no entries and counts zero when nothing changed', function (): void {
    $diff = Diff::fromChanges([]);

    expect($diff)->toBeEmpty()
        ->and($diff->isEmpty())->toBeTrue()
        ->and($diff->toArray())->toBeEmpty();
});

it('emits an entry as a map with its four keys in a fixed order', function (): void {
    $diff = Diff::fromChanges([new Change('/name', 'replace', 'Ada', 'Grace')]);

    expect($diff->toArray())->toBe([
        ['path' => '/name', 'op' => 'replace', 'old' => 'Ada', 'new' => 'Grace'],
    ]);
});

it('omits old entirely when the value is not recoverable', function (): void {
    $diff = Diff::fromChanges([new Change('/name', 'replace', null, 'Grace', oldKnown: false)]);

    expect($diff->toArray())->toBe([['path' => '/name', 'op' => 'replace', 'new' => 'Grace']]);
});

it('iterates the entries as the maps it stores', function (): void {
    $diff = Diff::fromChanges([new Change('/a', 'add', null, 1)]);

    expect(iterator_to_array($diff))->toBe([['path' => '/a', 'op' => 'add', 'old' => null, 'new' => 1]]);
});

it('rebuilds itself from the entries a column stores', function (): void {
    $entries = [['path' => '/name', 'op' => 'replace', 'old' => 'Ada', 'new' => 'Grace']];

    expect(Diff::fromEntries($entries)->toArray())->toBe($entries);
});

it('keeps an absent old absent through a rebuild', function (): void {
    $entries = [['path' => '/name', 'op' => 'remove', 'new' => null]];

    expect(Diff::fromEntries($entries)->toArray())->toBe($entries);
});

it('refuses an entry that is not an entry', function (mixed $entry): void {
    Diff::fromEntries([$entry]);
})->throws(DiffException::class)->with([
    'not an array' => 'replace',
    'no path' => [['op' => 'replace', 'new' => 1]],
    'no op' => [['path' => '/a', 'new' => 1]],
    'unknown op' => [['path' => '/a', 'op' => 'move', 'new' => 1]],
    'path not a string' => [['path' => 7, 'op' => 'add', 'new' => 1]],
    'no new' => [['path' => '/a', 'op' => 'add']],
]);

it('filters the entries of a subtree, in dot notation or as a literal pointer', function (string $path): void {
    [$before, $after] = DiffVectors::pair();

    expect(Diff::between($before, $after)->for($path)->toArray())->toBe([
        ['path' => '/profile/address/city', 'op' => 'replace', 'old' => 'Lima', 'new' => 'Arequipa'],
    ]);
})->with(['profile.address.city', '/profile/address/city', 'profile.address', '/profile']);

it('keeps a key that contains a dot reachable through its literal pointer', function (): void {
    $diff = Diff::between(['a.b' => 1], ['a.b' => 2]);

    expect($diff->for('/a.b')->toArray())->toHaveCount(1)
        ->and($diff->for('a.b')->toArray())->toBeEmpty();
});

it('returns everything for the root and nothing for a path that changed nothing', function (): void {
    [$before, $after] = DiffVectors::pair();
    $diff = Diff::between($before, $after);

    expect($diff->for('')->toArray())->toBe($diff->toArray())
        ->and($diff->for('name')->toArray())->toBeEmpty();
});

it('does not let a prefix match a sibling whose name merely starts the same', function (): void {
    $diff = Diff::between(['role' => 1, 'roles' => 1], ['role' => 2, 'roles' => 2]);

    expect($diff->for('role')->toArray())
        ->toBe([['path' => '/role', 'op' => 'replace', 'old' => 1, 'new' => 2]]);
});
