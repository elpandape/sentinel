<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Fixtures;

use ElPandaPe\Sentinel\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

final class CastingSubject extends Model
{
    use Auditable;

    /**
     * @var list<string>
     */
    protected array $auditExclude = ['secret'];

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

    // The column is datetime(6); without the microseconds Eloquent truncates on the way in.
    public function getDateFormat(): string
    {
        return 'Y-m-d H:i:s.u';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SubjectStatus::class,
            'price' => Money::class,
            'published_at' => 'immutable_datetime',
            'options' => 'array',
            'active' => 'boolean',
        ];
    }
}
