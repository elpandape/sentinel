<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Fixtures;

use Illuminate\Contracts\Database\Eloquent\Castable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Contracts\Support\Arrayable;

/**
 * @implements Arrayable<string, int|string>
 */
final readonly class Money implements Arrayable, Castable
{
    public function __construct(public int $amount, public string $currency) {}

    /**
     * @param  array<int, string>  $arguments
     * @return CastsAttributes<Money, Money>
     */
    public static function castUsing(array $arguments): CastsAttributes
    {
        return new MoneyCast;
    }

    /**
     * @return array{amount: int, currency: string}
     */
    public function toArray(): array
    {
        return ['amount' => $this->amount, 'currency' => $this->currency];
    }
}
