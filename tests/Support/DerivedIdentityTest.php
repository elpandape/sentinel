<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Support\DerivedIdentity;
use Illuminate\Support\Str;

it('gives the same row the same identity on every run', function (): void {
    expect(DerivedIdentity::of('owenit', '4711'))->toBe(DerivedIdentity::of('owenit', '4711'));
});

it('gives two rows of one source two identities', function (): void {
    expect(DerivedIdentity::of('owenit', '4711'))->not->toBe(DerivedIdentity::of('owenit', '4712'));
});

it('keeps two sources apart when they both number their rows from one', function (): void {
    expect(DerivedIdentity::of('owenit', '1'))->not->toBe(DerivedIdentity::of('altek', '1'));
});

it('is a ulid by shape, so nothing that checks the column is surprised by it', function (): void {
    $identity = DerivedIdentity::of('owenit', '4711');

    expect(Str::isUlid($identity))->toBeTrue()
        ->and($identity)->toHaveLength(26)
        ->and(strspn($identity, DerivedIdentity::ALPHABET))->toBe(26);
});

it('spreads over the alphabet instead of leaning on one end of it', function (): void {
    $identities = array_map(static fn (int $row): string => DerivedIdentity::of('owenit', (string) $row), range(1, 200));

    expect(array_unique($identities))->toHaveCount(200)
        ->and(count(array_unique(array_map(static fn (string $id): string => $id[0], $identities))))->toBeGreaterThan(4);
});
