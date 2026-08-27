<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use ReflectionClass;

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

    /**
     * Built without its constructor on purpose. A stage runs inside the lifecycle of the
     * very model it is asking about, and eloquent refuses to be instantiated while it is
     * booting. Declared defaults are set on the object either way, and reading a
     * declaration is all this needs.
     */
    private function resolve(string $subjectType): AuditPolicy
    {
        $class = Relation::getMorphedModel($subjectType) ?? $subjectType;

        if (! class_exists($class) || ! is_a($class, Model::class, true)) {
            return AuditPolicy::none();
        }

        /** @var Model $model */
        $model = new ReflectionClass($class)->newInstanceWithoutConstructor();

        return AuditPolicy::of($model);
    }
}
