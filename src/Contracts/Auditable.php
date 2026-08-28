<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Contracts;

use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Query\AuditQuery;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

interface Auditable
{
    /**
     * @return MorphMany<Audit, covariant Model>
     */
    public function audits(): MorphMany;

    public function relationHistory(string $relation): AuditQuery;

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

    /**
     * @return list<string>
     */
    public function auditTags(): array;

    /**
     * The columns whose movement is a state change rather than an edit.
     *
     * @return list<string>
     */
    public function auditTransitions(): array;

    /**
     * The belongsTo relations whose parent gets an entry when this model changes hands, keyed by
     * the relation on this model and naming the collection on the parent.
     *
     * @return array<string, string>
     */
    public function auditParents(): array;

    public function auditSnapshotsEnabled(): bool;

    public function auditSeverity(): ?Severity;
}
