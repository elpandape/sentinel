<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Mass;

use ElPandaPe\Sentinel\Enums\AuditEvent;
use ElPandaPe\Sentinel\Enums\MassMode;
use ElPandaPe\Sentinel\Exceptions\ConfigurationException;
use ElPandaPe\Sentinel\Sentinel;
use ElPandaPe\Sentinel\Support\AuditPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A query that asked to be audited. It is what `auditing()` hands back, and it exists so that the
 * three statements Eloquent fires no model event for can be intercepted without intercepting
 * anything else: nothing here is reachable from a query that did not ask.
 *
 * That is the trade this whole version rests on and it is worth stating plainly. Auditing every
 * mass update would turn a one-line statement into thousands of inserts nobody asked for, on
 * queries that have nothing to do with this package. So the switch is per query, and a query
 * without it costs exactly what it cost before: no listener, no extra statement, no branch.
 *
 * @template TModel of Model
 */
final readonly class AuditedQuery
{
    /**
     * @param  Builder<TModel>  $query
     */
    public function __construct(
        private Builder $query,
        private ?MassMode $mode,
        private Strategies $strategies,
        private Criteria $criteria,
        private MassCapture $capture,
        private Sentinel $sentinel,
    ) {}

    /**
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): int
    {
        $writes = Writes::of($values);

        return $this->run(AuditEvent::Updated, $writes, fn (): int => $this->query->update($values));
    }

    public function delete(): int
    {
        return $this->run(AuditEvent::Deleted, Writes::none(), function (): int {
            $deleted = $this->query->delete();

            return is_int($deleted) ? $deleted : 0;
        });
    }

    /**
     * An upsert names its own rows, so there is no criteria to read them back by and no summary
     * that could be anything else: whatever the mode says, this records the shape of what was sent
     * and what the engine reported. Matching a composite uniqueBy back to a where is not a thing
     * this version does, and pretending otherwise would be a mode that quietly did less than it
     * claimed.
     *
     * @param  list<array<string, mixed>>|array<string, mixed>  $values
     * @param  list<string>|string  $uniqueBy
     * @param  list<string>|null  $update
     */
    public function upsert(array $values, array|string $uniqueBy, ?array $update = null): int
    {
        $rows = array_is_list($values) ? $values : [$values];
        $first = $rows === [] ? [] : (array) reset($rows);
        $columns = array_values(array_filter(array_keys($first), is_string(...)));

        $affected = $this->query->upsert($values, $uniqueBy, $update);

        $this->capture->summary(new Operation(
            $this->query,
            AuditEvent::Upserted,
            Writes::none(),
            $this->criteria->ofRows($columns, (array) $uniqueBy, $update ?? $columns, count($rows)),
            MassMode::Summary,
        ), $affected);

        return $affected;
    }

    /**
     * A model that does not use the trait is refused rather than audited with nothing: the
     * declarations are what say which columns of the criteria may be written down, and a mass
     * operation over a model that declared none would be one nothing was protecting.
     *
     * @param  Builder<Model>  $query
     */
    public static function guard(Builder $query): void
    {
        if (! AuditPolicy::declared($query->getModel())) {
            throw ConfigurationException::notAuditable($query->getModel()::class);
        }
    }

    /**
     * Recording is asked about here rather than inside a strategy, so a paused engine leaves the
     * statement exactly as it found it — one call, no read, no transaction around it.
     *
     * @param  \Closure(): int  $run
     */
    private function run(AuditEvent $event, Writes $writes, \Closure $run): int
    {
        if (! $this->sentinel->isRecording()) {
            return $run();
        }

        $mode = $this->strategies->mode($this->mode);

        $operation = new Operation(
            $this->query,
            $event,
            $writes,
            $this->criteria->of($this->query->toBase(), $writes->opaque),
            $mode,
        );

        return $this->strategies->for($mode)->capture($operation, $run);
    }
}
