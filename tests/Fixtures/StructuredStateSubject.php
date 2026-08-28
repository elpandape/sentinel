<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Fixtures;

use ElPandaPe\Sentinel\Concerns\Auditable;
use ElPandaPe\Sentinel\Contracts\DeclaresTransitions;
use Illuminate\Database\Eloquent\Model;

final class StructuredStateSubject extends Model implements DeclaresTransitions
{
    use Auditable;

    /**
     * @var list<string>
     */
    protected array $auditTransitions = ['options'];

    public function allowsTransition(string $attribute, bool|float|int|string|null $from, bool|float|int|string|null $to): bool
    {
        return false;
    }

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

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['options' => 'array'];
    }
}
