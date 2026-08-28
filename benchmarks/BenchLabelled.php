<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Benchmarks;

use ElPandaPe\Sentinel\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

final class BenchLabelled extends Model
{
    use Auditable;

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected array $auditTags = ['billing', 'benchmark'];

    protected $table = 'bench_subjects';

    protected $guarded = [];
}
