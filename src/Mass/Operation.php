<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Mass;

use ElPandaPe\Sentinel\Enums\AuditEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One mass operation, as everything that records it needs to know: what is about to happen, over
 * which query, writing what, and the shape the entry will carry.
 *
 * The criteria arrives serialised. Reading a builder is one job and writing an entry is another,
 * and a capture that did both would be a second place where a raw fragment could slip through.
 *
 * @template TModel of Model
 */
final readonly class Operation
{
    /**
     * @param  Builder<TModel>  $query
     * @param  array<string, mixed>  $criteria
     */
    public function __construct(
        public Builder $query,
        public AuditEvent $event,
        public Writes $writes,
        public array $criteria,
    ) {}

    /**
     * @return TModel
     */
    public function model(): Model
    {
        return $this->query->getModel();
    }
}
