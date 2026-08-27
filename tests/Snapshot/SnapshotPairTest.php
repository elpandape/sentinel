<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Enums\AuditEvent;
use ElPandaPe\Sentinel\Snapshot\SnapshotBuilder;
use ElPandaPe\Sentinel\Tests\Fixtures\CastingSubject;
use ElPandaPe\Sentinel\Tests\Fixtures\SnapshotlessSubject;

use function ElPandaPe\Sentinel\Tests\sentinelConfig;
use function ElPandaPe\Sentinel\Tests\snapshotBuilder;

it('has nothing before a record existed', function (): void {
    $subject = new CastingSubject()->forceFill(['name' => 'Ada']);

    $pair = snapshotBuilder()->pair($subject, AuditEvent::Created);

    expect($pair->before)->toBeNull()
        ->and($pair->after)->toBe(['name' => 'Ada']);
});

it('carries both sides of an update', function (): void {
    $subject = new CastingSubject()->forceFill(['name' => 'Ada']);
    $subject->syncOriginal();
    $subject->name = 'Grace';

    $pair = snapshotBuilder()->pair($subject, AuditEvent::Updated);

    expect($pair->before)->toBe(['name' => 'Ada'])
        ->and($pair->after)->toBe(['name' => 'Grace']);
});

it('has nothing after a record was deleted, logically or physically', function (AuditEvent $event): void {
    $subject = new CastingSubject()->forceFill(['name' => 'Ada']);

    $pair = snapshotBuilder()->pair($subject, $event);

    expect($pair->before)->toBe(['name' => 'Ada'])
        ->and($pair->after)->toBeNull();
})->with([AuditEvent::Deleted, AuditEvent::ForceDeleted]);

it('restores from the state the record had in the bin', function (): void {
    $subject = new CastingSubject()->forceFill(['name' => 'Ada']);
    $point = ['deleted_at' => '2026-08-26T10:00:00.000000+00:00', 'name' => 'Ada'];

    $pair = snapshotBuilder()->pair($subject, AuditEvent::Restored, $point);

    expect($pair->before)->toBe($point)
        ->and($pair->after)->toBe(['name' => 'Ada']);
});

it('tells an empty state apart from no state at all', function (): void {
    $pair = snapshotBuilder()->pair(new CastingSubject, AuditEvent::Created);

    expect($pair->before)->toBeNull()
        ->and($pair->after)->toBe([]);
});

it('carries no snapshot for an event that is not a model change', function (): void {
    $pair = snapshotBuilder()->pair(new CastingSubject, AuditEvent::Custom);

    expect($pair->before)->toBeNull()->and($pair->after)->toBeNull();
});

it('builds the pair even when the configuration says not to keep it', function (): void {
    $builder = new SnapshotBuilder(sentinelConfig(['snapshots.enabled' => false]));
    $subject = new CastingSubject()->forceFill(['name' => 'Ada']);

    expect($builder->pair($subject, AuditEvent::Created)->after)->toBe(['name' => 'Ada'])
        ->and($builder->retains($subject))->toBeFalse();
});

it('builds the pair even when the model says not to keep it', function (): void {
    $subject = new SnapshotlessSubject()->forceFill(['name' => 'Ada']);

    expect(snapshotBuilder()->pair($subject, AuditEvent::Created)->after)->toBe(['name' => 'Ada'])
        ->and(snapshotBuilder()->retains($subject))->toBeFalse();
});

it('keeps the pair for a model that says nothing about snapshots', function (): void {
    expect(snapshotBuilder()->retains(new CastingSubject))->toBeTrue();
});
