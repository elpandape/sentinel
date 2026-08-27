<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Contracts;

use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Models\Audit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

interface Auditable
{
    /**
     * @return MorphMany<Audit, covariant Model>
     */
    public function audits(): MorphMany;

    /**
     * @return list<string>
     */
    public function auditIncluded(): array;

    /**
     * @return list<string>
     */
    public function auditExcluded(): array;

    /**
     * @return list<string>
     */
    public function auditRedacted(): array;

    /**
     * @return list<string>
     */
    public function auditEncrypted(): array;

    /**
     * @return list<string>
     */
    public function auditHashed(): array;

    public function auditSnapshotsEnabled(): bool;

    public function auditSeverity(): ?Severity;
}
