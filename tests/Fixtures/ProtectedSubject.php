<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Fixtures;

use ElPandaPe\Sentinel\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

final class ProtectedSubject extends Model
{
    use Auditable;

    /**
     * @var list<string>
     */
    protected array $auditRedact = ['email'];

    /**
     * @var list<string>
     */
    protected array $auditHash = ['secret'];

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
