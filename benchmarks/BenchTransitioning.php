<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Benchmarks;

use ElPandaPe\Sentinel\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

final class BenchTransitioning extends Model
{
    use Auditable;

    public $timestamps = false;

    protected $table = 'bench_subjects';

    protected $guarded = [];

    /**
     * @var list<string>
     */
    protected array $auditTransitions = ['role'];
}
