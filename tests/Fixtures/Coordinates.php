<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Fixtures;

use JsonSerializable;

final readonly class Coordinates implements JsonSerializable
{
    public function __construct(public float $latitude, public float $longitude) {}

    /**
     * @return array{lat: float, lng: float}
     */
    public function jsonSerialize(): array
    {
        return ['lat' => $this->latitude, 'lng' => $this->longitude];
    }
}
