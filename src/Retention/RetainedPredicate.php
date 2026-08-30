<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Retention;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

/**
 * Narrows a query to the entries retention still KEEPS, never to the ones it releases.
 *
 * The direction is the whole point, and it is not a style choice. Written as the negation of what
 * is eligible, an entry with a null subject_type whose audit_type no policy names evaluates
 * `NOT (NULL OR FALSE)`, which is NULL, which is not true — so it would fall out of the kept set and
 * be purged one day after it was written. That entry is not hypothetical: it is what an
 * authentication event with no actor looks like.
 *
 * So every term here evaluates to TRUE or FALSE and never to NULL: an equality on subject_type is
 * guarded by a not-null test in front of it, and the negative is written as "null, or not one of
 * these" rather than as a bare NOT IN.
 */
final readonly class RetainedPredicate
{
    public function __construct(private Schedule $schedule) {}

    public function applyTo(Builder $entries, CarbonImmutable $now): void
    {
        $subjects = $this->schedule->subjectTargets();
        $types = $this->schedule->typeTargets();

        $entries->where(function (Builder $kept) use ($subjects, $types, $now): void {
            foreach ($this->schedule->subjects as $policy) {
                $kept->orWhere(fn (Builder $held): Builder => $held
                    ->whereNotNull('subject_type')
                    ->where('subject_type', $policy->target)
                    ->where('created_at', '>=', $policy->duration->cutoff($now)));
            }

            foreach ($this->schedule->types as $policy) {
                $kept->orWhere(fn (Builder $held): Builder => $this->outsideSubjects($held, $subjects)
                    ->where('audit_type', $policy->target)
                    ->where('created_at', '>=', $policy->duration->cutoff($now)));
            }

            // What no policy names at all is kept forever, which is what makes retention something
            // an installation opts into one logical type at a time.
            $kept->orWhere(fn (Builder $held): Builder => $this->outsideSubjects($held, $subjects)
                ->whereNotIn('audit_type', $types));
        });
    }

    /**
     * @param  list<string>  $subjects
     */
    private function outsideSubjects(Builder $held, array $subjects): Builder
    {
        return $held->where(fn (Builder $outside): Builder => $outside
            ->whereNull('subject_type')
            ->orWhereNotIn('subject_type', $subjects));
    }
}
