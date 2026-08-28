<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Benchmarks;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int|null $author_id
 */
final class BenchPlainArticle extends Model
{
    public $timestamps = false;

    protected $table = 'bench_articles';

    protected $guarded = [];
}
