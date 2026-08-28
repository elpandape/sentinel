<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Data\RelationLine;
use ElPandaPe\Sentinel\Enums\RelationOperation;
use ElPandaPe\Sentinel\Integrity\JsonCanonicalizer;

use function ElPandaPe\Sentinel\Tests\relationLine;

it('carries the shape the table holds, minus the entry it hangs off', function (): void {
    expect(relationLine()->toArray())->toBe([
        'relation' => 'roles',
        'operation' => 'attach',
        'related_type' => 'role',
        'related_id' => '3',
        'pivot_before' => null,
        'pivot_after' => null,
    ]);
});

it('orders by relation, then by who was related', function (): void {
    $canonical = RelationLine::canonical([
        relationLine('roles', '9'),
        relationLine('permissions', '2'),
        relationLine('roles', '1'),
    ]);

    expect(array_map(static fn (array $line): string => $line['relation'].'/'.$line['related_id'], $canonical))
        ->toBe(['permissions/2', 'roles/1', 'roles/9']);
});

it('gives the same bytes whatever order the lines arrived in', function (): void {
    $canonicaliser = new JsonCanonicalizer;

    $one = $canonicaliser->canonicalize(RelationLine::canonical([relationLine('roles', '9'), relationLine('roles', '1')]));
    $other = $canonicaliser->canonicalize(RelationLine::canonical([relationLine('roles', '1'), relationLine('roles', '9')]));

    expect($one)->toBe($other);
});

it('separates two lines about the same related by what happened to it', function (): void {
    $canonical = RelationLine::canonical([
        relationLine('roles', '3', RelationOperation::Update),
        relationLine('roles', '3', RelationOperation::Attach),
    ]);

    expect(array_map(static fn (array $line): string => (string) $line['operation'], $canonical))
        ->toBe(['attach', 'update']);
});

it('orders the keys of a pivot state', function (): void {
    $line = new RelationLine(
        'roles',
        RelationOperation::Update,
        'role',
        '3',
        ['expires_at' => '2026-01-01', 'assigned_by' => 'ada'],
        ['assigned_by' => 'ada', 'expires_at' => '2027-01-01'],
    );

    expect(array_keys((array) $line->toArray()['pivot_before']))->toBe(['assigned_by', 'expires_at'])
        ->and(array_keys((array) $line->toArray()['pivot_after']))->toBe(['assigned_by', 'expires_at']);
});

it('keeps a pivot that never existed apart from one that existed and carried nothing', function (): void {
    $line = new RelationLine('roles', RelationOperation::Attach, 'role', '3', null, []);

    expect($line->toArray()['pivot_before'])->toBeNull()
        ->and($line->toArray()['pivot_after'])->toBe([]);
});

it('orders a line that names no related record at all', function (): void {
    $canonical = RelationLine::canonical([
        relationLine('roles', '1'),
        new RelationLine('roles', RelationOperation::Attach),
    ]);

    expect(array_map(static fn (array $line): ?string => $line['related_id'], $canonical))
        ->toBe([null, '1']);
});

it('hands back an empty list unchanged, which is what a sync that changed nothing produces', function (): void {
    expect(RelationLine::canonical([]))->toBeEmpty();
});
