<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Models\Audit;

use function ElPandaPe\Sentinel\Tests\insertAudit;
use function ElPandaPe\Sentinel\Tests\withSortedKeys;

it('brings every value of the diff back from the column', function (mixed $changes): void {
    insertAudit(['changes' => json_encode($changes, JSON_THROW_ON_ERROR)]);

    expect(withSortedKeys(Audit::query()->sole()->changes ?? []))->toBe(withSortedKeys($changes));
})->with([
    'an empty diff' => [[]],
    'a replacement' => [[['path' => '/name', 'op' => 'replace', 'old' => 'José', 'new' => '海']]],
    'a numeric key' => [[['path' => '/0', 'op' => 'add', 'old' => null, 'new' => 1]]],
    'an escaped pointer' => [[['path' => '/a~1b~0c', 'op' => 'add', 'old' => null, 'new' => true]]],
    'a subtree as a value' => [[['path' => '/p', 'op' => 'add', 'old' => null, 'new' => ['x' => [1, 2]]]]],
    'an absent old' => [[['path' => '/a', 'op' => 'replace', 'new' => 9]]],
    'two entries in order' => [[
        ['path' => '/a', 'op' => 'add', 'old' => null, 'new' => 1],
        ['path' => '/b', 'op' => 'remove', 'old' => 2, 'new' => null],
    ]],
]);

it('keeps the entries in the order the comparison emitted them', function (): void {
    $changes = [
        ['path' => '/z', 'op' => 'add', 'old' => null, 'new' => 1],
        ['path' => '/a', 'op' => 'add', 'old' => null, 'new' => 2],
    ];

    insertAudit(['changes' => json_encode($changes, JSON_THROW_ON_ERROR)]);

    expect(array_column(Audit::query()->sole()->changes ?? [], 'path'))->toBe(['/z', '/a']);
});

it('hands back an entry in the package order however the engine stored its keys', function (): void {
    $changes = [['path' => '/name', 'op' => 'replace', 'old' => 'Ada', 'new' => 'Grace']];

    insertAudit(['changes' => json_encode($changes, JSON_THROW_ON_ERROR)]);

    expect(Audit::query()->sole()->diff()->toArray())->toBe($changes);
});

it('tells an empty diff apart from a null one after a round trip', function (): void {
    insertAudit(['id' => 'AUDITWITHEMPTYDIFF00000001', 'changes' => '[]']);
    insertAudit(['id' => 'AUDITWITHNULLDIFF000000002', 'sequence' => 2, 'changes' => null]);

    $audits = Audit::query()->orderBy('id')->get();

    expect($audits->first()?->changes)->toBe([])
        ->and($audits->last()?->changes)->toBeNull();
});
