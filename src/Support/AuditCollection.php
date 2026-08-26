<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Support;

use ElPandaPe\Sentinel\Models\Audit;
use Illuminate\Database\Eloquent\Collection;

/**
 * @extends Collection<int, Audit>
 */
final class AuditCollection extends Collection {}
