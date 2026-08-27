<?php

declare(strict_types=1);

use function ElPandaPe\Sentinel\Tests\diffEntries;

it('reports one addition when an identified element is inserted in the middle', function (): void {
    $before = ['roles' => [['id' => 1, 'name' => 'admin'], ['id' => 3, 'name' => 'viewer']]];
    $after = ['roles' => [
        ['id' => 1, 'name' => 'admin'],
        ['id' => 2, 'name' => 'editor'],
        ['id' => 3, 'name' => 'viewer'],
    ]];

    expect(diffEntries($before, $after))->toBe([
        ['path' => '/roles/1', 'op' => 'add', 'old' => null, 'new' => ['id' => 2, 'name' => 'editor']],
    ]);
});

it('reports one removal when an identified element leaves the middle', function (): void {
    $before = ['roles' => [['id' => 1], ['id' => 2], ['id' => 3]]];
    $after = ['roles' => [['id' => 1], ['id' => 3]]];

    expect(diffEntries($before, $after))->toBe([
        ['path' => '/roles/1', 'op' => 'remove', 'old' => ['id' => 2], 'new' => null],
    ]);
});

it('sees no change when identified elements only swap places', function (): void {
    $before = ['roles' => [['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b']]];
    $after = ['roles' => [['id' => 2, 'name' => 'b'], ['id' => 1, 'name' => 'a']]];

    expect(diffEntries($before, $after))->toBeEmpty();
});

it('follows the identified element that moved and changed at once', function (): void {
    $before = ['roles' => [['id' => 1, 'name' => 'a'], ['id' => 2, 'name' => 'b']]];
    $after = ['roles' => [['id' => 2, 'name' => 'B'], ['id' => 1, 'name' => 'a']]];

    expect(diffEntries($before, $after))->toBe([
        ['path' => '/roles/0/name', 'op' => 'replace', 'old' => 'b', 'new' => 'B'],
    ]);
});

it('matches on uuid when no element carries an id', function (): void {
    $before = ['roles' => [['uuid' => 'a', 'n' => 1], ['uuid' => 'b', 'n' => 2]]];
    $after = ['roles' => [['uuid' => 'b', 'n' => 2]]];

    expect(diffEntries($before, $after))->toBe([
        ['path' => '/roles/0', 'op' => 'remove', 'old' => ['uuid' => 'a', 'n' => 1], 'new' => null],
    ]);
});

it('lets an addition and a removal land on the same index of an identified list', function (): void {
    $before = ['roles' => [['id' => 1], ['id' => 2]]];
    $after = ['roles' => [['id' => 1], ['id' => 3]]];

    expect(diffEntries($before, $after))->toBe([
        ['path' => '/roles/1', 'op' => 'add', 'old' => null, 'new' => ['id' => 3]],
        ['path' => '/roles/1', 'op' => 'remove', 'old' => ['id' => 2], 'new' => null],
    ]);
});

it('falls back to position when one element has no identity', function (): void {
    $before = ['roles' => [['id' => 1], ['name' => 'loose']]];
    $after = ['roles' => [['name' => 'loose'], ['id' => 1]]];

    expect(diffEntries($before, $after))->toBe([
        ['path' => '/roles/0/id', 'op' => 'remove', 'old' => 1, 'new' => null],
        ['path' => '/roles/0/name', 'op' => 'add', 'old' => null, 'new' => 'loose'],
        ['path' => '/roles/1/id', 'op' => 'add', 'old' => null, 'new' => 1],
        ['path' => '/roles/1/name', 'op' => 'remove', 'old' => 'loose', 'new' => null],
    ]);
});

it('falls back to position when an identity repeats inside its own list', function (): void {
    $before = ['roles' => [['id' => 1, 'n' => 'a'], ['id' => 1, 'n' => 'b']]];
    $after = ['roles' => [['id' => 1, 'n' => 'b'], ['id' => 1, 'n' => 'a']]];

    expect(diffEntries($before, $after))->toBe([
        ['path' => '/roles/0/n', 'op' => 'replace', 'old' => 'a', 'new' => 'b'],
        ['path' => '/roles/1/n', 'op' => 'replace', 'old' => 'b', 'new' => 'a'],
    ]);
});

it('does not treat an id of one and an id of the string one as the same element', function (): void {
    $before = ['roles' => [['id' => 1, 'n' => 'a']]];
    $after = ['roles' => [['id' => '1', 'n' => 'a']]];

    expect(diffEntries($before, $after))->toBe([
        ['path' => '/roles/0', 'op' => 'add', 'old' => null, 'new' => ['id' => '1', 'n' => 'a']],
        ['path' => '/roles/0', 'op' => 'remove', 'old' => ['id' => 1, 'n' => 'a'], 'new' => null],
    ]);
});

it('falls back to position when the elements are scalars', function (): void {
    expect(diffEntries(['tags' => ['a', 'b']], ['tags' => ['b', 'a']]))->toBe([
        ['path' => '/tags/0', 'op' => 'replace', 'old' => 'a', 'new' => 'b'],
        ['path' => '/tags/1', 'op' => 'replace', 'old' => 'b', 'new' => 'a'],
    ]);
});

it('appends and truncates by position when a list grows or shrinks', function (): void {
    expect(diffEntries(['tags' => ['a']], ['tags' => ['a', 'b']]))->toBe([
        ['path' => '/tags/1', 'op' => 'add', 'old' => null, 'new' => 'b'],
    ])
        ->and(diffEntries(['tags' => ['a', 'b']], ['tags' => ['a']]))->toBe([
            ['path' => '/tags/1', 'op' => 'remove', 'old' => 'b', 'new' => null],
        ]);
});

it('treats an empty list as having no identity to match on', function (): void {
    expect(diffEntries(['roles' => []], ['roles' => [['id' => 1]]]))->toBe([
        ['path' => '/roles/0', 'op' => 'add', 'old' => null, 'new' => ['id' => 1]],
    ]);
});

it('does not match on an identity that is not a scalar', function (): void {
    $before = ['roles' => [['id' => ['nested' => 1], 'n' => 'a']]];
    $after = ['roles' => [['id' => ['nested' => 1], 'n' => 'b']]];

    expect(diffEntries($before, $after))->toBe([
        ['path' => '/roles/0/n', 'op' => 'replace', 'old' => 'a', 'new' => 'b'],
    ]);
});

it('needs both sides identified before it matches on identity', function (): void {
    $before = ['roles' => [['id' => 1, 'n' => 'a']]];
    $after = ['roles' => [['n' => 'a']]];

    expect(diffEntries($before, $after))->toBe([
        ['path' => '/roles/0/id', 'op' => 'remove', 'old' => 1, 'new' => null],
    ]);
});
