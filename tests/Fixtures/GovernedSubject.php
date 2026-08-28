<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Fixtures;

use ElPandaPe\Sentinel\Concerns\Auditable;
use ElPandaPe\Sentinel\Contracts\DeclaresTransitions;
use Illuminate\Database\Eloquent\Model;

final class GovernedSubject extends Model implements DeclaresTransitions
{
    use Auditable;

    /**
     * @var list<string>
     */
    protected array $auditTransitions = ['status'];

    public function allowsTransition(string $attribute, bool|float|int|string|null $from, bool|float|int|string|null $to): bool
    {
        return in_array([$attribute, $from, $to], [
            ['status', 'draft', 'published'],
            ['status', 'published', 'archived'],
            ['status', null, 'draft'],
        ], true);
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
}
