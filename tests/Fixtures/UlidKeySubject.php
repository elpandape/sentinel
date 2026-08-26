<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

final class UlidKeySubject extends Model
{
    use HasUlids;

    public function getTable(): string
    {
        return 'fixture_ulid_subjects';
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
