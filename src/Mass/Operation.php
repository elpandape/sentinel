<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Mass;

use ElPandaPe\Sentinel\Enums\AuditEvent;
use ElPandaPe\Sentinel\Enums\MassMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * One mass operation, as everything that records it needs to know: what is about to happen, over
 * which query, writing what, under which mode, and the shape the entry will carry.
 *
 * The criteria arrives serialised. Reading a builder is one job and writing an entry is another,
 * and a capture that did both would be a second place where a raw fragment could slip through.
 *
 * The mode travels with the operation rather than being read again where the entry is built. It is
 * resolved once, from the query or from the configuration, and an operation that asked one thing
 * and recorded another would be the one bug this is here to make impossible.
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
        public MassMode $mode,
    ) {}

    /**
     * @return TModel
     */
    public function model(): Model
    {
        return $this->query->getModel();
    }
}
