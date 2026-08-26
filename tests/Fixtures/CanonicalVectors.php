<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Fixtures;

final class CanonicalVectors
{
    /**
     * @return array<string, array{array<array-key, mixed>, string}>
     */
    public static function cases(): array
    {
        return [
            'sorts keys by code unit, uppercase before lowercase' => [
                ['b' => 1, 'a' => 2, 'A' => 3],
                '{"A":3,"a":2,"b":1}',
            ],
            'sorts a surrogate pair the way utf-16 does and utf-8 does not' => [
                ["\u{10000}" => 1, "\u{FFFD}" => 2],
                '{"'."\u{10000}".'":1,"'."\u{FFFD}".'":2}',
            ],
            'keeps nested objects sorted too' => [
                ['z' => ['b' => 1, 'a' => 2]],
                '{"z":{"a":2,"b":1}}',
            ],
            'keeps list order untouched' => [
                ['list' => [3, 1, 2]],
                '{"list":[3,1,2]}',
            ],
            'sorts the objects inside a list without reordering the list' => [
                ['list' => [['b' => 1, 'a' => 2], ['d' => 3, 'c' => 4]]],
                '{"list":[{"a":2,"b":1},{"c":4,"d":3}]}',
            ],
            'writes null and booleans as literals' => [
                ['a' => null, 'b' => true, 'c' => false],
                '{"a":null,"b":true,"c":false}',
            ],
            'escapes only what json requires' => [
                ['a' => "quote\" backslash\\ tab\t newline\n"],
                '{"a":"quote\" backslash\\\\ tab\t newline\n"}',
            ],
            'escapes backspace and form feed as the short forms' => [
                ['a' => "\u{0008}\u{000C}"],
                '{"a":"\b\f"}',
            ],
            'escapes control characters as lowercase hex' => [
                ['a' => "\u{0001}\u{001F}"],
                '{"a":"\u0001\u001f"}',
            ],
            'leaves slashes and non-ascii alone' => [
                ['a' => 'a/b', 'b' => 'ñ€'],
                '{"a":"a/b","b":"ñ€"}',
            ],
            'canonicalizes an empty object and an empty list' => [
                ['object' => [], 'list' => []],
                '{"list":[],"object":[]}',
            ],
            'writes a numeric key as the string it becomes' => [
                [10 => 'a', 9 => 'b'],
                '{"10":"a","9":"b"}',
            ],
        ];
    }

    /**
     * @return array<string, array{int|float, string}>
     */
    public static function numbers(): array
    {
        return [
            'integer stays exact' => [1, '1'],
            'integer beyond 2^53 stays exact' => [9_007_199_254_740_993, '9007199254740993'],
            'negative integer' => [-42, '-42'],
            'zero' => [0, '0'],
            'integral float drops its fraction' => [1.0, '1'],
            'one half' => [0.5, '0.5'],
            'negative zero is zero' => [-0.0, '0'],
            'negative float' => [-1.5, '-1.5'],
            'plain decimal' => [1.5, '1.5'],
            'hundred' => [100.0, '100'],
            'smallest decimal without exponent' => [0.000001, '0.000001'],
            'first decimal with exponent' => [1.0e-7, '1e-7'],
            'largest integer without exponent' => [1.0e20, '100000000000000000000'],
            'first integer with exponent' => [1.0e21, '1e+21'],
            'denormal minimum' => [5.0e-324, '5e-324'],
            'double maximum' => [1.7976931348623157e308, '1.7976931348623157e+308'],
            'repeating fraction' => [0.1, '0.1'],
            'sum that is not exact' => [0.1 + 0.2, '0.30000000000000004'],
            'mantissa longer than one digit with an exponent' => [2.5e-10, '2.5e-10'],
        ];
    }
}
