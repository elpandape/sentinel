<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Fixtures;

use ElPandaPe\Sentinel\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * The inverse side of the polymorphic relation, and audited itself: morphedByMany() is where a
 * label says which teams and which posts carry it, with two related types in the one relation.
 */
final class Label extends Model
{
    use Auditable;

    /**
     * @return MorphToMany<Team, $this>
     */
    public function teams(): MorphToMany
    {
        return $this->morphedByMany(Team::class, 'labelable', 'fixture_labelables');
    }

    /**
     * @return MorphToMany<Post, $this>
     */
    public function posts(): MorphToMany
    {
        return $this->morphedByMany(Post::class, 'labelable', 'fixture_labelables');
    }

    public function getTable(): string
    {
        return 'fixture_labels';
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
