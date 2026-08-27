<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Security;

use ElPandaPe\Sentinel\Contracts\Masker;

/**
 * Keeps the shape and the first and last character of each run, so `c****s@e****e.c****m`
 * is still recognisably an address and still useless as one. The mask is a fixed width:
 * padding to the original length would hand back how long the secret was.
 *
 * A run of two characters or fewer is replaced whole — keeping both ends of it would be
 * keeping all of it.
 */
final readonly class PartialMasker implements Masker
{
    private const int WIDTH = 4;

    private const int SHORTEST = 3;

    public function __construct(private string $mask) {}

    public function mask(string $field, mixed $value): mixed
    {
        return match (true) {
            $value === null => null,
            is_array($value) => array_map(fn (mixed $item): mixed => $this->mask($field, $item), $value),
            is_scalar($value) => $this->partial((string) $value),
            default => str_repeat($this->mask, self::WIDTH),
        };
    }

    private function partial(string $value): string
    {
        return (string) preg_replace_callback(
            '/[\p{L}\p{N}]+/u',
            fn (array $matches): string => $this->run((string) $matches[0]),
            $value,
        );
    }

    private function run(string $run): string
    {
        $filler = str_repeat($this->mask, self::WIDTH);

        return mb_strlen($run) < self::SHORTEST
            ? $filler
            : mb_substr($run, 0, 1).$filler.mb_substr($run, -1);
    }
}
