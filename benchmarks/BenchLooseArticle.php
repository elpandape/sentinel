<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Benchmarks;

use ElPandaPe\Sentinel\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Audited, declaring no parents: the difference against BenchArticle is the two parent entries
 * and nothing else.
 *
 * @property int|null $author_id
 */
final class BenchLooseArticle extends Model
{
    use Auditable;

    public $timestamps = false;

    protected $table = 'bench_articles';

    protected $guarded = [];
}
