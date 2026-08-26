<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Integrity;

use ElPandaPe\Sentinel\Contracts\Canonicalizer;
use ElPandaPe\Sentinel\Exceptions\CanonicalizationException;
use JsonException;

final class JsonCanonicalizer implements Canonicalizer
{
    private const int MAX_SIGNIFICANT_DIGITS = 17;

    public function canonicalize(array $payload): string
    {
        return $this->object($payload);
    }

    /**
     * @param  array<array-key, mixed>  $value
     */
    private function object(array $value): string
    {
        $keys = array_map(strval(...), array_keys($value));

        usort($keys, $this->compare(...));

        return '{'.implode(',', array_map(
            fn (string $key): string => $this->string($key).':'.$this->value($value[$key]),
            $keys,
        )).'}';
    }

    /**
     * @param  list<mixed>  $value
     */
    private function list(array $value): string
    {
        return '['.implode(',', array_map($this->value(...), $value)).']';
    }

    private function value(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => (string) $value,
            is_float($value) => $this->number($value),
            is_string($value) => $this->string($value),
            is_array($value) => array_is_list($value) ? $this->list($value) : $this->object($value),
            default => throw CanonicalizationException::unsupportedType($value),
        };
    }

    // RFC 8785 orders members by UTF-16 code unit, which above the basic plane is not UTF-8 byte order.
    private function compare(string $left, string $right): int
    {
        return strcmp(
            mb_convert_encoding($left, 'UTF-16BE', 'UTF-8'),
            mb_convert_encoding($right, 'UTF-16BE', 'UTF-8'),
        );
    }

    private function string(string $value): string
    {
        try {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw CanonicalizationException::invalidString();
        }
    }

    private function number(float $value): string
    {
        if (is_nan($value) || is_infinite($value)) {
            throw CanonicalizationException::unsupportedNumber($value);
        }

        if ($value === 0.0) {
            return '0';
        }

        if ($value < 0) {
            return '-'.$this->number(-$value);
        }

        [$digits, $point] = $this->shortest($value);

        return $this->ecmascript($digits, $point);
    }

    /**
     * @return array{string, int}
     */
    private function shortest(float $value): array
    {
        $candidate = sprintf('%.0E', $value);

        for ($precision = 1; $precision < self::MAX_SIGNIFICANT_DIGITS && (float) $candidate !== $value; $precision++) {
            $candidate = sprintf('%.'.$precision.'E', $value);
        }

        $parts = explode('E', $candidate);

        return [rtrim(str_replace('.', '', $parts[0]), '0'), (int) ($parts[1] ?? '0') + 1];
    }

    // ECMAScript Number::toString, with $point the position of the decimal point among $digits.
    private function ecmascript(string $digits, int $point): string
    {
        $length = strlen($digits);

        return match (true) {
            $length <= $point && $point <= 21 => $digits.str_repeat('0', $point - $length),
            $point > 0 && $point <= 21 => substr($digits, 0, $point).'.'.substr($digits, $point),
            $point > -6 && $point <= 0 => '0.'.str_repeat('0', -$point).$digits,
            $length === 1 => $digits.'e'.$this->exponent($point),
            default => $digits[0].'.'.substr($digits, 1).'e'.$this->exponent($point),
        };
    }

    private function exponent(int $point): string
    {
        return $point > 0 ? '+'.($point - 1) : (string) ($point - 1);
    }
}
