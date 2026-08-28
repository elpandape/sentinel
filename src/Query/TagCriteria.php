<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Query;

/**
 * What a query asks of an entry's labels: the ones it must carry, and the ones at least one of
 * which it must carry. One criterion rather than two, because both spellings narrow the same
 * thing and every other criterion on this surface holds a single value — a repeated call there
 * overwrites, which here would be a filter that quietly stopped narrowing.
 *
 * Asking twice accumulates, so whereTag('a')->whereTag('b') and whereTag(['a', 'b']) are the
 * same question.
 */
final readonly class TagCriteria
{
    /**
     * @param  list<string>  $all
     * @param  list<string>  $any
     */
    public function __construct(public array $all = [], public array $any = []) {}

    /**
     * @param  list<string>  $tags
     */
    public function requiring(array $tags): self
    {
        return new self(array_values(array_unique([...$this->all, ...$tags])), $this->any);
    }

    /**
     * @param  list<string>  $tags
     */
    public function including(array $tags): self
    {
        return new self($this->all, array_values(array_unique([...$this->any, ...$tags])));
    }

    /**
     * @param  list<string>  $labels
     */
    public function matches(array $labels): bool
    {
        foreach ($this->all as $required) {
            if (! in_array($required, $labels, true)) {
                return false;
            }
        }

        return $this->any === [] || array_intersect($this->any, $labels) !== [];
    }
}
