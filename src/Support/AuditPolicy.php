<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Support;

use ElPandaPe\Sentinel\Concerns\Auditable as AuditableConcern;
use ElPandaPe\Sentinel\Contracts\Auditable;
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Exceptions\ConfigurationException;
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
     * @param  list<string>  $transitions
     * @param  array<string, string>  $parents
     */
    private function __construct(
        public array $included,
        public array $excluded,
        public array $redacted,
        public array $encrypted,
        public array $hashed,
        public array $tags,
        public array $transitions,
        public array $parents,
        public bool $snapshots,
        public ?Severity $severity,
    ) {
        $this->readable();
    }

    public static function none(): self
    {
        return new self([], [], [], [], [], [], [], [], true, null);
    }

    /**
     * Whether the model says anything about auditing itself at all. The difference between a model
     * with nothing declared and one that is not auditable is invisible to of() — both answer with
     * the empty policy — and there is one caller that has to tell them apart: a mass operation
     * refuses the second rather than recording a criteria nothing was protecting.
     */
    public static function declared(Model $model): bool
    {
        return self::answers($model);
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
            $model->auditTransitions(),
            $model->auditParents(),
            $model->auditSnapshotsEnabled(),
            $model->auditSeverity(),
        );
    }

    /**
     * A lifeline the entry cannot show is not a lifeline. A state column that is dropped,
     * masked, hashed or encrypted answers the question the feature exists for with a row of
     * asterisks, so the combination is refused where both lists are visible at once rather
     * than discovered by reading an entry months later.
     */
    private function readable(): void
    {
        foreach ($this->transitions as $column) {
            match (true) {
                in_array($column, $this->excluded, true) => throw ConfigurationException::unreadableTransition($column, 'auditExclude'),
                in_array($column, $this->redacted, true) => throw ConfigurationException::unreadableTransition($column, 'auditRedact'),
                in_array($column, $this->encrypted, true) => throw ConfigurationException::unreadableTransition($column, 'auditEncrypt'),
                in_array($column, $this->hashed, true) => throw ConfigurationException::unreadableTransition($column, 'auditHash'),
                $this->included !== [] && ! in_array($column, $this->included, true) => throw ConfigurationException::omittedTransition($column),
                default => null,
            };
        }
    }

    private static function answers(Model $model): bool
    {
        return $model instanceof Auditable
            || in_array(AuditableConcern::class, class_uses_recursive($model), true);
    }
}
