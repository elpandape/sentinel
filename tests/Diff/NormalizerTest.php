<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Diff\DiffException;
use ElPandaPe\Sentinel\Diff\Normalizer;
use ElPandaPe\Sentinel\Snapshot\SnapshotBuilder;
use ElPandaPe\Sentinel\Tests\Fixtures\Coordinates;
use ElPandaPe\Sentinel\Tests\Fixtures\PureStatus;
use ElPandaPe\Sentinel\Tests\Fixtures\Slug;
use ElPandaPe\Sentinel\Tests\Fixtures\SubjectStatus;
use ElPandaPe\Sentinel\Tests\Fixtures\UnrepresentableValue;
use Illuminate\Support\Collection;

it('leaves a scalar and a null exactly as they came', function (mixed $value): void {
    expect(Normalizer::value($value))->toBe($value);
})->with([1, 0, -1, 1.5, '1', '', true, false, null]);

it('reduces a backed enum to its value and a pure enum to its name', function (): void {
    expect(Normalizer::value(SubjectStatus::Published))->toBe('published')
        ->and(Normalizer::value(PureStatus::Draft))->toBe('Draft');
});

it('formats a date with the same precision a snapshot keeps', function (): void {
    $date = new DateTimeImmutable('2026-08-26 10:00:00.123456', new DateTimeZone('+00:00'));

    expect(Normalizer::value($date))->toBe('2026-08-26T10:00:00.123456+00:00');
});

it('formats a date exactly as the snapshot builder does', function (): void {
    expect(Normalizer::DATE_FORMAT)->toBe(SnapshotBuilder::DATE_FORMAT);
});

it('reduces a collection to the list it wraps', function (): void {
    expect(Normalizer::value(new Collection(['b', 'a'])))->toBe(['b', 'a']);
});

it('reduces a value object through the contract it declares', function (): void {
    expect(Normalizer::value(new Coordinates(1.5, -2.5)))->toBe(['lat' => 1.5, 'lng' => -2.5])
        ->and(Normalizer::value(new Slug('a-b')))->toBe('a-b');
});

it('keeps a list a list and a map a map without reordering either', function (): void {
    expect(Normalizer::value(['b' => 1, 'a' => 2]))->toBe(['b' => 1, 'a' => 2])
        ->and(Normalizer::value([3, 1, 2]))->toBe([3, 1, 2]);
});

it('reduces every level of a nested structure', function (): void {
    $value = ['tags' => new Collection([SubjectStatus::Published]), 'at' => ['deep' => new Slug('x')]];

    expect(Normalizer::value($value))->toBe(['tags' => ['published'], 'at' => ['deep' => 'x']]);
});

it('refuses a value no contract reaches, naming the path', function (): void {
    Normalizer::value(['profile' => ['badge' => new UnrepresentableValue]]);
})->throws(DiffException::class, '/profile/badge');

it('refuses a structure deeper than the declared limit', function (): void {
    $value = 'leaf';

    for ($i = 0; $i <= Normalizer::MAX_DEPTH; $i++) {
        $value = ['down' => $value];
    }

    Normalizer::value($value);
})->throws(DiffException::class, 'deeper than');
