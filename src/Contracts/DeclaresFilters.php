<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Contracts;

use ElPandaPe\Sentinel\Enums\Filter;

/**
 * A ledger whose backend cannot translate every published filter declares here the ones it
 * can. A ledger that does not implement this answers all of them, which is why it is not
 * part of Contracts\Ledger: no driver in this package needs it, and adding a method to a
 * contract published one version ago would break every driver that does not need it either.
 *
 * The refusal lands as the filter is added, not when the query runs. A driver that quietly
 * dropped a filter it could not translate would answer with entries nobody asked for, and a
 * trail that shows the wrong history is worse than one that refuses to answer.
 */
interface DeclaresFilters
{
    /**
     * @return list<Filter>
     */
    public function supportedFilters(): array;
}
