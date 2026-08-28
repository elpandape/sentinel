<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Fixtures;

use ElPandaPe\Sentinel\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Every field policy as a writable property, so one fixture covers what a model may
 * declare and what it may declare wrong.
 */
final class PolicySubject extends Model
{
    use Auditable;

    public mixed $auditInclude = null;

    public mixed $auditExclude = null;

    public mixed $auditRedact = null;

    public mixed $auditEncrypt = null;

    public mixed $auditHash = null;

    public mixed $auditTags = null;

    public mixed $auditParents = null;

    public mixed $auditSnapshots = null;

    public mixed $auditSeverity = null;

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
