<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Events;

use ElPandaPe\Sentinel\Models\Audit;
use Illuminate\Database\Eloquent\Model;

/**
 * A restoration about to touch the record. A listener that returns false stops it, and that is
 * the whole of the package's answer to who may restore: §18 defines no authorization, restoring
 * writes into the business model, and a gate invented here would be a policy Sentinel has no
 * standing to impose. The application decides, on the hook it already has.
 *
 * The keys travel, the values do not. A listener holds the entry and the record and can read
 * either; putting the plaintext of a field the pipeline masked into an event payload would undo
 * the one thing that pipeline is for.
 */
final readonly class AuditRestoring
{
    /**
     * @param  list<string>  $applying
     */
    public function __construct(
        public Audit $entry,
        public Model $subject,
        public array $applying,
        public ?string $relation = null,
    ) {}
}
