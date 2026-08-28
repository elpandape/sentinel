<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Fixtures;

use ElPandaPe\Sentinel\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

final class Team extends Model
{
    use Auditable;

    /**
     * @return BelongsToMany<Member, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Member::class, 'fixture_team_member', 'team_id', 'member_id')
            ->withPivot('role', 'expires_at');
    }

    /**
     * @return BelongsToMany<Member, $this>
     */
    public function guests(): BelongsToMany
    {
        return $this->belongsToMany(Member::class, 'fixture_team_guest', 'team_id', 'member_id');
    }

    /**
     * @return MorphToMany<Label, $this>
     */
    public function labels(): MorphToMany
    {
        return $this->morphToMany(Label::class, 'labelable', 'fixture_labelables')->withPivot('note');
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
