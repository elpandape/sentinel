<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Concerns;

use ElPandaPe\Sentinel\Capture\ModelObserver;
use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Exceptions\ConfigurationException;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait Auditable
{
    /**
     * The eloquent events the package listens to. Not five: a force delete fires
     * `deleted` on its way to `forceDeleted`, and by the time `restored` fires the
     * original is already synced, so both are derived from the four below.
     *
     * @var list<string>
     */
    private const array AUDITED_EVENTS = ['created', 'updated', 'deleted', 'forceDeleted'];

    public static function bootAuditable(): void
    {
        // Model::observe() instantiates the model, which cannot happen while it is booting.
        foreach (self::AUDITED_EVENTS as $event) {
            static::registerModelEvent($event, [ModelObserver::class, $event]);
        }
    }

    /**
     * @return MorphMany<Audit, $this>
     */
    public function audits(): MorphMany
    {
        /** @var MorphMany<Audit, $this> $audits */
        $audits = $this->morphMany($this->auditModel(), 'subject')->orderBy('id');

        return $audits;
    }

    public function latestAudit(): ?Audit
    {
        return $this->audits()->reorder()->orderByDesc('id')->first();
    }

    /**
     * @return list<string>
     */
    public function auditIncluded(): array
    {
        return $this->auditFields('auditInclude');
    }

    /**
     * @return list<string>
     */
    public function auditExcluded(): array
    {
        return $this->auditFields('auditExclude');
    }

    /**
     * @return list<string>
     */
    public function auditRedacted(): array
    {
        return $this->auditFields('auditRedact');
    }

    /**
     * @return list<string>
     */
    public function auditEncrypted(): array
    {
        return $this->auditFields('auditEncrypt');
    }

    /**
     * @return list<string>
     */
    public function auditHashed(): array
    {
        return $this->auditFields('auditHash');
    }

    /**
     * @return list<string>
     */
    public function auditTags(): array
    {
        return $this->auditFields('auditTags');
    }

    public function auditSnapshotsEnabled(): bool
    {
        $value = $this->auditProperty('auditSnapshots');

        return match (true) {
            $value === null => true,
            is_bool($value) => $value,
            default => throw ConfigurationException::expected('auditSnapshots', 'a boolean', get_debug_type($value)),
        };
    }

    public function auditSeverity(): ?Severity
    {
        $value = $this->auditProperty('auditSeverity');

        return match (true) {
            $value === null => null,
            $value instanceof Severity => $value,
            is_string($value) => Severity::tryFrom($value) ?? throw ConfigurationException::unknown(
                'auditSeverity',
                $value,
                'info, notice, warning, critical',
            ),
            default => throw ConfigurationException::expected('auditSeverity', 'a Severity or its value', get_debug_type($value)),
        };
    }

    /**
     * @return list<string>
     */
    private function auditFields(string $property): array
    {
        $value = $this->auditProperty($property);

        if ($value === null) {
            return [];
        }

        if (! is_array($value)) {
            throw ConfigurationException::expected($property, 'a list of strings', get_debug_type($value));
        }

        foreach ($value as $field) {
            if (! is_string($field)) {
                throw ConfigurationException::expected($property, 'a list of strings', get_debug_type($field));
            }
        }

        return array_values($value);
    }

    private function auditProperty(string $property): mixed
    {
        return property_exists($this, $property) ? $this->{$property} : null;
    }

    /**
     * @return class-string<Audit>
     */
    private function auditModel(): string
    {
        /** @var Config $config */
        $config = app(Config::class);

        return $config->model('audit', Audit::class);
    }
}
