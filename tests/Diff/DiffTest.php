<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Diff\Change;
use ElPandaPe\Sentinel\Diff\Diff;
use ElPandaPe\Sentinel\Diff\DiffException;

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
