<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Exceptions\SnapshotException;
use ElPandaPe\Sentinel\Snapshot\SnapshotBuilder;
use ElPandaPe\Sentinel\Tests\Fixtures\CastingSubject;
use ElPandaPe\Sentinel\Tests\Fixtures\HiddenSubject;
use ElPandaPe\Sentinel\Tests\Fixtures\IntKeySubject;
use ElPandaPe\Sentinel\Tests\Fixtures\Money;
use ElPandaPe\Sentinel\Tests\Fixtures\SelectiveSubject;
use ElPandaPe\Sentinel\Tests\Fixtures\SubjectStatus;

use function ElPandaPe\Sentinel\Tests\sentinelConfig;
use function ElPandaPe\Sentinel\Tests\snapshotBuilder;

it('serializes a backed enum by its value and a date with microseconds', function (): void {
    $subject = new CastingSubject()->forceFill([
        'status' => SubjectStatus::Published->value,
        'published_at' => '2026-08-26 10:00:00.123456',
    ]);

    $snapshot = snapshotBuilder()->build($subject, $subject->getAttributes());

    expect($snapshot['status'])->toBe('published')
        ->and($snapshot['published_at'])->toBe('2026-08-26T10:00:00.123456+00:00');
});

it('serializes a value object through the representation it declares', function (): void {
    $subject = new CastingSubject;
    $subject->price = new Money(1250, 'PEN');

    expect(snapshotBuilder()->build($subject, $subject->getAttributes())['price'])
        ->toBe(['amount' => 1250, 'currency' => 'PEN']);
});

it('keeps a list a list and a map a map', function (): void {
    $subject = new CastingSubject()->forceFill(['options' => ['z' => 1, 'a' => [1, 2]]]);

    expect(snapshotBuilder()->build($subject, $subject->getAttributes())['options'])
        ->toBe(['a' => [1, 2], 'z' => 1]);
});

it('orders keys so the same state always produces the same map', function (): void {
    $subject = new CastingSubject()->forceFill(['name' => 'Ada', 'email' => 'ada@example.com']);

    expect(array_keys(snapshotBuilder()->build($subject, $subject->getAttributes())))
        ->toBe(['email', 'name']);
});

it('drops the attributes the model excludes', function (): void {
    $subject = new CastingSubject()->forceFill(['name' => 'Ada', 'secret' => 'shhh']);

    expect(snapshotBuilder()->build($subject, $subject->getAttributes()))
        ->toHaveKey('name')
        ->not->toHaveKey('secret');
});

it('lets the include list win over the exclude list', function (): void {
    $subject = new SelectiveSubject()->forceFill([
        'name' => 'Ada',
        'email' => 'ada@example.com',
        'secret' => 'shhh',
    ]);

    expect(array_keys(snapshotBuilder()->build($subject, $subject->getAttributes())))
        ->toBe(['email', 'name']);
});

it('audits hidden attributes by default', function (): void {
    $subject = new HiddenSubject()->forceFill(['name' => 'Ada', 'secret' => 'shhh']);

    expect(snapshotBuilder()->build($subject, $subject->getAttributes()))->toHaveKey('secret');
});

it('drops hidden attributes when the configuration says so', function (): void {
    $subject = new HiddenSubject()->forceFill(['name' => 'Ada', 'secret' => 'shhh']);
    $builder = new SnapshotBuilder(sentinelConfig(['snapshots.include_hidden' => false]));

    expect($builder->build($subject, $subject->getAttributes()))->not->toHaveKey('secret');
});

it('reflects the precision the audited model keeps, and no more', function (): void {
    $subject = new HiddenSubject;
    $subject->mergeCasts(['published_at' => 'immutable_datetime']);
    $subject->published_at = '2026-08-26 10:00:00.123456';

    expect(snapshotBuilder()->build($subject, $subject->getAttributes())['published_at'])
        ->toBe('2026-08-26T10:00:00.000000+00:00');
});

it('reads the state it is given, not the state the model is in', function (): void {
    $subject = new CastingSubject()->forceFill(['name' => 'Ada']);
    $subject->syncOriginal();
    $subject->name = 'Grace';

    expect(snapshotBuilder()->build($subject, $subject->getRawOriginal())['name'])->toBe('Ada')
        ->and(snapshotBuilder()->build($subject, $subject->getAttributes())['name'])->toBe('Grace');
});

it('reads every attribute of a model that answers for no policy', function (): void {
    $subject = new IntKeySubject()->forceFill(['b' => 2, 'a' => 1]);

    expect(snapshotBuilder()->build($subject, $subject->getAttributes()))->toBe(['a' => 1, 'b' => 2]);
});

it('refuses an attribute it cannot represent instead of writing something else', function (): void {
    $subject = new CastingSubject;
    $subject->setAttribute('name', fopen('php://memory', 'r'));

    snapshotBuilder()->build($subject, $subject->getAttributes());
})->throws(SnapshotException::class, 'name');
