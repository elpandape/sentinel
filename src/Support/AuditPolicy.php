<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Support;

use ElPandaPe\Sentinel\Concerns\Auditable as AuditableConcern;
use ElPandaPe\Sentinel\Contracts\Auditable;
use ElPandaPe\Sentinel\Enums\Severity;
use Illuminate\Database\Eloquent\Model;

/**
 * What a model says about auditing itself. A trait cannot implement an interface, so a
 * model that uses the concern satisfies the contract without declaring it: this is the
 * one place that accepts either shape, and the defaults for a model that is neither.
 */
final readonly class AuditPolicy
{
    /**
     * @param  list<string>  $included
     * @param  list<string>  $excluded
     * @param  list<string>  $redacted
     * @param  list<string>  $encrypted
     * @param  list<string>  $hashed
     * @param  list<string>  $tags
     * @param  array<string, string>  $parents
     */
    private function __construct(
        public array $included,
        public array $excluded,
        public array $redacted,
        public array $encrypted,
        public array $hashed,
        public array $tags,
        public array $parents,
        public bool $snapshots,
        public ?Severity $severity,
    ) {}

    public static function none(): self
    {
        return new self([], [], [], [], [], [], [], true, null);
    }

    public static function of(Model $model): self
    {
        if (! self::answers($model)) {
            return self::none();
        }

        /** @var Model&Auditable $model */
        return new self(
            $model->auditIncluded(),
            $model->auditExcluded(),
            $model->auditRedacted(),
            $model->auditEncrypted(),
            $model->auditHashed(),
            $model->auditTags(),
            $model->auditParents(),
            $model->auditSnapshotsEnabled(),
            $model->auditSeverity(),
        );
    }

    private static function answers(Model $model): bool
    {
        return $model instanceof Auditable
            || in_array(AuditableConcern::class, class_uses_recursive($model), true);
    }
}
