<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Benchmarks;

use Illuminate\Database\Eloquent\Model;

final class BenchPlain extends Model
{
    public $timestamps = false;

    protected $table = 'bench_subjects';

    protected $guarded = [];
}
