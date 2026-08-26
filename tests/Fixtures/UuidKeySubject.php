<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class UuidKeySubject extends Model
{
    use HasUuids;

    public function getTable(): string
    {
        return 'fixture_uuid_subjects';
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
