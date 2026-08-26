<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

final class IntKeySubject extends Model
{
    public function getTable(): string
    {
        return 'fixture_int_subjects';
    }

    public function usesTimestamps(): bool
    {
        return false;
    }

    /**
     * @return list<string>
     */
    public function getGuarded(): array
    {
        return [];
    }
}
