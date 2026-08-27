<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Benchmarks;

use ElPandaPe\Sentinel\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * One encrypted field and one masked one, out of six columns. What a write costs when the
 * protections actually have work to do, next to the same write when they have none.
 */
final class BenchProtected extends Model
{
    use Auditable;

    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected array $auditEncrypt = ['email'];

    /**
     * @var list<string>
     */
    protected array $auditRedact = ['role'];

    protected $table = 'bench_subjects';

    protected $guarded = [];
}
