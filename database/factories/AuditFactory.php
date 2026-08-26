<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Database\Factories;

use ElPandaPe\Sentinel\Enums\Severity;
use ElPandaPe\Sentinel\Enums\Source;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Audit>
 */
final class AuditFactory extends Factory
{
    public function modelName(): string
    {
        /** @var Config $config */
        $config = app(Config::class);

        return $config->model('audit', Audit::class);
    }

    public function definition(): array
    {
        return [
            'stream' => 'global',
            'sequence' => $this->faker->unique()->numberBetween(1, 1_000_000),
            'audit_type' => 'model',
            'event' => 'created',
            'severity' => Severity::Info,
            'source' => Source::System,
            'context' => [],
            'payload_version' => 1,
            'algorithm' => 'sha256',
            'hash' => hash('sha256', Str::ulid()->toString()),
            'occurred_at' => now(),
        ];
    }
}
