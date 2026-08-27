<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Support;

use Closure;
use ElPandaPe\Sentinel\Data\AuditData;

/**
 * The package ships no policy of its own: it ships the register an application puts its
 * own in. Not scoped, because a policy is a decision of the application and outlives the
 * request or the job that happens to be running when an entry is built.
 */
final class Policies
{
    /**
     * @var list<Closure(AuditData): bool>
     */
    private array $policies = [];

    /**
     * @param  Closure(AuditData): bool  $policy
     */
    public function add(Closure $policy): void
    {
        $this->policies[] = $policy;
    }

    public function allows(AuditData $audit): bool
    {
        return array_all($this->policies, static fn (Closure $policy): bool => $policy($audit));
    }

    public function forget(): void
    {
        $this->policies = [];
    }
}
