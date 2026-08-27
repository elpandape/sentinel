<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Fixtures;

use ElPandaPe\Sentinel\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

final class HiddenSubject extends Model
{
    use Auditable;

    /**
     * @var list<string>
     */
    protected $hidden = ['secret'];

    public function getTable(): string
    {
        return 'fixture_audited_subjects';
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
