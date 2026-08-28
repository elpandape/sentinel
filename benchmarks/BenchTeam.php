<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Benchmarks;

use ElPandaPe\Sentinel\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class BenchTeam extends Model
{
    use Auditable;

    public $timestamps = false;

    protected $table = 'bench_teams';

    protected $guarded = [];

    /**
     * @return BelongsToMany<BenchMember, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(BenchMember::class, 'bench_team_member', 'team_id', 'member_id')
            ->withPivot('role');
    }
}
