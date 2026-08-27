<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Fixtures;

/**
 * One structure and its successor, wide enough that a comparison touches every rule the
 * component declares: nested maps, an identified list, a plain list and a key that goes.
 */
final class DiffVectors
{
    /**
     * @return array{array<string, mixed>, array<string, mixed>}
     */
    public static function pair(): array
    {
        return [
            [
                'name' => 'Ada',
                'score' => 10,
                'legacy' => true,
                'profile' => ['address' => ['city' => 'Lima', 'zip' => '15001']],
                'roles' => [['id' => 1, 'name' => 'admin'], ['id' => 2, 'name' => 'editor']],
                'tags' => ['a', 'b'],
            ],
            [
                'name' => 'Ada',
                'score' => 11,
                'profile' => ['address' => ['city' => 'Arequipa', 'zip' => '15001']],
                'roles' => [['id' => 1, 'name' => 'admin'], ['id' => 3, 'name' => 'viewer']],
                'tags' => ['a', 'b', 'c'],
            ],
        ];
    }
}
