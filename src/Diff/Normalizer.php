<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Diff;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use JsonSerializable;
use Stringable;
use UnitEnum;

/**
 * Mirrors what the snapshot builder already applied, for the caller who compares two
 * structures the package never built. The date format is the same string on purpose:
 * a test ties the two constants together so they cannot drift apart in silence.
 */
final class Normalizer
{
    public const string DATE_FORMAT = 'Y-m-d\TH:i:s.uP';

    public const int MAX_DEPTH = 64;

    public static function value(mixed $value, string $path = ''): mixed
    {
        return self::reduce($value, $path, 0);
    }

    private static function reduce(mixed $value, string $path, int $depth): mixed
    {
        if ($depth > self::MAX_DEPTH) {
            throw DiffException::tooDeep($path);
        }

        return match (true) {
            $value === null, is_scalar($value) => $value,
            $value instanceof BackedEnum => $value->value,
            $value instanceof UnitEnum => $value->name,
            $value instanceof DateTimeInterface => $value->format(self::DATE_FORMAT),
            $value instanceof Arrayable => self::each($value->toArray(), $path, $depth),
            $value instanceof JsonSerializable => self::reduce($value->jsonSerialize(), $path, $depth),
            $value instanceof Jsonable => self::reduce(json_decode($value->toJson(), true), $path, $depth),
            is_array($value) => self::each($value, $path, $depth),
            $value instanceof Stringable => (string) $value,
            default => throw DiffException::unsupportedType($path, $value),
        };
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function each(array $value, string $path, int $depth): array
    {
        $reduced = [];

        foreach ($value as $key => $item) {
            $reduced[$key] = self::reduce($item, $path.'/'.Pointer::escape((string) $key), $depth + 1);
        }

        return $reduced;
    }
}
