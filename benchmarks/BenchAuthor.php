<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Benchmarks;

use ElPandaPe\Sentinel\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class BenchAuthor extends Model
{
    use Auditable;

    public $timestamps = false;

    protected $table = 'bench_authors';

    protected $guarded = [];

    /**
     * @return HasMany<BenchArticle, $this>
     */
    public function articles(): HasMany
    {
        return $this->hasMany(BenchArticle::class, 'author_id');
    }
}
