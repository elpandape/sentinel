<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Benchmarks;

use ElPandaPe\Sentinel\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int|null $author_id
 */
final class BenchArticle extends Model
{
    use Auditable;

    public $timestamps = false;

    protected $table = 'bench_articles';

    protected $guarded = [];

    /**
     * @var array<string, string>
     */
    protected array $auditParents = ['author' => 'articles'];

    /**
     * @return BelongsTo<BenchAuthor, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(BenchAuthor::class, 'author_id');
    }
}
