<?php

declare(strict_types=1);

use ElPandaPe\Sentinel\Exceptions\CanonicalizationException;
use ElPandaPe\Sentinel\Integrity\JsonCanonicalizer;
use ElPandaPe\Sentinel\Tests\Fixtures\CanonicalVectors;

it('canonicalizes the vectors of the specification', function (array $payload, string $expected): void {
    expect(new JsonCanonicalizer()->canonicalize($payload))->toBe($expected);
})->with(CanonicalVectors::cases());

it('writes numbers the way ECMAScript does', function (int|float $value, string $expected): void {
    expect(new JsonCanonicalizer()->canonicalize(['n' => $value]))->toBe('{"n":'.$expected.'}');
})->with(CanonicalVectors::numbers());

it('does not depend on the serialize precision of the runtime', function (): void {
    $canonicalizer = new JsonCanonicalizer;
    $precision = ini_get('serialize_precision');

    ini_set('serialize_precision', '10');

    try {
        expect($canonicalizer->canonicalize(['n' => 0.1 + 0.2]))->toBe('{"n":0.30000000000000004}');
    } finally {
        ini_set('serialize_precision', $precision === false ? '-1' : $precision);
    }
});

it('refuses a number that json cannot carry', function (float $value): void {
    expect(fn (): string => new JsonCanonicalizer()->canonicalize(['n' => $value]))
        ->toThrow(CanonicalizationException::class);
})->with([NAN, INF, -INF]);

it('refuses a value it cannot canonicalize', function (): void {
    expect(fn (): string => new JsonCanonicalizer()->canonicalize(['n' => new stdClass]))
        ->toThrow(CanonicalizationException::class, 'stdClass');
});

it('refuses a string that is not valid utf-8', function (): void {
    expect(fn (): string => new JsonCanonicalizer()->canonicalize(['n' => "\xB1\x31"]))
        ->toThrow(CanonicalizationException::class, 'UTF-8');
});

it('refuses a key that is not valid utf-8', function (): void {
    expect(fn (): string => new JsonCanonicalizer()->canonicalize(["\xB1\x31" => 'a']))
        ->toThrow(CanonicalizationException::class, 'UTF-8');
});
