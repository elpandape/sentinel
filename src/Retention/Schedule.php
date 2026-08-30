<?php

declare(strict_types=1);

namespace ElPandaPe\Sentinel\Retention;

use ElPandaPe\Sentinel\Exceptions\ConfigurationException;
use ElPandaPe\Sentinel\Models\Audit;
use ElPandaPe\Sentinel\Support\Config;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * The retention map, read and resolved once.
 *
 * A key is a logical type and never a physical table: 'model:App\Models\User' names what an entry
 * is about, 'auth' names what kind of entry it is. The colon is the whole discriminator, because
 * 'model' on its own is a legal audit_type and means something else entirely.
 *
 * The class is resolved through the morph map before it is compared to anything. Without that step
 * every model: key is inert on an application that declares one, and inert in the direction that
 * keeps data forever rather than the one that loses it — which is why it would never be noticed.
 *
 * Two keys that seat the same entry with the same specificity are refused here rather than
 * arbitrated later: a purge that picked one of them would be choosing how long to keep evidence by
 * hash order.
 */
final readonly class Schedule
{
    private const string SUBJECT_PREFIX = 'model:';

    /**
     * @var list<Policy>
     */
    public array $subjects;

    /**
     * @var list<Policy>
     */
    public array $types;

    public function __construct(Config $config)
    {
        $subjects = [];
        $types = [];
        $seats = [];

        foreach ($config->retention() as $key => $declared) {
            $policy = $this->resolve($key, $declared);
            $seat = ($policy->bySubject ? 'subject:' : 'type:').$policy->target;

            if (array_key_exists($seat, $seats)) {
                throw ConfigurationException::ambiguousRetention($seats[$seat], $key, $policy->target);
            }

            $seats[$seat] = $key;

            if ($policy->bySubject) {
                $subjects[] = $policy;
            } else {
                $types[] = $policy;
            }
        }

        $this->subjects = $subjects;
        $this->types = $types;
    }

    public function isEmpty(): bool
    {
        return $this->subjects === [] && $this->types === [];
    }

    /**
     * The subject classes any policy names, which is what a type policy has to exclude: an entry
     * about a subject that has its own policy is never governed by the type it happens to be.
     *
     * @return list<string>
     */
    public function subjectTargets(): array
    {
        return array_map(static fn (Policy $policy): string => $policy->target, $this->subjects);
    }

    /**
     * @return list<string>
     */
    public function typeTargets(): array
    {
        return array_map(static fn (Policy $policy): string => $policy->target, $this->types);
    }

    /**
     * Which policy governs this entry, subject before type, and null for an entry no policy names —
     * which is kept forever. It is the same precedence the SQL predicate compiles, expressed once
     * more in PHP because the two are asked in different places and have to agree.
     */
    public function covering(Audit $audit): ?Policy
    {
        foreach ([...$this->subjects, ...$this->types] as $policy) {
            if ($policy->matches($audit->subject_type, $audit->audit_type)) {
                return $policy;
            }
        }

        return null;
    }

    private function resolve(string $key, string $declared): Policy
    {
        $duration = Duration::of($key, $declared);

        return str_starts_with($key, self::SUBJECT_PREFIX) || str_contains($key, '\\')
            ? Policy::subject($key, $this->morphAlias($key), $duration)
            : Policy::type($key, $key, $duration);
    }

    private function morphAlias(string $key): string
    {
        $class = ltrim(str_starts_with($key, self::SUBJECT_PREFIX)
            ? substr($key, strlen(self::SUBJECT_PREFIX))
            : $key, '\\');

        /** @var class-string<\Illuminate\Database\Eloquent\Model> $class */
        return (string) Relation::getMorphAlias($class);
    }
}
