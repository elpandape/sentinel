<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Import\Row;

it('reads a scalar column as text and an absent one as nothing', function (): void {
    $row = new Row(['name' => 'Ada', 'count' => 7, 'empty' => '', 'nothing' => null]);

    expect($row->text('name'))->toBe('Ada')
        ->and($row->text('count'))->toBe('7')
        ->and($row->text('empty'))->toBeNull()
        ->and($row->text('nothing'))->toBeNull()
        ->and($row->text('absent'))->toBeNull();
});

it('reads a numeric column as a number, whatever the driver handed back', function (): void {
    $row = new Row(['id' => '4711', 'mask' => 4, 'word' => 'four']);

    expect($row->integer('id'))->toBe(4711)
        ->and($row->integer('mask'))->toBe(4)
        ->and($row->integer('word'))->toBeNull()
        ->and($row->integer('absent'))->toBeNull();
});

it('decodes a json column whether the driver handed it back as text or as an array', function (): void {
    expect(new Row(['values' => '{"name":"Ada"}'])->json('values'))->toBe(['name' => 'Ada'])
        ->and(new Row(['values' => ['name' => 'Ada']])->json('values'))->toBe(['name' => 'Ada']);
});

it('refuses a json column that is not an attribute map, rather than half understanding it', function (): void {
    expect(new Row(['values' => '["Ada","Grace"]'])->json('values'))->toBeNull()
        ->and(new Row(['values' => 'not json at all'])->json('values'))->toBeNull()
        ->and(new Row(['values' => '"Ada"'])->json('values'))->toBeNull()
        ->and(new Row(['values' => ''])->json('values'))->toBeNull()
        ->and(new Row(['values' => null])->json('values'))->toBeNull();
});

it('reads an instant, and says nothing rather than inventing one', function (): void {
    expect(new Row(['at' => '2026-03-04 05:06:07'])->instant('at')?->format('Y-m-d H:i:s'))
        ->toBe('2026-03-04 05:06:07')
        ->and(new Row(['at' => null])->instant('at'))->toBeNull()
        ->and(new Row(['at' => 'the day before yesterday, ish'])->instant('at'))->toBeNull();
});

it('reads a row the driver handed back as an object', function (): void {
    expect(Row::of((object) ['name' => 'Ada'])->text('name'))->toBe('Ada');
});
