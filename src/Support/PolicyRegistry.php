<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * A pipeline stage knows the subject type, not the model: the data object is named after
 * the columns and travels serialized once the queued mode lands. This is where the type
 * becomes what the model declared, memoized because one subject writes many entries.
 */
final class PolicyRegistry
{
    /**
     * @var array<string, AuditPolicy>
     */
    private array $policies = [];

    public function for(?string $subjectType): AuditPolicy
    {
        if ($subjectType === null) {
            return AuditPolicy::none();
        }

        return $this->policies[$subjectType] ??= $this->resolve($subjectType);
    }

    private function resolve(string $subjectType): AuditPolicy
    {
        $class = Relation::getMorphedModel($subjectType) ?? $subjectType;

        if (! class_exists($class) || ! is_a($class, Model::class, true)) {
            return AuditPolicy::none();
        }

        return AuditPolicy::of(new $class);
    }
}
