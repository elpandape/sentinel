<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Pipeline\Stages\NormalizeData;

use function ElPandaPe\Sentinel\Tests\auditData;
use function ElPandaPe\Sentinel\Tests\pipeline;
use function ElPandaPe\Sentinel\Tests\stagedPipeline;

beforeEach(function (): void {
    stagedPipeline([NormalizeData::class]);
});

it('sorts the keys of every stored container', function (): void {
    $audit = pipeline()->process(auditData([
        'before' => ['z' => 1, 'a' => 2],
        'after' => ['z' => 3, 'a' => 4],
        'metadata' => ['tag' => 'x', 'batch' => 'y'],
        'context' => ['url' => '/orders', 'ip' => '203.0.113.7'],
    ]));

    expect(array_keys($audit?->before ?? []))->toBe(['a', 'z'])
        ->and(array_keys($audit?->after ?? []))->toBe(['a', 'z'])
        ->and(array_keys($audit?->metadata ?? []))->toBe(['batch', 'tag'])
        ->and(array_keys($audit?->context ?? []))->toBe(['ip', 'url']);
});

it('sorts keys all the way down', function (): void {
    $audit = pipeline()->process(auditData([
        'metadata' => ['outer' => ['z' => ['b' => 1, 'a' => 2], 'a' => 3]],
    ]));

    expect($audit?->metadata)->toBe(['outer' => ['a' => 3, 'z' => ['a' => 2, 'b' => 1]]]);
});

it('leaves a list in the order it arrived, because there the position is the meaning', function (): void {
    $audit = pipeline()->process(auditData([
        'metadata' => ['tags' => ['zulu', 'alpha', 'mike']],
    ]));

    expect($audit?->metadata)->toBe(['tags' => ['zulu', 'alpha', 'mike']]);
});

it('sorts the maps inside a list without reordering the list', function (): void {
    $audit = pipeline()->process(auditData([
        'metadata' => ['rows' => [['z' => 1, 'a' => 2], ['y' => 3, 'b' => 4]]],
    ]));

    expect($audit?->metadata)->toBe(['rows' => [['a' => 2, 'z' => 1], ['b' => 4, 'y' => 3]]]);
});

it('keeps a null container null instead of turning it into an empty one', function (): void {
    $audit = pipeline()->process(auditData(['before' => null, 'after' => null, 'metadata' => null]));

    expect($audit?->before)->toBeNull()
        ->and($audit?->after)->toBeNull()
        ->and($audit?->metadata)->toBeNull();
});

it('leaves the diff operations alone, contract and empty list included', function (): void {
    $changes = [['path' => '/name', 'op' => 'replace', 'old' => 'Ada', 'new' => 'Grace']];

    expect(pipeline()->process(auditData(['changes' => $changes]))?->changes)->toBe($changes)
        ->and(pipeline()->process(auditData(['changes' => []]))?->changes)->toBe([]);
});
