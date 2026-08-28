<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Fixtures;

use ElPandaPe\Sentinel\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A pivot has no class of its own to declare anything on, so the parent that owns the relation
 * declares for it — with the same three properties it already uses for its own columns.
 */
final class ProtectedTeam extends Model
{
    use Auditable;

    /**
     * @var list<string>
     */
    protected array $auditRedact = ['role'];

    /**
     * @var list<string>
     */
    protected array $auditEncrypt = ['expires_at'];

    /**
     * @return BelongsToMany<Member, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Member::class, 'fixture_team_member', 'team_id', 'member_id')
            ->withPivot('role', 'expires_at');
    }

    public function getTable(): string
    {
        return 'fixture_teams';
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
