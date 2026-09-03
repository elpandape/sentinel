<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Tests\Fixtures;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * A job of the application's own that happens to audit something, which is the case the envelope
 * exists for: the entry is written by a worker, and it belongs to the trace of the request that
 * queued the job rather than to the worker that ran it.
 */
final class AuditingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function handle(): void
    {
        new AuditedSubject()->forceFill(['name' => 'Ada'])->save();
    }
}
