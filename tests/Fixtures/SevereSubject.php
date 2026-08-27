<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Fixtures;

use ElPandaPe\Sentinel\Concerns\Auditable;
use ElPandaPe\Sentinel\Enums\Severity;
use Illuminate\Database\Eloquent\Model;

final class SevereSubject extends Model
{
    use Auditable;

    protected Severity $auditSeverity = Severity::Critical;

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
