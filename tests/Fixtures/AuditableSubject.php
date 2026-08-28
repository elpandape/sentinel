<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Fixtures;

use ElPandaPe\Sentinel\Contracts\Auditable;
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Models\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

final class AuditableSubject extends Model implements Auditable
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

    /**
     * @return MorphMany<Audit, $this>
     */
    public function audits(): MorphMany
    {
        return $this->morphMany(Audit::class, 'subject');
    }

    /**
     * @return list<string>
     */
    public function auditIncluded(): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    public function auditExcluded(): array
    {
        return ['remember_token'];
    }

    /**
     * @return list<string>
     */
    public function auditRedacted(): array
    {
        return ['email'];
    }

    /**
     * @return list<string>
     */
    public function auditEncrypted(): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    public function auditHashed(): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    public function auditTags(): array
    {
        return ['contract'];
    }

    public function auditSnapshotsEnabled(): bool
    {
        return true;
    }

    public function auditSeverity(): ?Severity
    {
        return null;
    }
}
